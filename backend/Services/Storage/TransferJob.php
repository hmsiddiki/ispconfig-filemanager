<?php

namespace Filegator\Services\Storage;

use Filegator\Services\Remote\UrlJobs;

/**
 * Copy or move files and folders on the user's storage (the FTP / SFTP login of the session) as a background job, like delete, zip and unzip.
 *
 * It runs in a worker process of its own (backend/Workers/transfer.php), so leaving or reloading the page does not stop it. Both used to run inside
 * the browser's request, where the web server's time limit or leaving the page could stop a large copy half-way without a word.
 *
 * Move: one rename per picked item (a whole folder moves in one step). A name that is taken in the destination gets a number ("photos (1)").
 * Copy: a picked folder is listed one level at a time (a listing per folder), its copy gets a free name once at the top ("photos (1)" when
 * "photos" is taken), so everything under it is new and is written without asking first whether it exists; then every file is read once
 * and written once. FTP and SFTP have no copy command: a copy is always a download and an upload.
 * A folder is never copied or moved into itself. A cancel stops where it is: what was copied or moved so far stays.
 */
class TransferJob
{
    /** At most this many items can be picked for one copy or move (each is a file or a whole folder). */
    public const MAX_ITEMS = 5000;

    public const KINDS = ['copy', 'move'];

    protected $storage;

    protected $jobs;

    protected $last = 0.0;

    public function __construct(Filesystem $storage, UrlJobs $jobs)
    {
        $this->storage = $storage;
        $this->jobs = $jobs;
    }

