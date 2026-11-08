<?php

namespace Filegator\Services\Storage;

use Filegator\Services\Archiver\ProgressFilter;
use Filegator\Services\Remote\UrlJobs;

/**
 * The last step of a large upload: the browser has sent every piece and the server holds the whole file, which now has to be copied into the
 * website over its FTP / SFTP login. For a file of a gigabyte that takes a while, so it is done by a worker process of its own
 * (backend/Workers/upload.php) instead of the browser's last request: the page can be left as soon as the upload bar is full, and the web
 * server's time limit cannot cut the copy off. Progress ("saving: x / y") goes to the same status file the zip and unzip use.
 *
 * The server's copy is always removed at the end, whether the file was saved or not.
 */
class UploadJob
{
    protected $storage;

    protected $jobs;

    public function __construct(Filesystem $storage, UrlJobs $jobs)
    {
        $this->storage = $storage;
        $this->jobs = $jobs;
    }

    /**
     * Do the whole job. Never throws: the outcome is written to the status file.
     *
     * @param string $id          job id (UrlJobs::validId)
     * @param string $data_file   the complete uploaded file on the server's own disk (removed at the end)
     * @param string $destination folder on the storage
     * @param string $name        file name in that folder
     * @param bool   $overwrite   replace a file with the same name (otherwise the new one gets a number: "name (1).ext")
     */
    public function run(string $id, string $data_file, string $destination, string $name, bool $overwrite): void
    {
        $started = (int) ($this->jobs->read($id)['started'] ?? time());
        $size = (int) @filesize($data_file);
        $base = ['kind' => 'upload', 'name' => $name, 'started' => $started, 'destination' => $destination, 'size' => $size];

        try {
            ProgressFilter::register();
            $attempts = 0;
            while (true) {
                $this->jobs->write($id, $base + ['state' => 'storing', 'bytes' => 0]);
                $fp = @fopen($data_file, 'rb');
                if (! $fp) {
                    throw new \InvalidArgumentException('The uploaded file is no longer on the server. Upload it again.');
                }
                $last = 0.0;
                stream_filter_append($fp, ProgressFilter::NAME, STREAM_FILTER_READ, ['tick' => function (int $bytes) use ($id, $base, $size, &$last) {
                    if (microtime(true) - $last >= 0.5) {
                        $last = microtime(true);
                        $this->jobs->write($id, $base + ['state' => 'storing', 'bytes' => min($bytes, $size)]);
                    }
                }]);
                try {
                    $saved = $overwrite
                        ? ($this->storage->store($destination, $name, $fp, true) ? $name : null)
                        : $this->storage->storeUnique($destination, $name, $fp);
                    if ($saved !== null) {
                        $this->jobs->write($id, $base + ['state' => 'done', 'saved_as' => $saved]);

                        return;
                    }
                    throw new \RuntimeException('Store failed');
                } catch (\InvalidArgumentException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // one more try: a long FTP transfer can lose its connection
                    if (++$attempts >= 2) {
                        throw $e;
                    }
                } finally {
                    if (is_resource($fp)) {
                        fclose($fp);
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->jobs->write($id, $base + ['state' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // storage failures can carry host names or paths: keep them out of the browser
            $this->jobs->write($id, $base + ['state' => 'error', 'message' => 'Saving the upload failed: ' . (new \ReflectionClass($e))->getShortName()]);
        } finally {
            @unlink($data_file);
        }
    }
}
