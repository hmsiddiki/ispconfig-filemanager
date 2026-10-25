<?php

namespace Filegator\Services\Remote;

/**
 * Short-lived, single-use login token passed from a trusted page (the ISPConfig panel) to the file manager.
 *
 * It carries the temporary FTP login inside, encrypted and authenticated with AES-256-GCM under a key that only
 * the panel and the file manager can read, so the password is never visible in the URL, the browser history or a log.
 * Valid for $ttl seconds and for one use only.
 *
 * Plain PHP with no other dependencies: the panel side loads this file directly.
 */
class SsoToken
{
    private const VERSION = "\x01";
    private const AAD = 'filegator-sso-v1';

    /** @param array $payload any JSON-able data (kept small) */
    public static function create(array $payload, string $key, int $ttl = 60): string
    {
        $payload['exp'] = time() + $ttl;
        $payload['jti'] = bin2hex(random_bytes(12));

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode($payload), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
        if ($cipher === false) {
            throw new \RuntimeException('Could not create the login token');
        }

        return rtrim(strtr(base64_encode(self::VERSION . $iv . $tag . $cipher), '+/', '-_'), '=');
    }

    /**
     * Verify and decrypt. Throws \InvalidArgumentException (message safe to show) when the token is not genuine,
     * is expired, or has been used before.
     *
     * @param string $used_dir folder that remembers used token ids (created if missing)
     */
    public static function open(string $token, string $key, string $used_dir): array
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 30 || $raw[0] !== self::VERSION) {
            throw new \InvalidArgumentException('Invalid login link');
        }

        $plain = openssl_decrypt(substr($raw, 29), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 1, 12), substr($raw, 13, 16), self::AAD);
        $payload = $plain === false ? null : json_decode($plain, true);
        if (! is_array($payload) || ! isset($payload['exp'], $payload['jti']) || ! preg_match('/^[a-f0-9]{24}$/', (string) $payload['jti'])) {
            throw new \InvalidArgumentException('Invalid login link');
        }
        if ((int) $payload['exp'] < time()) {
            throw new \InvalidArgumentException('This login link has expired. Open the file manager again from the panel.');
        }

        self::consume($payload['jti'], $used_dir);

        return $payload;
    }

    /** Remember the id; a second attempt with the same token fails. 'x' mode makes the check-and-claim atomic. */
    private static function consume(string $jti, string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot store used login links');
        }

        // old entries can never matter again (tokens live seconds): keep the folder small
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 600) {
                @unlink($file);
            }
        }

        $fp = @fopen($dir . '/' . $jti, 'x');
        if ($fp === false) {
            throw new \InvalidArgumentException('This login link was already used. Open the file manager again from the panel.');
        }
        fclose($fp);
    }
}
