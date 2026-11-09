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
use Filegator\Services\Storage\TransferJob;
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

echo "== a server that refuses some names (as Pure-FTPd refuses names holding \"\\\": the delete and the existence question alike)\n";
$refusing = function (string $word) use ($root) {
    $fs = new Filesystem();
    $fs->init(['separator' => '/', 'config' => [], 'adapter' => function () use ($root, $word) {
        return new class($root . '/home', $word) extends Local {
            private $word;
            public function __construct($root, $word) { parent::__construct($root); $this->word = $word; }
            public function deleteNow($path): bool { return strpos($path, $this->word) === false && @unlink($this->applyPathPrefix($path)); }
            public function has($path) { return strpos($path, $this->word) === false && parent::has($path); }
        };
    }]);
    $fs->setPathPrefix('/');

    return $fs;
};
mkdir($web . '/rf/sub', 0777, true);
file_put_contents($web . '/rf/refused-a.txt', 'x');
file_put_contents($web . '/rf/fine.txt', 'x');
file_put_contents($web . '/rf/sub/refused-b.txt', 'x');
file_put_contents($web . '/rf/sub/fine2.txt', 'x');
$id = newJob($jobs, 'delete');
(new DeleteJob($refusing('refused'), $jobs))->run($id, [['path' => '/site/web/rf/refused-a.txt', 'type' => 'file'], ['path' => '/site/web/rf/fine.txt', 'type' => 'file'],
    ['path' => '/site/web/rf/sub', 'type' => 'dir'], ['path' => '/site/web/rf/never-there.txt', 'type' => 'file']], '/site/web/rf');
$st = $jobs->read($id);
check('a refused picked file is reported as failed, not deleted', is_file($web . '/rf/refused-a.txt') && in_array('site/web/rf/refused-a.txt', $st['errors'] ?? [], true), json_encode($st));
// (the folder step then falls back to the full deleteDir(); this local stand-in removes the folder there, an FTP server that refuses the
// name refuses that too and the folder stays)
check('a refused file inside a picked folder is reported as failed', in_array('site/web/rf/sub/refused-b.txt', $st['errors'] ?? [], true), json_encode($st));
check('the others are deleted, and a picked file that was never there still counts as gone', ! is_file($web . '/rf/fine.txt') && ! is_file($web . '/rf/sub/fine2.txt')
    && ($st['files'] ?? 0) === 3 && ($st['failed'] ?? 0) >= 2 && ($st['state'] ?? '') === 'done', json_encode($st));
check('no "\\" in those names: no note about it', ! isset($st['note']), json_encode($st));
$id = newJob($jobs, 'delete');
(new DeleteJob($refusing('refused'), $jobs))->run($id, [['path' => '/site/web/rf/refused-a.txt', 'type' => 'file']], '/site/web/rf');
$st = $jobs->read($id);
check('when nothing could be deleted the job says so', ($st['state'] ?? '') === 'error' && strpos((string) ($st['message'] ?? ''), 'Nothing could be deleted') !== false, json_encode($st));

echo "== paths: \"\\\" is part of a name, \"..\" is never allowed\n";
foreach ([
    'a/b\\c.txt' => 'a/b\\c.txt', '/web//admin\\x.php/' => 'web/admin\\x.php', './a/./b' => 'a/b', 'a\\' => 'a\\', '' => '',
] as $in => $want) {
    check('cleanPath(' . json_encode((string) $in) . ')', Filesystem::cleanPath((string) $in) === $want, var_export(Filesystem::cleanPath((string) $in), true));
}
foreach (['../x', 'a/../b', 'a\\..\\b', '..\\x', "bad\x01"] as $bad) {
    $refused = false;
    try { Filesystem::cleanPath($bad); } catch (\LogicException $e) { $refused = true; }
    check('cleanPath refuses ' . json_encode($bad), $refused);
}
check('a job path keeps "\\" and refuses ".."', TransferJob::clean('/web/admin\\x.php') === '/web/admin\\x.php' && TransferJob::clean('/web/a\\..\\..\\etc') === null && TransferJob::clean('/web/../etc') === null);

