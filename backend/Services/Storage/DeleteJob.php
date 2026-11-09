<?php

namespace Filegator\Services\Storage;

use Filegator\Services\Remote\UrlJobs;
use League\Flysystem\FileNotFoundException;

/**
 * Deletes files and folders on the user's storage (the FTP / SFTP login of the session) as a background job.
 *
 * It runs in a worker process of its own (backend/Workers/delete.php), not inside the browser's request: deleting a big folder over FTP is one
 * command per file and can take minutes, and leaving the page must not stop it half-way. Progress goes to the same status file the zip and
 * unzip use: counting the files, then "n / m files", then done / error / cancelled.
 *
 * Folders are walked one level at a time (a listing per folder, so a big folder shows progress and can be cancelled while it is counted), every
 * file is deleted, then the folders themselves, deepest first. A cancel stops it where it is: what was deleted so far stays deleted.
 */
class DeleteJob
{
    /** At most this many items can be picked for one delete (each is a file or a whole folder). */
    public const MAX_ITEMS = 5000;

    protected $storage;

    protected $jobs;

    protected $last = 0.0;

    /** @var array<string, true> files the user picked themselves (not found by listing a folder) */
    protected $picked = [];

    /** @var array<string, array<string, true>|null> folder => the names its listing shows (null: the listing failed) */
    protected $listed = [];

    /** Shown when the FTP server refuses names holding "\" (Pure-FTPd does; zips made on Windows and some tools leave such files). */
    public const NOTE_BACKSLASH = 'The FTP server refuses file names that contain "\". Remove those files over SSH or with the hosting panel.';

    public function __construct(Filesystem $storage, UrlJobs $jobs)
    {
        $this->storage = $storage;
        $this->jobs = $jobs;
    }

    /** What the progress card calls the job: the item's name, or "n items". */
    public static function label(array $items): string
    {
        if (count($items) === 1) {
            $name = (string) substr(strrchr('/' . rtrim((string) ($items[0]['path'] ?? ''), '/'), '/'), 1);   // a "\" stays in the name

            return $name !== '' ? $name : '/';
        }

        return count($items) . ' items';
    }

    /** The picked paths, kept in the status file so the file list can leave them out while they are being deleted (also after a reload). */
    public static function paths(array $items): array
    {
        return array_slice(array_map(static function ($item) {
            return (string) ($item['path'] ?? '');
        }, $items), 0, 500);
    }

    /**
     * Do the whole job. Never throws: the outcome is written to the status file.
     *
     * @param string                                         $id     job id (UrlJobs::validId)
     * @param array<int, array{path: string, type: string}> $items  what to delete
     * @param string                                         $folder the folder the items were picked in (shown, and refreshed afterwards)
     */
    public function run(string $id, array $items, string $folder): void
    {
        $started = (int) ($this->jobs->read($id)['started'] ?? time());
        $base = ['kind' => 'delete', 'name' => self::label($items), 'started' => $started, 'destination' => $folder, 'paths' => self::paths($items)];
        $done = 0;
        $failed = 0;
        $errors = [];
        $note = null;

        try {
            [$files, $dirs, $tops] = $this->collect($id, $items, $base);
            $total = count($files);

            foreach ($files as $file) {
                $this->progress($id, $base + ['state' => 'deleting', 'done' => $done, 'total' => $total, 'failed' => $failed, 'current' => ltrim($file, '/')]);
                if ($this->deleteFile($file)) {
                    $done++;
                } else {
                    $failed++;
                    if (strpos($file, '\\') !== false) {
                        $note = self::NOTE_BACKSLASH;
                    }
                    if (count($errors) < 5) {
                        $errors[] = ltrim($file, '/');
                    }
                }
            }

            // deepest first, so each folder is already empty when it is removed: one RMD each. Only a folder that still holds something the
            // listing did not show falls back to the full deleteDir(), which lists and empties it.
            usort($dirs, static function ($a, $b) {
                return substr_count($b, '/') <=> substr_count($a, '/');
            });
            foreach ($dirs as $dir) {
                $this->progress($id, $base + ['state' => 'deleting', 'done' => $done, 'total' => $total, 'failed' => $failed, 'current' => ltrim($dir, '/') . '/']);
                $ok = false;
                try {
                    $ok = $this->storage->removeEmptyDir($dir);
                } catch (\Throwable $e) {
                    $ok = false;
                }
                // a folder inside a picked one that cannot be removed shows up again when its parent fails: only the picked ones are reported
                if (! $ok && isset($tops[$dir])) {
                    $failed++;
                    if (count($errors) < 5) {
                        $errors[] = ltrim($dir, '/') . '/';
                    }
                }
            }

            if ($failed > 0 && $done === 0 && $total > 0) {
                throw new \InvalidArgumentException('Nothing could be deleted: ' . implode(', ', $errors) . ($note !== null ? '. ' . $note : ''));
            }
            $this->jobs->write($id, $base + ['state' => 'done', 'files' => $done, 'failed' => $failed, 'errors' => $errors] + ($note !== null ? ['note' => $note] : []));
        } catch (\InvalidArgumentException $e) {
            $cancelled = $e->getMessage() === 'Delete cancelled';
            $this->jobs->write($id, $base + ['state' => $cancelled ? 'cancelled' : 'error', 'message' => $e->getMessage(), 'files' => $done]);
        } catch (\Throwable $e) {
            // storage failures can carry host names or paths: keep them out of the browser
            $this->jobs->write($id, $base + ['state' => 'error', 'message' => 'Deleting failed: ' . (new \ReflectionClass($e))->getShortName(), 'files' => $done]);
        }
    }