    /** A storage path in one form: "/a/b", never "..", "." or a trailing slash. Null for a path that climbs or is empty. */
    public static function clean(string $path): ?string
    {
        $path = '/' . trim(str_replace('\\', '/', $path), '/');
        if (preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * Do the whole job. Never throws: the outcome is written to the status file.
     *
     * @param string                                         $kind        copy | move
     * @param array<int, array{path: string, type: string}> $items       what to copy or move
     * @param string                                         $destination the folder they go into
     * @param string                                         $folder      the folder they were picked in (refreshed afterwards)
     */
    public function run(string $id, string $kind, array $items, string $destination, string $folder): void
    {
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'copy';
        $started = (int) ($this->jobs->read($id)['started'] ?? time());
        $base = ['kind' => $kind, 'name' => DeleteJob::label($items), 'started' => $started, 'destination' => $destination, 'folder' => $folder,
            'paths' => DeleteJob::paths($items)];
        $done = 0;
        $failed = 0;
        $errors = [];
        $bytes = 0;

        try {
            $dest = self::clean($destination);
            if ($dest === null) {
                throw new \InvalidArgumentException('That destination is not allowed');
            }
            $picked = [];
            foreach ($items as $item) {
                $path = self::clean((string) ($item['path'] ?? ''));
                $type = (string) ($item['type'] ?? '');
                if ($path === null || $path === '/' || ! in_array($type, ['file', 'dir'], true)) {
                    continue;
                }
                if ($type === 'dir' && ($dest === $path || strpos($dest . '/', $path . '/') === 0)) {
                    $failed++;
                    $errors[] = ltrim($path, '/') . ': a folder cannot go into itself';
                    continue;
                }
                $picked[] = ['path' => $path, 'type' => $type];
            }

            if ($kind === 'move') {
                $total = count($picked);
                foreach ($picked as $item) {
                    $this->progress($id, $base + ['state' => 'moving', 'done' => $done, 'total' => $total, 'failed' => $failed, 'current' => ltrim($item['path'], '/')]);
                    if (rtrim(dirname($item['path']), '/') === rtrim($dest, '/')) {
                        $done++;   // already in that folder: nothing to do (it used to be renamed to "name (1)")
                        continue;
                    }
                    $ok = false;
                    try {
                        $ok = (bool) $this->storage->move($item['path'], rtrim($dest, '/') . '/' . basename($item['path']));
                    } catch (\Throwable $e) {
                        $ok = false;
                    }
                    if ($ok) {
                        $done++;
                    } else {
                        $failed++;
                        if (count($errors) < 5) {
                            $errors[] = ltrim($item['path'], '/');
                        }
                    }
                }
                $this->finish($id, $base, $done, $failed, $errors, $bytes);

                return;
            }

            // copy: what to make (folders) and what to write (files), with the source and the new path of each
            [$dirs, $files] = $this->plan($id, $base, $picked, $dest);
            $total = count($files);
            foreach ($dirs as $dir) {
                $this->progress($id, $base + ['state' => 'transferring', 'done' => 0, 'total' => $total, 'failed' => $failed, 'current' => ltrim($dir, '/') . '/']);
                if (! $this->storage->ensureDirectory($dir)) {
                    throw new \InvalidArgumentException('Cannot create the folder ' . ltrim($dir, '/'));
                }
            }
            foreach ($files as [$from, $to]) {
                $state = $base + ['state' => 'transferring', 'done' => $done, 'total' => $total, 'failed' => $failed, 'bytes' => $bytes, 'current' => ltrim($from, '/')];
                $this->progress($id, $state);
                $in = null;
                try {
                    $in = $this->storage->readStreamForJob($from, function (int $got) use ($id, $state, $bytes) {
                        $this->progress($id, ['bytes' => $bytes + $got] + $state);
                    });
                    $size = (int) (fstat($in)['size'] ?? 0);
                    if (! $this->storage->storeNew(dirname($to), basename($to), $in)) {
                        throw new \RuntimeException('Store failed');
                    }
                    $bytes += $size;
                    $done++;
                } catch (\InvalidArgumentException $e) {
                    throw $e;   // cancelled
                } catch (\Throwable $e) {
                    $failed++;
                    if (count($errors) < 5) {
                        $errors[] = ltrim($from, '/');
                    }
                } finally {
                    if (is_resource($in)) {
                        fclose($in);
                    }
                }
            }
            $this->finish($id, $base, $done, $failed, $errors, $bytes);
        } catch (\InvalidArgumentException $e) {
            $cancelled = substr($e->getMessage(), -9) === 'cancelled';
            $this->jobs->write($id, $base + ['state' => $cancelled ? 'cancelled' : 'error', 'message' => $e->getMessage(), 'files' => $done, 'bytes' => $bytes]);
        } catch (\Throwable $e) {
            // storage failures can carry host names or paths: keep them out of the browser
            $this->jobs->write($id, $base + ['state' => 'error', 'message' => ($kind === 'move' ? 'Moving' : 'Copying') . ' failed: ' . (new \ReflectionClass($e))->getShortName(), 'files' => $done]);
        }
    }

    protected function finish(string $id, array $base, int $done, int $failed, array $errors, int $bytes): void
    {
        if ($failed > 0 && $done === 0) {
            throw new \InvalidArgumentException('Nothing could be ' . ($base['kind'] === 'move' ? 'moved' : 'copied') . ': ' . implode(', ', array_slice($errors, 0, 5)));
        }
        $this->jobs->write($id, $base + ['state' => 'done', 'files' => $done, 'failed' => $failed, 'errors' => array_slice($errors, 0, 5), 'bytes' => $bytes]);
    }

    /**
     * The folders to make (parents first) and the files to write, as [source, new path]. Each picked item gets a free name in the
     * destination once ("name (1)" when taken); everything under a copied folder is then new.
     *
     * @return array{0: string[], 1: array<int, array{0: string, 1: string}>}
     */
    protected function plan(string $id, array $base, array $picked, string $dest): array
    {
        $dirs = [];
        $files = [];
        $taken = [];   // names this job already gave out in the destination (two picked items with the same name)
        foreach ($picked as $item) {
            $target = rtrim($dest, '/') . '/' . basename($item['path']);
            while (isset($taken[$target]) || $this->storage->fileExists($target)) {
                $target = $this->storage->upcountName($target);
            }
            $taken[$target] = true;

            if ($item['type'] === 'file') {
                $files[] = [$item['path'], $target];
                continue;
            }
            $dirs[] = $target;
            $queue = [[$item['path'], $target]];
            while ($queue) {
                [$from, $to] = array_shift($queue);
                $this->progress($id, $base + ['state' => 'listing', 'files' => count($files)]);
                $children = null;
                for ($try = 0; $try < 2 && $children === null; $try++) {
                    try {
                        $children = $this->storage->getDirectoryCollection($from)->all();
                    } catch (\Throwable $e) {
                        // one retry, in case the connection had dropped
                    }
                }
                if ($children === null) {
                    throw new \InvalidArgumentException('Cannot read the folder ' . ltrim($from, '/'));
                }
                foreach ($children as $child) {
                    if ($child['type'] === 'back') {
                        continue;
                    }
                    $name = basename(str_replace('\\', '/', (string) $child['path']));
                    $childFrom = rtrim($from, '/') . '/' . $name;
                    $childTo = rtrim($to, '/') . '/' . $name;
                    if ($child['type'] === 'dir') {
                        $dirs[] = $childTo;
                        $queue[] = [$childFrom, $childTo];
                    } else {
                        $files[] = [$childFrom, $childTo];
                    }
                }
            }
        }

        return [$dirs, $files];
    }

    /** Write progress at most twice a second, and stop when the user cancelled. */
    protected function progress(string $id, array $state): void
    {
        if (microtime(true) - $this->last < 0.5) {
            return;
        }
        $this->last = microtime(true);
        if ($this->jobs->cancelRequested($id)) {
            throw new \InvalidArgumentException(($state['kind'] === 'move' ? 'Move' : 'Copy') . ' cancelled');
        }
        $this->jobs->write($id, $state);
    }
}
