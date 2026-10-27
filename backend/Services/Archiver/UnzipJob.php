<?php

namespace Filegator\Services\Archiver;

use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Storage\Filesystem;

/**
 * Unpacks one archive (zip, tar, tar.gz, tgz, tar.bz2, tar.xz) that sits on the user's storage (the FTP / SFTP login of the session) into a folder on that storage.
 *
 * It runs in a worker process of its own (backend/Workers/unzip.php), not inside the browser's request, so closing or reloading the page does
 * not stop it. Progress goes to a status file that the browser polls: copying the archive, then "n of m files", then done / error / cancelled.
 *
 * The archive is copied to a temporary file first (a zip can only be read when it can be seeked), and that file is always removed at the end.
 * A tar is first unpacked by the server's own tar program into a temporary folder (also removed at the end), then its files are stored one by one:
 * a tar cannot be read file by file, and tar itself knows how to refuse names that point outside the folder.
 */
class UnzipJob
{
    /** A job whose status file has not been touched for this many seconds is shown as stopped. */
    public const STALL_AFTER = 120;

    protected $storage;

    protected $jobs;

    protected $tmp_dir;

    protected $created = [];

    /** folders this job made itself: nothing was in them before, so a file written there cannot clash with an existing one */
    protected $fresh = [];

    public function __construct(Filesystem $storage, UrlJobs $jobs, string $tmp_dir)
    {
        $this->storage = $storage;
        $this->jobs = $jobs;
        $this->tmp_dir = rtrim($tmp_dir, '/\\');
    }

    /** zip, or one of the tar family: the extensions the job can unpack. */
    public static function isArchive(string $name): bool
    {
        return self::kind($name) !== null;
    }

    /** 'zip', 'tar' or null. */
    public static function kind(string $name): ?string
    {
        $name = strtolower($name);
        if (substr($name, -4) === '.zip') {
            return 'zip';
        }

        return preg_match('/\.(tar|tar\.gz|tgz|tar\.bz2|tbz2|tbz|tar\.xz|txz)$/', $name) === 1 ? 'tar' : null;
    }

    public static function isActive(array $state): bool
    {
        return in_array($state['state'] ?? '', ['queued', 'copying', 'extracting'], true);
    }

    /** The state as the browser should see it: a job that is "running" but silent for too long has died. */
    public static function present(array $state): array
    {
        if (self::isActive($state) && time() - (int) ($state['updated'] ?? 0) > self::STALL_AFTER) {
            $state['state'] = 'error';
            $state['message'] = 'The unzip stopped unexpectedly. Files extracted so far were kept.';
        }

        return $state;
    }

