<?php

/*
 * File Manager launcher for the ISPConfig panel (Sites > File Manager).
 *
 * What it does, in order:
 *   1. lets in only people the panel has already signed in, with access to the Sites module
 *      (and, for clients, FTP not switched off in their limits);
 *   2. lists the websites THIS person may open, using ISPConfig's own permission rules
 *      (an admin sees all, a client only their own, a reseller their clients');
 *   3. on "Open" (checked with ISPConfig's CSRF token) creates a TEMPORARY FTP account for that website,
 *      owned by the admin group so no client ever sees it in their FTP user list, expiring on its own;
 *   4. hands the file manager a single-use, encrypted token holding that login, shown to the user in an iframe.
 *
 * Nothing here stores a password: each opening makes a fresh account and the password exists only inside the token.
 * Installed by install.sh to interface/web/filemanager/index.php. The @...@ values are filled in at install time.
 */

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';

$app->auth->check_module_permissions('sites');

const FM_HOME = '@FM_HOME@';
const FM_APP_URL = '@FM_APP_URL@';          // where the file manager answers, on this same address
const FM_ACCOUNT_TTL = @FM_ACCOUNT_TTL@;    // seconds a temporary FTP account lives
const FM_MAX_OPEN_PER_SITE = 20;            // temporary accounts alive at once for one website
const FM_MARK = 'filegator-temp';           // written in the account's user_config: how clean-up finds only ours

require_once FM_HOME . '/backend/Services/Remote/SsoToken.php';

use Filegator\Services\Remote\SsoToken;

function fm_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fm_notice($text, $kind = 'error')
{
    $colour = $kind === 'error' ? '#c0392b' : '#555';
    echo '<div style="margin:1em 0;padding:1em 1.2em;border-left:4px solid ' . $colour . ';background:rgba(127,127,127,.1);border-radius:4px">' . fm_h($text) . '</div>';
}

/** Create the temporary FTP account for a website and return what the token needs. */
function fm_create_account(array $site)
{
    global $app;

    // expired ones are already refused by the FTP server itself: this only keeps the table tidy
    $app->db->query("DELETE FROM ftp_user WHERE user_config = ? AND expires < DATE_SUB(NOW(), INTERVAL 1 HOUR)", FM_MARK);

    $open = $app->db->queryOneRecord("SELECT COUNT(*) AS c FROM ftp_user WHERE user_config = ? AND parent_domain_id = ? AND expires > NOW()", FM_MARK, $site['domain_id']);
    if ((int) $open['c'] >= FM_MAX_OPEN_PER_SITE) {
        throw new RuntimeException('Too many file manager sessions are open for this website. Close one, or try again later.');
    }

    // numeric ids of the website's system user: files must be created as that user, never as root
    $uid = function_exists('posix_getpwnam') ? posix_getpwnam($site['system_user']) : false;
    $gid = function_exists('posix_getgrnam') ? posix_getgrnam($site['system_group']) : false;
    if (! $uid || ! $gid || (int) $uid['uid'] < 1 || (int) $gid['gid'] < 1) {
        throw new RuntimeException('The website\'s system user could not be found.');
    }

    $dir = (string) $site['document_root'];
    if ($dir === '' || $dir[0] !== '/' || strpos($dir, '..') !== false || ! is_dir($dir)) {
        throw new RuntimeException('The website folder could not be found.');
    }

    $username = 'fm' . bin2hex(random_bytes(7));
    $password = bin2hex(random_bytes(16));
    $hash = crypt($password, '$6$rounds=5000$' . bin2hex(random_bytes(8)) . '$');

    $app->db->query(
        "INSERT INTO ftp_user (sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, server_id, parent_domain_id, username, username_prefix, password, quota_size, active, uid, gid, dir, quota_files, ul_ratio, dl_ratio, ul_bandwidth, dl_bandwidth, expires, user_type, user_config)
         VALUES (1, 1, 'riud', 'riud', '', ?, ?, ?, '', ?, -1, 'y', ?, ?, ?, -1, -1, -1, -1, -1, DATE_ADD(NOW(), INTERVAL ? SECOND), 'user', ?)",
        $site['server_id'], $site['domain_id'], $username, $hash, (string) $uid['uid'], (string) $gid['gid'], $dir, FM_ACCOUNT_TTL, FM_MARK
    );

    return ['username' => $username, 'password' => $password, 'expires' => time() + FM_ACCOUNT_TTL];
}