    /**
     * Every file to delete and every folder to remove afterwards.
     *
     * @return array{0: string[], 1: string[], 2: array<string, bool>} files, folders, the folders that were picked
     */
    protected function collect(string $id, array $items, array $base): array
    {
        $files = [];
        $dirs = [];
        $tops = [];

        foreach ($items as $item) {
            $path = TransferJob::clean((string) ($item['path'] ?? ''));   // a "\" stays part of the name
            if ($path === null || $path === '/') {
                continue;   // never the home folder itself, never a path that climbs out
            }
            if (($item['type'] ?? '') === 'file') {
                $files[] = $path;
                $this->picked[$path] = true;
                continue;
            }

            $dirs[] = $path;
            $tops[$path] = true;
            $queue = [$path];
            while ($queue) {
                $dir = array_shift($queue);
                $this->progress($id, $base + ['state' => 'listing', 'files' => count($files)]);

                $children = null;
                for ($try = 0; $try < 2 && $children === null; $try++) {
                    try {
                        $children = $this->storage->getDirectoryCollection($dir)->all();
                    } catch (\Throwable $e) {
                        // one retry, in case the connection had dropped; after that deleteDir() still tries the folder as a whole
                    }
                }
                foreach ((array) $children as $child) {
                    if ($child['type'] === 'back') {
                        continue;
                    }
                    $child_path = '/' . trim($child['path'], '/');
                    if ($child['type'] === 'dir') {
                        $dirs[] = $child_path;
                        $queue[] = $child_path;
                    } else {
                        $files[] = $child_path;
                    }
                }
            }
        }

        return [array_values(array_unique($files)), array_values(array_unique($dirs)), $tops];
    }

    /** One request per file (deleteFileNow); a file that is already gone counts as deleted. */
    /**
     * One delete command per file. When it fails, the server is not asked "is it still there?": it can refuse that question too (Pure-FTPd
     * does for names holding "\"), and a refused question looks like "not there", which made refused files count as deleted. Instead:
     * a file found by listing its folder was there a moment ago, so it failed; a file the user picked counts as already deleted only
     * when its folder's listing (taken once per folder) no longer shows it.
     */
    protected function deleteFile(string $path): bool
    {
        try {
            if ($this->storage->deleteFileOnce($path)) {
                return true;
            }
        } catch (FileNotFoundException $e) {
            // already gone, or refused: decided below
        } catch (\Throwable $e) {
            return false;
        }

        return isset($this->picked[$path]) && ! $this->inListing($path);
    }

    /** Does the listing of $path's folder show it? Listed once per folder; when the listing fails the answer is yes (do not claim it is gone). */
    protected function inListing(string $path): bool
    {
        $slash = (int) strrpos($path, '/');
        $parent = $slash > 0 ? substr($path, 0, $slash) : '/';
        $name = substr($path, $slash + 1);
        if (! array_key_exists($parent, $this->listed)) {
            $names = [];
            try {
                foreach ($this->storage->getDirectoryCollection($parent)->all() as $child) {
                    if (($child['type'] ?? '') !== 'back') {
                        $names[(string) substr(strrchr('/' . (string) $child['path'], '/'), 1)] = true;
                    }
                }
            } catch (\Throwable $e) {
                $names = null;
            }
            $this->listed[$parent] = $names;
        }

        return $this->listed[$parent] === null || isset($this->listed[$parent][$name]);
    }

    /** Write progress at most twice a second, and stop when the user cancelled. */
    protected function progress(string $id, array $state): void
    {
        if (microtime(true) - $this->last < 0.5) {
            return;
        }
        $this->last = microtime(true);
        if ($this->jobs->cancelRequested($id)) {
            throw new \InvalidArgumentException('Delete cancelled');
        }
        $this->jobs->write($id, $state);
    }
}
