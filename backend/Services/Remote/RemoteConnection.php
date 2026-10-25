<?php

namespace Filegator\Services\Remote;

/**
 * One FTP / FTPS / SFTP login: validated input, a Flysystem adapter for it,
 * and encrypt/decrypt so it can sit in the server-side session without being readable as plain text.
 */
class RemoteConnection
{
    public const PROTOCOLS = ['sftp', 'ftp', 'ftps'];

    public $protocol;
    public $host;
    public $port;
    public $username;
    public $password;
    public $root;
    public $timeout;

    public function __construct(string $protocol, string $host, int $port, string $username, string $password, string $root = '', int $timeout = 15)
    {
        $this->protocol = $protocol;
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->root = $root;
        $this->timeout = $timeout;
    }

    /**
     * Build from raw user input. Throws \InvalidArgumentException with a message that is safe to show the user.
     *
     * @param string[] $allowed_hosts host names / IPs this install may connect to; '*' allows any
     */
    public static function fromInput(array $in, array $allowed_hosts, array $allowed_protocols = self::PROTOCOLS): self
    {
        $protocol = strtolower(trim((string) ($in['protocol'] ?? 'sftp')));
        if (! in_array($protocol, self::PROTOCOLS, true) || ! in_array($protocol, $allowed_protocols, true)) {
            throw new \InvalidArgumentException('Protocol not allowed');
        }

        $host = strtolower(trim((string) ($in['host'] ?? '')));
        if ($host === '' || strlen($host) > 253 || ! preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$|^\[[0-9a-f:]+\]$/', $host)) {
            throw new \InvalidArgumentException('Invalid host');
        }
        $host = trim($host, '[]');

        if (! in_array('*', $allowed_hosts, true) && ! in_array($host, array_map('strtolower', $allowed_hosts), true)) {
            throw new \InvalidArgumentException('This host is not allowed');
        }

        $default_port = $protocol === 'sftp' ? 22 : 21;
        $port = isset($in['port']) && $in['port'] !== '' ? (int) $in['port'] : $default_port;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid port');
        }

        $username = (string) ($in['username'] ?? '');
        $password = (string) ($in['password'] ?? '');
        if ($username === '' || strlen($username) > 128 || strpos($username, "\0") !== false || strpos($password, "\0") !== false) {
            throw new \InvalidArgumentException('Invalid username or password');
        }

        // optional start folder; keep it clean so it cannot be used to break out of the quoted FTP command
        $root = trim((string) ($in['root'] ?? ''));
        if (strlen($root) > 512 || preg_match('/[\x00-\x1f]/', $root) || preg_match('#(^|/)\.\.(/|$)#', $root)) {
            throw new \InvalidArgumentException('Invalid start folder');
        }

        return new self($protocol, $host, $port, $username, $password, $root);
    }

    public function label(): string
    {
        return $this->username . '@' . $this->host;
    }

    public function adapter()
    {
        switch ($this->protocol) {
            case 'sftp':
                return new \Filegator\Services\Storage\Adapters\FilegatorSftp([
                    'host' => $this->host,
                    'port' => $this->port,
                    'username' => $this->username,
                    'password' => $this->password,
                    'root' => $this->root,
                    'timeout' => $this->timeout,
                ]);
            case 'ftp':
            case 'ftps':
                return new \Filegator\Services\Storage\Adapters\FilegatorFtp([
                    'host' => $this->host,
                    'port' => $this->port,
                    'username' => $this->username,
                    'password' => $this->password,
                    'root' => $this->root,
                    'timeout' => $this->timeout,
                    'passive' => true,
                    'ssl' => $this->protocol === 'ftps',
                    // never follow the address the server sends back for data connections (FTP bounce / SSRF)
                    'ignorePassiveAddress' => true,
                ]);
        }

        throw new \InvalidArgumentException('Protocol not allowed');
    }

    /** Open the connection and read the start folder: proves host, port, login and permissions in one go. */
    public function test(): bool
    {
        $adapter = $this->adapter();
        $adapter->getConnection();
        $adapter->listContents('', false);
        $adapter->disconnect();

        return true;
    }

    public function encrypt(string $key): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode(get_object_vars($this)), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $blob, string $key): ?self
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $d = $plain === false ? null : json_decode($plain, true);
        if (! is_array($d)) {
            return null;
        }

        return new self(
            (string) ($d['protocol'] ?? ''),
            (string) ($d['host'] ?? ''),
            (int) ($d['port'] ?? 0),
            (string) ($d['username'] ?? ''),
            (string) ($d['password'] ?? ''),
            (string) ($d['root'] ?? ''),
            (int) ($d['timeout'] ?? 15)
        );
    }
}
