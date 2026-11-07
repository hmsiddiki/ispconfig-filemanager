<?php

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Auth\Adapters\IspconfigAuth;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Session\SessionStorageInterface as Session;

/**
 * Entry point for the control panel: ?r=/sso&token=...  Logs in with the single-use token, then sends the browser on
 * to the plain app address so the token never stays in the address bar, the history or a copied link.
 */
class SsoController
{
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function login(Request $request, Response $response, AuthInterface $auth, Session $session, Config $config)
    {
        if (! $auth instanceof IspconfigAuth) {
            return $response->json('Not found', 404);
        }

        $ip = $request->getClientIp();
        if ($auth->loginWithToken((string) $request->input('token'))) {
            $this->logger->log('Panel login from IP ' . $ip);

            // open the website's document root, not its home folder (logs, ssl, private, ...). A site without it falls back to the home
            // folder (FileController::getDirectory). 'start_folder' => '' in configuration.php opens the home folder.
            // a subdomain's tab opens that subdomain's own folder (named by the panel)
            $start = $auth->startFolder() ?? trim(str_replace('\\', '/', (string) ($config->get('start_folder') ?? 'web')), '/');
            if ($start !== '' && strpos('/' . $start . '/', '/../') === false) {
                $session->set(FileController::SESSION_CWD, '/' . $start);
            }

            // keep only the (validated) theme hint; everything else, the token above all, is dropped
            $theme = (string) $request->input('theme');
            $query = in_array($theme, ['light', 'dark', 'auto'], true) ? '?theme=' . $theme : '';
            $path = strtok((string) $request->getRequestUri(), '?');

            return $response->redirect($path . $query);
        }

        $this->logger->log('Panel login refused from IP ' . $ip . ': ' . $auth->getError());

        return $response->html(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>File Manager</title><body style="font:15px system-ui,sans-serif;padding:2rem;text-align:center;color:#444">'
            . '<p>' . htmlspecialchars($auth->getError(), ENT_QUOTES) . '</p></body>',
            403
        );
    }
}
