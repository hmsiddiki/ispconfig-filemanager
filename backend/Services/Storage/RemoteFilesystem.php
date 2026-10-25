<?php

namespace Filegator\Services\Storage;

use Filegator\Services\Remote\RemoteSession;
use Filegator\Services\Session\SessionStorageInterface as Session;
use League\Flysystem\Adapter\NullAdapter;

/**
 * The stock Filesystem, but the storage behind it is the FTP / SFTP server this session logged in to.
 * Nothing is opened until the first file operation, and a session with no connection gets an empty, inert storage.
 */
class RemoteFilesystem extends Filesystem
{
    protected $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function init(array $config = [])
    {
        $connection = (new RemoteSession($this->session, $config['key_file']))->load();

        $config['adapter'] = function () use ($connection) {
            return $connection ? $connection->adapter() : new NullAdapter();
        };

        parent::init($config);
    }
}
