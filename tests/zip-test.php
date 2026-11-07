<?php
// Tests the background zip job (ZipJob + ZipWriter) against a local folder standing in for the FTP / SFTP storage.
require __DIR__ . '/../vendor/autoload.php';

use Filegator\Services\Archiver\UnzipJob;
use Filegator\Services\Archiver\ZipJob;
use Filegator\Services\Archiver\ZipWriter;
use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Storage\Filesystem;
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
/** name => contents of every file in a zip; null when the zip does not open cleanly or a checksum is wrong */
function zipContents(string $file): ?array {
    $z = new ZipArchive();
    if ($z->open($file, ZipArchive::CHECKCONS) !== true) return null;
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = $z->getNameIndex($i);
        $data = substr($name, -1) === '/' ? '' : $z->getFromIndex($i);   // reading to the end verifies the CRC
        if ($data === false) return null;
        $out[$name] = $data;
    }
    $z->close();

    return $out;
}
function newJob(UrlJobs $jobs): string {
    $id = bin2hex(random_bytes(12));
    $jobs->write($id, ['kind' => 'zip', 'state' => 'queued', 'started' => time()]);

    return $id;
}

$root = sys_get_temp_dir() . '/fg-zip-test-' . bin2hex(random_bytes(3));
register_shutdown_function(function () use ($root) { rrmdir($root); });
$web = $root . '/home/site/web';
mkdir($web . '/sub/deep', 0777, true);
mkdir($web . '/empty', 0777, true);
mkdir($root . '/work/tmp', 0777, true);

$files = [
    'web/index.php' => '<?php echo "hello";',
    'web/sub/deep/a.txt' => 'deep file',
    'web/big.css' => str_repeat("body { color: #333; }\n", 150000),           // ~3 MB, several read chunks, deflated
    'web/photo.jpg' => random_bytes(2500000),                                 // stored as is, several chunks
    'web/zero.txt' => '',
    "web/\u{00fc}n\u{00ef}c\u{00f6}de \u{6587}\u{4ef6}.txt" => 'utf-8 name',
];
foreach ($files as $rel => $data) {
    file_put_contents($root . '/home/site/' . $rel, $data);
}
file_put_contents($root . '/home/site/top.txt', 'top level file');

$jobs = new UrlJobs('tester|t', $root . '/work/jobs');

echo "== names\n";
foreach (['backup' => 'backup.zip', 'site.zip' => 'site.zip', 'A.ZIP' => 'A.ZIP', '../../evil' => 'evil.zip', 'x/y/z.zip' => 'z.zip', '' => null, '..' => null, "bad\x01" => null] as $in => $want) {
    check('cleanName(' . json_encode((string) $in) . ')', ZipJob::cleanName((string) $in) === $want, var_export(ZipJob::cleanName((string) $in), true));
}