    /**
     * Do the whole job. Never throws: the outcome is written to the status file.
     *
     * @param string $id          job id (UrlJobs::validId)
     * @param string $source      archive path on the storage
     * @param string $destination folder on the storage
     */
    public function run(string $id, string $source, string $destination): void
    {
        $started = (int) ($this->jobs->read($id)['started'] ?? time());
        $name = basename($source);
        $base = ['name' => $name, 'started' => $started, 'destination' => $destination];
        $tmp = null;
        $zip = null;
        $zip_open = false;
        $staging = null;
        $kind = self::kind($source);

        try {
            if ($kind === null) {
                throw new \InvalidArgumentException('This kind of archive is not supported');
            }
            if (! is_dir($this->tmp_dir) && ! @mkdir($this->tmp_dir, 0700, true) && ! is_dir($this->tmp_dir)) {
                throw new \RuntimeException('No temporary folder');
            }
            $tmp = $this->tmp_dir . DIRECTORY_SEPARATOR . 'unzip-' . $id . ($kind === 'zip' ? '.zip' : '.tar');

            $this->copyArchive($id, $source, $tmp, $base);

            if ($kind === 'tar') {
                $staging = $this->tmp_dir . DIRECTORY_SEPARATOR . 'unpack-' . $id;
                $result = $this->extractTar($id, $tmp, $staging, $destination, $base);
                $this->jobs->write($id, $base + $result + ['state' => 'done']);

                return;
            }

            $zip = new \ZipArchive();
            $opened = $zip->open($tmp);
            if ($opened !== true) {
                throw new \InvalidArgumentException('This archive cannot be read by the server');
            }
            $zip_open = true;

            $result = $this->extract($id, $zip, $destination, $base);
            $this->jobs->write($id, $base + $result + ['state' => 'done']);
        } catch (\InvalidArgumentException $e) {
            $cancelled = $e->getMessage() === 'Unzip cancelled';
            $this->jobs->write($id, $base + ['state' => $cancelled ? 'cancelled' : 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // storage failures can carry host names or paths: keep them out of the browser
            $this->jobs->write($id, $base + ['state' => 'error', 'message' => 'Unpacking failed: ' . (new \ReflectionClass($e))->getShortName()]);
        } finally {
            if ($zip_open) {
                @$zip->close();
            }
            if ($tmp !== null) {
                @unlink($tmp);
            }
            if ($staging !== null) {
                self::removeTree($staging);
            }
        }
    }

    protected function copyArchive(string $id, string $source, string $tmp, array $base): void
    {
        $remote = $this->storage->readStream($source);
        $in = $remote['stream'];
        $out = fopen($tmp, 'wb');
        if (! $out) {
            throw new \RuntimeException('Cannot create the temporary file');
        }

        $total = (int) ($remote['filesize'] ?? 0);
        $bytes = 0;
        $last = 0.0;
        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Read failed');
                }
                fwrite($out, $chunk);
                $bytes += strlen($chunk);
                if (microtime(true) - $last >= 0.5) {
                    $last = microtime(true);
                    if ($this->jobs->cancelRequested($id)) {
                        throw new \InvalidArgumentException('Unzip cancelled');
                    }
                    $this->jobs->write($id, $base + ['state' => 'copying', 'bytes' => $bytes, 'total' => $total]);
                }
            }
        } finally {
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    /** @return array{files: int, failed: int, errors: string[]} */
    protected function extract(string $id, \ZipArchive $zip, string $destination, array $base): array
    {
        $total = $zip->numFiles;
        $done = 0;
        $failed = 0;
        $errors = [];
        $last = 0.0;

        for ($i = 0; $i < $total; $i++) {
            $entry = $zip->getNameIndex($i);
            $path = is_string($entry) ? self::cleanEntry($entry) : null;

            if (microtime(true) - $last >= 0.5) {
                $last = microtime(true);
                if ($this->jobs->cancelRequested($id)) {
                    throw new \InvalidArgumentException('Unzip cancelled');
                }
                $this->jobs->write($id, $base + ['state' => 'extracting', 'done' => $done, 'total' => $total, 'failed' => $failed, 'current' => $path !== null ? basename($path) : '']);
            }

            if ($path === null) {
                $failed++;
                $errors[] = 'Skipped an unsafe name';
                continue;
            }

            try {
                if (substr($entry, -1) === '/') {
                    $this->ensureDir($destination, $path);
                } else {
                    $this->storeFile(static function () use ($zip, $entry) {
                        return $zip->getStream($entry);
                    }, $destination, $path);
                }
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                if (count($errors) < 5) {
                    $errors[] = $path . ': ' . (new \ReflectionClass($e))->getShortName();
                }
            }
        }

        return ['files' => $done, 'failed' => $failed, 'errors' => $errors, 'total' => $total, 'done' => $done];
    }

    /** Folders in the archive are created once each; one that is already there is simply used. */
    protected function ensureDir(string $destination, string $dir): void
    {
        $dir = trim($dir, '/');
        if ($dir === '' || isset($this->created[$dir])) {
            return;
        }

        $parent = '';
        foreach (explode('/', $dir) as $part) {
            $current = $parent === '' ? $part : $parent . '/' . $part;
            if (! isset($this->created[$current])) {
                $full = rtrim($destination, '/') . '/' . $current;
                if (! $this->storage->fileExists($full)) {
                    $this->storage->createDir(rtrim($destination, '/') . ($parent === '' ? '' : '/' . $parent), $part);
                    $this->fresh[$current] = true;
                } elseif ($parent !== '' && isset($this->fresh[$parent])) {
                    $this->fresh[$current] = true;
                }
                $this->created[$current] = true;
            }
            $parent = $current;
        }
    }

    /** @param callable $open returns a fresh read stream of the file (called again if the store has to be retried) */
    protected function storeFile(callable $open, string $destination, string $path): void
    {
        $dir = dirname($path);
        if ($dir !== '.' && $dir !== '') {
            $this->ensureDir($destination, $dir);
        }

        $attempts = 0;
        while (true) {
            $stream = $open();
            if (! is_resource($stream)) {
                throw new \RuntimeException('Cannot read the entry');
            }
            try {
                $target = rtrim($destination, '/') . ($dir === '.' ? '' : '/' . $dir);
                $ok = $dir !== '.' && isset($this->fresh[$dir])
                    ? $this->storage->storeNew($target, basename($path), $stream)
                    : $this->storage->store($target, basename($path), $stream);
                if (! $ok) {
                    throw new \RuntimeException('Store failed');
                }

                return;
            } catch (\Throwable $e) {
                // a long job can outlive the FTP / SFTP connection: one more try opens a new one
                if (++$attempts >= 2) {
                    throw $e;
                }
            } finally {
                fclose($stream);
            }
        }
    }

    /**
     * @return array{files: int, failed: int, errors: string[], total: int, done: int}
     */
    protected function extractTar(string $id, string $tmp, string $staging, string $destination, array $base): array
    {
        if (! @mkdir($staging, 0700, true) && ! is_dir($staging)) {
            throw new \RuntimeException('No temporary folder');
        }

        // relative names, run from the temporary folder: a path with a drive letter (C:) would be read by GNU tar as a remote host
        $cmd = [self::tarProgram(), '-xf', basename($tmp), '-C', basename($staging), '--no-same-owner', '--no-same-permissions'];
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $process = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['pipe', 'w']], $pipes, dirname($tmp));
        if (! is_resource($process)) {
            throw new \InvalidArgumentException('The server cannot unpack this kind of archive');
        }
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $last = 0.0;
        while (true) {
            $status = proc_get_status($process);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (! $status['running']) {
                break;
            }
            if ($this->jobs->cancelRequested($id)) {
                proc_terminate($process);
                proc_close($process);
                throw new \InvalidArgumentException('Unzip cancelled');
            }
            if (microtime(true) - $last >= 0.5) {
                $last = microtime(true);
                $this->jobs->write($id, $base + ['state' => 'copying', 'phase' => 'unpacking']);
            }
            usleep(200000);
        }
        $code = $status['exitcode'];
        fclose($pipes[2]);
        proc_close($process);
        if ($code !== 0 && $code !== -1) {
            throw new \InvalidArgumentException('This archive cannot be read by the server');
        }

