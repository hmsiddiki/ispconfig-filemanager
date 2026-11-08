<?php
// Tests the chunked upload endpoint: pieces written in place at their offsets (in any order, also twice), exactly one request finishing
// the file, bad pieces refused, small files stored at once and large ones handed to the background save. The storage is a local folder.
require __DIR__ . '/../vendor/autoload.php';

use Filegator\Config\Config;
use Filegator\Controllers\UploadController;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\User;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Session\SessionStorageInterface;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Tmpfs\Adapters\Tmpfs;
use League\Flysystem\Adapter\Local;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

class TestSession implements SessionStorageInterface {
    public $saved = 0;
    public function set(string $key, $data) {}
    public function get(string $key, $default = null) { return $key === 'remote_connection' || strpos($key, 'remote') !== false ? 'blob' : $default; }
    public function invalidate() {}
    public function save() { $this->saved++; }
    public function migrate($destroy = false, $lifetime = null): bool { return true; }
}
class TestLogger implements LoggerInterface { public function log(string $message, int $level = 0) {} }

/** The controller with the test's storage, and no worker process: the background save runs in the request (as where proc_open is off). */
class TestUploadController extends UploadController {
    public $work;
    public function setUp(Config $config, SessionStorageInterface $session, Filesystem $storage, Tmpfs $tmpfs, string $work): void {
        $this->config = $config; $this->session = $session; $this->storage = $storage; $this->tmpfs = $tmpfs; $this->work = $work;
        $this->logger = new TestLogger();
        $user = new User(); $user->setUsername('ftp123'); $user->setName('site.test'); $user->setHomedir('/');
        $this->user = $user;
        $this->jobs = new UrlJobs($this->userId(), $work . '/jobs');
    }
    protected function spawn(string $worker, string $payload_file): bool { return false; }
    protected function workDir(): string { return $this->work; }
    protected function loginBlob(): ?string { return 'blob'; }
    public function jobs(): UrlJobs { return $this->jobs; }
}

$root = sys_get_temp_dir() . '/fg-upload-test-' . bin2hex(random_bytes(3));
register_shutdown_function(function () use ($root) { rrmdir($root); });
mkdir($root . '/home/web', 0777, true);
mkdir($root . '/tmpfs', 0777, true);
mkdir($root . '/work/tmp', 0777, true);
mkdir($root . '/pieces', 0777, true);

$storage = new Filesystem();
$storage->init(['separator' => '/', 'config' => [], 'adapter' => function () use ($root) { return new Local($root . '/home'); }]);
$storage->setPathPrefix('/');
$tmpfs = new Tmpfs();
$tmpfs->init(['path' => $root . '/tmpfs/', 'gc_probability_perc' => 0, 'gc_older_than' => 3600]);
$config = new Config(['overwrite_on_upload' => false, 'frontend_config' => ['upload_max_size' => 200 * 1024 * 1024]]);
$session = new TestSession();

$ctl = (new ReflectionClass(TestUploadController::class))->newInstanceWithoutConstructor();
$ctl->setUp($config, $session, $storage, $tmpfs, $root . '/work');

/** Send piece $n of $data as resumable.js does; returns [status, data]. */
function piece(TestUploadController $ctl, string $root, string $data, string $name, int $chunk, int $n, string $ident, ?string $override = null): array {
    $total = max(1, (int) ceil(strlen($data) / $chunk));
    $body = $override ?? substr($data, ($n - 1) * $chunk, $chunk);
    $file = $root . '/pieces/p' . bin2hex(random_bytes(4));
    file_put_contents($file, $body);
    $post = ['resumableFilename' => $name, 'resumableRelativePath' => '/web', 'resumableChunkNumber' => $n, 'resumableChunkSize' => $chunk,
        'resumableTotalChunks' => $total, 'resumableTotalSize' => strlen($data), 'resumableIdentifier' => $ident];
    $request = new Request([], $post, [], [], ['file' => new UploadedFile($file, 'blob', null, null, true)]);
    $response = new Response();
    $ctl->upload($request, $response);
    $out = json_decode((string) $response->getContent(), true);

    return [$response->getStatusCode(), $out['data'] ?? null];
}

