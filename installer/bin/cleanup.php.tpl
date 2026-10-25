<?php

/*
 * Removes expired temporary FTP accounts made by the ISPConfig file manager.
 *
 * Not security-critical: Pure-FTPd refuses an expired account by itself (its login queries check `expires`), so this only
 * keeps the ftp_user table tidy. It touches only rows carrying the file manager's own marker.
 * Run from cron (see /etc/cron.d/ispconfig-filemanager). Installed by install.sh.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require '@PANEL_DIR@/interface/lib/config.inc.php';

$db = @new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_database'], (int) ($conf['db_port'] ?? 3306));
if ($db->connect_errno) {
    fwrite(STDERR, "filemanager cleanup: cannot connect to the ISPConfig database\n");
    exit(1);
}

$stmt = $db->prepare("DELETE FROM ftp_user WHERE user_config = ? AND expires IS NOT NULL AND expires < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$mark = 'filegator-temp';
$stmt->bind_param('s', $mark);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo 'removed ' . $stmt->affected_rows . " expired temporary FTP account(s)\n";
}
