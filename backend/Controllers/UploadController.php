<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Session\SessionStorageInterface as Session;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Storage\UploadJob;
use Filegator\Services\Tmpfs\TmpfsInterface;

/**
 * Uploads in pieces ("chunks", sent by resumable.js in the browser).
 *
 * - No request holds the PHP session: PHP locks the session for the whole request, so the pieces the browser sends side by side used to be
 *   handled one after the other (and waited for every other request of the file manager as well).
 * - Each piece is written straight to its place in one file on the server's disk (offset = (piece - 1) x piece size), so nothing has to be
 *   joined together at the end (that used to copy the whole file once more inside the last request).
 * - The pieces that arrived are counted under a lock, so of two last pieces arriving together exactly one finishes the file.
 * - The finished file is copied into the website. A small one right away; a large one by a background job (UploadJob), so the last request
 *   answers at once and the page can be left as soon as the bar is full.
 * - Pieces are kept per website (not per temporary login): adding the same file again, also after a reload, sends only the missing pieces.
 */
class UploadController extends BackgroundJobController
{
    /** Files at least this big are copied into the website by a background job instead of the browser's last request. */
    public const BACKGROUND_FROM = 32 * 1024 * 1024;

    protected $tmpfs;

    public function __construct(Config $config, Session $session, AuthInterface $auth, Filesystem $storage, LoggerInterface $logger, TmpfsInterface $tmpfs)
    {
        parent::__construct($config, $session, $auth, $storage, $logger);
        $this->tmpfs = $tmpfs;
    }

    public function chunkCheck(Request $request, Response $response)
    {
        $this->session->save();

        $chunk_number = (int) $request->input('resumableChunkNumber');
        if ($chunk_number > 0 && $this->tmpfs->exists($this->key($request) . 'part' . $chunk_number)) {
            return $response->json('Chunk exists', 200);
        }

        return $response->json('Chunk does not exists', 204);
    }

