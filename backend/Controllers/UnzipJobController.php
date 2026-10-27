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
 * "Unzip" as a background job.
 *
 * start() answers at once with a job id and hands the work to a worker process (backend/Workers/unzip.php), so a page reload, a closed tab or a
 * proxy timeout cannot stop it. The browser polls status(), which also lists the user's recent jobs: that is how a reloaded page finds the job
 * again. Where a separate process cannot be started, the job runs inside the request instead (still finishing when the browser leaves).
 */
class UnzipJobController
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

    public function start(Request $request, Response $response)
    {
        $source = (string) $request->input('item');
        $destination = (string) $request->input('destination', $this->storage->getSeparator());

        if ($source === '' || ! UnzipJob::isArchive($source) || ! $this->storage->fileExists($source)) {
            return $response->json('Not a supported archive', 422);
        }

        foreach ($this->jobs->all() as $state) {
            if (UnzipJob::isActive(UnzipJob::present($state))) {
                return $response->json('An unzip is already running. Wait for it to finish or cancel it.', 429);
            }
        }

        $blob = $this->session->get(RemoteSession::SESSION_KEY);
        if (! is_string($blob) || $blob === '') {
            return $response->json('Sign in again', 401);
        }

        $id = bin2hex(random_bytes(12));
        $this->jobs->cleanup(86400);
        $this->jobs->write($id, ['state' => 'queued', 'name' => basename($source), 'started' => time(), 'destination' => $destination]);

        $payload = [
            'id' => $id,
            'user' => $this->userId(),
            'jobs_dir' => $this->workDir() . DIRECTORY_SEPARATOR . 'jobs',
            'tmp_dir' => $this->workDir() . DIRECTORY_SEPARATOR . 'tmp',
            'blob' => $blob,
            'key_file' => $this->keyFile(),
            'home' => $this->user->getHomeDir(),
            'source' => $source,
            'destination' => $destination,
        ];
        $file = $this->jobs->putPayload($id, $payload);
        $this->logger->log('Unzip started: ' . basename($source));

        // the session lock must not be held while the job runs, or the user's other requests wait for it
        $this->session->save();

        if ($this->spawn($file)) {
            return $response->json(['id' => $id]);
        }

        // no way to start a separate process here: run the job in this request; the browser polls the same status file
        ignore_user_abort(true);
        @set_time_limit(0);
        $this->jobs->takePayload($id);
        (new UnzipJob($this->storage, $this->jobs, $payload['tmp_dir']))->run($id, $source, $destination);

        return $response->json(['id' => $id]);
    }

    /** The user's recent jobs (newest first). "dismiss" lists finished jobs the browser has shown, so they stop coming back. */
    public function status(Request $request, Response $response)
    {
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
        $this->jobs->requestCancel((string) $request->input('id'));

        return $response->json('Done');
    }

    /** Start the worker detached from this request. Returns false when the server does not let this process start programs. */
    protected function spawn(string $payload_file): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (! function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return false;
        }

        $windows = DIRECTORY_SEPARATOR === '\\';
        $worker = dirname(__DIR__) . '/Workers/unzip.php';
        $php = (string) ($this->config->get('unzip_php_cli') ?: $this->phpCli());
        $null = $windows ? 'NUL' : '/dev/null';

        if ($windows) {
            $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($payload_file);
        } else {
            // setsid puts the worker in a session of its own, so nothing that ends the web request or its process group reaches it
            $cmd = 'exec setsid ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($payload_file) . ' >/dev/null 2>&1 </dev/null &';
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

    /** Where jobs and the temporary copies of archives live: next to the app's other temporary files. */
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