if (DIRECTORY_SEPARATOR === '/') {
    // only where "\" can be part of a file name (Linux: the servers this runs on; on Windows it is a folder separator)
    echo "== files with \"\\\" in their name (left by a zip made on Windows)\n";
    // Flysystem's Local adapter turns "\" into "/" in the paths it lists (Local::getFilePath); the FTP and SFTP adapters, which the file
    // manager really uses, keep names as the server sends them. This stand-in lists like they do.
    $bs = new Filesystem();
    $bs->init(['separator' => '/', 'config' => [], 'adapter' => function () use ($root) {
        return new class($root . '/home') extends Local {
            protected function getFilePath(\SplFileInfo $file)
            {
                return trim($this->removePathPrefix($file->getPathname()), '/');
            }
        };
    }]);
    $bs->setPathPrefix('/');
    mkdir($web . '/bs', 0777, true);
    foreach (['admin\\a.php', 'admin\\b.php', 'content\\themes\\', 'keep.txt'] as $n) {
        file_put_contents($web . '/bs/' . $n, 'x');
    }
    $id = newJob($jobs, 'delete');
    (new DeleteJob($bs, $jobs))->run($id, [['path' => '/site/web/bs/admin\\a.php', 'type' => 'file'], ['path' => '/site/web/bs/content\\themes\\', 'type' => 'file']], '/site/web/bs');
    $st = $jobs->read($id);
    check('delete really removes them', ! file_exists($web . '/bs/admin\\a.php') && ! file_exists($web . '/bs/content\\themes\\') && ($st['files'] ?? 0) === 2, json_encode($st));
    check('and nothing else', file_exists($web . '/bs/admin\\b.php') && file_exists($web . '/bs/keep.txt'));
    check('rename keeps the "\\"', $bs->rename('/site/web/bs', 'admin\\b.php', 'renamed\\b.php') && file_exists($web . '/bs/renamed\\b.php') && ! file_exists($web . '/bs/admin\\b.php'));
    mkdir($web . '/bs/to', 0777, true);
    $id = newJob($jobs, 'move');
    (new TransferJob($bs, $jobs))->run($id, 'move', [['path' => '/site/web/bs/renamed\\b.php', 'type' => 'file']], '/site/web/bs/to', '/site/web/bs');
    check('move keeps the "\\"', file_exists($web . '/bs/to/renamed\\b.php') && ! file_exists($web . '/bs/renamed\\b.php'), json_encode($jobs->read($id)));
    mkdir($web . '/bs/keep2', 0777, true);
    $id = newJob($jobs, 'copy');
    (new TransferJob($bs, $jobs))->run($id, 'copy', [['path' => '/site/web/bs/to', 'type' => 'dir']], '/site/web/bs/keep2', '/site/web/bs');
    check('copy of a folder holding one keeps the "\\"', file_exists($web . '/bs/keep2/to/renamed\\b.php'), json_encode($jobs->read($id)));
    check('a file with "\\" is seen as existing', $bs->fileExists('/site/web/bs/keep.txt') && $bs->fileExists('/site/web/bs/to/renamed\\b.php') && ! $bs->fileExists('/site/web/bs/to/renamed/b.php'));
    // a server that refuses every name with "\" (Pure-FTPd): the delete fails, is reported, and the result explains why
    file_put_contents($web . '/bs/admin\\c.php', 'x');
    $id = newJob($jobs, 'delete');
    (new DeleteJob($refusing('\\'), $jobs))->run($id, [['path' => '/site/web/bs/admin\\c.php', 'type' => 'file'], ['path' => '/site/web/bs/keep.txt', 'type' => 'file']], '/site/web/bs');
    $st = $jobs->read($id);
    check('a refused "\\" name is failed, kept, and the note says why', file_exists($web . '/bs/admin\\c.php') && ! file_exists($web . '/bs/keep.txt')
        && ($st['failed'] ?? 0) === 1 && ($st['note'] ?? '') === DeleteJob::NOTE_BACKSLASH, json_encode($st));
} else {
    echo "== files with \"\\\" in their name: skipped on Windows (there \"\\\" is a folder separator); CI runs them on Linux\n";
}

