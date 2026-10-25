<?php

/*
 * File Manager entry point inside the ISPConfig panel (interface/web/filemanager/app/index.php).
 * Only this file and the built front-end assets are web-accessible. The application code, its libraries and keys live
 * outside the web root, in FM_HOME. What the app writes (logs, sessions, scratch space) lives in FM_DATA, inside the
 * one folder ISPConfig allows the panel's sandboxed PHP to write to.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

define('FM_HOME', '@FM_HOME@');   // program, libraries, keys: read-only is fine
define('FM_DATA', '@FM_DATA@');   // logs, sessions, scratch space: must be writable by the panel's PHP

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    echo 'Minimum requirement is PHP 8.1 You are using: ' . PHP_VERSION . "\n";
    die;
}

if (! is_writable(FM_DATA . '/logs/') || ! is_writable(FM_DATA . '/sessions/')) {
    http_response_code(500); // an error page must never look like a working one to a monitor or a test
    echo 'The file manager is not set up correctly: its data folder is not writable (' . FM_DATA . ").\n";
    die;
}

require FM_HOME . '/vendor/autoload.php';

define('APP_ENV', 'production');

if (! defined('APP_PUBLIC_PATH')) {
    define('APP_PUBLIC_PATH', '');
}

define('APP_PUBLIC_DIR', __DIR__);
define('APP_VERSION', '7.16.8');

use Filegator\App;
use Filegator\Config\Config;
use Filegator\Container\Container;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Kernel\StreamedResponse;

$config = require FM_HOME . '/configuration.php';

new App(new Config($config), Request::createFromGlobals(), new Response(), new StreamedResponse(), new Container());
