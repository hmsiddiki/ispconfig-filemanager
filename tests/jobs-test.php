<?php
// Tests the background delete (DeleteJob), the background save of a large upload (UploadJob), the job kinds, the waiting slot
// and the upload piece size, against a local folder standing in for the FTP / SFTP storage.
require __DIR__ . '/../vendor/autoload.php';

use Filegator\Controllers\UploadController;
use Filegator\Controllers\ViewController;
use Filegator\Services\Archiver\UnzipJob;
use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Storage\DeleteJob;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Storage\UploadJob;
use League\Flysystem\Adapter\Local;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $extra === '' ? '' : "  -> $extra") . "\n";
}
function rrmdir(string $d): void {
    foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) continue;
        is_dir($f) ? rrmdir($f) : @unlink($f);
    }
    @rmdir($d);
}
function makeStorage(string $root): Filesystem {
    $fs = new Filesystem();
    $fs->init(['separator' => '/', 'config' => [], 'adapter' => function () use ($root) { return new Local($root . '/home'); }]);
    $fs->setPathPrefix('/');

    return $fs;
}
function newJob(UrlJobs $jobs, string $kind): string {
    $id = bin2hex(random_bytes(12));
    $jobs->write($id, ['kind' => $kind, 'state' => 'queued', 'started' => time()]);

    return $id;
}

$root = sys_get_temp_dir() . '/fg-jobs-test-' . bin2hex(random_bytes(3));
register_shutdown_function(function () use ($root) { rrmdir($root); });
$web = $root . '/home/site/web';
mkdir($web . '/old/deep/deeper', 0777, true);
mkdir($web . '/keep', 0777, true);
mkdir($root . '/work/tmp', 0777, true);
for ($i = 0; $i < 30; $i++) {
    file_put_contents($web . '/old/f' . $i . '.txt', 'x' . $i);
}
file_put_contents($web . '/old/deep/a.txt', 'a');
file_put_contents($web . '/old/deep/deeper/b.txt', 'b');
file_put_contents($web . '/single.txt', 'single');
file_put_contents($web . '/keep/k.txt', 'k');

$jobs = new UrlJobs('tester|t', $root . '/work/jobs');
$storage = makeStorage($root);

echo "== job kinds\n";
check('delete is a kind', UnzipJob::kindOf(['kind' => 'delete']) === 'delete');
check('upload is a kind', UnzipJob::kindOf(['kind' => 'upload']) === 'upload');
check('no kind = unzip', UnzipJob::kindOf([]) === 'unzip');
check('unknown kind = unzip', UnzipJob::kindOf(['kind' => 'evil']) === 'unzip');
check('deleting is active', UnzipJob::isActive(['state' => 'deleting']));
check('storing is active', UnzipJob::isActive(['state' => 'storing']));
$stalled = UnzipJob::present(['kind' => 'delete', 'state' => 'deleting', 'updated' => time() - 1000]);
check('stalled delete shows an error', $stalled['state'] === 'error' && strpos($stalled['message'], 'delete') !== false, json_encode($stalled));

echo "== delete\n";
$id = newJob($jobs, 'delete');
(new DeleteJob($storage, $jobs))->run($id, [['path' => '/site/web/old', 'type' => 'dir'], ['path' => '/site/web/single.txt', 'type' => 'file']], '/site/web');
$st = $jobs->read($id);
check('delete done', ($st['state'] ?? '') === 'done', json_encode($st));
check('all files counted', ($st['files'] ?? 0) === 33, json_encode($st));
check('nothing failed', ($st['failed'] ?? -1) === 0, json_encode($st));
check('folder gone', ! is_dir($web . '/old'));
check('file gone', ! is_file($web . '/single.txt'));
check('other folder kept', is_file($web . '/keep/k.txt'));
check('label for 2 items', ($st['name'] ?? '') === '2 items');
check('paths kept for the list', ($st['paths'] ?? []) === ['/site/web/old', '/site/web/single.txt'], json_encode($st['paths'] ?? null));

echo "== delete refuses the home folder and climbing paths\n";
$id = newJob($jobs, 'delete');
(new DeleteJob($storage, $jobs))->run($id, [['path' => '/', 'type' => 'dir'], ['path' => '/site/../site', 'type' => 'dir'], ['path' => '/site/web/..', 'type' => 'dir']], '/');
check('home and site still there', is_file($web . '/keep/k.txt'));

