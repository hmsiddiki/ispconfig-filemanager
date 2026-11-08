<?php

/**
 * Background worker for the last step of a large upload: php upload.php <handover file>
 *
 * Started by UploadController once the browser has sent every piece of a large file. It copies the file from the server's own disk into the
 * website over the session's FTP / SFTP login, so the page can be left as soon as the upload bar is full. The handover file (private to the
 * panel user, deleted here as soon as it is read) carries the job id, the encrypted login, where the file is and where it goes.
 */

use Filegator\Services\Archiver\UnzipJob;
use Filegator\Services\Remote\RemoteConnection;
use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Storage\UploadJob;

if (PHP_SAPI !== 'cli' || empty($argv[1])) {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

ignore_user_abort(true);
@set_time_limit(0);
@ini_set('memory_limit', '256M');

$raw = @file_get_contents($argv[1]);
@unlink($argv[1]);
$p = $raw === false ? null : json_decode($raw, true);
foreach (['id', 'user', 'jobs_dir', 'tmp_dir', 'blob', 'key_file', 'home', 'data_file', 'destination', 'name'] as $need) {
    if (! is_array($p) || ! isset($p[$need]) || ! is_string($p[$need])) {
        exit(1);
    }
}
// the file to copy must be one this app put in its own scratch folder
$data_file = $p['data_file'];
if (! UrlJobs::validId($p['id']) || dirname($data_file) !== rtrim($p['tmp_dir'], '/\\') || basename($data_file) !== 'upload-' . $p['id'] . '.data') {
    exit(1);
}

$jobs = new UrlJobs($p['user'], $p['jobs_dir']);
$base = ['kind' => 'upload', 'name' => $p['name'], 'started' => (int) ($jobs->read($p['id'])['started'] ?? time()), 'destination' => $p['destination'],
    'size' => (int) @filesize($data_file)];

// a crash that PHP can still report must not leave the job looking alive, nor the server's copy of the file on the disk
register_shutdown_function(function () use ($jobs, $p, $base, $data_file) {
    @unlink($data_file);
    $state = $jobs->read($p['id']);
    if ($state && UnzipJob::isActive($state)) {
        $jobs->write($p['id'], $base + ['state' => 'error', 'message' => 'Saving the upload stopped unexpectedly. Upload the file again.']);
    }
});

// a few uploads of one website are saved at the same time; more wait their turn ("queued")
$beat = function () use ($jobs, $p, $base) {
    $jobs->write($p['id'], $base + ['state' => 'queued']);
};
$user_slot = $jobs->acquireWaiting('upload-user-' . $jobs->userKey(), 2, 6 * 3600, $beat);
$total_slot = $user_slot ? $jobs->acquireWaiting('upload-all', 4, 6 * 3600, $beat) : null;
if (! $user_slot || ! $total_slot) {
    $jobs->write($p['id'], $base + ['state' => 'error', 'message' => 'The server is busy. Upload the file again later.']);
    exit(0);
}

try {
    $hex = trim((string) @file_get_contents($p['key_file']));
    $connection = strlen($hex) === 64 && ctype_xdigit($hex) ? RemoteConnection::decrypt($p['blob'], hex2bin($hex)) : null;
    if ($connection === null) {
        throw new \InvalidArgumentException('The login for this session is no longer valid. Sign in again and upload the file again.');
    }

    $storage = new Filesystem();
    $storage->init(['separator' => '/', 'config' => [], 'adapter' => function () use ($connection) {
        return $connection->adapter();
    }]);
    $storage->setPathPrefix($p['home']);

    (new UploadJob($storage, $jobs))->run($p['id'], $data_file, $p['destination'], $p['name'], ! empty($p['overwrite']));
} catch (\Throwable $e) {
    $jobs->write($p['id'], $base + ['state' => 'error', 'message' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Saving the upload could not start']);
} finally {
    $jobs->release($total_slot);
    $jobs->release($user_slot);
}
