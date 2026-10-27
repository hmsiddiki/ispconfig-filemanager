<?php

/**
 * Background worker for "Unzip": php unzip.php <handover file>
 *
 * Started by UnzipJobController as a process of its own so the unzip goes on when the browser page is closed or reloaded. The handover file
 * (private to the panel user, deleted here as soon as it is read) carries the job id, the encrypted FTP / SFTP login of the session and the paths.
 * All progress goes to the job's status file, which the browser polls.
 */

use Filegator\Services\Archiver\UnzipJob;
use Filegator\Services\Remote\RemoteConnection;
use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Storage\Filesystem;

if (PHP_SAPI !== 'cli' || empty($argv[1])) {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

ignore_user_abort(true);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$raw = @file_get_contents($argv[1]);
@unlink($argv[1]);
$p = $raw === false ? null : json_decode($raw, true);
foreach (['id', 'user', 'jobs_dir', 'blob', 'key_file', 'home', 'source', 'destination', 'tmp_dir'] as $need) {
    if (! is_array($p) || ! isset($p[$need]) || ! is_string($p[$need])) {
        exit(1);
    }
}
if (! UrlJobs::validId($p['id'])) {
    exit(1);
}

$jobs = new UrlJobs($p['user'], $p['jobs_dir']);
$base = ['name' => basename($p['source']), 'started' => (int) ($jobs->read($p['id'])['started'] ?? time()), 'destination' => $p['destination']];

// a crash that PHP can still report (out of memory, a fatal error) must not leave the job looking alive
register_shutdown_function(function () use ($jobs, $p, $base) {
    $state = $jobs->read($p['id']);
    if ($state && UnzipJob::isActive($state)) {
        $jobs->write($p['id'], $base + ['state' => 'error', 'message' => 'The unzip stopped unexpectedly. Files extracted so far were kept.']);
    }
});

$user_slot = $jobs->acquire('unzip-user-' . $jobs->userKey(), 1);
$total_slot = $user_slot ? $jobs->acquire('unzip-all', 2) : null;
if (! $user_slot || ! $total_slot) {
    $jobs->write($p['id'], $base + ['state' => 'error', 'message' => $user_slot ? 'The server is busy with other unzips. Try again in a moment.' : 'An unzip is already running.']);
    exit(0);
}

try {
    $hex = trim((string) @file_get_contents($p['key_file']));
    $connection = strlen($hex) === 64 && ctype_xdigit($hex) ? RemoteConnection::decrypt($p['blob'], hex2bin($hex)) : null;
    if ($connection === null) {
        throw new \InvalidArgumentException('The login for this session is no longer valid. Sign in again.');
    }

    $storage = new Filesystem();
    $storage->init(['separator' => '/', 'config' => [], 'adapter' => function () use ($connection) {
        return $connection->adapter();
    }]);
    $storage->setPathPrefix($p['home']);

    (new UnzipJob($storage, $jobs, $p['tmp_dir']))->run($p['id'], $p['source'], $p['destination']);
} catch (\Throwable $e) {
    $jobs->write($p['id'], $base + ['state' => 'error', 'message' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'The unzip could not start']);
} finally {
    $jobs->release($total_slot);
    $jobs->release($user_slot);
}
