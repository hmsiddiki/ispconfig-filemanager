<?php

namespace Filegator\Services\Remote;

/**
 * Bookkeeping for URL downloads: progress files the browser polls, cancel flags, and concurrency slots.
 *
 * Slots are flock()ed files, so a download that crashes or is killed frees its slot by itself (no stale locks to clean up).
 * Everything for one user is keyed by $user_key, so one user cannot read or cancel another user's download.
 */
class UrlJobs
{
    protected $dir;

    protected $user_key;

    public function __construct(string $user_key, ?string $dir = null)
    {
        $this->user_key = substr(sha1($user_key), 0, 16);
        $this->dir = $dir ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'filegator-url';
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    public function userKey(): string
    {
        return $this->user_key;
    }

    /** Job ids come from the browser: only a fixed shape is accepted so they can never be used as a path. */
    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/', $id);
    }

    /**
     * Take one of $max slots named $scope. Returns a handle to pass to release(), or null when all are taken.
     *
     * @return resource|null
     */
    public function acquire(string $scope, int $max)
    {
        $scope = preg_replace('/[^a-z0-9\-]/i', '', $scope);
        for ($i = 0; $i < max(1, $max); $i++) {
            $fp = @fopen($this->dir . DIRECTORY_SEPARATOR . 'slot-' . $scope . '-' . $i . '.lock', 'c');
            if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
                return $fp;
            }
            if ($fp) {
                fclose($fp);
            }
        }

        return null;
    }

    public function release($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function write(string $id, array $state): void
    {
        $state['updated'] = time();
        $path = $this->path($id, 'json');
        // written to a side file and renamed over the real one: file_put_contents() empties the file first, and a poll that arrives at that
        // moment would read nothing (the job would seem to have vanished)
        $tmp = $path . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($state)) !== false && @rename($tmp, $path)) {
            return;
        }
        @unlink($tmp);
        @file_put_contents($path, json_encode($state), LOCK_EX);   // where rename over an open file is refused (Windows)
    }

    public function read(string $id): ?array
    {
        if (! self::validId($id)) {
            return null;
        }
        // the writer may be mid-write: one retry is enough for a file this small
        for ($try = 0; $try < 2; $try++) {
            $raw = @file_get_contents($this->path($id, 'json'));
            $data = $raw === false ? null : json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
            usleep(20000);
        }

        return null;
    }

    public function requestCancel(string $id): void
    {
        if (self::validId($id)) {
            @touch($this->path($id, 'cancel'));
        }
    }

    public function cancelRequested(string $id): bool
    {
        return is_file($this->path($id, 'cancel'));
    }

    /**
     * Every job of this user that still has a status file, newest first: [id => state array].
     *
     * @return array<string, array>
     */
    public function all(): array
    {
        $out = [];
        $prefix = $this->dir . DIRECTORY_SEPARATOR . 'job-' . $this->user_key . '-';
        foreach (glob($prefix . '*.json') ?: [] as $file) {
            $id = substr(basename($file), strlen('job-' . $this->user_key . '-'), 24);
            $state = self::validId($id) ? $this->read($id) : null;
            if ($state !== null) {
                $out[$id] = $state;
            }
        }
        uasort($out, static function ($a, $b) {
            return ($b['started'] ?? 0) <=> ($a['started'] ?? 0);
        });

        return $out;
    }

    /** Drop every file of one job (status, cancel flag, handover). */
    public function forget(string $id): void
    {
        if (self::validId($id)) {
            foreach (['json', 'cancel', 'payload'] as $ext) {
                @unlink($this->path($id, $ext));
            }
        }
    }

    /** Hand a worker process what it needs. The file is private to the panel user and the worker deletes it as soon as it has read it. */
    public function putPayload(string $id, array $payload): string
    {
        $path = $this->path($id, 'payload');
        if (@file_put_contents($path, json_encode($payload), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write the job file');
        }
        @chmod($path, 0600);

        return $path;
    }

    public function takePayload(string $id): ?array
    {
        $path = $this->path($id, 'payload');
        $raw = @file_get_contents($path);
        @unlink($path);
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** Remove status files nobody will read any more. */
    public function cleanup(int $older_than = 3600): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . 'job-*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - $older_than) {
                @unlink($file);
            }
        }
    }

    protected function path(string $id, string $ext): string
    {
        if (! self::validId($id)) {
            throw new \InvalidArgumentException('Invalid request');
        }

        return $this->dir . DIRECTORY_SEPARATOR . 'job-' . $this->user_key . '-' . $id . '.' . $ext;
    }
}
