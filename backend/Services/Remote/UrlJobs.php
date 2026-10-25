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
        @file_put_contents($this->path($id, 'json'), json_encode($state), LOCK_EX);
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
