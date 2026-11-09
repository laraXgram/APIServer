<?php

declare(strict_types=1);

namespace LaraGram\Server\Files;

use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Foundation\FileDecoder;

/**
 * Stops a download as soon as it passes a size limit (Client::on_update_file
 * cancels it the same way): a file_id seen for the first time has no known
 * size, and the rest of a huge file would be downloaded for nothing.
 */
final class BoundedFileDecoder extends FileDecoder
{
    private bool $limitExceeded = false;

    public function __construct(Client $client, private readonly int $maxSize)
    {
        parent::__construct($client);
    }

    public function limitExceeded(): bool
    {
        return $this->limitExceeded;
    }

    protected function getFileChunkOn(Client $conn, int $dcId, array $location, int $offset, int $limit, bool $raw = false): array
    {
        $chunk = parent::getFileChunkOn($conn, $dcId, $location, $offset, $limit, $raw);
        if ($offset + strlen($chunk['bytes'] ?? '') > $this->maxSize) {
            $this->limitExceeded = true;

            throw new MTProtoException('The file is bigger than the download limit.');
        }

        return $chunk;
    }
}