echo "== a folder and a file\n";
$items = [['path' => '/site/web', 'type' => 'dir'], ['path' => '/site/top.txt', 'type' => 'file', 'size' => 14]];
$id = newJob($jobs);
(new ZipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($id, $items, '/site', 'backup.zip');
$st = $jobs->read($id);
check('state is done', ($st['state'] ?? '') === 'done', json_encode($st));
check('it is a zip job', ($st['kind'] ?? '') === 'zip');
check('counts: 7 files, none failed', ($st['files'] ?? 0) === 7 && ($st['failed'] ?? -1) === 0, json_encode($st));
check('saved under the name asked for', ($st['saved_as'] ?? '') === 'backup.zip' && is_file($root . '/home/site/backup.zip'));
check('size reported', ($st['size'] ?? 0) === filesize($root . '/home/site/backup.zip'));
$got = zipContents($root . '/home/site/backup.zip');
check('the zip opens cleanly and every checksum is right', $got !== null);
$got = $got ?? [];
$ok = true;
foreach ($files + ['top.txt' => 'top level file'] as $rel => $data) {
    if (! array_key_exists($rel, $got) || $got[$rel] !== $data) { $ok = false; echo "     missing/different: $rel\n"; }
}
check('every file is in it, byte for byte, named from the folder it was picked in', $ok, implode(', ', array_keys($got)));
check('folders are in it, the empty one too', array_key_exists('web/', $got) && array_key_exists('web/empty/', $got) && array_key_exists('web/sub/deep/', $got));
check('nothing named with the parent path', ! array_key_exists('site/web/index.php', $got));
check('the jpg was stored, the css deflated', (function () use ($root) {
    $z = new ZipArchive(); $z->open($root . '/home/site/backup.zip');
    $jpg = $z->statName('web/photo.jpg'); $css = $z->statName('web/big.css'); $z->close();
    return $jpg['comp_method'] === 0 && $css['comp_method'] === 8 && $css['comp_size'] < $css['size'] / 10;
})());
check('temporary zip removed', glob($root . '/work/tmp/*') === []);

echo "== the same name again: the first zip is kept\n";
$id = newJob($jobs);
(new ZipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($id, $items, '/site', 'backup.zip');
$st = $jobs->read($id);
check('saved as "backup (1).zip"', ($st['saved_as'] ?? '') === 'backup (1).zip' && is_file($root . '/home/site/backup (1).zip'), json_encode($st));

echo "== round trip through the unzip job\n";
$uid = bin2hex(random_bytes(12));
$jobs->write($uid, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($uid, '/site/backup.zip', '/site/restore');
$ok = ($jobs->read($uid)['state'] ?? '') === 'done';
foreach ($files as $rel => $data) {
    $ok = $ok && is_file($root . '/home/site/restore/' . $rel) && file_get_contents($root . '/home/site/restore/' . $rel) === $data;
}
check('unzipping gives back the same files', $ok, json_encode($jobs->read($uid)));
check('and the empty folder', is_dir($root . '/home/site/restore/web/empty'));

echo "== zip64 records\n";
$id = newJob($jobs);
(new ZipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->forceZip64()->run($id, $items, '/site', 'z64.zip');
$got = zipContents($root . '/home/site/z64.zip');
check('a zip64 zip opens cleanly with the same contents', $got !== null && ($got['web/big.css'] ?? '') === $files['web/big.css'] && count($got) === 11, json_encode($jobs->read($id)));
$raw = file_get_contents($root . '/home/site/z64.zip');
check('it really has the zip64 end records', strpos($raw, pack('V', 0x06064b50)) !== false && strpos($raw, pack('V', 0x07064b50)) !== false);
$tar = @shell_exec('tar -tf ' . escapeshellarg($root . '/home/site/z64.zip') . ' 2>&1');
if (is_string($tar) && strpos($tar, 'web/big.css') !== false) {
    check('another reader (bsdtar) lists it too', strpos($tar, 'top.txt') !== false);
}

echo "== more than 65,535 entries\n";
$many = $root . '/work/many.zip';
$w = new ZipWriter($many);
for ($i = 0; $i < 70000; $i++) {
    $w->addDir('d' . $i);
}
$m = fopen('php://memory', 'w+b'); fwrite($m, 'last'); rewind($m);
$w->addStream('last.txt', $m, time(), 4);
$w->finish();
$z = new ZipArchive();
check('70,001 entries are all found', $z->open($many, ZipArchive::CHECKCONS) === true && $z->numFiles === 70001 && $z->getFromName('last.txt') === 'last');
$z->close();
@unlink($many);

echo "== an entry that fails half-way is rolled back\n";
$rb = $root . '/work/rb.zip';
$w = new ZipWriter($rb);
$m = fopen('php://memory', 'w+b'); fwrite($m, 'first'); rewind($m);
$w->addStream('first.txt', $m, time(), 5);
$pos = $w->position();
$m = fopen('php://memory', 'w+b'); fwrite($m, str_repeat('z', 3 * 1024 * 1024)); rewind($m);
try {
    $w->addStream('broken.txt', $m, time(), 3 * 1024 * 1024, function (int $b) { if ($b > 1024 * 1024) throw new RuntimeException('connection lost'); });
} catch (RuntimeException $e) {
    $w->rollback($pos);
}
$m = fopen('php://memory', 'w+b'); fwrite($m, 'after'); rewind($m);
$w->addStream('after.txt', $m, time(), 5);
$w->finish();
check('the zip holds the good entries only, and opens cleanly', zipContents($rb) === ['first.txt' => 'first', 'after.txt' => 'after']);

echo "== files that cannot be read\n";
$id = newJob($jobs);
(new ZipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($id, [['path' => '/site/top.txt', 'type' => 'file'], ['path' => '/site/gone.txt', 'type' => 'file']], '/site', 'part.zip');
$st = $jobs->read($id);
check('a missing file is counted as failed, the rest is zipped', ($st['state'] ?? '') === 'done' && ($st['files'] ?? 0) === 1 && ($st['failed'] ?? 0) === 1, json_encode($st));
$id = newJob($jobs);
(new ZipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($id, [['path' => '/site/gone.txt', 'type' => 'file']], '/site', 'none.zip');
$st = $jobs->read($id);
check('nothing readable at all ends in error, no zip saved', ($st['state'] ?? '') === 'error' && ! is_file($root . '/home/site/none.zip'), json_encode($st));

echo "== cancel\n";
$id = newJob($jobs);
$jobs->requestCancel($id);
(new ZipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($id, $items, '/site', 'cancelled.zip');
check('a cancelled zip says so', ($jobs->read($id)['state'] ?? '') === 'cancelled', json_encode($jobs->read($id)));
check('nothing saved, no temporary zip left', ! is_file($root . '/home/site/cancelled.zip') && glob($root . '/work/tmp/*') === []);

echo "== job states\n";
check('zip states count as running', UnzipJob::isActive(['state' => 'listing']) && UnzipJob::isActive(['state' => 'zipping']) && UnzipJob::isActive(['state' => 'uploading']));
$stalled = UnzipJob::present(['kind' => 'zip', 'state' => 'zipping', 'updated' => time() - 600]);
check('a silent zip is presented as stopped, with its own message', $stalled['state'] === 'error' && strpos($stalled['message'], 'zip stopped') !== false);
check('an old job without a kind is an unzip', UnzipJob::present(['state' => 'done'])['kind'] === 'unzip');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
