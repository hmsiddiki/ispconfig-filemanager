<?php

namespace Filegator\Controllers;

use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Storage\DeleteJob;
use Filegator\Services\Storage\TransferJob;

/**
 * "Copy" and "Move" as background jobs, the same way as delete, zip and unzip.
 *
 * start() answers at once with a job id and hands the work to a worker process (backend/Workers/transfer.php). Inside the browser's request a
 * large copy over FTP (a download and an upload per file) was cut off by the web server's time limit or by leaving or reloading the page.
 * Progress and cancel go through the shared /unzipjob/status and /unzipjob/cancel.
 */
class TransferJobController extends BackgroundJobController
{
    /** At most this many copies and moves of one website wait or run at the same time (they run one after the other). */
    public const MAX_ACTIVE = 5;

    public function start(Request $request, Response $response)
    {
        $kind = (string) $request->input('kind', '');
        $destination = (string) $request->input('destination', '');
        $folder = (string) $request->input('folder', $this->storage->getSeparator());

        $items = [];
        foreach ((array) $request->input('items', []) as $item) {
            $item = (array) $item;
            $path = (string) ($item['path'] ?? '');
            $type = (string) ($item['type'] ?? '');
            if (trim($path, '/\\') !== '' && in_array($type, ['file', 'dir'], true)) {
                $items[] = ['path' => $path, 'type' => $type];
            }
        }

        $blob = $this->loginBlob();
        // the session lock must not be held while the job is checked and started, or the user's other requests wait for it
        $this->session->save();

        if ($blob === null) {
            return $response->json('Sign in again', 401);
        }
        if (! in_array($kind, TransferJob::KINDS, true) || TransferJob::clean($destination) === null) {
            return $response->json('Nothing to do', 422);
        }
        if ($items === [] || count($items) > TransferJob::MAX_ITEMS) {
            return $response->json('Nothing to ' . $kind, 422);
        }
        if ($this->countActive('copy') + $this->countActive('move') >= self::MAX_ACTIVE) {
            return $response->json('Several copies or moves are already running. Wait for them to finish.', 429);
        }

        $id = bin2hex(random_bytes(12));
        $name = DeleteJob::label($items);
        $this->jobs->cleanup(86400);
        $this->jobs->write($id, ['kind' => $kind, 'state' => 'queued', 'name' => $name, 'started' => time(), 'destination' => $destination,
            'folder' => $folder, 'paths' => DeleteJob::paths($items)]);

        $payload = $this->basePayload($id, $blob) + ['kind' => $kind, 'items' => $items, 'destination' => $destination, 'folder' => $folder, 'name' => $name];
        $file = $this->jobs->putPayload($id, $payload);
        $this->logger->log(ucfirst($kind) . ' started to ' . $destination . ': ' . implode(', ', array_slice(array_column($items, 'path'), 0, 20)) . (count($items) > 20 ? ' ...' : ''));

        if ($this->spawn('transfer.php', $file)) {
            return $response->json(['id' => $id]);
        }

        $this->jobs->takePayload($id);
        $this->runHere($response, ['id' => $id], function () use ($id, $kind, $items, $destination, $folder) {
            (new TransferJob($this->storage, $this->jobs))->run($id, $kind, $items, $destination, $folder);
        });

        return $response->json(['id' => $id]);
    }
}
