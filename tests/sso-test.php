<?php
// Tests SsoToken: genuine, tampered, wrong key, expired, replayed, malformed, and that secrets stay out of the token text.
$REMOTE = str_replace(chr(92), '/', realpath(__DIR__ . '/../backend/Services/Remote')) . '/'; // forward slashes: also used inside code strings
require_once $REMOTE . 'SsoToken.php';
use Filegator\Services\Remote\SsoToken;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $extra === '' ? '' : "  -> $extra") . "\n"; }
function opens(string $t, string $k, string $d): ?string { try { SsoToken::open($t, $k, $d); return null; } catch (InvalidArgumentException $e) { return $e->getMessage(); } }

$dir = sys_get_temp_dir() . '/fg-sso-' . bin2hex(random_bytes(3));
$key = random_bytes(32);
$payload = ['u' => 'fm1a2b3c', 'p' => 'S3cret-Pass-Word', 'name' => 'innerit.site'];

$t = SsoToken::create($payload, $key, 60);
check('token is URL-safe text', (bool) preg_match('/^[A-Za-z0-9_-]+$/', $t), $t);
check('password and user are not readable in the token', strpos($t, 'S3cret') === false && strpos(base64_decode(strtr($t, '-_', '+/')), 'S3cret') === false && strpos(base64_decode(strtr($t, '-_', '+/')), 'innerit') === false);
check('token is reasonably short for a URL', strlen($t) < 400, (string) strlen($t));

$out = SsoToken::open($t, $key, $dir);
check('genuine token opens and returns the data', $out['u'] === 'fm1a2b3c' && $out['p'] === 'S3cret-Pass-Word' && $out['name'] === 'innerit.site');
check('token carries an expiry and an id', $out['exp'] > time() && strlen($out['jti']) === 24);
check('same token cannot be used twice (replay)', opens($t, $key, $dir) === 'This login link was already used. Open the file manager again from the panel.', (string) opens($t, $key, $dir));

$t2 = SsoToken::create($payload, $key, 60);
check('wrong key is rejected', opens($t2, random_bytes(32), $dir) === 'Invalid login link');
check('...and a failed attempt does not burn the token', opens($t2, $key, $dir) === null);

$t3 = SsoToken::create($payload, $key, 60);
$raw = base64_decode(strtr($t3, '-_', '+/')); $raw[40] = $raw[40] ^ "\x01";
check('tampered ciphertext is rejected', opens(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), $key, $dir) === 'Invalid login link');
$raw = base64_decode(strtr($t3, '-_', '+/')); $raw[15] = $raw[15] ^ "\x01";
check('tampered auth tag is rejected', opens(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), $key, $dir) === 'Invalid login link');
check('truncated token is rejected', opens(substr($t3, 0, 20), $key, $dir) === 'Invalid login link');
check('garbage is rejected', opens('not a token at all!!', $key, $dir) === 'Invalid login link');
check('empty is rejected', opens('', $key, $dir) === 'Invalid login link');
check('token for another purpose (different AAD) is rejected', (function () use ($key, $dir) {
    $iv = random_bytes(12); $tag = ''; $c = openssl_encrypt(json_encode(['exp' => time() + 60, 'jti' => bin2hex(random_bytes(12))]), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'something-else');
    return opens(rtrim(strtr(base64_encode("\x01" . $iv . $tag . $c), '+/', '-_'), '='), $key, $dir) === 'Invalid login link';
})());

$exp = SsoToken::create($payload, $key, -5);
check('expired token is rejected with a helpful message', strpos((string) opens($exp, $key, $dir), 'expired') !== false);

// two valid tokens differ (random IV and id) even for identical data
check('two tokens for the same data differ', SsoToken::create($payload, $key) !== SsoToken::create($payload, $key));

// used-id folder: old entries are cleaned
foreach (glob($dir . '/*') as $f) { touch($f, time() - 1200); }
SsoToken::open(SsoToken::create($payload, $key), $key, $dir);
check('old used-id files are cleaned up', count(glob($dir . '/*')) === 1, (string) count(glob($dir . '/*')));

// concurrency: many parallel tries with one token, exactly one wins
$t4 = SsoToken::create($payload, $key, 60); $wins = 0;
$procs = [];
for ($i = 0; $i < 6; $i++) {
    $procs[] = proc_open([PHP_BINARY, '-r', 'require "' . $REMOTE . 'SsoToken.php"; try { Filegator\Services\Remote\SsoToken::open($argv[1], hex2bin($argv[2]), $argv[3]); echo "WIN"; } catch (Throwable $e) { echo "LOSE"; }', $t4, bin2hex($key), $dir], [1 => ['pipe', 'w']], $pipes[$i]);
}
foreach ($procs as $i => $p) { if (stream_get_contents($pipes[$i][1]) === 'WIN') $wins++; proc_close($p); }
check('6 simultaneous uses of one token: exactly one succeeds', $wins === 1, "wins=$wins");

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
