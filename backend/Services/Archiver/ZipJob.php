<?php

namespace Filegator\Services\Archiver;

use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Storage\Filesystem;

/**
 * Makes one zip of files and folders on the user's storage (the FTP / SFTP login of the session) and saves it in a folder on that storage.
 *
 * It runs in a worker process of its own (backend/Workers/zip.php), not inside the browser's request, so closing or reloading the page does not
 * stop it, and it never holds the user's session. Progress goes to a status file that the browser polls (the same one the unzip uses):
 * listing the folders, then "n of m files" with the bytes, then saving the zip, then done / error / cancelled.
 *
 * The zip is built in one temporary file next to the app's other temporary files (always removed at the end) and only then stored, so a zip
 * that fails or is cancelled leaves nothing half-written in the website. Names inside the zip are relative to the folder the items were picked
 * in: unzipping it there puts everything back where it was.
 */
class ZipJob
{
    /** Room kept free on the server's disk on top of the zip itself. */
    public const DISK_MARGIN = 100 * 1024 * 1024;

    protected $storage;

    protected $jobs;

    protected $tmp_dir;

    protected $force_zip64 = false;

    protected $last = 0.0;

    /** folders that could not be listed (left out of the zip, reported as failed) */
    protected $unlisted = [];

    public function __construct(Filesystem $storage, UrlJobs $jobs, string $tmp_dir)
    {
        $this->storage = $storage;
        $this->jobs = $jobs;
        $this->tmp_dir = rtrim($tmp_dir, '/\\');
    }

    /** Tests only: write the zip64 records even for a small zip. */
    public function forceZip64(bool $on = true): self
    {
        $this->force_zip64 = $on;

        return $this;
    }

    /** The name the zip will be saved under: no folders, no control characters, ends in ".zip". Null when nothing usable is left. */
    public static function cleanName(string $name): ?string
    {
        $name = trim(str_replace('\\', '/', $name));
        $name = trim((string) substr($name, (int) strrpos('/' . $name, '/')));
        if ($name === '' || $name === '.' || $name === '..' || preg_match('/[\x00-\x1f]/', $name)) {
            return null;
        }
        if (strtolower(substr($name, -4)) !== '.zip') {
            $name .= '.zip';
        }

        return $name;
    }