echo "== delete cancel\n";
mkdir($web . '/big', 0777, true);
for ($i = 0; $i < 20; $i++) {
    file_put_contents($web . '/big/f' . $i, 'x');
}
$id = newJob($jobs, 'delete');
$jobs->requestCancel($id);
(new DeleteJob($storage, $jobs))->run($id, [['path' => '/site/web/big', 'type' => 'dir']], '/site/web');
$st = $jobs->read($id);
check('cancelled', ($st['state'] ?? '') === 'cancelled', json_encode($st));
check('cancelled before deleting', count(glob($web . '/big/*')) === 20);

echo "== delete of a missing item\n";
$id = newJob($jobs, 'delete');
(new DeleteJob($storage, $jobs))->run($id, [['path' => '/site/web/nope.txt', 'type' => 'file']], '/site/web');
$st = $jobs->read($id);
check('missing file counts as deleted', ($st['state'] ?? '') === 'done' && ($st['failed'] ?? 1) === 0, json_encode($st));

echo "== upload save\n";
$data = str_repeat('0123456789abcdef', 300000);   // ~4.8 MB
$id = newJob($jobs, 'upload');
$tmp = $root . '/work/tmp/upload-' . $id . '.data';
file_put_contents($tmp, $data);
(new UploadJob($storage, $jobs))->run($id, $tmp, '/site/web', 'big.bin', false);
$st = $jobs->read($id);
check('upload done', ($st['state'] ?? '') === 'done', json_encode($st));
check('stored intact', @file_get_contents($web . '/big.bin') === $data);
check('server copy removed', ! is_file($tmp));
check('size reported', ($st['size'] ?? 0) === strlen($data));

$id = newJob($jobs, 'upload');
$tmp = $root . '/work/tmp/upload-' . $id . '.data';
file_put_contents($tmp, 'second');
(new UploadJob($storage, $jobs))->run($id, $tmp, '/site/web', 'big.bin', false);
$st = $jobs->read($id);
check('taken name gets a number', ($st['saved_as'] ?? '') === 'big (1).bin', json_encode($st));
check('first file untouched', @file_get_contents($web . '/big.bin') === $data);

$id = newJob($jobs, 'upload');
$tmp = $root . '/work/tmp/upload-' . $id . '.data';
file_put_contents($tmp, 'replaced');
(new UploadJob($storage, $jobs))->run($id, $tmp, '/site/web', 'big.bin', true);
check('overwrite replaces', @file_get_contents($web . '/big.bin') === 'replaced');

$id = newJob($jobs, 'upload');
(new UploadJob($storage, $jobs))->run($id, $root . '/work/tmp/upload-' . $id . '.data', '/site/web', 'gone.bin', false);
$st = $jobs->read($id);
check('missing server copy is an error', ($st['state'] ?? '') === 'error', json_encode($st));

echo "== waiting slot\n";
$held = $jobs->acquire('t-slot', 1);
$beats = 0;
$t = microtime(true);
// the timeout is counted in whole seconds: 2 means at least 1 full second has passed, whenever the clock ticks over
$got = $jobs->acquireWaiting('t-slot', 1, 2, function () use (&$beats) { $beats++; });
check('times out while the slot is held', $got === null && microtime(true) - $t >= 1.0);
check('status kept fresh while waiting', $beats >= 1);
$jobs->release($held);
$got = $jobs->acquireWaiting('t-slot', 1, 5, function () {});
check('gets the slot once free', $got !== null);
$jobs->release($got);

echo "== upload piece size\n";
$mb = 1024 * 1024;
check('configured size kept when PHP allows it', ViewController::chunkSize(8 * $mb, '20M', '21M') === 8 * $mb);
check('lowered to upload_max_filesize', ViewController::chunkSize(8 * $mb, '2M', '8M') === 2 * $mb);
check('lowered below post_max_size', ViewController::chunkSize(8 * $mb, '20M', '4M') === 4 * $mb - 64 * 1024);
check('no limit = configured', ViewController::chunkSize(8 * $mb, '0', '0') === 8 * $mb);
check('ini 1G', ViewController::iniBytes('1G') === 1024 * $mb);
check('ini 512K', ViewController::iniBytes('512K') === 512 * 1024);
check('ini bytes', ViewController::iniBytes('1048576') === $mb);

echo "== upload file names\n";
check('plain name kept', UploadController::cleanName('photo 1.jpg') === 'photo 1.jpg');
check('slashes replaced', UploadController::cleanName('../../etc/passwd') === '..-..-etc-passwd');
check('dot-dot refused', UploadController::cleanName('..') === '');
check('control chars replaced', UploadController::cleanName("a\x01b") === 'a-b');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
