<?php

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Archiver\UnzipJob;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Remote\RemoteSession;
use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Session\SessionStorageInterface as Session;
use Filegator\Services\Storage\Filesystem;

/**
 * What the background jobs (unzip, zip) share: one status file per job, one status list the browser polls for all of them, cancel, and
 * starting the worker process that does the work outside the browser's request.
 *
 * A job never holds the user's session: start() releases it before the work begins, so the user's other requests (and a reload of the page)
 * are answered while the job runs.
 */
abstract class BackgroundJobController
{
    protected $config;

    protected $session;

    protected $storage;

    protected $logger;

    protected $user;

    protected $jobs;

    public function __construct(Config $config, Session $session, AuthInterface $auth, Filesystem $storage, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->session = $session;
        $this->logger = $logger;

        $this->user = $auth->user() ?: $auth->getGuest();
        $this->storage = $storage;
        $this->storage->setPathPrefix($this->user->getHomeDir());

        $this->jobs = new UrlJobs($this->userId(), $this->workDir() . DIRECTORY_SEPARATOR . 'jobs');
    }

    /** The user's recent jobs of every kind (newest first). "dismiss" lists finished jobs the browser has shown, so they stop coming back. */
    public function status(Request $request, Response $response)
    {
        // polling must never wait for, or hold, the session
        $this->session->save();

        foreach ((array) $request->input('dismiss', []) as $id) {
            $state = is_string($id) ? $this->jobs->read($id) : null;
            if ($state !== null && ! UnzipJob::isActive(UnzipJob::present($state))) {
                $this->jobs->forget($id);
            }
        }

        $out = [];
        foreach ($this->jobs->all() as $id => $state) {
            $out[] = ['id' => $id] + UnzipJob::present($state);
        }

        return $response->json($out);
    }

    public function cancel(Request $request, Response $response)
    {
        $this->session->save();
        $this->jobs->requestCancel((string) $request->input('id'));

        return $response->json('Done');
    }

    /** Is a job of this kind ('zip' / 'unzip') already running for this website? */
    protected function hasActive(string $kind): bool
    {
        foreach ($this->jobs->all() as $state) {
            $state = UnzipJob::present($state);
            if (UnzipJob::isActive($state) && UnzipJob::kindOf($state) === $kind) {
                return true;
            }
        }

        return false;
    }

    /** The encrypted FTP / SFTP login of this session, which the worker uses to reach the storage. Null when signed out. */
    protected function loginBlob(): ?string
    {
        $blob = $this->session->get(RemoteSession::SESSION_KEY);

        return is_string($blob) && $blob !== '' ? $blob : null;
    }

    /** What every worker needs besides its own fields. */
    protected function basePayload(string $id, string $blob): array
    {
        return [
            'id' => $id,
            'user' => $this->userId(),
            'jobs_dir' => $this->workDir() . DIRECTORY_SEPARATOR . 'jobs',
            'tmp_dir' => $this->workDir() . DIRECTORY_SEPARATOR . 'tmp',
            'blob' => $blob,
            'key_file' => $this->keyFile(),
            'home' => $this->user->getHomeDir(),
        ];
    }

    /**
     * Start backend/Workers/$worker detached from this request. Returns false when the server does not let this process start programs.
     */
    protected function spawn(string $worker, string $payload_file): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (! function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return false;
        }

        $windows = DIRECTORY_SEPARATOR === '\\';
        $script = dirname(__DIR__) . '/Workers/' . $worker;
        $php = (string) ($this->config->get('unzip_php_cli') ?: $this->phpCli());
        $null = $windows ? 'NUL' : '/dev/null';

        if ($windows) {
            $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($payload_file);
        } else {
            // setsid puts the worker in a session of its own, so nothing that ends the web request or its process group reaches it
            $cmd = 'exec setsid ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($payload_file) . ' >/dev/null 2>&1 </dev/null &';
        }

        $process = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (! is_resource($process)) {
            return false;
        }
        // the shell returns at once (the worker is in the background); this reaps it without waiting for the worker
        proc_close($process);

        // the worker deletes the handover file the moment it starts: if it is still there a moment later, nothing started
        for ($i = 0; $i < 20; $i++) {
            usleep(100000);
            if (! is_file($payload_file)) {
                return true;
            }
        }
        @unlink($payload_file);

        return false;
    }

    /**
     * No separate process could be started: run the job in this request. The browser polls the same status file, and leaving does not stop it.
     * Under PHP-FPM the answer goes out first, so the page is not left waiting for the whole job.
     */
    protected function runHere(Response $response, array $answer, callable $job): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);
        if (function_exists('fastcgi_finish_request')) {
            $response->json($answer);
            $response->send();
            fastcgi_finish_request();
        }
        $job();
    }

    /** The command-line PHP: under FPM, PHP_BINARY is the FPM program, which cannot run scripts. */
    protected function phpCli(): string
    {
        $name = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
        foreach ([PHP_BINDIR . DIRECTORY_SEPARATOR . $name, dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $name] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return $name;
    }

    /**
     * Whose jobs these are. Opening the file manager from the panel makes a new temporary FTP login (a new random username) every time, so the
     * username cannot be part of this: the name is the website, which stays the same across reloads and new tabs.
     */
    protected function userId(): string
    {
        $name = trim((string) $this->user->getName());

        return $name !== '' ? $name : $this->user->getUsername();
    }

    /** Where jobs and the temporary files live: next to the app's other temporary files. (The folder is called "unzip" for older installs.) */
    protected function workDir(): string
    {
        $services = (array) $this->config->get('services');
        $tmp = (array) ($services['Filegator\Services\Tmpfs\TmpfsInterface']['config'] ?? []);

        return rtrim((string) ($tmp['path'] ?? sys_get_temp_dir()), '/\\') . DIRECTORY_SEPARATOR . 'unzip';
    }

    protected function keyFile(): string
    {
        $services = (array) $this->config->get('services');
        $fs = (array) ($services['Filegator\Services\Storage\Filesystem']['config'] ?? []);

        return (string) ($fs['key_file'] ?? '');
    }
}
