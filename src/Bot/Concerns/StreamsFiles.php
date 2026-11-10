<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Filesystem\Filesystem;
use LaraGram\Server\Files\FileIdException;
use LaraGram\Server\Files\RemoteFileLocation;
use LaraGram\Server\Files\StreamingFileDecoder;
use LaraGram\Server\Http\HttpConnection;
use LaraGram\Server\Http\HttpInboundConnection;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MediaConverter;
use LaraGram\Server\Support\FileName;
use LaraGram\Server\Support\MimeType;

/**
 * Serving files at /file/bot<token>/<file_path>, like api.telegram.org
 * (--stream-files).
 *
 * getFile answers at once with a path that names the file, instead of
 * downloading it first. Requesting the path streams the file from Telegram
 * while it is being downloaded, with byte ranges, so players can seek. A
 * file that is already on disk is sent from there, and with file storage a
 * file streamed from its start to its end is kept for the next requests.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait StreamsFiles
{
    /** Bytes sent from disk at a time. */
    private const DISK_CHUNK = 1 << 20;

    /** @var array<string, int> file_unique_id => size given by getFile, for the streams that follow it */
    private array $streamedFileSizes = [];

    /**
     * The file_path of a file that is streamed instead of downloaded: its
     * file_id names it, so the stream needs nothing but the path.
     */
    protected function streamedFilePath(RemoteFileLocation $location, string $extension, int $size): string
    {
        $uniqueId = $location->toUniqueId();
        if ($size > 0) {
            unset($this->streamedFileSizes[$uniqueId]);
            $this->streamedFileSizes[$uniqueId] = $size;
            if (count($this->streamedFileSizes) > 10000) {
                unset($this->streamedFileSizes[array_key_first($this->streamedFileSizes)]);
            }
        }

        return self::getFileTypeName($location->fileType).'/'.$location->toFileId().($extension === '' ? '' : '.'.$extension);
    }

    /**
     * Answer GET /file/bot<token>/<file_path>.
     *
     * @param array<string, mixed> $read the request
     */
    public function streamFile(string $filePath, array $read, HttpInboundConnection $connection): void
    {
        $keepAlive = (bool) $read['keep_alive'];
        if ($filePath === '' || str_contains($filePath, '..') || str_starts_with($filePath, '/') || str_contains($filePath, "\0")) {
            $this->sendStreamError($connection, $keepAlive, 404, 'Not Found');

            return;
        }
        $range = self::header($read['headers'], 'range');

        // a file kept on disk, by getFile or by an earlier stream
        if ($this->parameters->fileStorage) {
            $path = $this->filesDir.$filePath;
            if (is_file($path)) {
                $this->sendDiskFile($path, $range, $keepAlive, $connection);

                return;
            }
        }

        [$directory, $name] = array_pad(explode('/', $filePath, 2), 2, '');
        [$fileId, $extension] = array_pad(explode('.', $name, 2), 2, '');
        try {
            $location = RemoteFileLocation::fromFileId($fileId);
        } catch (FileIdException) {
            $this->sendStreamError($connection, $keepAlive, 404, 'Not Found');

            return;
        }
        if ($directory !== self::getFileTypeName($location->fileType)) {
            $this->sendStreamError($connection, $keepAlive, 404, 'Not Found');

            return;
        }
        $uniqueId = $location->toUniqueId();

        if ($this->parameters->fileStorage && ($cached = $this->getDownloadedFilePath($uniqueId)) !== null) {
            $this->sendDiskFile($this->filesDir.$cached, $range, $keepAlive, $connection);

            return;
        }

        $size = $this->streamedFileSizes[$uniqueId] ?? (MediaConverter::fileHint($uniqueId)[1] ?? 0);
        $maxSize = $this->parameters->localMode ? 0 : self::MAX_DOWNLOAD_FILE_SIZE;
        if ($maxSize > 0 && $size > $maxSize) {
            $this->sendStreamError($connection, $keepAlive, 400, 'Bad Request: file is too big');

            return;
        }

        [$status, $start, $end] = self::parseRange($range, $size);
        if ($status === 416) {
            $connection->write("HTTP/1.1 416 Range Not Satisfiable\r\nConnection: keep-alive\r\nContent-Range: bytes */{$size}\r\nContent-Length: 0\r\n\r\n", $keepAlive);

            return;
        }

        $head = "HTTP/1.1 {$status} ".HttpConnection::reason($status)."\r\nConnection: keep-alive\r\n"
            .'Content-Type: '.MimeType::fromExtension($extension, 'application/octet-stream')."\r\n";
        $chunked = $size === 0;
        if ($chunked) {
            $head .= "Transfer-Encoding: chunked\r\n";
        } else {
            $head .= "Accept-Ranges: bytes\r\nContent-Length: ".($end - $start + 1)."\r\n";
            if ($status === 206) {
                $head .= "Content-Range: bytes {$start}-{$end}/{$size}\r\n";
            }
        }
        $head .= "\r\n";

        // with file storage, a whole file streamed from its start is kept for the next requests
        $cache = $this->parameters->fileStorage && $start === 0 && ($size === 0 || $end === $size - 1) ? $this->openStreamCache() : null;

        $headSent = false;
        $alive = true;
        $sink = function (string $bytes) use ($connection, $head, $chunked, &$cache, &$headSent, &$alive): bool {
            if ($cache !== null && @fwrite($cache[0], $bytes) !== strlen($bytes)) {
                $cache[2] = false;
            }
            $data = $chunked ? dechex(strlen($bytes))."\r\n".$bytes."\r\n" : $bytes;
            if (!$headSent) {
                $headSent = true;
                $data = $head.$data;
            }
            $alive = $connection->writePart($data);

            return $alive;
        };

        $decoder = new StreamingFileDecoder($this->td, $this->parameters->streamReadAhead, $maxSize);
        try {
            $sent = $decoder->stream(self::inputFileLocation($location), $location->dcId, $start, $chunked ? null : $end, $sink);
        } catch (\Throwable $e) {
            $this->closeStreamCache($cache, null);
            if ($headSent) {
                // the response started already, so the client sees a cut-off body
                $connection->endResponse(false);

                return;
            }
            $this->sendStreamError($connection, $keepAlive, 400, $decoder->limitExceeded()
                ? 'Bad Request: file is too big'
                : 'Bad Request: wrong file_id or the file is temporarily unavailable');

            return;
        }

        if (!$headSent) {
            // an empty file
            $alive = $connection->writePart($head);
        }
        if ($chunked && $alive) {
            $alive = $connection->writePart("0\r\n\r\n");
        }
        $complete = $alive && ($chunked || $sent === $end - $start + 1);
        $this->closeStreamCache($cache, $complete ? [$location, $uniqueId, $extension, $sent] : null);
        $connection->endResponse($keepAlive && $complete);
    }

    /**
     * Send a file from disk, with byte ranges.
     */
    private function sendDiskFile(string $path, string $range, bool $keepAlive, HttpInboundConnection $connection): void
    {
        // a mount with noatime wouldn't record this use, and the storage clean-up goes by it
        @touch($path, (int) @filemtime($path), time());
        $size = (int) @filesize($path);
        [$status, $start, $end] = self::parseRange($range, $size);
        if ($status === 416 || $size === 0) {
            $connection->write("HTTP/1.1 416 Range Not Satisfiable\r\nConnection: keep-alive\r\nContent-Range: bytes */{$size}\r\nContent-Length: 0\r\n\r\n", $keepAlive);

            return;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->sendStreamError($connection, $keepAlive, 404, 'Not Found');

            return;
        }

        [, $extension] = FileName::split($path);
        $head = "HTTP/1.1 {$status} ".HttpConnection::reason($status)."\r\nConnection: keep-alive\r\n"
            .'Content-Type: '.MimeType::fromExtension($extension, 'application/octet-stream')."\r\n"
            ."Accept-Ranges: bytes\r\nContent-Length: ".($end - $start + 1)."\r\n"
            .($status === 206 ? "Content-Range: bytes {$start}-{$end}/{$size}\r\n" : '')."\r\n";

        $alive = $connection->writePart($head);
        fseek($handle, $start);
        $left = $end - $start + 1;
        while ($alive && $left > 0 && ($bytes = fread($handle, min(self::DISK_CHUNK, $left))) !== false && $bytes !== '') {
            $left -= strlen($bytes);
            $alive = $connection->writePart($bytes);
        }
        fclose($handle);
        $connection->endResponse($keepAlive && $alive && $left === 0);
    }

    /**
     * A single "bytes=" range of a file of the given size (0 when unknown).
     *
     * @return array{0: int, 1: int, 2: int} status, first byte, last byte
     */
    private static function parseRange(string $range, int $size): array
    {
        $whole = [200, 0, max(0, $size - 1)];
        if ($range === '' || $size === 0 || preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $match) !== 1 || $match[1].$match[2] === '') {
            return $whole;
        }
        if ($match[1] === '') {
            // the last N bytes
            $start = max(0, $size - (int) $match[2]);
            $end = $size - 1;
        } else {
            $start = (int) $match[1];
            $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
        }
        if ($start >= $size || $start > $end) {
            return [416, 0, 0];
        }

        return [206, $start, $end];
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as [$key, $value]) {
            if ($key === $name) {
                return $value;
            }
        }

        return '';
    }

    private function sendStreamError(HttpInboundConnection $connection, bool $keepAlive, int $status, string $description): void
    {
        $body = Query::errorJson($status, $description);
        $connection->write("HTTP/1.1 {$status} ".HttpConnection::reason($status)."\r\nConnection: keep-alive\r\n"
            ."Content-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body, $keepAlive);
    }

    /**
     * A temporary file a stream is copied to.
     *
     * @return array{0: resource, 1: string, 2: bool}|null handle, path, still valid
     */
    private function openStreamCache(): ?array
    {
        try {
            (new Filesystem())->ensureDirectoryExists($this->filesDir.'temp/', 0750);
        } catch (\Throwable) {
            return null;
        }
        $path = $this->filesDir.'temp/'.bin2hex(random_bytes(8));
        $handle = @fopen($path, 'xb');

        return $handle === false ? null : [$handle, $path, true];
    }

    /**
     * Keep the copy of a complete stream like a downloaded file, or drop it.
     *
     * @param array{0: resource, 1: string, 2: bool}|null $cache
     * @param array{0: RemoteFileLocation, 1: string, 2: string, 3: int}|null $file
     */
    private function closeStreamCache(?array $cache, ?array $file): void
    {
        if ($cache === null) {
            return;
        }
        fclose($cache[0]);
        if ($file === null || !$cache[2] || $file[3] === 0) {
            @unlink($cache[1]);

            return;
        }
        [$location, $uniqueId, $extension, $size] = $file;
        try {
            $this->storeDownloadedFile($cache[1], $location, $uniqueId, $extension, $size);
        } catch (\Throwable) {
            @unlink($cache[1]);
        }
    }
}
