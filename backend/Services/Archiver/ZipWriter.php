<?php

namespace Filegator\Services\Archiver;

/**
 * Writes a zip file one entry at a time, straight from a stream.
 *
 * PHP's ZipArchive only reads the files it is given when the archive is closed, so every file has to sit on disk until the very end (a 2 GB
 * site needed 2 GB of copies plus the zip), and the close itself is one long silent step. This writer compresses each file as it is read, so
 * the job can report progress all the way and the only thing on disk is the zip itself.
 *
 * Sizes over 4 GB and more than 65,535 entries are written in the zip64 format, which every current unzip program reads.
 */
class ZipWriter
{
    /** Already compressed: deflating these again costs time and saves nothing, so they are stored as they are. */
    public const STORE_EXTENSIONS = [
        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'jar', 'war',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'ico',
        'mp3', 'mp4', 'm4a', 'm4v', 'mov', 'avi', 'mkv', 'webm', 'ogg', 'ogv', 'flac', 'aac', 'wmv',
        'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'epub',
    ];

    private const MAX32 = 0xFFFFFFFF;

    private const MAX16 = 0xFFFF;

    /** A file expected to be bigger than this gets room for zip64 sizes in its local header (it may still grow a little while being read). */
    private const ZIP64_LOCAL_FROM = 0xF0000000;

    private $fp;

    private $entries = [];

    private $force_zip64;

    private $finished = false;

    /**
     * @param string $path        the zip file to create (overwritten)
     * @param bool   $force_zip64 always write the zip64 records (only the tests need this: real archives use them when they have to)
     */
    public function __construct(string $path, bool $force_zip64 = false)
    {
        $fp = @fopen($path, 'w+b');
        if (! $fp) {
            throw new ZipWriteException('Cannot create the zip file on the server');
        }
        $this->fp = $fp;
        $this->force_zip64 = $force_zip64;
    }

    /** Where the next entry starts: pass it to rollback() to throw away an entry that failed half-way. */
    public function position(): int
    {
        return (int) ftell($this->fp);
    }

    /** Forget everything written from $position on (an entry that could not be read to the end). */
    public function rollback(int $position): void
    {
        ftruncate($this->fp, $position);
        fseek($this->fp, $position);
        while ($this->entries && end($this->entries)['offset'] >= $position) {
            array_pop($this->entries);
        }
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function addDir(string $name, int $mtime = 0): void
    {
        $name = rtrim($name, '/') . '/';
        $mtime = self::mtime($mtime);
        $offset = $this->position();
        $this->writeLocalHeader($name, 0, $mtime, false);
        $this->entries[] = [
            'name' => $name, 'method' => 0, 'mtime' => $mtime, 'crc' => 0, 'csize' => 0, 'usize' => 0,
            'offset' => $offset, 'dir' => true, 'zip64_local' => false,
        ];
    }

    /**
     * Add one file, read from $in to its end.
     *
     * @param resource      $in            open for reading
     * @param int           $expected_size the size the listing showed (decides whether room for zip64 sizes is needed)
     * @param callable|null $tick          function (int $bytes_read): void, called after each chunk; may throw to stop
     * @return int bytes read
     */
    public function addStream(string $name, $in, int $mtime = 0, int $expected_size = 0, ?callable $tick = null): int
    {
        $mtime = self::mtime($mtime);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $method = ($expected_size === 0 || in_array($ext, self::STORE_EXTENSIONS, true)) ? 0 : 8;
        $zip64_local = $this->force_zip64 || $expected_size > self::ZIP64_LOCAL_FROM;

        $offset = $this->position();
        $this->writeLocalHeader($name, $method, $mtime, $zip64_local);
        $data_start = $this->position();

        $crc = hash_init('crc32b');
        $deflate = $method === 8 ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : null;
        $usize = 0;
        while (! feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                throw new \RuntimeException('Read failed');
            }
            if ($chunk === '') {
                continue;
            }
            $usize += strlen($chunk);
            hash_update($crc, $chunk);
            $this->write($deflate ? deflate_add($deflate, $chunk, ZLIB_NO_FLUSH) : $chunk);
            if ($tick !== null) {
                $tick($usize);
            }
        }
        if ($deflate) {
            $this->write(deflate_add($deflate, '', ZLIB_FINISH));
        }
        $csize = $this->position() - $data_start;
        $crc_value = (int) hexdec(hash_final($crc));

        if (! $zip64_local && ($usize >= self::MAX32 || $csize >= self::MAX32)) {
            throw new \RuntimeException('The file grew past 4 GB while it was being zipped');
        }

        // the sizes and the checksum are known now: put them into the local header written before the data
        $end = $this->position();
        fseek($this->fp, $offset + 14);
        if ($zip64_local) {
            $this->write(pack('V', $crc_value));
            fseek($this->fp, $offset + 30 + strlen($name) + 4);
            $this->write(pack('PP', $usize, $csize));
        } else {
            $this->write(pack('VVV', $crc_value, $csize, $usize));
        }
        fseek($this->fp, $end);

        $this->entries[] = [
            'name' => $name, 'method' => $method, 'mtime' => $mtime, 'crc' => $crc_value, 'csize' => $csize, 'usize' => $usize,
            'offset' => $offset, 'dir' => false, 'zip64_local' => $zip64_local,
        ];

        return $usize;
    }

