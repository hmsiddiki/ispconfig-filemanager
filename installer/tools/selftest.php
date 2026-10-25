<?php

/*
 * Installer self-test: proves, on THIS server, that everything the file manager depends on works together.
 *   php selftest.php <FM_HOME> <ispconfig config.inc.php> <ftp host> <ftp port> [<folder holding vendor/ and backend/>]
 * Makes one temporary FTP account for the first active website, logs in with it, reads the folder, deletes the account.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
[, $home, $conf_file, $ftp_host, $ftp_port, $code_dir] = $argv + [null, null, null, '127.0.0.1', '21', null];
// This runs as root. The program code it loads comes from the verified release package (not from the install folder, which the
// panel's own PHP user can write to); the install folder is only read for the key and configuration.
$code_dir = $code_dir ?: $home;

$failed = false;
function ok($m) { echo "  \033[32mok\033[0m    $m\n"; }
function warn($m) { echo "  \033[33mwarn\033[0m  $m\n"; }
function bad($m) { global $failed; $failed = true; echo "  \033[31mFAIL\033[0m  $m\n"; }

// 1. the program loads and its login token works
require $code_dir . '/vendor/autoload.php';
$key = hex2bin(trim((string) @file_get_contents($home . '/private/keys/sso.key')));
if (strlen($key) !== 32) {
    bad('the login key is missing or damaged');
    exit(1);
}
$used = sys_get_temp_dir() . '/fm-selftest-' . bin2hex(random_bytes(3));
try {
    $t = Filegator\Services\Remote\SsoToken::create(['u' => 'x', 'p' => 'y'], $key, 30);
    $p = Filegator\Services\Remote\SsoToken::open($t, $key, $used);
    $again = false;
    try { Filegator\Services\Remote\SsoToken::open($t, $key, $used); $again = true; } catch (InvalidArgumentException $e) {}
    $p['u'] === 'x' && ! $again ? ok('login tokens: create, open, and refuse a second use') : bad('login tokens misbehave');
} catch (Throwable $e) {
    bad('login tokens: ' . $e->getMessage());
}
array_map('unlink', glob($used . '/*') ?: []);
@rmdir($used);

// 2. a real temporary account, a real FTP login
require $conf_file;
$db = @new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_database'], (int) ($conf['db_port'] ?? 3306));
if ($db->connect_errno) {
    bad('cannot connect to the ISPConfig database');
    exit(1);
}
$site = $db->query("SELECT domain_id, domain, document_root, system_user, system_group, server_id FROM web_domain WHERE type = 'vhost' AND active = 'y' AND server_id = " . (int) $conf['server_id'] . ' ORDER BY domain_id LIMIT 1')->fetch_assoc();
if (! $site) {
    warn('no website exists yet, so the FTP login could not be tried. Create a website in ISPConfig and test from the panel.');
    exit($failed ? 1 : 0);
}

$uid = posix_getpwnam($site['system_user']);
$gid = posix_getgrnam($site['system_group']);
if (! $uid || ! $gid || ! is_dir($site['document_root'])) {
    bad('the website ' . $site['domain'] . ' has no usable system user or folder');
    exit(1);
}

$user = 'fm' . bin2hex(random_bytes(7));
$pass = bin2hex(random_bytes(16));
$hash = crypt($pass, '$6$rounds=5000$' . bin2hex(random_bytes(8)) . '$');
$sql = "INSERT INTO ftp_user (sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, server_id, parent_domain_id, username, username_prefix, password, quota_size, active, uid, gid, dir, quota_files, ul_ratio, dl_ratio, ul_bandwidth, dl_bandwidth, expires, user_type, user_config)
        VALUES (1, 1, 'riud', 'riud', '', ?, ?, ?, '', ?, -1, 'y', ?, ?, ?, -1, -1, -1, -1, -1, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 'user', 'filegator-temp')";
$st = $db->prepare($sql);
$uidS = (string) $uid['uid']; $gidS = (string) $gid['gid'];
$st->bind_param('iisssss', $site['server_id'], $site['domain_id'], $user, $hash, $uidS, $gidS, $site['document_root']);
if (! $st->execute()) {
    bad('could not create the temporary FTP account: ' . $db->error);
    exit(1);
}
ok('temporary FTP account created for ' . $site['domain']);

try {
    $c = new Filegator\Services\Remote\RemoteConnection('ftp', $ftp_host, (int) $ftp_port, $user, $pass, '');
    $c->test();
    ok("logged in over FTP ($ftp_host:$ftp_port) as that account");

    $fs = new League\Flysystem\Filesystem($c->adapter());
    $names = array_column($fs->listContents('', false), 'path');
    $names ? ok('website folder readable: ' . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? ', ...' : '')) : warn('the website folder is empty');

    $bad = new Filegator\Services\Remote\RemoteConnection('ftp', $ftp_host, (int) $ftp_port, $user, 'wrong-' . $pass, '');
    try { $bad->test(); bad('a wrong password was accepted'); } catch (Throwable $e) { ok('a wrong password is refused'); }
} catch (Throwable $e) {
    bad('FTP login failed: ' . $e->getMessage());
}

$db->query("DELETE FROM ftp_user WHERE username = '" . $db->real_escape_string($user) . "' AND user_config = 'filegator-temp'");
$left = $db->query("SELECT COUNT(*) c FROM ftp_user WHERE username = '" . $db->real_escape_string($user) . "'")->fetch_assoc()['c'];
$left == 0 ? ok('temporary account removed again') : warn('the test account could not be removed (the clean-up job will remove it when it expires)');

exit($failed ? 1 : 0);
