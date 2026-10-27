<?php
// Tests the background unzip job (UnzipJob + UrlJobs helpers) against a local folder standing in for the FTP / SFTP storage.
require __DIR__ . '/../vendor/autoload.php';

use Filegator\Services\Archiver\UnzipJob;
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

$root = sys_get_temp_dir() . '/fg-unzip-test-' . bin2hex(random_bytes(3));
mkdir($root . '/home/site', 0777, true);
mkdir($root . '/work/tmp', 0777, true);
register_shutdown_function(function () use ($root) { rrmdir($root); });

function makeStorage(string $root): Filesystem {
    $fs = new Filesystem();
    $fs->init(['separator' => '/', 'config' => [], 'adapter' => function () use ($root) { return new Local($root . '/home'); }]);
    $fs->setPathPrefix('/');

    return $fs;
}

echo "== storage adapters compile (the tests use a local folder, so the FTP and SFTP classes would otherwise never be loaded)
";
check('FilegatorFtp loads', class_exists(\Filegator\Services\Storage\Adapters\FilegatorFtp::class));
check('FilegatorSftp loads', class_exists(\Filegator\Services\Storage\Adapters\FilegatorSftp::class));

echo "== entry names\n";
foreach ([
    'a/b/c.txt' => 'a/b/c.txt', '/abs/x' => 'abs/x', 'a\\b.txt' => 'a/b.txt', './x//y' => 'x/y', 'dir/' => 'dir',
    '../evil' => null, 'a/../../evil' => null, 'C:/win' => null, '' => null, '/' => null, "bad\x01name" => null,
] as $in => $want) {
    check('cleanEntry(' . json_encode((string) $in) . ')', UnzipJob::cleanEntry((string) $in) === $want, var_export(UnzipJob::cleanEntry((string) $in), true));
}

echo "== a normal zip\n";
$zipPath = $root . '/home/site/pack.zip';
$z = new ZipArchive();
$z->open($zipPath, ZipArchive::CREATE);
$z->addEmptyDir('empty');
$z->addFromString('index.php', '<?php echo 1;');
$z->addFromString('sub/deep/file.txt', 'hello');          // no directory entries for sub/ and sub/deep/
$z->addFromString('other/readme.md', str_repeat('x', 5000));
$z->addFromString('../evil.txt', 'nope');                  // must be skipped, not written
$z->close();

