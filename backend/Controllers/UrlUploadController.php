<?php

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Remote\UrlFetcher;
use Filegator\Services\Remote\UrlJobs;
use Filegator\Services\Session\SessionStorageInterface as Session;
use Filegator\Services\Storage\Filesystem;

/**
 * "Upload from URL": the server downloads a file from an address the user typed and stores it in the current folder.
 *
 * The browser starts a download with fetch() under an id it made up, then polls progress() with the same id
 * while that request is still running. The outcome is also written to the progress file, so the result is
 * known even if the first request is cut off by a proxy timeout.
 *
 * The address rules live in UrlFetcher (public internet only, size and time limits).
 * Concurrency: max_concurrent_per_user (default 1) and max_concurrent_total (default 3).
 */
class UrlUploadController
{
    protected $config;

    protected $session;

    protected $storage;

    protected $logger;

    protected $jobs;

    public function __construct(Config $config, Session $session, AuthInterface $auth, Filesystem $storage, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->session = $session;
        $this->logger = $logger;

        $user = $auth->user() ?: $auth->getGuest();
        $this->storage = $storage;
        $this->storage->setPathPrefix($user->getHomeDir());

        $this->jobs = new UrlJobs($user->getUsername() . '|' . $user->getName());
    }

    public function fetch(Request $request, Response $response)
    {
        $options = (array) $this->config->get('url_upload', []);
        if (empty($options['enabled'])) {
            return $response->json('Not allowed', 403);
        }

        $id = (string) $request->input('id');
        if (! UrlJobs::validId($id)) {
            return $response->json('Invalid request', 422);
        }

        $url = (string) $request->input('url');
        $dir = (string) $request->input('dir', $this->session->get(FileController::SESSION_CWD, $this->storage->getSeparator()));
        $name = trim((string) $request->input('name', ''));
        $host = (string) parse_url($url, PHP_URL_HOST);

        // a long download must not keep the session file locked, or every other request of this user waits for it
        $this->session->save();

        $user_slot = $this->jobs->acquire('user-' . $this->jobs->userKey(), (int) ($options['max_concurrent_per_user'] ?? 1));
        if (! $user_slot) {
            return $response->json('A download is already running. Wait for it to finish or cancel it.', 429);
        }
        $total_slot = $this->jobs->acquire('all', (int) ($options['max_concurrent_total'] ?? 3));
        if (! $total_slot) {
            $this->jobs->release($user_slot);

            return $response->json('The server is busy with other downloads. Try again in a moment.', 429);
        }

        // finish the job even if the browser gives up waiting (closed tab, proxy timeout)
        ignore_user_abort(true);
        $this->jobs->cleanup();
        $this->jobs->write($id, ['state' => 'downloading', 'bytes' => 0, 'total' => 0]);

        $tmp = null;
        try {
            $last_write = 0.0;
            $file = (new UrlFetcher($options))->fetch($url, function (int $bytes, int $total) use ($id, &$last_write) {
                if ($this->jobs->cancelRequested($id)) {
                    return false;
                }
                // a status write per progress tick would be a write per few KB: twice a second is plenty
                if (microtime(true) - $last_write >= 0.5) {
                    $last_write = microtime(true);
                    $this->jobs->write($id, ['state' => 'downloading', 'bytes' => $bytes, 'total' => $total]);
                }

                return true;
            });
            $tmp = $file['path'];

            $final_name = $name !== '' ? UrlFetcher::cleanFileName($name) : $file['name'];
            $this->jobs->write($id, ['state' => 'saving', 'bytes' => $file['size'], 'total' => $file['size'], 'name' => $final_name]);

            $stream = fopen($tmp, 'rb');
            try {
                $success = $this->storage->store($dir, $final_name, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $success) {
                throw new \InvalidArgumentException('Unable to save file');
            }

            $this->logger->log('URL upload: ' . $host . ' -> ' . $final_name . ' (' . $file['size'] . ' bytes)');
            $this->jobs->write($id, ['state' => 'done', 'bytes' => $file['size'], 'total' => $file['size'], 'name' => $final_name]);

            return $response->json('Done');
        } catch (\InvalidArgumentException $e) {
            $cancelled = $e->getMessage() === 'Download cancelled';
            $this->logger->log('URL upload ' . ($cancelled ? 'cancelled' : 'refused: ' . $e->getMessage()) . ' (' . $host . ')');
            $this->jobs->write($id, ['state' => $cancelled ? 'cancelled' : 'error', 'message' => $e->getMessage()]);

            return $response->json($e->getMessage(), 422);
        } catch (\Throwable $e) {
            // storage failures can carry host names or paths: log them, show something generic
            $this->logger->log('URL upload failed: ' . get_class($e) . ' ' . $e->getMessage());
            $this->jobs->write($id, ['state' => 'error', 'message' => 'Could not save the file to the server']);

            return $response->json('Could not save the file to the server', 422);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            $this->jobs->release($total_slot);
            $this->jobs->release($user_slot);
        }
    }

    public function progress(Request $request, Response $response)
    {
        $state = $this->jobs->read((string) $request->input('id'));

        return $response->json($state ?: ['state' => 'unknown']);
    }

    public function cancel(Request $request, Response $response)
    {
        $this->jobs->requestCancel((string) $request->input('id'));

        return $response->json('Done');
    }
}