// ---- who may use this ---------------------------------------------------------------------------------------------
$is_admin = $app->auth->is_admin();
$user_id = $app->auth->get_user_id();
$embed = ! empty($_GET['embed']); // shown inside a website's own "File Manager" tab: no heading, no website picker

echo '<div class="fm-launcher" style="padding:0 0 1em">';
if (! $embed) {
    echo '<h2 style="margin:.2em 0 .8em">File Manager</h2>';
}

if (! $is_admin && (string) $app->auth->get_client_limit($user_id, 'ftp_user') === '0') {
    fm_notice('File access is not enabled for your account. Please contact your provider.');
    echo '</div>';
    exit;
}

// ---- the websites this person may open ----------------------------------------------------------------------------
$app->uses('tform');
$sites = $app->db->queryAllRecords(
    "SELECT domain_id, domain, document_root, system_user, system_group, server_id FROM web_domain
     WHERE type = 'vhost' AND active = 'y' AND server_id = ? AND " . $app->tform->getAuthSQL('r') . " ORDER BY domain",
    $conf['server_id']
);

if (! $sites) {
    fm_notice('There are no websites to browse yet.', 'info');
    echo '</div>';
    exit;
}

$by_id = [];
foreach ($sites as $s) {
    $by_id[(int) $s['domain_id']] = $s;
}

// ---- open one -----------------------------------------------------------------------------------------------------
$opened = null;
if (isset($_GET['site'])) {
    $app->auth->csrf_token_check('GET'); // ISPConfig's own check: stops another web page from opening this link for the user

    $site_id = (int) $_GET['site'];
    if (! isset($by_id[$site_id])) {
        fm_notice('You do not have access to that website.');
    } else {
        try {
            $site = $by_id[$site_id];
            $account = fm_create_account($site);
            $key = hex2bin(trim((string) @file_get_contents(FM_HOME . '/private/keys/sso.key')));
            if (strlen($key) !== 32) {
                throw new RuntimeException('The file manager is not set up correctly (missing key).');
            }
            $token = SsoToken::create([
                'u' => $account['username'],
                'p' => $account['password'],
                'name' => $site['domain'],
                'acct_exp' => $account['expires'],
            ], $key, 60);
            $opened = ['site' => $site, 'url' => FM_APP_URL . '?r=/sso&token=' . $token];
            $app->log('File manager opened for website ' . $site['domain'] . ' (temporary FTP account ' . $account['username'] . ')', LOGLEVEL_DEBUG);
        } catch (Throwable $e) {
            $app->log('File manager could not be opened: ' . $e->getMessage(), LOGLEVEL_WARN);
            fm_notice($e instanceof RuntimeException ? $e->getMessage() : 'The file manager could not be opened.');
        }
    }
}

// ---- one fresh CSRF token for whatever the person clicks next -------------------------------------------------------
$csrf = $app->auth->csrf_token_get('filemanager_open');
$query = '&_csrf_id=' . rawurlencode($csrf['csrf_id']) . '&_csrf_key=' . rawurlencode($csrf['csrf_key']);

// ---- loading another page into the panel's content area ---------------------------------------------------------------
// ISPConfig 3.2/3.3 expose ISPConfig.loadContent(page); older versions had a plain loadContent(page). Use whichever exists,
// and fall back to an ordinary page load so it still works with a customised theme.
// In a website's tab the page is loaded into that tab only (loadContentInto); on its own page it replaces the whole content area.
echo '<script>window.fmLoad = function (page) {'
    . ($embed
        ? ' ISPConfig.loadContentInto("fm-tab-pane", page);'
        : ' if (window.ISPConfig && typeof ISPConfig.loadContent === "function") { ISPConfig.loadContent(page); }'
        . ' else if (typeof window.loadContent === "function") { window.loadContent(page); }'
        . ' else { window.location.href = "/" + page; }')
    . ' };</script>';

// ---- website picker (not in a website's own tab: there the website is already chosen) --------------------------------------
if (! $embed) {
    echo '<div style="display:flex;gap:.6em;align-items:center;flex-wrap:wrap;margin:0 0 1em">';
    echo '<label for="fm-site" style="font-weight:600">Website</label>';
    echo '<select id="fm-site" style="min-width:16em;max-width:100%">';
    foreach ($sites as $s) {
        $selected = $opened && (int) $opened['site']['domain_id'] === (int) $s['domain_id'] ? ' selected' : '';
        echo '<option value="' . (int) $s['domain_id'] . '"' . $selected . '>' . fm_h($s['domain']) . '</option>';
    }
    echo '</select>';
    echo '<button type="button" class="btn btn-primary" onclick="fmLoad(\'filemanager/index.php?site=\' + document.getElementById(\'fm-site\').value + \'' . $query . '\');">'
        . ($opened ? 'Switch website' : 'Open') . '</button>';
    echo '</div>';
}

