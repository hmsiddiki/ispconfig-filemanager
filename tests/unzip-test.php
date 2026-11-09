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
check('existing folders are used, never copied to "folder (1)"', array_filter(glob($root . '/home/site/out/* (*'), 'is_dir') === [],
    implode(', ', array_map('basename', glob($root . '/home/site/out/*'))));
check('nor nested ones', array_filter(glob($root . '/home/site/out/sub/*'), 'is_dir') === [$root . '/home/site/out/sub/deep'], implode(', ', array_map('basename', glob($root . '/home/site/out/sub/*'))));

echo "== the same archive again, replacing existing files\n";
file_put_contents($root . '/home/site/out/index.php', 'MY OWN VERSION');
file_put_contents($root . '/home/site/out/sub/deep/file.txt', 'old');
$before = count(glob($root . '/home/site/out/*'));
$ido = bin2hex(random_bytes(12));
$jobs->write($ido, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->onConflict('overwrite')->run($ido, '/site/pack.zip', '/site/out');
$st = $jobs->read($ido);
check('done', ($st['state'] ?? '') === 'done' && ($st['conflict'] ?? '') === 'overwrite', json_encode($st));
check('existing files are replaced by the archive\'s', file_get_contents($root . '/home/site/out/index.php') === '<?php echo 1;' && file_get_contents($root . '/home/site/out/sub/deep/file.txt') === 'hello');
check('no new numbered copies', count(glob($root . '/home/site/out/*')) === $before && ! is_file($root . '/home/site/out/index (2).php'), implode(', ', array_map('basename', glob($root . '/home/site/out/*'))));
$odd = new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp');
$odd->onConflict('delete-everything');
$prop = new \ReflectionProperty(UnzipJob::class, 'conflict');
$prop->setAccessible(true);
check('an unknown mode falls back to keep both', $prop->getValue($odd) === 'rename');

echo "== a plugin folder unzipped over its live copy (.git and empty folders already there)\n";
@mkdir($root . '/home/site/plugins/qc/.git/hooks', 0777, true);
@mkdir($root . '/home/site/plugins/qc/empty', 0777, true);
file_put_contents($root . '/home/site/plugins/qc/.git/HEAD', 'old head');
file_put_contents($root . '/home/site/plugins/qc/.git/hooks/keep.sample', 'mine');
$pz = $root . '/home/site/qc.zip';
$z = new ZipArchive();
$z->open($pz, ZipArchive::CREATE);
$z->addEmptyDir('qc/');
$z->addEmptyDir('qc/.git/');
$z->addEmptyDir('qc/.git/hooks/');
$z->addEmptyDir('qc/empty/');
$z->addFromString('qc/.git/HEAD', 'ref: refs/heads/main');
$z->addFromString('qc/.git/hooks/pre-applypatch.sample', 'hook');
$z->addFromString('qc/plugin.php', '<?php // v2');
$z->close();
foreach (['rename', 'overwrite'] as $mode) {
    $idp = bin2hex(random_bytes(12));
    $jobs->write($idp, ['state' => 'queued', 'started' => time()]);
    (new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->onConflict($mode)->run($idp, '/site/qc.zip', '/site/plugins');
    $st = $jobs->read($idp);
    $dirs = array_map('basename', array_filter(glob($root . '/home/site/plugins/qc/{,.}*', GLOB_BRACE), function ($p) { return is_dir($p) && ! in_array(basename($p), ['.', '..'], true); }));
    sort($dirs);
    check("$mode: done, nothing failed", ($st['state'] ?? '') === 'done' && ($st['failed'] ?? 1) === 0, json_encode($st));
    check("$mode: only .git and empty, no \"(1)\" folders", $dirs === ['.git', 'empty'], implode(', ', $dirs));
    check("$mode: files inside the existing .git land in it", is_file($root . '/home/site/plugins/qc/.git/hooks/pre-applypatch.sample') && is_file($root . '/home/site/plugins/qc/.git/hooks/keep.sample'));
}
check('overwrite replaced .git/HEAD', file_get_contents($root . '/home/site/plugins/qc/.git/HEAD') === 'ref: refs/heads/main');
check('keep both: the extension-less HEAD got "HEAD (1)" next to it, inside .git', is_file($root . '/home/site/plugins/qc/.git/HEAD (1)'),
    implode(', ', array_map('basename', glob($root . '/home/site/plugins/qc/.git/*'))));

echo "== a zip made on Windows (\"\\\" between folders, folder entries ending in \"\\\"), folder attributes, links\n";
$wz = $root . '/home/site/win.zip';
$z = new ZipArchive();
$z->open($wz, ZipArchive::CREATE);
$z->addFromString('content\\', '');                               // Compress-Archive (Windows PowerShell 5.1) writes these
$z->addFromString('content\\themes\\', '');
$z->addFromString('content\\themes\\default\\', '');
$z->addFromString('content\\themes\\default\\style.css', 'body{}');
$z->addFromString('content\\plugins\\', '');                      // an empty folder
$z->addFromString('index.php', '<?php // root');
$z->addFromString('dosdir', '');                                  // a folder known only by its MS-DOS directory bit
$z->setExternalAttributesName('dosdir', ZipArchive::OPSYS_DOS, 0x10);
$z->addFromString('unixdir', '');                                 // a folder known only by its Unix file type
$z->setExternalAttributesName('unixdir', ZipArchive::OPSYS_UNIX, 040755 << 16);
$z->addFromString('dosfile.txt', 'not a folder');                // DOS directory bit on a file with content: still a file
$z->setExternalAttributesName('dosfile.txt', ZipArchive::OPSYS_DOS, 0x10);
$z->addFromString('evil-link', '/etc/passwd');                    // a symbolic link: must never be made
$z->setExternalAttributesName('evil-link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
$z->close();
$idw = bin2hex(random_bytes(12));
$jobs->write($idw, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($idw, '/site/win.zip', '/site/win');
$st = $jobs->read($idw);
$w = $root . '/home/site/win';
check('folders ending in "\\" are folders, not empty files', is_dir("$w/content") && is_dir("$w/content/themes") && is_dir("$w/content/themes/default") && is_dir("$w/content/plugins"),
    implode(', ', array_map(function ($p) { return basename($p) . (is_dir($p) ? '/' : ''); }, glob("$w/content/*") ?: [])));
check('the file inside lands in its folder', is_file("$w/content/themes/default/style.css") && file_get_contents("$w/content/themes/default/style.css") === 'body{}');
check('nothing lands one level up', ! file_exists("$w/content/default") && ! file_exists("$w/default") && ! file_exists("$w/style.css"));
check('no file names with "\\" in them', array_filter(glob("$w/*") ?: [], function ($p) { return strpos(basename($p), '\\') !== false; }) === []);
check('a folder known only by its DOS or Unix attribute is a folder', is_dir("$w/dosdir") && is_dir("$w/unixdir"));
check('a file that has the DOS bit but content stays a file', is_file("$w/dosfile.txt") && file_get_contents("$w/dosfile.txt") === 'not a folder');
check('the link is not made, as a link or as a file', ! file_exists("$w/evil-link") && ! is_link("$w/evil-link"));
check('the link is reported', ($st['failed'] ?? 0) === 1 && strpos(json_encode($st['errors'] ?? $st), 'skipped a link') !== false, json_encode($st));
check('the rest is done', ($st['state'] ?? '') === 'done' && is_file("$w/index.php"), json_encode($st));

echo "== a file where the archive needs a folder\n";
@mkdir($root . '/home/site/blocked', 0777, true);
file_put_contents($root . '/home/site/blocked/content', 'I am a file');
$bz = $root . '/home/site/blocked.zip';
$z = new ZipArchive();
$z->open($bz, ZipArchive::CREATE);
$z->addFromString('content/themes/x.php', '<?php // x');
$z->addFromString('other.txt', 'fine');
$z->close();
$idb = bin2hex(random_bytes(12));
$jobs->write($idb, ['state' => 'queued', 'started' => time()]);
(new UnzipJob(makeStorage($root), $jobs, $root . '/work/tmp'))->run($idb, '/site/blocked.zip', '/site/blocked');
$st = $jobs->read($idb);
$b = $root . '/home/site/blocked';
check('the file in the way is left as it is', is_file("$b/content") && file_get_contents("$b/content") === 'I am a file');
check('the entry is not written anywhere else', ! file_exists("$b/themes") && ! file_exists("$b/x.php") && ! file_exists("$b/themes/x.php"));
check('it is reported as a file in the way', ($st['failed'] ?? 0) === 1 && strpos(json_encode($st['errors'] ?? $st), 'in the way') !== false, json_encode($st));
check('the other entries are unpacked', is_file("$b/other.txt"));

echo "== numbering a taken name changes only the name, never the folders\n";
$fsn = makeStorage($root);
foreach ([
    'photo.jpg' => 'photo (1).jpg', 'photo (1).jpg' => 'photo (2).jpg', 'photo (9).jpg' => 'photo (10).jpg', 'HEAD' => 'HEAD (1)',
    '.htaccess' => '.htaccess (1)', 'archive.tar.gz' => 'archive.tar (1).gz', 'folder' => 'folder (1)',
    '/site/qc/.git/HEAD' => '/site/qc/.git/HEAD (1)', '/site/jquery.ui/LICENSE' => '/site/jquery.ui/LICENSE (1)', '/site/a.b/c.txt' => '/site/a.b/c (1).txt',
    '/site/.git' => '/site/.git (1)',
] as $in => $want) {
    check('upcountName(' . $in . ')', $fsn->upcountName($in) === $want, $fsn->upcountName($in));
}

echo "== folders: new folder numbers a taken name, ensureDirectory never does\n";
$fs = makeStorage($root);
@mkdir($root . '/home/site/nf/taken', 0777, true);   // an existing EMPTY folder: the old recursive listing saw nothing and reused it
$fs->createDir('/site/nf', 'taken');
check('createDir: a taken (even empty) name gets a number', is_dir($root . '/home/site/nf/taken (1)'), implode(', ', array_map('basename', glob($root . '/home/site/nf/*'))));
check('ensureDirectory: an existing folder is used as it is', $fs->ensureDirectory('/site/nf/taken') && ! is_dir($root . '/home/site/nf/taken (2)'));
check('ensureDirectory: missing parents are created', $fs->ensureDirectory('/site/nf/a/b/c') && is_dir($root . '/home/site/nf/a/b/c'));

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
