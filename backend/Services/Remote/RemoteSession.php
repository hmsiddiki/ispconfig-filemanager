<?php

namespace Filegator\Services\Remote;

use Filegator\Services\Session\SessionStorageInterface as Session;

/**
 * Keeps the logged-in FTP/SFTP connection in the session, encrypted with a key that lives outside the session store.
 */
class RemoteSession
{
    public const SESSION_KEY = 'remote_connection';

    protected $session;

    protected $key_file;

    public function __construct(Session $session, string $key_file)
    {
        $this->session = $session;
        $this->key_file = $key_file;
    }

    public function save(RemoteConnection $connection): void
    {
        $this->session->set(self::SESSION_KEY, $connection->encrypt($this->key()));
    }

    public function load(): ?RemoteConnection
    {
        $blob = $this->session->get(self::SESSION_KEY);

        return is_string($blob) && $blob !== '' ? RemoteConnection::decrypt($blob, $this->key()) : null;
    }

    /** 32-byte key, created on first use. Keep this file out of the web root's reach and out of backups you share. */
    protected function key(): string
    {
        if (! is_file($this->key_file)) {
            $dir = dirname($this->key_file);
            if (! is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            file_put_contents($this->key_file, bin2hex(random_bytes(32)), LOCK_EX);
            @chmod($this->key_file, 0600);
        }

        $hex = trim((string) file_get_contents($this->key_file));
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            throw new \RuntimeException('Invalid remote key file');
        }

        return hex2bin($hex);
    }
}