    /** Write the central directory. Returns the size of the finished zip. */
    public function finish(): int
    {
        if ($this->finished) {
            return $this->position();
        }
        $cd_start = $this->position();
        foreach ($this->entries as $e) {
            $this->writeCentralEntry($e);
        }
        $cd_size = $this->position() - $cd_start;
        $count = count($this->entries);

        $zip64 = $this->force_zip64 || $count >= self::MAX16 || $cd_start >= self::MAX32 || $cd_size >= self::MAX32;
        if ($zip64) {
            $record = $this->position();
            $this->write(pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $count, $count, $cd_size, $cd_start));
            $this->write(pack('VVPV', 0x07064b50, 0, $record, 1));
        }
        $this->write(pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            min($count, self::MAX16),
            min($count, self::MAX16),
            min($cd_size, self::MAX32),
            min($cd_start, self::MAX32),
            0
        ));

        fflush($this->fp);
        $size = $this->position();
        fclose($this->fp);
        $this->finished = true;

        return $size;
    }

    /** Close without finishing (the caller deletes the file). */
    public function abort(): void
    {
        if (! $this->finished && is_resource($this->fp)) {
            fclose($this->fp);
        }
        $this->finished = true;
    }

    private function writeLocalHeader(string $name, int $method, int $mtime, bool $zip64): void
    {
        [$time, $date] = self::dosTime($mtime);
        $extra = $this->timeExtra($mtime);
        if ($zip64) {
            // sizes follow once known (addStream() fills them in)
            $extra = pack('vvPP', 0x0001, 16, 0, 0) . $extra;
        }
        $this->write(pack(
            'VvvvvvVVVvv',
            0x04034b50,
            $zip64 ? 45 : 20,
            0x0800, // names are UTF-8
            $method,
            $time,
            $date,
            0,
            $zip64 ? self::MAX32 : 0,
            $zip64 ? self::MAX32 : 0,
            strlen($name),
            strlen($extra)
        ) . $name . $extra);
    }

    private function writeCentralEntry(array $e): void
    {
        [$time, $date] = self::dosTime($e['mtime']);

        // zip64 fields come in this order and only when needed; an entry with a zip64 local header always lists both sizes here too
        $big_sizes = $e['zip64_local'] || $e['usize'] >= self::MAX32 || $e['csize'] >= self::MAX32;
        $big_offset = $e['offset'] >= self::MAX32 || $this->force_zip64;
        $z = '';
        if ($big_sizes) {
            $z .= pack('PP', $e['usize'], $e['csize']);
        }
        if ($big_offset) {
            $z .= pack('P', $e['offset']);
        }
        $extra = ($z !== '' ? pack('vv', 0x0001, strlen($z)) . $z : '') . $this->timeExtra($e['mtime']);
        $version = $z !== '' ? 45 : 20;

        $attr = $e['dir'] ? ((040755 << 16) | 0x10) : (0100644 << 16);
        $this->write(pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            (3 << 8) | $version, // made on Unix, so the permissions in the high bits are read
            $version,
            0x0800,
            $e['method'],
            $time,
            $date,
            $e['crc'],
            $big_sizes ? self::MAX32 : $e['csize'],
            $big_sizes ? self::MAX32 : $e['usize'],
            strlen($e['name']),
            strlen($extra),
            0,
            0,
            0,
            $attr,
            $big_offset ? self::MAX32 : $e['offset']
        ) . $e['name'] . $extra);
    }

    /**
     * An unknown time (0) becomes "now", once per entry: the local header and the central directory are written at different moments and must
     * carry the same time, or strict readers call the zip inconsistent.
     */
    private static function mtime(int $mtime): int
    {
        return $mtime > 0 ? $mtime : time();
    }

    /** "Extended timestamp" field: the modification time in UTC, which the DOS time cannot carry. */
    private function timeExtra(int $mtime): string
    {
        return $mtime > 0 ? pack('vvCV', 0x5455, 5, 1, $mtime) : '';
    }

    /** @return array{int, int} DOS time and date (local time; 1980 is the earliest a zip can hold) */
    public static function dosTime(int $mtime): array
    {
        $d = getdate($mtime > 0 ? $mtime : time());
        if ($d['year'] < 1980) {
            return [0, (1 << 5) | 1];
        }

        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (min($d['year'], 2107) - 1980) << 9 | ($d['mon'] << 5) | $d['mday'],
        ];
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        $done = 0;
        while ($done < $len) {
            $n = fwrite($this->fp, $done === 0 ? $data : substr($data, $done));
            if ($n === false || $n === 0) {
                throw new ZipWriteException('Cannot write the zip file on the server (is the disk full?)');
            }
            $done += $n;
        }
    }
}