echo "== delete leaves a folder that still holds something the listing did not show to the full deleteDir()\n";
check('removeEmptyDir on a non-empty folder still removes it (fallback)', (function () use ($storage, $web) {
    mkdir($web . '/notempty/inner', 0777, true);
    file_put_contents($web . '/notempty/inner/x', 'x');
    return $storage->removeEmptyDir('/site/web/notempty') && ! is_dir($web . '/notempty');
})());
check('deleteFileNow: deleted, and a missing file counts as gone', (function () use ($storage, $web) {
    file_put_contents($web . '/gone.txt', 'x');
    return $storage->deleteFileNow('/site/web/gone.txt') && ! is_file($web . '/gone.txt') && $storage->deleteFileNow('/site/web/gone.txt');
})());
check('typeOf: file, folder, nothing', $storage->typeOf('/site/web/keep/k.txt') === 'file' && $storage->typeOf('/site/web/keep') === 'dir' && $storage->typeOf('/site/web/nope') === null);

echo "== copy\n";
mkdir($web . '/src/sub/deeper', 0777, true);
mkdir($web . '/src/emptydir', 0777, true);
mkdir($web . '/dst', 0777, true);
file_put_contents($web . '/src/a.txt', 'A');
file_put_contents($web . '/src/sub/b.txt', 'B');
file_put_contents($web . '/src/sub/deeper/c.bin', str_repeat("\x00\xff", 5000));
file_put_contents($web . '/loose.txt', 'loose');
$id = newJob($jobs, 'copy');
(new TransferJob($storage, $jobs))->run($id, 'copy', [['path' => '/site/web/src', 'type' => 'dir'], ['path' => '/site/web/loose.txt', 'type' => 'file']], '/site/web/dst', '/site/web');
$st = $jobs->read($id);
check('copy done', ($st['state'] ?? '') === 'done' && ($st['kind'] ?? '') === 'copy', json_encode($st));
check('all files copied', ($st['files'] ?? 0) === 4 && ($st['failed'] ?? -1) === 0, json_encode($st));
check('tree copied with its contents', file_get_contents($web . '/dst/src/a.txt') === 'A' && file_get_contents($web . '/dst/src/sub/b.txt') === 'B'
    && file_get_contents($web . '/dst/src/sub/deeper/c.bin') === str_repeat("\x00\xff", 5000));
check('empty folder copied too', is_dir($web . '/dst/src/emptydir'));
check('single file copied', file_get_contents($web . '/dst/loose.txt') === 'loose');
check('sources untouched', is_file($web . '/src/sub/deeper/c.bin') && is_file($web . '/loose.txt'));
check('bytes counted', ($st['bytes'] ?? 0) === 1 + 1 + 10000 + 5, json_encode($st));

$id = newJob($jobs, 'copy');
(new TransferJob($storage, $jobs))->run($id, 'copy', [['path' => '/site/web/src', 'type' => 'dir'], ['path' => '/site/web/loose.txt', 'type' => 'file']], '/site/web/dst', '/site/web');
$st = $jobs->read($id);
check('copying again: the taken names get a number, nothing is overwritten', is_dir($web . '/dst/src (1)') && is_file($web . '/dst/src (1)/sub/deeper/c.bin')
    && file_get_contents($web . '/dst/loose (1).txt') === 'loose' && file_get_contents($web . '/dst/src/a.txt') === 'A',
    implode(', ', array_map('basename', glob($web . '/dst/*'))));