    /**
     * Do the whole job. Never throws: the outcome is written to the status file.
     *
     * @param string                                     $id          job id (UrlJobs::validId)
     * @param array<int, array{path: string, type: string}> $items    what to zip (all from the same folder)
     * @param string                                     $destination folder on the storage the zip is saved in
     * @param string                                     $name        file name of the zip
     */
    public function run(string $id, array $items, string $destination, string $name): void
    {
        $started = (int) ($this->jobs->read($id)['started'] ?? time());
        $base = ['kind' => 'zip', 'name' => $name, 'started' => $started, 'destination' => $destination];
        $tmp = null;
        $writer = null;

        try {
            if (! is_dir($this->tmp_dir) && ! @mkdir($this->tmp_dir, 0700, true) && ! is_dir($this->tmp_dir)) {
                throw new \RuntimeException('No temporary folder');
            }

            $list = $this->collect($id, $items, $base);
            if ($list['entries'] === []) {
                throw new \InvalidArgumentException('Nothing to zip');
            }

            $free = @disk_free_space($this->tmp_dir);
            if ($free !== false && $free < $list['bytes'] + self::DISK_MARGIN) {
                throw new \InvalidArgumentException('Not enough free disk space on the server to build this zip (it needs up to ' . self::human($list['bytes']) . ').');
            }

            $tmp = $this->tmp_dir . DIRECTORY_SEPARATOR . 'zip-' . $id . '.zip';
            $writer = new ZipWriter($tmp, $this->force_zip64);
            $result = $this->write($id, $writer, $list, $base);
            if ($result['files'] === 0 && $result['failed'] > 0) {
                throw new \InvalidArgumentException('None of the files could be read: ' . implode(', ', $result['errors']));
            }
            $size = $writer->finish();

            $saved = $this->upload($id, $tmp, $size, $destination, $name, $base + $result);
            $this->jobs->write($id, $base + $result + ['state' => 'done', 'size' => $size, 'saved_as' => $saved]);
        } catch (\InvalidArgumentException $e) {
            $cancelled = $e->getMessage() === 'Zip cancelled';
            $this->jobs->write($id, $base + ['state' => $cancelled ? 'cancelled' : 'error', 'message' => $e->getMessage()]);
        } catch (ZipWriteException $e) {
            $this->jobs->write($id, $base + ['state' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // storage failures can carry host names or paths: keep them out of the browser
            $this->jobs->write($id, $base + ['state' => 'error', 'message' => 'Zipping failed: ' . (new \ReflectionClass($e))->getShortName()]);
        } finally {
            if ($writer !== null) {
                $writer->abort();
            }
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Walk the chosen folders one level at a time (a listing per folder, so a big site shows progress and can be cancelled while it is counted).
     *
     * @return array{entries: array<int, array{type: string, path: string, rel: string, size: int, time: int}>, bytes: int, files: int}
     */
    protected function collect(string $id, array $items, array $base): array
    {
        $entries = [];
        $bytes = 0;
        $files = 0;
        $seen = [];

        foreach ($items as $item) {
            $path = '/' . trim(str_replace('\\', '/', (string) ($item['path'] ?? '')), '/');
            if ($path === '/') {
                continue;
            }
            // names in the zip start at the folder the item was picked in
            $parent = rtrim(dirname($path), '/\\');
            $strip = strlen($parent) + 1;

            if (($item['type'] ?? '') === 'file') {
                $rel = UnzipJob::cleanEntry(substr($path, $strip));
                if ($rel !== null && ! isset($seen[$rel])) {
                    $seen[$rel] = true;
                    $size = (int) ($item['size'] ?? 0);
                    $entries[] = ['type' => 'file', 'path' => $path, 'rel' => $rel, 'size' => $size, 'time' => (int) ($item['time'] ?? 0)];
                    $bytes += $size;
                    $files++;
                }
                continue;
            }

            $queue = [$path];
            while ($queue) {
                $dir = array_shift($queue);
                $rel_dir = UnzipJob::cleanEntry(substr($dir, $strip));
                if ($rel_dir !== null && ! isset($seen[$rel_dir . '/'])) {
                    $seen[$rel_dir . '/'] = true;
                    $entries[] = ['type' => 'dir', 'path' => $dir, 'rel' => $rel_dir, 'size' => 0, 'time' => 0];
                }

                $this->progress($id, $base + ['state' => 'listing', 'files' => $files, 'bytes' => $bytes]);

                $children = null;
                for ($try = 0; $try < 2 && $children === null; $try++) {
                    try {
                        $children = $this->storage->getDirectoryCollection($dir)->all();
                    } catch (\Throwable $e) {
                        // a folder the login cannot read: leave it out (one retry first, in case the connection had dropped)
                    }
                }
                if ($children === null) {
                    $this->unlisted[] = ltrim($dir, '/');
                    continue;
                }

                foreach ($children as $child) {
                    if ($child['type'] === 'back') {
                        continue;
                    }
                    $child_path = '/' . trim($child['path'], '/');
                    if ($child['type'] === 'dir') {
                        $queue[] = $child_path;
                        continue;
                    }
                    $rel = UnzipJob::cleanEntry(substr($child_path, $strip));
                    if ($rel === null || isset($seen[$rel])) {
                        continue;
                    }
                    $seen[$rel] = true;
                    $entries[] = ['type' => 'file', 'path' => $child_path, 'rel' => $rel, 'size' => (int) $child['size'], 'time' => (int) $child['time']];
                    $bytes += (int) $child['size'];
                    $files++;
                }
            }
        }

        return ['entries' => $entries, 'bytes' => $bytes, 'files' => $files];
    }

    /** @return array{files: int, failed: int, errors: string[], total: int, done: int} */
    protected function write(string $id, ZipWriter $writer, array $list, array $base): array
    {
        $total = $list['files'];
        $total_bytes = $list['bytes'];
        $done = 0;
        $failed = count($this->unlisted);
        $errors = array_map(static function ($dir) {
            return $dir . '/: cannot be read';
        }, array_slice($this->unlisted, 0, 5));
        $bytes_done = 0;

        foreach ($list['entries'] as $entry) {
            if ($entry['type'] === 'dir') {
                $writer->addDir($entry['rel'], $entry['time']);
                continue;
            }

            $state = $base + ['state' => 'zipping', 'done' => $done, 'total' => $total, 'failed' => $failed, 'current' => basename($entry['rel'])];
            $tick = function (int $bytes) use ($id, $state, &$bytes_done, $total_bytes) {
                $this->progress($id, $state + ['bytes' => $bytes_done + $bytes, 'total_bytes' => $total_bytes]);
            };
            // reading a file over FTP counts as the first half of its bytes, packing it into the zip as the second
            $read_tick = function (int $bytes) use ($tick) {
                $tick(intdiv($bytes, 2));
            };

            $start = $writer->position();
            $attempts = 0;
            while (true) {
                $in = null;
                try {
                    $in = $this->storage->readStreamForJob($entry['path'], $read_tick);
                    $read = (int) fstat($in)['size'];
                    $half = intdiv($read, 2);
                    $writer->addStream($entry['rel'], $in, $entry['time'], max($entry['size'], $read), function (int $bytes) use ($tick, $half) {
                        $tick($half + intdiv($bytes, 2));
                    });
                    $bytes_done += max($entry['size'], $read);
                    $done++;
                    break;
                } catch (\InvalidArgumentException | ZipWriteException $e) {
                    throw $e; // cancelled, or the zip itself cannot be written: nothing after this would work either
                } catch (\Throwable $e) {
                    $writer->rollback($start);
                    // a long job can outlive the FTP / SFTP connection: one more try opens a new one
                    if (++$attempts < 2) {
                        continue;
                    }
                    $failed++;
                    $bytes_done += $entry['size'];
                    if (count($errors) < 5) {
                        $errors[] = $entry['rel'] . ': ' . (new \ReflectionClass($e))->getShortName();
                    }
                    break;
                } finally {
                    if (is_resource($in)) {
                        fclose($in);
                    }
                }
            }
        }

        return ['files' => $done, 'failed' => $failed, 'errors' => $errors, 'total' => $total, 'done' => $done];
    }

    /** Save the finished zip in the website. Returns the name it got (a taken name gets a number). */
    protected function upload(string $id, string $tmp, int $size, string $destination, string $name, array $state): string
    {
        ProgressFilter::register();
        $attempts = 0;
        while (true) {
            $this->cancelCheck($id);
            $this->jobs->write($id, $state + ['state' => 'uploading', 'bytes' => 0, 'size' => $size]);
            $fp = fopen($tmp, 'rb');
            if (! $fp) {
                throw new \RuntimeException('Cannot read the zip file');
            }
            $last = 0.0;
            stream_filter_append($fp, ProgressFilter::NAME, STREAM_FILTER_READ, ['tick' => function (int $bytes) use ($id, $state, $size, &$last) {
                // a cancel cannot stop a store already under way; the status file only has to stay fresh
                if (microtime(true) - $last >= 0.5) {
                    $last = microtime(true);
                    $this->jobs->write($id, $state + ['state' => 'uploading', 'bytes' => min($bytes, $size), 'size' => $size]);
                }
            }]);
            try {
                $saved = $this->storage->storeUnique($destination, $name, $fp);
                if ($saved !== null) {
                    return $saved;
                }
                throw new \RuntimeException('Store failed');
            } catch (\Throwable $e) {
                if (++$attempts >= 2) {
                    throw $e;
                }
            } finally {
                if (is_resource($fp)) {
                    fclose($fp);
                }
            }
        }
    }

    /** Write progress at most twice a second, and stop when the user cancelled. */
    protected function progress(string $id, array $state): void
    {
        if (microtime(true) - $this->last < 0.5) {
            return;
        }
        $this->last = microtime(true);
        $this->cancelCheck($id);
        $this->jobs->write($id, $state);
    }

    protected function cancelCheck(string $id): void
    {
        if ($this->jobs->cancelRequested($id)) {
            throw new \InvalidArgumentException('Zip cancelled');
        }
    }

    protected static function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? $bytes : number_format($bytes, 1)) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
