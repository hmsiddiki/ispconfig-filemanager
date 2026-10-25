<?php

/*
 * Adds "File Manager" to the ISPConfig Sites menu. ISPConfig loads every *.menu.php file from sites/lib/menu.d/,
 * and that folder is not overwritten by ISPConfig updates (which is why the entry lives here and not in module.conf.php).
 * Installed by install.sh.
 */

// shown only to people who may actually use it: admins, and clients whose limits allow FTP
if ($app->auth->is_admin() || (string) $app->auth->get_client_limit($app->auth->get_user_id(), 'ftp_user') !== '0') {
    $items = array();

    $items[] = array(
        'title'   => 'File Manager',
        'target'  => 'content',
        'link'    => 'filemanager/index.php',
        'html_id' => 'filemanager_open'
    );

    $module['nav'][] = array(
        'title' => 'File Manager',
        'open'  => 1,
        'items' => $items
    );

    unset($items);
}
