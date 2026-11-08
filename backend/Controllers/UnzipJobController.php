<?php

namespace Filegator\Controllers;

use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Archiver\UnzipJob;

/**
 * "Unzip" as a background job.
 *
 * start() answers at once with a job id and hands the work to a worker process (backend/Workers/unzip.php), so a page reload, a closed tab or a
 * proxy timeout cannot stop it. The browser polls status() (shared with the zip job, see BackgroundJobController), which also lists the user's
 * recent jobs: that is how a reloaded page finds the job again. Where a separate process cannot be started, the job runs inside the request
 * instead (still finishing when the browser leaves).
 */
class UnzipJobController extends BackgroundJobController
{
    public function start(Request $request, Response $response)
    {
        $source = (string) $request->input('item');
        $destination = (string) $request->input('destination', $this->storage->getSeparator());
        // a file that is already there: 'rename' (keep both, the new one gets " (1)") or 'overwrite' (replace it)
        $conflict = in_array((string) $request->input('conflict', 'rename'), UnzipJob::CONFLICTS, true) ? (string) $request->input('conflict') : 'rename';

        $blob = $this->loginBlob();
        // the session lock must not be held while the job is checked and started, or the user's other requests wait for it
        $this->session->save();

        if ($blob === null) {
            return $response->json('Sign in again', 401);
        }
        if ($source === '' || ! UnzipJob::isArchive($source) || ! $this->storage->fileExists($source)) {
            return $response->json('Not a supported archive', 422);
        }
        if ($this->hasActive('unzip')) {
            return $response->json('An unzip is already running. Wait for it to finish or cancel it.', 429);
        }

        $id = bin2hex(random_bytes(12));
        $this->jobs->cleanup(86400);
        $this->jobs->write($id, ['kind' => 'unzip', 'state' => 'queued', 'name' => basename($source), 'started' => time(), 'destination' => $destination, 'conflict' => $conflict]);

        $payload = $this->basePayload($id, $blob) + ['source' => $source, 'destination' => $destination, 'conflict' => $conflict];
        $file = $this->jobs->putPayload($id, $payload);
        $this->logger->log('Unzip started: ' . basename($source));

        if ($this->spawn('unzip.php', $file)) {
            return $response->json(['id' => $id]);
        }

        $this->jobs->takePayload($id);
        $this->runHere($response, ['id' => $id], function () use ($id, $payload, $source, $destination) {
            (new UnzipJob($this->storage, $this->jobs, $payload['tmp_dir']))->onConflict($conflict)->run($id, $source, $destination);
        });

        return $response->json(['id' => $id]);
    }
}
