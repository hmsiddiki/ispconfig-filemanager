<?php

return [
    'public_path' => APP_PUBLIC_PATH,
    'public_dir' => APP_PUBLIC_DIR,
    'overwrite_on_upload' => false,
    'timezone' => 'UTC', // https://www.php.net/manual/en/timezones.php
    'download_inline' => ['pdf'], // download inline in the browser, array of extensions, use * for all. WARNING: Enabling untrusted file types may lead to XSS
    'lockout_attempts' => 5, // max failed login attempts before ip lockout
    'lockout_timeout' => 60, // ip lockout timeout in seconds

    // "Upload from URL": the server downloads files from addresses users type. Public internet only unless allow_private is set.
    'url_upload' => [
        'enabled' => true,
        'max_size' => 200 * 1024 * 1024, // bytes per file. Keep in step with 'url_upload_max_size' in frontend_config below
        'timeout' => 600, // seconds for the whole download
        'max_concurrent_per_user' => 1, // downloads one user can run at the same time
        'max_concurrent_total' => 3, // downloads running at once on the whole server
        'allowed_ports' => [80, 443],
        'max_redirects' => 5,
        'allow_private' => false, // true lets users reach localhost / internal networks: leave off unless you know why you need it
    ],

    'frontend_config' => [
        'app_name' => 'File Manager',
        'app_version' => APP_VERSION,
        'language' => 'english',
        'logo' => 'logo.svg',
        'upload_max_size' => 100 * 1024 * 1024, // 100MB
        'upload_chunk_size' => 1 * 1024 * 1024, // 1MB
        'upload_simultaneous' => 3,
        'default_archive_name' => 'archive.zip',
        'editable' => ['.txt', '.log', '.md', '.json', '.xml', '.yml', '.yaml', '.toml', '.ini', '.conf', '.env', '.htaccess', '.csv', '.html', '.htm', '.css', '.scss', '.less', '.js', '.mjs', '.jsx', '.ts', '.tsx', '.vue', '.php', '.py', '.sh', '.bash', '.sql', '.go', '.java', '.c', '.h', '.cpp', '.diff', '.patch', 'Dockerfile'],
        'date_format' => 'YY/MM/DD hh:mm:ss', // see: https://momentjs.com/docs/#/displaying/format/
        'guest_redirection' => '', // useful for external auth adapters
        'search_simultaneous' => 5,
        'default_view' => 'grid', // 'grid' or 'list': what people see until they click the view button themselves
        'max_width' => 1440, // px: widest the content gets (an embedded file manager always fills its frame)
        'url_upload_enabled' => true, // shows "From URL" in the New menu (keep in step with 'url_upload' above)
        'url_upload_max_size' => 200 * 1024 * 1024, // only shown in the dialog; the real limit is 'url_upload' => 'max_size'
        'filter_entries' => [],
        'pagination' => ['', 5, 10, 15],
        // FTP / SFTP login form. 'host' pre-fills the host field; 'lock_host' => true hides it (use with a single allowed host).
        'remote_login' => [
            'enabled' => true,
            'protocols' => ['sftp', 'ftp', 'ftps'],
            'host' => '127.0.0.1',
            'lock_host' => false,
        ],
    ],

    'services' => [
        'Filegator\Services\Logger\LoggerInterface' => [
            'handler' => '\Filegator\Services\Logger\Adapters\MonoLogger',
            'config' => [
                'monolog_handlers' => [
                    function () {
                        return new \Monolog\Handler\StreamHandler(
                            __DIR__ . '/private/logs/app.log',
                            \Monolog\Logger::DEBUG
                        );
                    },
                ],
            ],
        ],
        'Filegator\Services\Session\SessionStorageInterface' => [
            'handler' => '\Filegator\Services\Session\Adapters\SessionStorage',
            'config' => [
                'handler' => function () {
                    $save_path = null; // use default system path
                    //$save_path = __DIR__.'/private/sessions';
                    $handler = new \Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler($save_path);

                    return new \Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage([
                        "cookie_samesite" => "Lax",
                        "cookie_secure" => null,
                        "cookie_httponly" => true,
                    ], $handler);
                },
            ],
        ],
        'Filegator\Services\Cors\Cors' => [
            'handler' => '\Filegator\Services\Cors\Cors',
            'config' => [
                'enabled' => APP_ENV == 'production' ? false : true,
            ],
        ],
        'Filegator\Services\Tmpfs\TmpfsInterface' => [
            'handler' => '\Filegator\Services\Tmpfs\Adapters\Tmpfs',
            'config' => [
                'path' => __DIR__ . '/private/tmp/',
                'gc_probability_perc' => 10,
                'gc_older_than' => 60 * 60 * 24 * 2, // 2 days
            ],
        ],
        'Filegator\Services\Security\Security' => [
            'handler' => '\Filegator\Services\Security\Security',
            'config' => [
                'csrf_protection' => true,
                'csrf_key' => 'CHANGE-ME-random-string',
                'ip_allowlist' => [],
                'ip_denylist' => [],
                'allow_insecure_overlays' => false,
            ],
        ],
        'Filegator\Services\View\ViewInterface' => [
            'handler' => '\Filegator\Services\View\Adapters\Vuejs',
            'config' => [
                'add_to_head' => '',
                'add_to_body' => '',
            ],
        ],
        'Filegator\Services\Storage\Filesystem' => [
            'handler' => '\Filegator\Services\Storage\RemoteFilesystem',
            'config' => [
                'separator' => '/',
                'config' => [],
                'key_file' => dirname(__DIR__, 3) . '/filegator-data/remote.key', // encrypts the stored FTP/SFTP login; keep private
            ],
        ],
        'Filegator\Services\Archiver\ArchiverInterface' => [
            'handler' => '\Filegator\Services\Archiver\Adapters\ZipArchiver',
            'config' => [],
        ],
        'Filegator\Services\Auth\AuthInterface' => [
            'handler' => '\Filegator\Services\Auth\Adapters\RemoteAuth',
            'config' => [
                'key_file' => dirname(__DIR__, 3) . '/filegator-data/remote.key',
                // Hosts this install may connect to. Keep it to localhost when the file manager only serves this server.
                // Use ['*'] only if users really must reach any host (it also lets them probe your network).
                'allowed_hosts' => ['127.0.0.1', 'localhost', '::1'],
                'allowed_protocols' => ['sftp', 'ftp', 'ftps'],
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
