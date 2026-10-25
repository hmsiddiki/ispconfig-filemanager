<?php

// File Manager for the ISPConfig panel. Written by install.sh; safe to edit.
// Keys live next to this file (private/keys), outside the web root. Everything the app WRITES (logs, sessions, upload scratch
// space) lives in FM_DATA, inside the one folder ISPConfig lets the panel's PHP write to: the panel runs sandboxed
// (systemd ProtectSystem) with the rest of /usr read-only.

// sessions live as long as the temporary FTP accounts that back them (8 hours), not PHP's default of 24 minutes
ini_set('session.gc_maxlifetime', '28800');

return [
    'public_path' => APP_PUBLIC_PATH,
    'public_dir' => APP_PUBLIC_DIR,
    'overwrite_on_upload' => false,
    'timezone' => 'UTC',
    'download_inline' => ['pdf'], // WARNING: enabling untrusted file types may lead to XSS
    'lockout_attempts' => 5,
    'lockout_timeout' => 60,

    // "Upload from URL": the server downloads files from addresses users type. Public internet only.
    'url_upload' => [
        'enabled' => true,
        'max_size' => 200 * 1024 * 1024, // bytes per file. Keep in step with 'url_upload_max_size' below
        'timeout' => 600,
        'max_concurrent_per_user' => 1,
        'max_concurrent_total' => 3,
        'allowed_ports' => [80, 443],
        'max_redirects' => 5,
        'allow_private' => false, // leave off: true would let users reach this server's own network
    ],

    'frontend_config' => [
        'app_name' => 'File Manager',
        'app_version' => APP_VERSION,
        'language' => 'english',
        'logo' => 'logo.svg',
        'upload_max_size' => 100 * 1024 * 1024,
        'upload_chunk_size' => 1 * 1024 * 1024, // must stay below the panel's client_max_body_size (20M by default)
        'upload_simultaneous' => 3,
        'default_archive_name' => 'archive.zip',
        'editable' => ['.txt', '.log', '.md', '.json', '.xml', '.yml', '.yaml', '.toml', '.ini', '.conf', '.env', '.htaccess', '.csv', '.html', '.htm', '.css', '.scss', '.less', '.js', '.mjs', '.jsx', '.ts', '.tsx', '.vue', '.php', '.py', '.sh', '.bash', '.sql', '.go', '.java', '.c', '.h', '.cpp', '.diff', '.patch', 'Dockerfile'],
        'date_format' => 'YY/MM/DD hh:mm:ss',
        'guest_redirection' => '',
        'search_simultaneous' => 5,
        'default_view' => 'grid', // 'grid' or 'list'
        'max_width' => 1440,
        'url_upload_enabled' => true,
        'url_upload_max_size' => 200 * 1024 * 1024,
        'filter_entries' => [],
        'pagination' => ['', 5, 10, 15],
        // panel mode: the control panel signs people in, so the app shows no login form and no logout button
        'sso_only' => true,
        'remote_login' => ['enabled' => false, 'protocols' => ['ftp'], 'host' => '127.0.0.1', 'lock_host' => true],
    ],

    'services' => [
        'Filegator\Services\Logger\LoggerInterface' => [
            'handler' => '\Filegator\Services\Logger\Adapters\MonoLogger',
            'config' => [
                'monolog_handlers' => [
                    function () {
                        return new \Monolog\Handler\StreamHandler(FM_DATA . '/logs/app.log', \Monolog\Logger::INFO);
                    },
                ],
            ],
        ],
        'Filegator\Services\Session\SessionStorageInterface' => [
            'handler' => '\Filegator\Services\Session\Adapters\SessionStorage',
            'config' => [
                'handler' => function () {
                    // own folder, so the panel's sessions and this app's never share a clean-up schedule
                    $handler = new \Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler(FM_DATA . '/sessions');

                    return new \Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage([
                        'cookie_samesite' => 'Lax',
                        'cookie_secure' => ! empty($_SERVER['HTTPS']),
                        'cookie_httponly' => true,
                        'cookie_path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
                    ], $handler);
                },
            ],
        ],
        'Filegator\Services\Cors\Cors' => [
            'handler' => '\Filegator\Services\Cors\Cors',
            'config' => ['enabled' => false],
        ],
        'Filegator\Services\Tmpfs\TmpfsInterface' => [
            'handler' => '\Filegator\Services\Tmpfs\Adapters\Tmpfs',
            'config' => [
                'path' => FM_DATA . '/tmp/',
                'gc_probability_perc' => 10,
                'gc_older_than' => 60 * 60 * 24 * 2,
            ],
        ],
        'Filegator\Services\Security\Security' => [
            'handler' => '\Filegator\Services\Security\Security',
            'config' => [
                'csrf_protection' => true,
                'csrf_key' => '@CSRF_KEY@',
                'ip_allowlist' => [],
                'ip_denylist' => [],
                'allow_insecure_overlays' => false,
            ],
        ],
        'Filegator\Services\View\ViewInterface' => [
            'handler' => '\Filegator\Services\View\Adapters\Vuejs',
            'config' => ['add_to_head' => '', 'add_to_body' => ''],
        ],
        'Filegator\Services\Storage\Filesystem' => [
            'handler' => '\Filegator\Services\Storage\RemoteFilesystem',
            'config' => [
                'separator' => '/',
                'config' => [],
                'key_file' => __DIR__ . '/private/keys/remote.key', // encrypts the temporary FTP login kept in the session
            ],
        ],
        'Filegator\Services\Archiver\ArchiverInterface' => [
            'handler' => '\Filegator\Services\Archiver\Adapters\ZipArchiver',
            'config' => [],
        ],
        'Filegator\Services\Auth\AuthInterface' => [
            'handler' => '\Filegator\Services\Auth\Adapters\IspconfigAuth',
            'config' => [
                'key_file' => __DIR__ . '/private/keys/remote.key',
                'sso_key_file' => __DIR__ . '/private/keys/sso.key', // shared with the panel page that signs people in
                'sso_used_dir' => FM_DATA . '/sso-used',
                // this server's own FTP service: the temporary accounts are made for it, never a remote host
                'ftp_host' => '@FTP_HOST@',
                'ftp_port' => @FTP_PORT@,
                'allowed_hosts' => ['@FTP_HOST@'],
                'allowed_protocols' => ['ftp'],
            ],
        ],
        'Filegator\Services\Router\Router' => [
            'handler' => '\Filegator\Services\Router\Router',
            'config' => [
                'query_param' => 'r',
                'routes_file' => __DIR__ . '/backend/Controllers/routes.php',
            ],
        ],
    ],
];
