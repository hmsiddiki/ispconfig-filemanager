<?php
// Tests UrlJobs (slots, status files, isolation) and UrlFetcher progress/cancel.
$base_dir = str_replace('\\', '/', realpath(__DIR__ . '/../backend/Services/Remote')) . '/'; // forward slashes: also used inside code strings
require_once $base_dir . 'UrlFetcher.php';
require_once $base_dir . 'UrlJobs.php';

use Filegator\Services\Remote\UrlFetcher;
use Filegator\Services\Remote\UrlJobs;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $extra === '' ? '' : "  -> $extra") . "\n";
}

$dir = sys_get_temp_dir() . '/fg-jobs-test-' . bin2hex(random_bytes(3));

echo "== job ids\n";
foreach (['aaaaaaaaaaaaaaaaaaaaaaaa' => true, '0123456789abcdef01234567' => true, '../../etc/passwd' => false, 'AAAAAAAAAAAAAAAAAAAAAAAA' => false,
          'aaaaaaaaaaaaaaaaaaaaaaa' => false, 'aaaaaaaaaaaaaaaaaaaaaaaaa' => false, '' => false, "aaaaaaaaaaaaaaaaaaaaaaa\n" => false] as $id => $want) {
    check('id ' . json_encode((string) $id) . ' => ' . ($want ? 'valid' : 'invalid'), UrlJobs::validId((string) $id) === $want);
}
try { (new UrlJobs('u', $dir))->write('../x', []); check('write() refuses a bad id', false); } catch (InvalidArgumentException $e) { check('write() refuses a bad id', true); }

echo "== concurrency slots\n";
$a = new UrlJobs('alice|a@host', $dir);
$b = new UrlJobs('bob|b@host', $dir);
$s1 = $a->acquire('user-' . $a->userKey(), 1);
check('first download gets the slot', $s1 !== null);
check('second download of the same user is refused (limit 1)', $a->acquire('user-' . $a->userKey(), 1) === null);
$bs = $b->acquire('user-' . $b->userKey(), 1);
check('another user is not blocked by alice', $bs !== null);
$a->release($s1);
$s2 = $a->acquire('user-' . $a->userKey(), 1);
check('slot is free again after release', $s2 !== null);
$a->release($s2); $b->release($bs);

$g = [];
for ($i = 0; $i < 3; $i++) { $g[] = $a->acquire('all', 3); }
check('three global slots can be taken', count(array_filter($g)) === 3);
check('a fourth global download is refused', $b->acquire('all', 3) === null);
$a->release($g[0]);
$again = $b->acquire('all', 3);
check('a global slot frees up for someone else', $again !== null);
foreach ($g as $h) { $a->release($h); } $b->release($again);

check('per-user limit 2 allows two, refuses third', (function () use ($a) {
    $x = $a->acquire('user-two', 2); $y = $a->acquire('user-two', 2); $z = $a->acquire('user-two', 2);
    $ok = $x && $y && $z === null; $a->release($x); $a->release($y); return $ok;
})());

echo "== a killed download frees its slot\n";
$child = proc_open([PHP_BINARY, '-r', 'require "' . $base_dir . 'UrlJobs.php"; $j = new Filegator\Services\Remote\UrlJobs("carol|c@host", "' . $dir . '"); $h = $j->acquire("user-" . $j->userKey(), 1); echo $h ? "held\n" : "failed\n"; sleep(30);'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$line = trim((string) fgets($pipes[1]));
check('child process holds the slot', $line === 'held', $line);
$c = new UrlJobs('carol|c@host', $dir);
check('slot is busy while the child lives', $c->acquire('user-' . $c->userKey(), 1) === null);
proc_terminate($child); proc_close($child);
usleep(300000);
$after = $c->acquire('user-' . $c->userKey(), 1);
check('slot is free after the child is killed (no stale lock)', $after !== null);
$c->release($after);

echo "== status files and isolation\n";
$id = bin2hex(random_bytes(12));
$a->write($id, ['state' => 'downloading', 'bytes' => 5, 'total' => 10]);
$r = $a->read($id);
check('write then read round trip', ($r['state'] ?? '') === 'downloading' && $r['bytes'] === 5 && $r['total'] === 10 && isset($r['updated']));
check("another user cannot read alice's job", $b->read($id) === null);
check('unknown id reads as null', $a->read(bin2hex(random_bytes(12))) === null);
check('bad id reads as null', $a->read('../../x') === null);
check('cancel flag starts unset', $a->cancelRequested($id) === false);
$b->requestCancel($id);
check("bob cannot cancel alice's job", $a->cancelRequested($id) === false);
$a->requestCancel($id);
check('alice can cancel her own job', $a->cancelRequested($id) === true);
$old = glob($dir . '/job-*'); foreach ($old as $f) { touch($f, time() - 7200); }
$a->cleanup();
check('cleanup removes old status files', count(glob($dir . '/job-*') ?: []) === 0);
check('cleanup keeps lock files', count(glob($dir . '/slot-*') ?: []) > 0);

echo "== progress + cancel on a real download (local test server)\n";
$root = sys_get_temp_dir() . '/fgurl-www2'; @mkdir($root);
file_put_contents("$root/big.bin", str_repeat('x', 6 * 1024 * 1024));
file_put_contents("$root/router.php", "<?php return false;");
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8097', '-t', $root, "$root/router.php"], [1 => ['file', "$root/srv.log", 'w'], 2 => ['file', "$root/srv.log", 'a']], $pipes2);
usleep(900000);
$loc = new UrlFetcher(['allow_private' => true, 'allowed_ports' => [8097], 'max_size' => 20 * 1024 * 1024, 'timeout' => 30]);

$seen = [];
$res = $loc->fetch('http://127.0.0.1:8097/big.bin', function (int $bytes, int $total) use (&$seen) { $seen[] = [$bytes, $total]; return true; });
$last = end($seen);
check('progress callback is called', count($seen) >= 2, 'calls=' . count($seen));
check('progress is non-decreasing', (function () use ($seen) { $p = -1; foreach ($seen as [$b]) { if ($b < $p) return false; $p = $b; } return true; })());
check('progress reports the total size', $last && $last[1] === 6 * 1024 * 1024, json_encode($last));
check('final progress reaches the full size', $last && $last[0] === 6 * 1024 * 1024 && $res['size'] === 6 * 1024 * 1024, json_encode($last));
@unlink($res['path']);

$calls = 0; $msg = null; $before = glob(sys_get_temp_dir() . '/fgurl*') ?: [];
try { $loc->fetch('http://127.0.0.1:8097/big.bin', function () use (&$calls) { $calls++; return $calls < 2; }); } catch (InvalidArgumentException $e) { $msg = $e->getMessage(); }
check('returning false cancels the download', $msg === 'Download cancelled', (string) $msg);
$leftover = array_filter(array_diff(glob(sys_get_temp_dir() . '/fgurl*') ?: [], $before), 'is_file');
check('cancel leaves no temp file behind', count($leftover) === 0, implode(',', $leftover));

proc_terminate($proc); proc_close($proc);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
