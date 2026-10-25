<?php

namespace Filegator\Controllers;

use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Auth\Adapters\IspconfigAuth;
use Filegator\Services\Logger\LoggerInterface;

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

    public function login(Request $request, Response $response, AuthInterface $auth)
    {
        if (! $auth instanceof IspconfigAuth) {
            return $response->json('Not found', 404);
        }

        $ip = $request->getClientIp();
        if ($auth->loginWithToken((string) $request->input('token'))) {
            $this->logger->log('Panel login from IP ' . $ip);

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
