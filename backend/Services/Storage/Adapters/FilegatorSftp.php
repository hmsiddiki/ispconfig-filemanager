<?php

namespace Filegator\Services\Storage\Adapters;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use League\Flysystem\Util\MimeType;
use phpseclib3\Net\SFTP;

/**
 * Flysystem 1.x adapter for SFTP on phpseclib 3 (modern key exchange, ed25519 host keys, patched).
 * Connects on first use. Paths are relative to the login folder unless a root is given.
 */
class FilegatorSftp extends AbstractAdapter
{
    use StreamedCopyTrait;

    // phpseclib "type" values from rawlist()
    protected const TYPE_FILE = 1;
    protected const TYPE_DIR = 2;
    protected const TYPE_SYMLINK = 3;

    protected $config;

    /** @var SFTP|null */
    protected $sftp;

    public function __construct(array $config)
    {
        $this->config = $config + ['port' => 22, 'timeout' => 15, 'root' => '', 'permPublic' => 0644, 'permPrivate' => 0600, 'dirPublic' => 0755];
        $root = trim((string) $this->config['root']);
        if ($root !== '') {
            $this->setPathPrefix(rtrim($root, '/') . '/');
        }
    }

    /** @throws \RuntimeException */
    public function getConnection(): SFTP
    {
        if ($this->sftp && $this->sftp->isConnected()) {
            return $this->sftp;
        }

        $sftp = new SFTP($this->config['host'], (int) $this->config['port'], (int) $this->config['timeout']);
        if (! $sftp->login($this->config['username'], $this->config['password'])) {
            throw new \RuntimeException('SFTP login failed');
        }

        return $this->sftp = $sftp;
    }

    public function disconnect(): void
    {
        if ($this->sftp) {
            $this->sftp->disconnect();
            $this->sftp = null;
        }
    }

    public function write($path, $contents, Config $config)
    {
        return $this->put($path, $contents);
    }

    public function writeStream($path, $resource, Config $config)
    {
        return $this->put($path, $resource);
    }

    public function update($path, $contents, Config $config)
    {
        return $this->put($path, $contents);
    }

    public function updateStream($path, $resource, Config $config)
    {
        return $this->put($path, $resource);
    }

    public function rename($path, $newpath)
    {
        return $this->getConnection()->rename($this->full($path), $this->full($newpath));
    }

    public function delete($path)
    {
        return $this->getConnection()->delete($this->full($path), false);
    }

    public function deleteDir($dirname)
    {
        return $this->getConnection()->delete($this->full($dirname), true);
    }

    public function createDir($dirname, Config $config)
    {
        return $this->getConnection()->mkdir($this->full($dirname), -1, true)
            ? ['type' => 'dir', 'path' => $dirname]
            : false;
    }

    public function setVisibility($path, $visibility)
    {
        $mode = $visibility === 'public' ? $this->config['permPublic'] : $this->config['permPrivate'];

        return $this->getConnection()->chmod($mode, $this->full($path)) ? compact('path', 'visibility') : false;
    }

    public function has($path)
    {
        return $this->getMetadata($path) !== false;
    }

    public function read($path)
    {
        $contents = $this->getConnection()->get($this->full($path));

        return $contents === false ? false : compact('contents', 'path');
    }

    public function readStream($path)
    {
        $stream = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
        if (! $this->getConnection()->get($this->full($path), $stream)) {
            fclose($stream);

            return false;
        }
        rewind($stream);

        return compact('stream', 'path');
    }

    public function listContents($directory = '', $recursive = false)
    {
        $list = $this->getConnection()->rawlist($this->full($directory) ?: '.');
        if (! is_array($list)) {
            return [];
        }

        $result = [];
        foreach ($list as $name => $attrs) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = ltrim(($directory === '' ? '' : rtrim($directory, '/') . '/') . $name, '/');
            $entry = $this->normalize($path, $attrs);
            $result[] = $entry;

            if ($recursive && $entry['type'] === 'dir') {
                $result = array_merge($result, $this->listContents($path, true));
            }
        }

        return $result;
    }

    public function getMetadata($path)
    {
        $attrs = $this->getConnection()->stat($this->full($path) ?: '.');

        return is_array($attrs) ? $this->normalize($path, $attrs) : false;
    }

    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    public function getMimetype($path)
    {
        return ['path' => $path, 'type' => 'file', 'mimetype' => MimeType::detectByFilename($path) ?: 'application/octet-stream'];
    }

    public function getVisibility($path)
    {
        $meta = $this->getMetadata($path);

        return $meta ? ['path' => $path, 'visibility' => $meta['visibility']] : false;
    }

    /** Used by FileGator's "permissions" dialog: octal mode such as 755. */
    public function chmodPath(string $path, int $mode): bool
    {
        return (bool) $this->getConnection()->chmod($mode, $this->full($path));
    }

    protected function put(string $path, $data)
    {
        $full = $this->full($path);
        if (! $this->getConnection()->put($full, $data)) {
            return false;
        }

        return ['type' => 'file', 'path' => $path, 'visibility' => 'public'];
    }

    /** Remote path for a relative one: root prefix applied, never empty. */
    protected function full(string $path): string
    {
        return $this->applyPathPrefix(ltrim($path, '/')) ?? '';
    }

    protected function normalize(string $path, array $attrs): array
    {
        $type = $attrs['type'] ?? self::TYPE_FILE;
        if ($type === self::TYPE_SYMLINK) {
            $type = $this->getConnection()->is_dir($this->full($path)) ? self::TYPE_DIR : self::TYPE_FILE;
        }

        $perms = (int) ($attrs['permissions'] ?? 0);
        $entry = [
            'type' => $type === self::TYPE_DIR ? 'dir' : 'file',
            'path' => $path,
            'dirname' => Util::dirname($path),
            'timestamp' => (int) ($attrs['mtime'] ?? 0),
            'visibility' => ($perms & 0044) ? 'public' : 'private',
            'permissions' => substr(sprintf('%o', $perms), -3),
        ];
        if ($entry['type'] === 'file') {
            $entry['size'] = (int) ($attrs['size'] ?? 0);
        }

        return $entry;
    }
}
