<?php

namespace Filegator\Services\Auth\Adapters;

use Filegator\Services\Auth\User;
use Filegator\Services\Remote\RemoteConnection;
use Filegator\Services\Remote\SsoToken;

/**
 * File manager inside the ISPConfig panel: nobody types a login. The panel has already checked who the person is and
 * which website they may touch, created a temporary FTP account for it, and hands over a single-use token.
 * The connection target (the server's own FTP on 127.0.0.1) comes from this server's config, never from the token.
 */
class IspconfigAuth extends RemoteAuth
{
    public const SESSION_EXPIRES = 'ispconfig_expires';

    protected $sso_key_file;

    protected $used_dir;

    protected $ftp_host = '127.0.0.1';

    protected $ftp_port = 21;

    public function init(array $config = [])
    {
        parent::init($config);
        $this->sso_key_file = $config['sso_key_file'];
        $this->used_dir = $config['sso_used_dir'];
        $this->ftp_host = $config['ftp_host'] ?? $this->ftp_host;
        $this->ftp_port = (int) ($config['ftp_port'] ?? $this->ftp_port);
    }

    /** No typed logins here: the only way in is a token made by the panel. */
    public function connect(array $input): bool
    {
        $this->error = 'Open the file manager from the control panel';

        return false;
    }

    public function loginWithToken(string $token): bool
    {
        try {
            $payload = SsoToken::open($token, $this->readKey(), $this->used_dir);
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return false;
        } catch (\Throwable $e) {
            $this->error = 'The file manager is not set up correctly';

            return false;
        }

        $user = (string) ($payload['u'] ?? '');
        $pass = (string) ($payload['p'] ?? '');
        if ($user === '' || $pass === '') {
            $this->error = 'Invalid login link';

            return false;
        }

        $connection = new RemoteConnection('ftp', $this->ftp_host, $this->ftp_port, $user, $pass, '');
        try {
            $connection->test();
        } catch (\Throwable $e) {
            $this->error = 'Could not open the website folder. Open the file manager again from the panel.';

            return false;
        }

        $this->remote->save($connection);
        $this->session->set(self::SESSION_USER, [
            'username' => $user,
            'name' => (string) ($payload['name'] ?? 'Website'),
        ]);
        // the session ends when the temporary FTP account does: nothing outlives the account that backs it
        $this->session->set(self::SESSION_EXPIRES, (int) ($payload['acct_exp'] ?? (time() + 8 * 3600)));
        // every opening starts at the top of the website, not wherever the browser's last visit happened to end
        $this->session->set('current_path', '/');
        $this->session->migrate(true);

        return true;
    }

    public function user(): ?User
    {
        $expires = (int) $this->session->get(self::SESSION_EXPIRES, 0);
        if ($expires && $expires < time()) {
            return null;
        }

        return parent::user();
    }

    protected function readKey(): string
    {
        $hex = trim((string) @file_get_contents($this->sso_key_file));
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            throw new \RuntimeException('Invalid SSO key file');
        }

        return hex2bin($hex);
    }
}
