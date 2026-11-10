<?php

declare(strict_types=1);

namespace LaraGram\Server\Files;

use LaraGram\MTProto\Core\Client as MTProtoClient;
use LaraGram\MTProto\Foundation\FileUploader;
use LaraGram\Server\Bot\QueryError;

/**
 * A file of a request that is never written to disk (--no-file-storage).
 *
 * The first 10 MB stay in memory, so a small file is uploaded as an ordinary
 * file once the request is handled, and a photo or a webhook certificate can
 * still be read. A bigger file is uploaded while the request is being read:
 * its parts go to Telegram with upload.saveBigFilePart as soon as they
 * arrive, with -1 parts in total until the last one, as TDLib does for a
 * file whose size isn't final yet. Reading the request waits for each part,
 * so a client sending faster than Telegram receives fills its socket instead
 * of the memory.
 */
final class StreamedUpload
{
    /** Files up to this size are kept in memory and uploaded as small files. */
    public const MEMORY_LIMIT = FileUploader::BIG_FILE_THRESHOLD;

    private const PART_SIZE = FileUploader::PART_SIZE;

    private string $buffer = '';

    private int $size = 0;

    private bool $isBig = false;

    private int $fileId = 0;

    private int $sentParts = 0;

    private bool $finished = false;

    private ?\Throwable $error = null;

    private ?MTProtoClient $client = null;

    /**
     * @param \Closure(): ?MTProtoClient $resolveClient the bot's connection, resolved once the file turns out to be big
     */
    public function __construct(private readonly \Closure $resolveClient)
    {
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isBig(): bool
    {
        return $this->isBig;
    }

    /**
     * The whole file, when it is small enough to be kept in memory.
     */
    public function contents(): ?string
    {
        return $this->isBig ? null : $this->buffer;
    }

    public function write(string $bytes): void
    {
        $this->size += strlen($bytes);
        if ($this->error !== null) {
            // the rest of a failed upload is read and dropped, so the request can still be answered
            return;
        }
        $this->buffer .= $bytes;

        if (!$this->isBig && $this->size > self::MEMORY_LIMIT) {
            $this->isBig = true;
            $this->fileId = random_int(PHP_INT_MIN, PHP_INT_MAX);
        }

        // the last part is held back, so it can carry the number of parts
        while ($this->isBig && $this->error === null && strlen($this->buffer) > self::PART_SIZE) {
            $part = substr($this->buffer, 0, self::PART_SIZE);
            $this->buffer = substr($this->buffer, self::PART_SIZE);
            $this->sendPart($part, -1);
        }
    }

    /**
     * The request body of the file ended.
     */
    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        if ($this->isBig && $this->error === null) {
            $this->sendPart($this->buffer, $this->sentParts + 1);
            $this->buffer = '';
        }
    }

    /**
     * The TL InputFile of the uploaded file. A small file is uploaded now.
     *
     * @return array<string, mixed>
     */
    public function inputFile(MTProtoClient $client, string $fileName): array
    {
        if ($this->error !== null) {
            throw new QueryError(400, 'Bad Request: failed to upload the file: '.$this->error->getMessage());
        }
        if (!$this->isBig) {
            try {
                return (new FileUploader($client))->fromString($this->buffer, $fileName);
            } catch (\Throwable $e) {
                throw QueryError::fromThrowable($e);
            }
        }

        return ['_' => 'inputFileBig', 'id' => $this->fileId, 'parts' => $this->sentParts, 'name' => $fileName];
    }

    public function discard(): void
    {
        $this->buffer = '';
    }

    private function sendPart(string $bytes, int $totalParts): void
    {
        try {
            $this->client ??= ($this->resolveClient)();
            if ($this->client === null) {
                throw new \RuntimeException('the bot isn\'t available');
            }
            if ($this->sentParts >= FileUploader::MAX_PARTS) {
                throw new \RuntimeException('the file is too big');
            }
            $this->client->invoke('upload.saveBigFilePart', [
                'file_id' => $this->fileId,
                'file_part' => $this->sentParts,
                'file_total_parts' => $totalParts,
                'bytes' => $bytes,
            ]);
            $this->sentParts++;
        } catch (\Throwable $e) {
            $this->error = $e;
            $this->buffer = '';
        }
    }
}
