<?php

namespace Filegator\Services\Archiver;

/**
 * A read filter that counts the bytes going through a stream and reports them: the storage's own upload (ftp_fput, SFTP put) reads the stream
 * itself and has no progress hook of its own.
 *
 * stream_filter_append($fp, ProgressFilter::NAME, STREAM_FILTER_READ, ['tick' => function (int $bytes) { ... }]);
 */
class ProgressFilter extends \php_user_filter
{
    public const NAME = 'filegator.progress';

    private $bytes = 0;

    public static function register(): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }

    #[\ReturnTypeWillChange]
    public function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->bytes += $bucket->datalen;
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        $tick = $this->params['tick'] ?? null;
        if (is_callable($tick)) {
            $tick($this->bytes);
        }

        return PSFS_PASS_ON;
    }
}
