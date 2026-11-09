<?php

/**
 * Background worker for "Copy" and "Move": php transfer.php <handover file>
 *
 * Started by TransferJobController as a process of its own so a large copy or move goes on when the browser page is closed, reloaded or left for
 * another panel tab. The handover file (private to the panel user, deleted here as soon as it is read) carries the job id, the encrypted FTP / SFTP
 * login of the session, the kind (copy or move), the items and the destination. All progress goes to the job's status file, which the browser polls.
 */

use Filegator\Services\Archiver\UnzipJob;
use Filegator\Services\Remote\RemoteConnection;
use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Storage\DeleteJob;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Storage\TransferJob;

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
foreach (['id', 'user', 'jobs_dir', 'blob', 'key_file', 'home', 'folder', 'name', 'kind', 'destination'] as $need) {
    if (! is_array($p) || ! isset($p[$need]) || ! is_string($p[$need])) {
        exit(1);
    }
}
if (! UrlJobs::validId($p['id']) || ! isset($p['items']) || ! is_array($p['items']) || ! in_array($p['kind'], TransferJob::KINDS, true)) {
    exit(1);
}

$jobs = new UrlJobs($p['user'], $p['jobs_dir']);
$word = $p['kind'] === 'move' ? 'move' : 'copy';
$base = ['kind' => $p['kind'], 'name' => $p['name'], 'started' => (int) ($jobs->read($p['id'])['started'] ?? time()), 'destination' => $p['destination'],
    'folder' => $p['folder'], 'paths' => DeleteJob::paths($p['items'])];

// a crash that PHP can still report (out of memory, a fatal error) must not leave the job looking alive
register_shutdown_function(function () use ($jobs, $p, $base, $word) {
    $state = $jobs->read($p['id']);
    if ($state && UnzipJob::isActive($state)) {
        $jobs->write($p['id'], $base + ['state' => 'error', 'message' => 'The ' . $word . ' stopped unexpectedly. ' . ($word === 'move' ? 'Items moved so far stay moved.' : 'Files copied so far were kept.')]);
    }
});

// copies and moves of one website run one after the other, and a few at a time on the whole server; a job that has to wait stays "queued"
$beat = function () use ($jobs, $p, $base, $word) {
    if ($jobs->cancelRequested($p['id'])) {
        $jobs->write($p['id'], $base + ['state' => 'cancelled', 'message' => ucfirst($word) . ' cancelled', 'files' => 0]);
        exit(0);
    }
    $jobs->write($p['id'], $base + ['state' => 'queued']);
};
$user_slot = $jobs->acquireWaiting('transfer-user-' . $jobs->userKey(), 1, 6 * 3600, $beat);
$total_slot = $user_slot ? $jobs->acquireWaiting('transfer-all', 3, 6 * 3600, $beat) : null;
if (! $user_slot || ! $total_slot) {
    $jobs->write($p['id'], $base + ['state' => 'error', 'message' => 'The server is busy. Try again later.']);
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

    (new TransferJob($storage, $jobs))->run($p['id'], $p['kind'], $p['items'], $p['destination'], $p['folder']);
} catch (\Throwable $e) {
    $jobs->write($p['id'], $base + ['state' => 'error', 'message' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'The ' . $word . ' could not start']);
} finally {
    $jobs->release($total_slot);
    $jobs->release($user_slot);
}