$jobs = new UrlJobs('tester|t', $root . '/work/jobs');
$id = bin2hex(random_bytes(12));
$jobs->write($id, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($id, '/site/pack.zip', '/site/out');
$st = $jobs->read($id);
check('state is done', ($st['state'] ?? '') === 'done', json_encode($st));
check('files in the right places', is_file($root . '/home/site/out/index.php') && file_get_contents($root . '/home/site/out/sub/deep/file.txt') === 'hello' && filesize($root . '/home/site/out/other/readme.md') === 5000);
check('empty folder kept', is_dir($root . '/home/site/out/empty'));
check('"../evil.txt" was not written anywhere', ! is_file($root . '/home/site/evil.txt') && ! is_file($root . '/home/evil.txt') && ! is_file($root . '/home/site/out/evil.txt'));
check('the unsafe name is counted as failed', ($st['failed'] ?? 0) === 1 && ($st['total'] ?? 0) === 5, json_encode($st));
check('temporary copy removed', glob($root . '/work/tmp/*') === []);
check('the archive itself is untouched', is_file($zipPath));

echo "== unzipping into a folder that already has the files: nothing is overwritten\n";
file_put_contents($root . '/home/site/out/index.php', 'MY OWN VERSION');
$idr = bin2hex(random_bytes(12));
$jobs->write($idr, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($idr, '/site/pack.zip', '/site/out');
check('the existing file is untouched', file_get_contents($root . '/home/site/out/index.php') === 'MY OWN VERSION');
check('the new copy gets a numbered name', is_file($root . '/home/site/out/index (1).php') && is_file($root . '/home/site/out/sub/deep/file (1).txt'), implode(', ', array_map('basename', glob($root . '/home/site/out/*'))));

echo "== cancel\n";
$z = new ZipArchive();
$z->open($root . '/home/site/big.zip', ZipArchive::CREATE);
for ($i = 0; $i < 400; $i++) {
    $z->addFromString("f/$i.txt", str_repeat('y', 100));
}
$z->close();
$id2 = bin2hex(random_bytes(12));
$jobs->write($id2, ['state' => 'queued', 'started' => time()]);
$jobs->requestCancel($id2);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($id2, '/site/big.zip', '/site/out2');
check('a cancelled job says so', ($jobs->read($id2)['state'] ?? '') === 'cancelled', json_encode($jobs->read($id2)));
check('and leaves no temporary copy', glob($root . '/work/tmp/*') === []);

echo "== not a zip / missing\n";
file_put_contents($root . '/home/site/fake.zip', 'this is not a zip file');
$id3 = bin2hex(random_bytes(12));
$jobs->write($id3, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($id3, '/site/fake.zip', '/site/out3');
check('a file that is not a zip ends in error', ($jobs->read($id3)['state'] ?? '') === 'error', json_encode($jobs->read($id3)));
$id4 = bin2hex(random_bytes(12));
$jobs->write($id4, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($id4, '/site/nothing.zip', '/site/out4');
check('a missing archive ends in error', ($jobs->read($id4)['state'] ?? '') === 'error', json_encode($jobs->read($id4)));

echo "== tar family\n";
foreach (['a.tar' => 'tar', 'a.TAR.GZ' => 'tar', 'a.tgz' => 'tar', 'a.tar.bz2' => 'tar', 'a.zip' => 'zip', 'a.gz' => null, 'a.rar' => null, 'tar' => null] as $n => $k) {
    check('kind(' . $n . ')', UnzipJob::kind($n) === $k);
}
$src = $root . '/tarsrc';
mkdir($src . '/proj/sub', 0777, true);
file_put_contents($src . '/proj/a.txt', 'alpha');
file_put_contents($src . '/proj/sub/b.txt', str_repeat('b', 3000));
mkdir($src . '/proj/emptydir');
foreach (['tar' => '-cf', 'tar.gz' => '-czf', 'tar.bz2' => '-cjf'] as $ext => $flag) {
    $archive = $root . '/home/site/pack.' . $ext;
    $o = [];
    $cwd = getcwd();
    chdir($src);
    exec('tar ' . $flag . ' ../home/site/pack.' . $ext . ' proj 2>&1', $o, $rc);
    chdir($cwd);
    if ($rc !== 0) { check('tar can make ' . $ext . ' here', false, implode(' ', $o)); continue; }
    $tid = bin2hex(random_bytes(12));
    $jobs->write($tid, ['state' => 'queued', 'started' => time()]);
    $out = '/site/t-' . str_replace('.', '', $ext);
    (new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($tid, '/site/pack.' . $ext, $out);
    $o2 = $root . '/home/site/t-' . str_replace('.', '', $ext) . '/proj';
    $st = $jobs->read($tid);
    check($ext . ': done with the files in place', ($st['state'] ?? '') === 'done' && file_get_contents($o2 . '/a.txt') === 'alpha' && filesize($o2 . '/sub/b.txt') === 3000 && is_dir($o2 . '/emptydir'), json_encode($st));
    check($ext . ': no temporary copy or folder left', glob($root . '/work/tmp/*') === []);
}
$tid = bin2hex(random_bytes(12));
file_put_contents($root . '/home/site/junk.tar', 'not really a tar');
$jobs->write($tid, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($tid, '/site/junk.tar', '/site/t-junk');
check('a broken tar ends in error', ($jobs->read($tid)['state'] ?? '') === 'error', json_encode($jobs->read($tid)));
$tid = bin2hex(random_bytes(12));
$jobs->write($tid, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($tid, '/site/pack.rar', '/site/t-rar');
check('an unsupported type is refused', ($jobs->read($tid)['state'] ?? '') === 'error');

echo "== listing, stalled jobs, forgetting\n";
$all = $jobs->all();
check('all() lists this user\'s jobs, newest first', count($all) >= 4 && array_key_exists($id, $all));
check('another user sees none', (new UrlJobs('someone|else', $root . '/work/jobs'))->all() === []);
$old = ['state' => 'extracting', 'updated' => time() - 600];
check('a silent running job is presented as stopped', UnzipJob::present($old)['state'] === 'error');
check('a fresh one stays running', UnzipJob::present(['state' => 'extracting', 'updated' => time()])['state'] === 'extracting');
check('a finished one is left alone', UnzipJob::present(['state' => 'done', 'updated' => 1])['state'] === 'done');
$jobs->forget($id);
check('forget() removes the job', $jobs->read($id) === null && ! array_key_exists($id, $jobs->all()));
$pid = bin2hex(random_bytes(12));
$jobs->putPayload($pid, ['a' => 1]);
check('payload is handed over once', $jobs->takePayload($pid) === ['a' => 1] && $jobs->takePayload($pid) === null);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
