<?php

namespace Filegator\Services\Remote;

/**
 * Downloads a file from a URL the user typed, to a temp file, without letting that URL reach into the server's own network.
 *
 * - http/https only, on allowed ports, no user:password@ in the URL
 * - every address the host name resolves to must be a public one (loopback, private, link-local, metadata, CGNAT... are refused)
 * - the connection is pinned to the address that was checked, so DNS cannot change its answer between check and connect
 * - redirects are followed by hand and every hop goes through the same checks
 * - hard limits on size and time
 *
 * All exceptions are \InvalidArgumentException with a message that is safe to show the user.
 */
class UrlFetcher
{
    protected $max_size;

    protected $timeout;

    protected $allowed_ports;

    protected $allow_private;

    protected $max_redirects;

    public function __construct(array $options = [])
    {
        $this->max_size = (int) ($options['max_size'] ?? 100 * 1024 * 1024);
        $this->timeout = (int) ($options['timeout'] ?? 300);
        $this->allowed_ports = $options['allowed_ports'] ?? [80, 443];
        $this->allow_private = (bool) ($options['allow_private'] ?? false);
        $this->max_redirects = (int) ($options['max_redirects'] ?? 5);
    }

    /**
     * @param callable|null $on_progress function (int $bytes_so_far, int $total_or_0): ?bool - return false to cancel
     *
     * @return array{path: string, name: string, size: int} path is a temp file the caller must delete
     */
    public function fetch(string $url, ?callable $on_progress = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fgurl');
        if ($tmp === false) {
            throw new \InvalidArgumentException('Could not create a temporary file');
        }

        try {
            $current = $url;
            for ($hop = 0; ; $hop++) {
                if ($hop > $this->max_redirects) {
                    throw new \InvalidArgumentException('Too many redirects');
                }

                $target = $this->checkUrl($current);
                $res = $this->request($target, $tmp, $on_progress);

                if (in_array($res['status'], [301, 302, 303, 307, 308], true) && $res['location'] !== '') {
                    $current = $this->resolveLocation($current, $res['location']);
                    continue;
                }
                if ($res['status'] !== 200) {
                    throw new \InvalidArgumentException('The server answered with HTTP ' . $res['status']);
                }

                return [
                    'path' => $tmp,
                    'name' => $this->nameFor($current, $res['disposition']),
                    'size' => $res['bytes'],
                ];
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e instanceof \InvalidArgumentException ? $e : new \InvalidArgumentException('Download failed');
        }
    }

    /** Validate a URL and decide which address to connect to. */
    public function checkUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \InvalidArgumentException('Invalid URL');
        }

        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            throw new \InvalidArgumentException('Invalid URL');
        }

        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('Only http and https addresses are allowed');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new \InvalidArgumentException('Addresses with a user name or password are not allowed');
        }

        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, $this->allowed_ports, true)) {
            throw new \InvalidArgumentException('That port is not allowed');
        }

        $host = strtolower(trim($p['host'], '[]'));
        $is_ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (! $is_ip && ! preg_match('/^[a-z0-9]([a-z0-9.\-]{0,251}[a-z0-9])?$/', $host)) {
            throw new \InvalidArgumentException('Invalid host name');
        }

        $ips = $is_ip ? [$host] : $this->resolve($host);
        if (! $ips) {
            throw new \InvalidArgumentException('Could not find that host');
        }

        // every answer must be public, not just the one we connect to
        if (! $this->allow_private) {
            foreach ($ips as $ip) {
                if (! self::isPublicIp($ip)) {
                    throw new \InvalidArgumentException('That address is not allowed');
                }
            }
        }

        // prefer IPv4 when both exist
        usort($ips, function ($a, $b) {
            return (strpos($a, ':') === false ? 0 : 1) <=> (strpos($b, ':') === false ? 0 : 1);
        });

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'is_ip' => $is_ip];
    }

    protected function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if (! $ips) {
            $v4 = @gethostbynamel($host);
            $ips = is_array($v4) ? $v4 : [];
        }

        return array_values(array_unique($ips));
    }

    protected function request(array $target, string $tmp, ?callable $on_progress = null): array
    {
        if (! function_exists('curl_init')) {
            throw new \InvalidArgumentException('The server cannot download files (cURL is missing)');
        }

        $out = fopen($tmp, 'wb');
        $state = ['status' => 0, 'location' => '', 'disposition' => '', 'bytes' => 0, 'too_large' => false];

        $ch = curl_init($target['url']);
        $opts = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FileManager)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$state) {
                $len = strlen($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    // a new response (redirect hop or 100-continue): forget the previous one's headers
                    $state['status'] = (int) $m[1];
                    $state['location'] = $state['disposition'] = '';
                } elseif (stripos($line, 'location:') === 0) {
                    $state['location'] = trim(substr($line, 9));
                } elseif (stripos($line, 'content-disposition:') === 0) {
                    $state['disposition'] = trim(substr($line, 20));
                } elseif (stripos($line, 'content-length:') === 0 && $state['status'] === 200) {
                    if ((int) trim(substr($line, 15)) > $this->max_size) {
                        $state['too_large'] = true;

                        return 0; // abort before downloading anything
                    }
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$state, $out) {
                if ($state['status'] !== 200) {
                    return strlen($data); // redirect / error page: not kept
                }
                $state['bytes'] += strlen($data);
                if ($state['bytes'] > $this->max_size) {
                    $state['too_large'] = true;

                    return 0;
                }

                return fwrite($out, $data);
            },
        ];

        if ($on_progress) {
            $opts[CURLOPT_NOPROGRESS] = false;
            $opts[CURLOPT_PROGRESSFUNCTION] = function ($ch, $total, $now) use (&$state, $on_progress) {
                if ($state['status'] !== 200) {
                    return 0; // still on a redirect hop: nothing worth reporting yet
                }

                return $on_progress((int) $now, (int) $total) === false ? 1 : 0; // non-zero aborts the transfer
            };
        }

        // connect to the address that was checked, whatever DNS says now
        if (! $target['is_ip']) {
            $ip = strpos($target['ip'], ':') === false ? $target['ip'] : '[' . $target['ip'] . ']';
            $opts[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $ip];
        }

        curl_setopt_array($ch, $opts);
        @set_time_limit($this->timeout + 60);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        fclose($out);

        if ($state['too_large']) {
            throw new \InvalidArgumentException('The file is larger than the allowed ' . round($this->max_size / 1048576) . ' MB');
        }
        if ($errno !== 0) {
            throw new \InvalidArgumentException($this->curlMessage($errno));
        }

        return $state;
    }

    protected function curlMessage(int $errno): string
    {
        switch ($errno) {
            case 6:
                return 'Could not find that host';
            case 7:
                return 'Could not connect to that server';
            case 28:
                return 'The download took too long';
            case 42:
                return 'Download cancelled';
            case 35: case 51: case 58: case 60: case 77: case 83:
                return 'Could not verify the secure connection to that server';
            default:
                return 'Download failed';
        }
    }

    /** Turn a Location header into an absolute URL. */
    protected function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (strpos($location, '//') === 0) {
            return $p['scheme'] . ':' . $location;
        }
        if ($location[0] === '/') {
            return $origin . $location;
        }
        $dir = rtrim(substr($p['path'] ?? '/', 0, strrpos($p['path'] ?? '/', '/') + 1), '/');

        return $origin . $dir . '/' . $location;
    }

    protected function nameFor(string $url, string $disposition): string
    {
        $name = '';
        if (preg_match("/filename\\*\\s*=\\s*[^']*'[^']*'([^;]+)/i", $disposition, $m)) {
            $name = rawurldecode(trim($m[1], " \t\"'"));
        } elseif (preg_match('/filename\s*=\s*("([^"]*)"|[^;]+)/i', $disposition, $m)) {
            $name = trim($m[2] !== '' ? $m[2] : $m[1], " \t\"'");
        }
        if ($name === '') {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $name = rawurldecode(basename($path));
        }

        return self::cleanFileName($name);
    }

    /** A safe single file name: no folders, no control characters, no leading dots. */
    public static function cleanFileName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $name);
        $name = ltrim(trim($name), '.');
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 200) : substr($name, 0, 200);
        $name = rtrim($name, ". ");

        return $name === '' ? 'download' : $name;
    }

    /** True only for addresses on the public internet. */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $bin = inet_pton($ip);

        if (strlen($bin) === 16) {
            // IPv6 forms that carry an IPv4 address: judge the IPv4 address instead
            if (strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {          // ::ffff:a.b.c.d
                return self::isPublicIp(inet_ntop(substr($bin, 12)));
            }
            if (strncmp($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8), 12) === 0) {   // 64:ff9b::/96 NAT64
                return self::isPublicIp(inet_ntop(substr($bin, 12)));
            }
            if (strncmp($bin, "\x20\x02", 2) === 0) {                                   // 2002::/16 6to4
                return self::isPublicIp(inet_ntop(substr($bin, 2, 4)));
            }
            foreach (['2001:0000::/32', '2001:db8::/32', 'ff00::/8', 'fec0::/10', '100::/64', '::/128', '::1/128'] as $cidr) {
                if (self::inCidr($bin, $cidr)) {
                    return false;
                }
            }
        } else {
            foreach (['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3'] as $cidr) {
                if (self::inCidr($bin, $cidr)) {
                    return false;
                }
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    protected static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netbin = inet_pton($net);
        if (strlen($netbin) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes && strncmp($bin, $netbin, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;

        return (ord($bin[$bytes]) & $mask) === (ord($netbin[$bytes]) & $mask);
    }
}
