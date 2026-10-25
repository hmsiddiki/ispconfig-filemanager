<?php

namespace Filegator\Services\Auth\Adapters;

use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Auth\ConnectionAuthInterface;
use Filegator\Services\Auth\User;
use Filegator\Services\Auth\UsersCollection;
use Filegator\Services\Remote\RemoteConnection;
use Filegator\Services\Remote\RemoteSession;
use Filegator\Services\Service;
use Filegator\Services\Session\SessionStorageInterface as Session;

/**
 * Whoever can open an FTP / SFTP connection is logged in, with the rights that server gives them.
 * There is no user list here: the remote server is the only authority.
 */
class RemoteAuth implements Service, AuthInterface, ConnectionAuthInterface
{
    public const SESSION_USER = 'remote_user';

    protected $session;

    protected $remote;

    protected $allowed_hosts = ['127.0.0.1', 'localhost', '::1'];

    protected $allowed_protocols = RemoteConnection::PROTOCOLS;

    protected $error = 'Connection failed';

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function init(array $config = [])
    {
        $this->remote = new RemoteSession($this->session, $config['key_file']);
        $this->allowed_hosts = $config['allowed_hosts'] ?? $this->allowed_hosts;
        $this->allowed_protocols = $config['allowed_protocols'] ?? $this->allowed_protocols;
    }

    public function connect(array $input): bool
    {
        try {
            $connection = RemoteConnection::fromInput($input, $this->allowed_hosts, $this->allowed_protocols);
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return false;
        }

        try {
            $connection->test();
        } catch (\Throwable $e) {
            // The raw error can name internal hosts or leak banner text: keep it generic for the user.
            $this->error = 'Could not connect or log in. Check the host, port, username and password.';

            return false;
        }

        $this->remote->save($connection);
        $this->session->set(self::SESSION_USER, ['username' => $connection->username, 'name' => $connection->label()]);
        $this->session->migrate(true);

        return true;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function user(): ?User
    {
        $data = $this->session->get(self::SESSION_USER);
        if (! is_array($data) || ! $this->remote->load()) {
            return null;
        }

        $user = new User();
        $user->setUsername($data['username']);
        $user->setName($data['name']);
        $user->setRole('user');
        $user->setHomedir('/');
        $user->setPermissions(['read', 'write', 'upload', 'download', 'batchdownload', 'zip', 'chmod']);

        return $user;
    }

    public function authenticate($username, $password): bool
    {
        return false; // this backend only logs in through connect()
    }

    public function forget()
    {
        return $this->session->invalidate();
    }

    public function find($username): ?User
    {
        return null;
    }

    public function store(User $user)
    {
        return $this->session->set(self::SESSION_USER, ['username' => $user->getUsername(), 'name' => $user->getName()]);
    }

    public function update($username, User $user, $password = ''): User
    {
        throw new \Exception('Not supported: the account lives on the remote server');
    }

    public function add(User $user, $password): User
    {
        throw new \Exception('Not supported: the account lives on the remote server');
    }

    public function delete(User $user)
    {
        throw new \Exception('Not supported: the account lives on the remote server');
    }

    public function getGuest(): User
    {
        $guest = new User();
        $guest->setUsername('guest');
        $guest->setName('Guest');
        $guest->setRole('guest');
        $guest->setHomedir('/');
        $guest->setPermissions([]);

        return $guest;
    }

    public function allUsers(): UsersCollection
    {
        return new UsersCollection();
    }
}
