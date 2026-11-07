<?php

namespace Filegator\Controllers;

use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Archiver\ZipJob;

/**
 * "Zip" as a background job, the same way as the unzip.
 *
 * start() answers at once with a job id and hands the work to a worker process (backend/Workers/zip.php): zipping a whole website takes
 * minutes, and inside the browser's request it held the user's session the whole time (every other request of the file manager, including a
 * reload of the page, waited for it) and was cut off by the web server's time limit. Progress is polled through the shared status().
 */
class ZipJobController extends BackgroundJobController
{
    /** At most this many items can be picked for one zip (each is a file or a whole folder). */
    public const MAX_ITEMS = 5000;

    public function start(Request $request, Response $response)
    {
        $destination = (string) $request->input('destination', $this->storage->getSeparator());
        $name = ZipJob::cleanName((string) $request->input('name', (string) $this->config->get('frontend_config.default_archive_name')));

        $items = [];
        foreach ((array) $request->input('items', []) as $item) {
            $item = (array) $item;
            $path = (string) ($item['path'] ?? '');
            $type = (string) ($item['type'] ?? '');
            if ($path !== '' && in_array($type, ['file', 'dir'], true)) {
                $items[] = ['path' => $path, 'type' => $type, 'size' => (int) ($item['size'] ?? 0), 'time' => (int) ($item['time'] ?? 0)];
            }
        }

        $blob = $this->loginBlob();
        // the session lock must not be held while the job is checked and started, or the user's other requests wait for it
        $this->session->save();

        if ($blob === null) {
            return $response->json('Sign in again', 401);
        }
        if ($name === null) {
            return $response->json('Invalid name', 422);
        }
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            return $response->json('Nothing to zip', 422);
        }
        if ($this->hasActive('zip')) {
            return $response->json('A zip is already being made. Wait for it to finish or cancel it.', 429);
        }

        $id = bin2hex(random_bytes(12));
        $this->jobs->cleanup(86400);
        $this->jobs->write($id, ['kind' => 'zip', 'state' => 'queued', 'name' => $name, 'started' => time(), 'destination' => $destination]);

        $payload = $this->basePayload($id, $blob) + ['items' => $items, 'destination' => $destination, 'name' => $name];
        $file = $this->jobs->putPayload($id, $payload);
        $this->logger->log('Zip started: ' . $name);

        if ($this->spawn('zip.php', $file)) {
            return $response->json(['id' => $id]);
        }

        $this->jobs->takePayload($id);
        $this->runHere($response, ['id' => $id], function () use ($id, $payload, $items, $destination, $name) {
            (new ZipJob($this->storage, $this->jobs, $payload['tmp_dir']))->run($id, $items, $destination, $name);
        });

        return $response->json(['id' => $id]);
    }
}