        // what the archive holds, as relative paths: folders, then files; links and special files are never stored
        $dirs = [];
        $files = [];
        $root = rtrim(str_replace('\\', '/', $staging), '/') . '/';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = substr(str_replace('\\', '/', $item->getPathname()), strlen($root));
            if ($item->isLink()) {
                continue;
            }
            if ($item->isDir()) {
                $dirs[] = $rel;
            } elseif ($item->isFile()) {
                $files[] = $rel;
            }
        }

        $total = count($dirs) + count($files);
        $done = 0;
        $failed = 0;
        $errors = [];
        $last = 0.0;
        foreach (array_merge($dirs, $files) as $rel) {
            if (microtime(true) - $last >= 0.5) {
                $last = microtime(true);
                if ($this->jobs->cancelRequested($id)) {
                    throw new \InvalidArgumentException('Unzip cancelled');
                }
                $this->jobs->write($id, $base + ['state' => 'extracting', 'done' => $done, 'total' => $total, 'failed' => $failed, 'current' => basename($rel)]);
            }
            $local = $root . $rel;
            try {
                if (is_dir($local)) {
                    $this->ensureDir($destination, $rel);
                } else {
                    $this->storeFile(static function () use ($local) {
                        return fopen($local, 'rb');
                    }, $destination, $rel);
                }
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                if (count($errors) < 5) {
                    $errors[] = $rel . ': ' . (new \ReflectionClass($e))->getShortName();
                }
            }
        }

        return ['files' => $done, 'failed' => $failed, 'errors' => $errors, 'total' => $total, 'done' => $done];
    }

    /** tar in the PATH (a path to it can be set with the environment variable FILEGATOR_TAR). */
    protected static function tarProgram(): string
    {
        $own = getenv('FILEGATOR_TAR');

        return $own !== false && $own !== '' ? $own : 'tar';
    }

    /** Delete a folder with everything in it; links are removed, never followed. */
    public static function removeTree(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . DIRECTORY_SEPARATOR . $entry;
                is_dir($path) && ! is_link($path) ? self::removeTree($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** An archive entry name made safe for the storage: no drive letters, no leading slash, no "..", no control characters. Null when nothing is left. */
    public static function cleanEntry(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || preg_match('/[\x00-\x1f]/', $name) || preg_match('#^[a-z]:#i', $name)) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
    }
}