    public function upload(Request $request, Response $response)
    {
        // read what is needed from the session, then let go of it: the browser sends several pieces at the same time
        $blob = $this->loginBlob();
        $this->session->save();

        $file_name = self::cleanName((string) $request->input('resumableFilename', 'file'));
        $destination = (string) $request->input('resumableRelativePath');
        $chunk_number = (int) $request->input('resumableChunkNumber');
        $chunk_size = (int) $request->input('resumableChunkSize');
        $total_chunks = (int) $request->input('resumableTotalChunks');
        $total_size = (int) $request->input('resumableTotalSize');
        $max = (int) $this->config->get('frontend_config.upload_max_size');

        $filebag = $request->files;
        $file = $filebag->get('file');

        // php 8.1 fix
        // remove new key 'full_path' so it can preserve compatibility with symfony FileBag
        // see https://php.watch/versions/8.1/$_FILES-full-path
        if ($file && is_array($file) && array_key_exists('full_path', $file)) {
            unset($file['full_path']);
            $filebag->set('file', $file);
            $file = $filebag->get('file');
        }

        if (! $file || ! $file->isValid() || $file_name === '') {
            return $response->json('Bad file', 422);
        }
        if ($total_size > $max) {
            return $response->json('Chunk too big', 422);
        }

        $length = (int) $file->getSize();
        $offset = ($chunk_number - 1) * $chunk_size;
        // every piece must land inside the file the browser announced: nothing can make the server's copy bigger than that
        if ($total_size < 0 || $chunk_size < 1 || $total_chunks < 1 || $chunk_number < 1 || $chunk_number > $total_chunks || $offset + $length > $total_size) {
            return $response->json('Bad file', 422);
        }

        $key = $this->key($request);
        $data = $this->tmpfs->getFileLocation($key . 'data');

        $in = fopen($file->getPathname(), 'rb');
        $out = fopen($data, 'c');
        $written = false;
        if ($in && $out && fseek($out, $offset) === 0) {
            $written = stream_copy_to_stream($in, $out) === $length;
        }
        if ($in) {
            fclose($in);
        }
        if ($out) {
            fclose($out);
        }
        if (! $written) {
            return $response->json('Error storing file', 500);
        }

        if (! $this->arrived($key, $chunk_number, $total_chunks)) {
            return $response->json('Uploaded');
        }

        // every piece is here
        for ($i = 1; $i <= $total_chunks; ++$i) {
            @unlink($this->tmpfs->getFileLocation($key . 'part' . $i));
        }
        foreach (['count', 'done', 'lock'] as $marker) {
            @unlink($this->tmpfs->getFileLocation($key . $marker));
        }
        clearstatcache(true, $data);
        if ((int) @filesize($data) !== $total_size) {
            @unlink($data);

            return $response->json('Upload incomplete', 422);
        }

        $overwrite = (bool) $this->config->get('overwrite_on_upload', false);

        if ($total_size < self::BACKGROUND_FROM || $blob === null) {
            $stream = fopen($data, 'rb');
            $res = $stream ? $this->storage->store($destination, $file_name, $stream, $overwrite) : false;
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($data);

            return $res ? $response->json('Stored') : $response->json('Error storing file');
        }

        // large: hand the copy into the website to a background job and answer now
        $id = bin2hex(random_bytes(12));
        $tmp_dir = $this->workDir() . DIRECTORY_SEPARATOR . 'tmp';
        if (! is_dir($tmp_dir)) {
            @mkdir($tmp_dir, 0700, true);
        }
        $job_file = $tmp_dir . DIRECTORY_SEPARATOR . 'upload-' . $id . '.data';
        if (! @rename($data, $job_file)) {
            @unlink($data);

            return $response->json('Error storing file');
        }

        $this->jobs->cleanup(86400);
        $this->jobs->write($id, ['kind' => 'upload', 'state' => 'queued', 'name' => $file_name, 'started' => time(), 'destination' => $destination,
            'size' => $total_size]);
        $payload = $this->basePayload($id, $blob) + ['data_file' => $job_file, 'destination' => $destination, 'name' => $file_name, 'overwrite' => $overwrite];
        $handover = $this->jobs->putPayload($id, $payload);
        $this->logger->log('Upload saving in the background: ' . $file_name);

        if ($this->spawn('upload.php', $handover)) {
            return $response->json(['job' => $id]);
        }

        $this->jobs->takePayload($id);
        $this->runHere($response, ['job' => $id], function () use ($id, $job_file, $destination, $file_name, $overwrite) {
            (new UploadJob($this->storage, $this->jobs))->run($id, $job_file, $destination, $file_name, $overwrite);
        });

        return $response->json(['job' => $id]);
    }

    /**
     * Mark piece $n as arrived. True for exactly one request: the one that completes the set. Counted under a lock (a counter file, so this
     * stays one small read per piece however many pieces a file has); a piece sent twice (a retry) is counted once.
     */
    protected function arrived(string $key, int $n, int $total): bool
    {
        $lock = fopen($this->tmpfs->getFileLocation($key . 'lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            return false;
        }
        try {
            $part = $this->tmpfs->getFileLocation($key . 'part' . $n);
            $count_file = $this->tmpfs->getFileLocation($key . 'count');
            $count = (int) @file_get_contents($count_file);
            if (! is_file($part)) {
                touch($part);
                $count++;
                file_put_contents($count_file, (string) $count);
            }
            $done_file = $this->tmpfs->getFileLocation($key . 'done');
            if ($count >= $total && ! is_file($done_file)) {
                touch($done_file);

                return true;
            }

            return false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Where this upload's pieces live: per website and per file (resumable.js's identifier is the size and the path of the file). The piece
     * size is part of it: pieces left by an earlier try with another piece size sit at other offsets and must not be reused.
     */
    protected function key(Request $request): string
    {
        $parts = [$this->userId(), (string) $request->input('resumableIdentifier'), (int) $request->input('resumableChunkSize'), (int) $request->input('resumableTotalSize')];

        return 'multipart_' . substr(sha1(implode('|', $parts)), 0, 32) . '_';
    }

    /** The file name as stored: the same characters are replaced as the upload always did. */
    public static function cleanName(string $name): string
    {
        $name = (string) preg_replace('~[<>:"/\\\\|?*]|[\x00-\x1F]|[\x7F\xA0\xAD]|[;{}^\~`]~u', '-', $name);
        $name = mb_substr($name, 0, 255);

        return in_array($name, ['', '.', '..'], true) ? '' : $name;
    }
}