echo "== small file, pieces out of order and one sent twice\n";
$chunk = 64 * 1024;
$data = random_bytes(5 * $chunk + 1234);   // 6 pieces, the last one short
$answers = [];
foreach ([3, 1, 6, 2, 2, 5] as $n) {
    $answers[] = piece($ctl, $root, $data, 'small.bin', $chunk, $n, '1-small')[1];
}
check('not finished before the last piece', ! in_array('Stored', $answers, true), json_encode($answers));
$last = piece($ctl, $root, $data, 'small.bin', $chunk, 4, '1-small');
check('last piece stores the file', $last[1] === 'Stored', json_encode($last));
check('file intact', @file_get_contents($root . '/home/web/small.bin') === $data);
check('scratch files cleaned up', glob($root . '/tmpfs/multipart_*') === [], implode(', ', glob($root . '/tmpfs/*')));
check('session released every time', $session->saved >= 7);
$again = piece($ctl, $root, $data, 'small.bin', $chunk, 4, '1-small');
check('a late duplicate does not store it again', $again[1] === 'Uploaded' && ! is_file($root . '/home/web/small (1).bin'), json_encode($again));
array_map('unlink', glob($root . '/tmpfs/multipart_*'));

echo "== bad pieces\n";
$r = piece($ctl, $root, $data, 'bad.bin', $chunk, 7, '1-bad');
check('piece number past the end refused', $r[0] === 422);
$r = piece($ctl, $root, $data, 'bad.bin', $chunk, 6, '1-bad', str_repeat('x', $chunk));
check('piece that would grow the file refused', $r[0] === 422);
$r = piece($ctl, $root, $data, 'bad.bin', $chunk, 0, '1-bad');
check('piece 0 refused', $r[0] === 422);
$r = piece($ctl, $root, str_repeat('x', 300 * 1024 * 1024 / 1024), '..', $chunk, 1, '1-dots');
check('name ".." refused', $r[0] === 422);

echo "== large file goes to the background save\n";
$chunk = 4 * 1024 * 1024;
$big = str_repeat(random_bytes(1024 * 1024), 34);   // 34 MB: above the 32 MB line
$n_total = (int) ceil(strlen($big) / $chunk);
$res = null;
for ($n = $n_total; $n >= 1; $n--) {
    $res = piece($ctl, $root, $big, 'big.bin', $chunk, $n, '2-big');
}
check('answers with a job', is_array($res[1]) && isset($res[1]['job']), json_encode($res));
$id = is_array($res[1]) ? (string) ($res[1]['job'] ?? '') : '';
$st = $id !== '' ? $ctl->jobs()->read($id) : null;
check('job finished', ($st['state'] ?? '') === 'done' && ($st['kind'] ?? '') === 'upload', json_encode($st));
check('large file intact', md5_file($root . '/home/web/big.bin') === md5($big));
check('server copy removed', glob($root . '/work/tmp/upload-*') === [] && glob($root . '/tmpfs/multipart_*') === []);

echo "== another website's pieces are separate\n";
$other = (new ReflectionClass(TestUploadController::class))->newInstanceWithoutConstructor();
$other->setUp($config, $session, $storage, $tmpfs, $root . '/work');
$prop = new ReflectionProperty(UploadController::class, 'user');
$prop->setAccessible(true);
$u = new User(); $u->setUsername('ftp999'); $u->setName('other.test'); $u->setHomedir('/');
$prop->setValue($other, $u);
$chunk = 64 * 1024;
$data = random_bytes(2 * $chunk);
piece($ctl, $root, $data, 'shared.bin', $chunk, 1, '3-same');
$r = piece($other, $root, $data, 'shared.bin', $chunk, 2, '3-same');
check('pieces of one site do not complete another site\'s file', $r[1] === 'Uploaded', json_encode($r));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
