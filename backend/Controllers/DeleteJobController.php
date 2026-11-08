<?php

namespace Filegator\Controllers;

use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Storage\DeleteJob;

/**
 * "Delete" as a background job, the same way as zip and unzip.
 *
 * start() answers at once with a job id and hands the work to a worker process (backend/Workers/delete.php): deleting a large folder over FTP
 * is one command per file, and inside the browser's request it was cut off by the web server's time limit or by leaving the page, which left
 * the folder half-deleted with no word about it. Progress is polled through the shared status().
 */
class DeleteJobController extends BackgroundJobController
{
    /** At most this many deletes of one website wait or run at the same time (they run one after the other). */
    public const MAX_ACTIVE = 5;

    public function start(Request $request, Response $response)
    {
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
        if ($items === [] || count($items) > DeleteJob::MAX_ITEMS) {
            return $response->json('Nothing to delete', 422);
        }
        if ($this->countActive('delete') >= self::MAX_ACTIVE) {
            return $response->json('Several deletes are already running. Wait for them to finish.', 429);
        }

        $id = bin2hex(random_bytes(12));
        $name = DeleteJob::label($items);
        $this->jobs->cleanup(86400);
        $this->jobs->write($id, ['kind' => 'delete', 'state' => 'queued', 'name' => $name, 'started' => time(), 'destination' => $folder,
            'paths' => DeleteJob::paths($items)]);

        $payload = $this->basePayload($id, $blob) + ['items' => $items, 'folder' => $folder, 'name' => $name];
        $file = $this->jobs->putPayload($id, $payload);
        $this->logger->log('Delete started: ' . implode(', ', array_slice(array_column($items, 'path'), 0, 20)) . (count($items) > 20 ? ' ...' : ''));

        if ($this->spawn('delete.php', $file)) {
            return $response->json(['id' => $id]);
        }

        $this->jobs->takePayload($id);
        $this->runHere($response, ['id' => $id], function () use ($id, $items, $folder) {
            (new DeleteJob($this->storage, $this->jobs))->run($id, $items, $folder);
        });

        return $response->json(['id' => $id]);
    }
}
