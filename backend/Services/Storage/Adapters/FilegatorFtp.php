<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Storage\Adapters;

use League\Flysystem\Adapter\Ftp;
use League\Flysystem\NotSupportedException;

class FilegatorFtp extends Ftp
{
    /** @var array<string, true> folders this connection has already found or made: asking again costs a round trip per file */
    protected $known_dirs = [];

    public function ensureDirectory($dirname)
    {
        if (isset($this->known_dirs[$dirname])) {
            return;
        }
        parent::ensureDirectory($dirname);
        $this->known_dirs[$dirname] = true;
    }

    public function deleteDir($dirname)
    {
        $this->known_dirs = [];

        return parent::deleteDir($dirname);
    }

    /**
     * One DELE and nothing else. Flysystem's delete() first asks whether the file is there (a CWD attempt and a LIST, which opens a
     * new data connection): four round trips per file where one does. False when it failed or the file was not there.
     */
    public function deleteNow($path): bool
    {
        return @ftp_delete($this->getConnection(), $path);
    }

    /** One RMD of a folder expected to be empty; false when it is not empty or not there (deleteDir() then lists and empties it). */
    public function removeEmptyDir($dirname): bool
    {
        $this->known_dirs = [];

        return @ftp_rmdir($this->getConnection(), $dirname);
    }


    /**
     * Normalize a file entry.
     *
     * @param string $item
     * @param string $base
     *
     * @return array normalized file array
     *
     * @throws NotSupportedException
     */
    protected function normalizeObject($item, $base)
    {
        $systemType = $this->systemType ?: $this->detectSystemType($item);

        if ($systemType === 'unix') {
            $result = $this->normalizeUnixObject($item, $base);
            return $this->afterNormalizeUnixObject($result, $item, $base);
        } elseif ($systemType === 'windows') {
            $result = $this->normalizeWindowsObject($item, $base);
            return $this->afterNormalizeWindowsObject($result, $item, $base);
        }

        throw NotSupportedException::forFtpSystemType($systemType);
    }

    /**
     * Normalize a Unix file entry, with permissions.
     *
     * Given $item contains:
     *    '-rw-r--r--   1 ftp      ftp           409 Aug 19 09:01 file1.txt'
     *
     * This function will return:
     * [
     *   'type' => 'file',
     *   'path' => 'file1.txt',
     *   'visibility' => 'public',
     *   'size' => 409,
     *   'timestamp' => 1566205260,
     *   'permissions' => 644
     * ]
     *
     * @param array $result original normalized file array
     * @param string $item
     * @param string $base
     *
     * @return array normalized file array
     */
    protected function afterNormalizeUnixObject($result, $item, $base)
    {
        $item = preg_replace('#\s+#', ' ', trim($item), 7);
        [$permissions, /* $number */, /* $owner */, /* $group */, $size, $month, $day, $timeOrYear, $name] = explode(' ', $item, 9);
        $permissions = $this->normalizePermissions($permissions);

        $result['permissions'] = decoct($permissions);
        return $result;
    }

    /**
     * Normalize a Windows/DOS file entry, with permissions.
     *
     * @param array $result original normalized file array
     * @param string $item
     * @param string $base
     *
     * @return array normalized file array
     */
    protected function afterNormalizeWindowsObject($result, $item, $base)
    {
        $result['permissions'] = 777;
        return $result;
    }

}