// Open straight away when there is no choice to make: a single website, or a website's own tab. This is done by the browser
// (not on the plain page load), so a hidden image tag on another site cannot trigger it.
$auto_id = 0;
if ($embed) {
    $wanted = (int) ($_GET['auto'] ?? 0);
    if ($wanted && isset($by_id[$wanted])) {
        $auto_id = $wanted;
    } elseif ($wanted && ! $opened) {
        fm_notice('You do not have access to that website.');
    }
} elseif (count($sites) === 1) {
    $auto_id = (int) $sites[0]['domain_id'];
}
if (! $opened && $auto_id) {
    echo '<script>setTimeout(function () { fmLoad(\'filemanager/index.php?' . ($embed ? 'embed=1&' : '') . 'site=' . $auto_id . $query . '\'); }, 0);</script>';
}

// ---- the file manager itself ----------------------------------------------------------------------------------------
if ($opened) {
    echo '<iframe id="fm-frame" src="' . fm_h($opened['url']) . '" title="File Manager" allow="clipboard-write" '
        . 'style="width:100%;height:calc(100vh - 160px);min-height:680px;border:1px solid rgba(127,127,127,.25);border-radius:8px;background:transparent"></iframe>';

    // keep the file manager in the panel's light/dark theme: a theme that marks <html> or <body> with data-theme="dark|light"
    // (or a "dark" / "light" class) is picked up here and passed on, live, whenever it changes
    echo <<<'JS'
<script>
(function () {
    var frame = document.getElementById('fm-frame');
    function theme() {
        var h = document.documentElement, b = document.body;
        var t = h.getAttribute('data-theme') || b.getAttribute('data-theme') || '';
        if (t === 'dark' || t === 'light') return t;
        if (h.classList.contains('dark') || b.classList.contains('dark') || b.classList.contains('theme-dark')) return 'dark';
        if (h.classList.contains('light') || b.classList.contains('light') || b.classList.contains('theme-light')) return 'light';
        return null; // no signal from the panel: leave the file manager's own switch in charge
    }
    function send() {
        var t = theme();
        if (t && frame.contentWindow) frame.contentWindow.postMessage({ type: 'filemanager-theme', theme: t }, location.origin);
    }
    frame.addEventListener('load', send);
    var watch = new MutationObserver(send);
    watch.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
    watch.observe(document.body, { attributes: true, attributeFilter: ['data-theme', 'class'] });

    // Full screen "over the parent": the file manager cannot grow past its own frame, so when its editor goes full screen it asks
    // this page to stretch the frame over the whole browser window (and to give it back afterwards).
    var saved = null, watchdog = null;
    function setFull(on) {
        if (on && !saved) {
            saved = { style: frame.getAttribute('style'), overflow: document.documentElement.style.overflow, bodyOverflow: document.body.style.overflow };
            frame.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;max-width:none;min-height:0;margin:0;border:0;border-radius:0;z-index:2147483000;background:#000';
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden'; // no page scrollbar peeking out behind the stretched frame
            // if another page replaces this one while the frame is stretched, the window must not stay locked
            watchdog = setInterval(function () { if (!document.body.contains(frame)) setFull(false); }, 500);
        } else if (!on && saved) {
            clearInterval(watchdog);
            if (document.body.contains(frame)) frame.setAttribute('style', saved.style || '');
            document.documentElement.style.overflow = saved.overflow;
            document.body.style.overflow = saved.bodyOverflow;
            saved = null;
        }
    }
    function onMessage(event) {
        if (!document.body.contains(frame)) { window.removeEventListener('message', onMessage); setFull(false); return; }
        if (event.origin !== location.origin || event.source !== frame.contentWindow) return; // only our own file manager, same address
        if (event.data && event.data.type === 'filemanager-fullscreen') setFull(!!event.data.on);
    }
    window.addEventListener('message', onMessage);
})();
</script>
JS;
}

echo '</div>';
