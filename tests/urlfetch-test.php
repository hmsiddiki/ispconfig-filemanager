<?php
// Tests UrlFetcher: address policy, URL rules, and real downloads (local test web server, redirects, limits).
require_once __DIR__ . '/../backend/Services/Remote/UrlFetcher.php';

use Filegator\Services\Remote\UrlFetcher;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $extra === '' ? '' : "  -> $extra") . "\n";
}
function refused(callable $f): ?string {
    try { $f(); return null; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
}

echo "== isPublicIp\n";
$public = ['8.8.8.8', '1.1.1.1', '93.184.216.34', '151.101.1.69', '2606:4700:4700::1111', '2a00:1450:4001:81b::200e'];
$private = [
    '127.0.0.1', '127.1.2.3', '0.0.0.0', '10.0.0.5', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '169.254.0.1',
    '100.64.0.1', '100.127.255.255', '192.0.0.1', '192.0.2.1', '198.18.0.1', '198.51.100.7', '203.0.113.9', '224.0.0.1', '240.0.0.1', '255.255.255.255',
    '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '2001:db8::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2',
    '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '64:ff9b::7f00:1', '2002:7f00:1::1', '2002:a9fe:a9fe::1',
    'not-an-ip', '',
];
foreach ($public as $ip) check("public: $ip", UrlFetcher::isPublicIp($ip) === true);
foreach ($private as $ip) check("blocked: " . ($ip === '' ? '(empty)' : $ip), UrlFetcher::isPublicIp($ip) === false);

echo "== URL rules (default policy)\n";
$f = new UrlFetcher();
foreach ([
    'file:///etc/passwd' => 'file scheme',
    'gopher://127.0.0.1:70/_x' => 'gopher scheme',
    'ftp://example.com/a.zip' => 'ftp scheme',
    'http://127.0.0.1/' => 'loopback ip',
    'http://localhost/' => 'localhost name',
    'http://[::1]/' => 'ipv6 loopback',
    'http://169.254.169.254/latest/meta-data/' => 'cloud metadata',
    'http://2130706433/' => 'decimal-encoded 127.0.0.1',
    'http://0x7f.0.0.1/' => 'hex-encoded loopback',
    'http://017700000001/' => 'octal-encoded loopback',
    'http://example.com:22/' => 'ssh port',
    'http://example.com:6379/' => 'redis port',
    'http://user:pass@example.com/' => 'credentials in url',
    'http://exa mple.com/' => 'space in host',
    "http://example.com/\r\nHost: evil" => 'header injection',
    'http:///nohost' => 'no host',
    '' => 'empty',
    'javascript:alert(1)' => 'javascript scheme',
] as $url => $label) {
    $msg = refused(function () use ($f, $url) { $f->checkUrl($url); });
    check("refuses $label", $msg !== null, "was accepted");
}
check('accepts https://example.com/a.zip', refused(function () use ($f) { $f->checkUrl('https://example.com/a.zip'); }) === null);
check('accepts explicit port 443', refused(function () use ($f) { $f->checkUrl('https://example.com:443/a'); }) === null);

echo "== name cleaning\n";
foreach ([
    '../../etc/passwd' => '_.._etc_passwd',
    '..\\..\\win.ini' => '_.._win.ini',
    '.htaccess' => 'htaccess',
    "bad\x00name\x1f.txt" => 'badname.txt',
    '' => 'download',
    '...' => 'download',
    'a:b*c?.zip' => 'a_b_c_.zip',
] as $in => $want) {
    $got = UrlFetcher::cleanFileName((string) $in);
    check("clean " . json_encode($in) . " -> $want", $got === $want, "got $got");
}
check('long name is cut', mb_strlen(UrlFetcher::cleanFileName(str_repeat('a', 500) . '.zip')) <= 200);

echo "== real downloads (local test server, private check off for the test only)\n";
$root = sys_get_temp_dir() . '/fgurl-www'; @mkdir($root);
file_put_contents("$root/hello.txt", "hello from the web\n");
file_put_contents("$root/big.bin", str_repeat('x', 3 * 1024 * 1024));
file_put_contents("$root/router.php", <<<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__;
switch ($p) {
    case '/redirect': header('Location: /hello.txt', true, 302); exit;
    case '/redirect-abs-private': header('Location: http://169.254.169.254/latest/meta-data/', true, 302); exit;
    case '/loop': header('Location: /loop', true, 302); exit;
    case '/named': header('Content-Disposition: attachment; filename="report final.txt"'); echo "named body"; exit;
    case '/named-star': header("Content-Disposition: attachment; filename*=UTF-8''r%C3%A9sum%C3%A9.txt"); echo "star body"; exit;
    case '/evil-name': header('Content-Disposition: attachment; filename="../../evil.php"'); echo "x"; exit;
    case '/missing': http_response_code(404); echo "nope"; exit;
    case '/slow': sleep(5); echo "late"; exit;
}
return false; // let the built-in server serve static files
PHP);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8099', '-t', $root, "$root/router.php"], [1 => ['file', "$root/srv.log", 'w'], 2 => ['file', "$root/srv.log", 'a']], $pipes);
usleep(900000);
$base = 'http://127.0.0.1:8099';
$loc = new UrlFetcher(['allow_private' => true, 'allowed_ports' => [8099], 'max_size' => 1024 * 1024, 'timeout' => 3, 'max_redirects' => 3]);

check('default policy refuses the local server', refused(function () use ($base) { (new UrlFetcher(['allowed_ports' => [8099]]))->fetch("$base/hello.txt"); }) === 'That address is not allowed');

$r = $loc->fetch("$base/hello.txt");
check('downloads a file', file_get_contents($r['path']) === "hello from the web\n" && $r['name'] === 'hello.txt' && $r['size'] === 19, json_encode($r)); @unlink($r['path']);
$r = $loc->fetch("$base/redirect");
check('follows a redirect', file_get_contents($r['path']) === "hello from the web\n" && $r['name'] === 'hello.txt'); @unlink($r['path']);
$r = $loc->fetch("$base/named");
check('uses Content-Disposition name', $r['name'] === 'report final.txt', $r['name']); @unlink($r['path']);
$r = $loc->fetch("$base/named-star");
check('uses RFC 5987 filename*', $r['name'] === "résumé.txt", $r['name']); @unlink($r['path']);
$r = $loc->fetch("$base/evil-name");
check('neutralises a path in the file name', $r['name'] === '.._.._evil.php' || strpos($r['name'], '/') === false, $r['name']); @unlink($r['path']);

check('redirect loop is stopped', refused(function () use ($loc, $base) { $loc->fetch("$base/loop"); }) === 'Too many redirects');
check('404 reported', refused(function () use ($loc, $base) { $loc->fetch("$base/missing"); }) === 'The server answered with HTTP 404');
check('size limit enforced', (string) refused(function () use ($loc, $base) { $loc->fetch("$base/big.bin"); }) !== '' && strpos((string) refused(function () use ($loc, $base) { $loc->fetch("$base/big.bin"); }), 'larger than') !== false);
$t = microtime(true);
check('timeout enforced', refused(function () use ($loc, $base) { $loc->fetch("$base/slow"); }) === 'The download took too long' && microtime(true) - $t < 6);

// redirect into a forbidden address must be refused even though the first hop was allowed
$strict = new UrlFetcher(['allow_private' => false, 'allowed_ports' => [8099, 80]]);
// first hop (127.0.0.1) is already blocked here, so prove the per-hop check separately with the checkUrl on the redirect target
check('redirect target to metadata is refused by checkUrl', refused(function () use ($strict) { $strict->checkUrl('http://169.254.169.254/latest/meta-data/'); }) === 'That address is not allowed');

// leave no temp files behind after failures
$before = glob(sys_get_temp_dir() . '/fgurl*') ?: [];
try { $loc->fetch("$base/missing"); } catch (Throwable $e) {}
try { $loc->fetch("$base/big.bin"); } catch (Throwable $e) {}
$after = glob(sys_get_temp_dir() . '/fgurl*') ?: [];
$leftover = array_filter(array_diff($after, $before), 'is_file');
check('no temp files left after failed downloads', count($leftover) === 0, implode(',', $leftover));

proc_terminate($proc); proc_close($proc);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