$id = newJob($jobs, 'copy');
(new TransferJob($storage, $jobs))->run($id, 'copy', [['path' => '/site/web/loose.txt', 'type' => 'file']], '/site/web', '/site/web');
check('a copy into the same folder gets a numbered name', file_get_contents($web . '/loose (1).txt') === 'loose');

$id = newJob($jobs, 'copy');
(new TransferJob($storage, $jobs))->run($id, 'copy', [['path' => '/site/web/src', 'type' => 'dir']], '/site/web/src/sub', '/site/web');
$st = $jobs->read($id);
check('a folder is never copied into itself', ($st['state'] ?? '') === 'error' && strpos((string) ($st['message'] ?? ''), 'into itself') !== false && ! is_dir($web . '/src/sub/src'), json_encode($st));

$id = newJob($jobs, 'copy');
(new TransferJob($storage, $jobs))->run($id, 'copy', [['path' => '/site/web/../etc', 'type' => 'dir']], '/site/web/dst', '/site/web');
$st = $jobs->read($id);
check('climbing paths are refused', ($st['state'] ?? '') !== 'done' || ($st['files'] ?? 0) === 0, json_encode($st));

$id = newJob($jobs, 'copy');
$jobs->requestCancel($id);
(new TransferJob($storage, $jobs))->run($id, 'copy', [['path' => '/site/web/src', 'type' => 'dir']], '/site/web/keep', '/site/web');
$st = $jobs->read($id);
check('copy cancel', ($st['state'] ?? '') === 'cancelled' && ! is_file($web . '/keep/src/a.txt'), json_encode($st));

echo "== move\n";
mkdir($web . '/mv/inner', 0777, true);
file_put_contents($web . '/mv/inner/m.txt', 'M');
file_put_contents($web . '/mvfile.txt', 'F');
file_put_contents($web . '/dst/mvfile.txt', 'already there');
$id = newJob($jobs, 'move');
(new TransferJob($storage, $jobs))->run($id, 'move', [['path' => '/site/web/mv', 'type' => 'dir'], ['path' => '/site/web/mvfile.txt', 'type' => 'file']], '/site/web/dst', '/site/web');
$st = $jobs->read($id);
check('move done', ($st['state'] ?? '') === 'done' && ($st['kind'] ?? '') === 'move' && ($st['files'] ?? 0) === 2, json_encode($st));
check('folder moved with its contents', ! is_dir($web . '/mv') && file_get_contents($web . '/dst/mv/inner/m.txt') === 'M');
check('a taken name gets a number, the existing file is kept', file_get_contents($web . '/dst/mvfile.txt') === 'already there' && file_get_contents($web . '/dst/mvfile (1).txt') === 'F' && ! is_file($web . '/mvfile.txt'));
$id = newJob($jobs, 'move');
(new TransferJob($storage, $jobs))->run($id, 'move', [['path' => '/site/web/dst/mv', 'type' => 'dir']], '/site/web/dst', '/site/web/dst');
$st = $jobs->read($id);
check('moving into the folder it is already in changes nothing', ($st['state'] ?? '') === 'done' && is_dir($web . '/dst/mv') && ! is_dir($web . '/dst/mv (1)'), json_encode($st));
$id = newJob($jobs, 'move');
(new TransferJob($storage, $jobs))->run($id, 'move', [['path' => '/site/web/dst', 'type' => 'dir']], '/site/web/dst/mv', '/site/web');
$st = $jobs->read($id);
check('a folder is never moved into itself', ($st['state'] ?? '') === 'error' && is_dir($web . '/dst/mv'), json_encode($st));
check('move and copy are job kinds, their states count as running', UnzipJob::kindOf(['kind' => 'copy']) === 'copy' && UnzipJob::kindOf(['kind' => 'move']) === 'move'
    && UnzipJob::isActive(['state' => 'transferring']) && UnzipJob::isActive(['state' => 'moving']));
check('a stalled copy says so', strpos(UnzipJob::present(['kind' => 'copy', 'state' => 'transferring', 'updated' => 1])['message'] ?? '', 'copy stopped') !== false);

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
