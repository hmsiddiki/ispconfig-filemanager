<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Storage;

use Filegator\Services\Service;
use League\Flysystem\Config;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Util;

class Filesystem implements Service
{
    protected $separator;

    protected $storage;

    protected $path_prefix;

    public function init(array $config = [])
    {
        $this->separator = $config['separator'];
        $this->path_prefix = $this->separator;

        $adapter = $config['adapter'];
        $config = $config['config'] ?? [];

        $this->storage = new Flysystem($adapter(), $config);
    }

    /**
     * A new folder named $name in $path; a name that is taken gets a number ("photos (1)"). Taken is asked with has() (one question
     * to the storage). It used to list the whole existing folder recursively, which over FTP took minutes for a large folder.
     */
    public function createDir(string $path, string $name)
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->createDir($destination);
    }

    /**
     * Make sure the folder $path exists, exactly under that name: an existing folder is used as it is, a missing one is created
     * (with any missing parents). Never renames. For unzipping, where the archive's folders must land where the archive says.
     */
    public function ensureDirectory(string $path): bool
    {
        $destination = Util::normalizePath($this->applyPathPrefix($path));
        if ($destination === '' || $this->storage->has($destination)) {
            return true;
        }

        return (bool) $this->storage->createDir($destination);
    }

    public function createFile(string $path, string $name)
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        $this->storage->put($destination, '');
    }

    public function fileExists(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->storage->has($path);
    }

    public function isDir(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->storage->getSize($path) === false;
    }

    public function copyFile(string $source, string $destination)
    {
        $source = $this->applyPathPrefix($source);
        $destination = $this->joinPaths($this->applyPathPrefix($destination), $this->getBaseName($source));

        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->copy($source, $destination);
    }

    public function copyDir(string $source, string $destination)
    {
        $source = $this->applyPathPrefix($this->addSeparators($source));
        $destination = $this->applyPathPrefix($this->addSeparators($destination));
        $source_dir = $this->getBaseName($source);
        $real_destination = $this->joinPaths($destination, $source_dir);

        while (! empty($this->storage->listContents($real_destination, true))) {
            $real_destination = $this->upcountName($real_destination);
        }

        $contents = $this->storage->listContents($source, true);

        if (empty($contents)) {
            $this->storage->createDir($real_destination);
        }

        foreach ($contents as $file) {
            $source_path = $this->separator . ltrim($file['path'], $this->separator);
            $path = substr($source_path, strlen($source), strlen($source_path));

            if ($file['type'] == 'dir') {
                $this->storage->createDir($this->joinPaths($real_destination, $path));

                continue;
            }

            if ($file['type'] == 'file') {
                $this->storage->copy($file['path'], $this->joinPaths($real_destination, $path));
            }
        }
    }

    public function deleteDir(string $path)
    {
        return $this->storage->deleteDir($this->applyPathPrefix($path));
    }

    public function deleteFile(string $path)
    {
        return $this->storage->delete($this->applyPathPrefix($path));
    }

    public function readStream(string $path): array
    {
        if ($this->isDir($path)) {
            throw new \Exception('Cannot stream directory');
        }

        $path = $this->applyPathPrefix($path);

        return [
            'filename' => $this->getBaseName($path),
            'stream' => $this->storage->readStream($path),
            'filesize' => $this->storage->getSize($path),
        ];
    }

    /**
     * Read a file into a temporary stream for a long job (the background zip), without the two extra questions readStream() asks first
     * (is it a folder? how big is it?): over FTP each of those is a round trip, and a site has thousands of files.
     * Over FTP the download is non-blocking, so $tick(bytes so far) is called while a large file comes in: the job can report progress
     * and stop when cancelled (an exception thrown from $tick ends the transfer). Returns a rewound stream; the caller closes it.
     *
     * @param callable|null $tick function (int $bytes): void
     * @return resource
     */
    public function readStreamForJob(string $path, ?callable $tick = null)
    {
        $full = $this->applyPathPrefix($path);
        $adapter = $this->storage->getAdapter();

        if ($adapter instanceof \League\Flysystem\Adapter\Ftp && function_exists('ftp_nb_fget')) {
            $stream = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
            try {
                $connection = $adapter->getConnection();
                $status = ftp_nb_fget($connection, $stream, $full, FTP_BINARY);
                while ($status === FTP_MOREDATA) {
                    if ($tick !== null) {
                        $tick((int) ftell($stream));
                    }
                    $status = ftp_nb_continue($connection);
                }
                if ($status !== FTP_FINISHED) {
                    throw new \RuntimeException('Read failed');
                }
            } catch (\Throwable $e) {
                fclose($stream);
                if (isset($connection) && isset($status) && $status === FTP_MOREDATA) {
                    // a transfer stopped half-way leaves the control connection out of step: start a new one for what comes next
                    $adapter->disconnect();
                }
                throw $e;
            }
            rewind($stream);

            return $stream;
        }

        $result = $this->storage->getAdapter()->readStream($full);
        if (! is_array($result) || ! is_resource($result['stream'] ?? null)) {
            throw new \RuntimeException('Read failed');
        }

        return $result['stream'];
    }

    /**
     * store(), but answers with the name the file got (a taken name gets a number: "site (1).zip"), or null when the write failed.
     */
    public function storeUnique(string $path, string $name, $resource): ?string
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);
        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->putStream($destination, $resource) ? $this->getBaseName($destination) : null;
    }

    public function move(string $from, string $to): bool
    {
        $from = $this->applyPathPrefix($from);
        $to = $this->applyPathPrefix($to);

        while ($this->storage->has($to)) {
            $to = $this->upcountName($to);
        }

        return $this->storage->rename($from, $to);
    }

    public function rename(string $destination, string $from, string $to): bool
    {
        $from = $this->joinPaths($this->applyPathPrefix($destination), $from);
        $to = $this->joinPaths($this->applyPathPrefix($destination), $to);

        while ($this->storage->has($to)) {
            $to = $this->upcountName($to);
        }

        return $this->storage->rename($from, $to);
    }

    public function store(string $path, string $name, $resource, bool $overwrite = false): bool
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while ($this->storage->has($destination)) {
            if ($overwrite) {
                $this->storage->delete($destination);
            } else {
                $destination = $this->upcountName($destination);
            }
        }

        return $this->storage->putStream($destination, $resource);
    }

    /**
     * Store a file that is known not to exist yet (for example inside a folder that was just created), without looking for a clash first.
     * store() asks the storage whether the name is taken, and Flysystem asks again: over FTP each of those questions is a directory listing.
     * An existing file with this name would be overwritten, so only use it where that cannot happen.
     */
    public function storeNew(string $path, string $name, $resource): bool
    {
        $destination = Util::normalizePath($this->joinPaths($this->applyPathPrefix($path), $name));

        return (bool) $this->storage->getAdapter()->writeStream($destination, $resource, new Config());
    }

    /**
     * Write new contents into a file in place (creating it when missing).
     * Unlike store() this never deletes the old file first, so a failed write cannot lose it,
     * and the file keeps its permissions.
     */
    public function replace(string $path, string $name, $resource): bool
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        return $this->storage->putStream($destination, $resource);
    }

    /**
     * Change file permissions one item, with optional recursion
     *
     * @param string $path
     * @param int $permissions
     * @param null|'all'|'folders'|'files' $recursive
     * @return bool
     * @throws \Exception
     */
    public function chmod(string $path, int $permissions, string $recursive = null)
    {
        // block special permission bits (setuid, setgid, sticky)
        if ($permissions < 0 || $permissions > 777) {
            throw new \Exception('Invalid permission, must be between 0 and 777');
        }

        // ensure every digit is 0-7
        if (!preg_match('/^[0-7]{1,3}$/', (string) $permissions)) {
            throw new \Exception('Invalid permission value. Must be between 0 and 777.');
        }

        $path = $this->applyPathPrefix($path);
        $path = Util::normalizePath($path);
        $adapter = $this->storage->getAdapter();

        $mainResult = $this->chmodItem($path, $permissions);
        if ($recursive !== null) {
            if (method_exists($adapter, 'setRecurseManually')) {
                $adapter->setRecurseManually(true); // this is needed for ftp driver
            }
            $contents = $this->storage->listContents($path, true);
            foreach ($contents as $item) {
                try {
                    if ($item['type'] == 'dir' && ($recursive == 'all' || $recursive == 'folders')) {
                        $this->chmodItem($item['path'], $permissions);
                    }
                    if ($item['type'] == 'file' && ($recursive == 'all' || $recursive == 'files')) {
                        $this->chmodItem($item['path'], $permissions);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return $mainResult;
    }
    /**
     * Change file permissions for a single item
     *
     * @param string $path
     * @param int $permissions
     * @return bool
     * @throws \Exception
     */
    public function chmodItem(string $path, int $permissions)
    {
        $adapter = $this->storage->getAdapter();

        switch (get_class($adapter)) {
            case 'League\Flysystem\Adapter\Local':
                $absolutePath = $adapter->applyPathPrefix($path);
                return chmod($absolutePath, octdec($permissions));
                break;
            case 'League\Flysystem\Sftp\SftpAdapter':
                return $adapter->getConnection()->chmod($path, octdec($permissions));
                break;
            case 'Filegator\Services\Storage\Adapters\FilegatorSftp':
                return $adapter->chmodPath($path, octdec($permissions));
                break;
            case 'Filegator\Services\Storage\Adapters\FilegatorFtp':
                return ftp_chmod($adapter->getConnection(), octdec($permissions), $path) !== false;
                break;
            default:
                throw new \Exception('Selected adapter does not support unix permissions');
                break;
        }
    }

    public function setPathPrefix(string $path_prefix)
    {
        $this->path_prefix = $this->addSeparators($path_prefix);
    }

    public function getSeparator()
    {
        return $this->separator;
    }

    public function getPathPrefix(): string
    {
        return $this->path_prefix;
    }

    public function getDirectoryCollection(string $path, bool $recursive = false): DirectoryCollection
    {
        $collection = new DirectoryCollection($path);

        foreach ($this->storage->listContents($this->applyPathPrefix($path), $recursive) as $entry) {
            // By default only 'path' and 'type' is present

            $name = $this->getBaseName($entry['path']);
            $userpath = $this->stripPathPrefix($entry['path']);
            $dirname = $entry['dirname'] ?? $path;
            $size = $entry['size'] ?? 0;
            $timestamp = $entry['timestamp'] ?? 0;
            $permissions = $this->getPermissions($entry);

            $collection->addFile($entry['type'], $userpath, $name, $size, $timestamp, $permissions);
        }

        if (! $recursive && $this->addSeparators($path) !== $this->separator) {
            $collection->addFile('back', $this->getParent($path), '..', 0, 0, -1);
        }

        return $collection;
    }

    protected function getPermissions(array $entry): int
    {
        $adapter = $this->storage->getAdapter();
        $path = $entry['path'];

        switch (get_class($adapter)) {
            case 'League\Flysystem\Adapter\Local':
                $path = $adapter->applyPathPrefix($path); // get the full path
                $permissions = substr(sprintf('%o', fileperms($path)), -3);
                return $permissions;
                break;
            case 'League\Flysystem\Sftp\SftpAdapter':
                $stat = $adapter->getConnection()->stat($path);
                return $stat && isset($stat['permissions']) ? substr(decoct($stat['permissions']), -3) : -1;
                break;
            case 'Filegator\Services\Storage\Adapters\FilegatorFtp':
            case 'Filegator\Services\Storage\Adapters\FilegatorSftp':
                return $entry['permissions'] ?? -1;
                break;
        }
        return -1;
    }

    /**
     * The next free-looking name for a taken path: "photo.jpg" -> "photo (1).jpg", "photo (1).jpg" -> "photo (2).jpg", "HEAD" -> "HEAD (1)",
     * ".htaccess" -> ".htaccess (1)", "archive.tar.gz" -> "archive.tar (1).gz". Only the last part of the path (the file or folder
     * name) changes.
     *
     * It used to run one pattern over the whole path, where the "extension" (".something" at the end) could take in slashes: for a
     * file without an extension inside a folder with a dot ("qc/.git/HEAD") it matched ".git/HEAD" and wrote "qc/ (1).git/HEAD",
     * a new folder. Unzipping over a git checkout then made " (1).git", " (2).git" ... trees without end.
     */
    public function upcountName($name)
    {
        $name = (string) $name;
        $slash = strrpos($name, '/');
        $dir = $slash === false ? '' : substr($name, 0, $slash + 1);
        $base = $slash === false ? $name : substr($name, $slash + 1);

        // the extension is the last ".xxx" of the name, but a name that only starts with a dot (".htaccess", ".git") has none
        $dot = strrpos($base, '.');
        $ext = $dot !== false && $dot > 0 ? substr($base, $dot) : '';
        $stem = $ext !== '' ? substr($base, 0, $dot) : $base;

        $index = 1;
        if (preg_match('/^(.*) \((\d+)\)$/', $stem, $m)) {
            $stem = $m[1];
            $index = (int) $m[2] + 1;
        }

        return $dir . $stem . ' (' . $index . ')' . $ext;
    }

    private function applyPathPrefix(string $path): string
    {
        if ($path == '..'
            || strpos($path, '..' . $this->separator) !== false
            || strpos($path, $this->separator . '..') !== false
        ) {
            $path = $this->separator;
        }

        return $this->joinPaths($this->getPathPrefix(), $path);
    }

    private function stripPathPrefix(string $path): string
    {
        $path = $this->separator . ltrim($path, $this->separator);

        if (substr($path, 0, strlen($this->getPathPrefix())) == $this->getPathPrefix()) {
            $path = $this->separator . substr($path, strlen($this->getPathPrefix()));
        }

        return $path;
    }

    private function addSeparators(string $dir): string
    {
        if (! $dir || $dir == $this->separator || ! trim($dir, $this->separator)) {
            return $this->separator;
        }

        return $this->separator . trim($dir, $this->separator) . $this->separator;
    }

    private function joinPaths(string $path1, string $path2): string
    {
        $path1 = $this->escapeDots($path1);
        $path2 = $this->escapeDots($path2);

        if (! $path2 || ! trim($path2, $this->separator)) {
            return $this->addSeparators($path1);
        }

        return $this->addSeparators($path1) . ltrim($path2, $this->separator);
    }

    private function getParent(string $dir): string
    {
        if (! $dir || $dir == $this->separator || ! trim($dir, $this->separator)) {
            return $this->separator;
        }

        $tmp = explode($this->separator, trim($dir, $this->separator));
        array_pop($tmp);

        return $this->separator . trim(implode($this->separator, $tmp), $this->separator);
    }

    private function getBaseName(string $path): string
    {
        if (! $path || $path == $this->separator || ! trim($path, $this->separator)) {
            return $this->separator;
        }

        $tmp = explode($this->separator, trim($path, $this->separator));

        return  (string) array_pop($tmp);
    }

    private function escapeDots(string $path): string
    {
        $path = preg_replace('/\\\+\.{2,}/', '', $path);
        $path = preg_replace('/\.{2,}\\\+/', '', $path);
        $path = preg_replace('/\/+\.{2,}/', '', $path);
        $path = preg_replace('/\.{2,}\/+/', '', $path);
        $path = preg_replace('#(^|[\\\\/])\.\.($|[\\\\/])#', '$1$2', $path);

        return $path;
    }
}
