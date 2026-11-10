<?php

declare(strict_types=1);

namespace LaraGram\Server\Files;

use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Foundation\FileDecoder;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Streams a byte range of a file from Telegram, in order, without keeping
 * more than a few chunks in memory (--stream-files).
 *
 * Chunks are fetched with upload.getFile, 1 MB at a time on 1 MB boundaries.
 * While one chunk is being sent, the next ones are already being fetched
 * (read-ahead), spread over the media connections of the file's datacenter.
 */
final class StreamingFileDecoder extends FileDecoder
{
    public const CHUNK = self::CHUNK_SIZE;

    private bool $limitExceeded = false;

    /**
     * @param int $readAhead how many chunks are fetched ahead of the one being sent
     * @param int $maxSize stop once the file passes this size; 0 is no limit
     */
    public function __construct(Client $client, private readonly int $readAhead = 4, private readonly int $maxSize = 0)
    {
        parent::__construct($client);
    }

    public function limitExceeded(): bool
    {
        return $this->limitExceeded;
    }

    /**
     * Send bytes $start..$end (inclusive; null is the end of the file) to $sink.
     *
     * @param array<string, mixed> $location TL InputFileLocation
     * @param callable(string): bool $sink receives the bytes in order; returns false to stop
     * @return int the number of bytes sent
     *
     * @throws MTProtoException
     */
    public function stream(array $location, int $dcId, int $start, ?int $end, callable $sink): int
    {
        $connections = $this->connections($dcId);
        $readAhead = count($connections) === 0 ? 0 : max(0, $this->readAhead);

        $first = intdiv($start, self::CHUNK);
        $last = $end === null ? PHP_INT_MAX : intdiv($end, self::CHUNK);
        $lastFetched = $last;

        /** @var array<int, Channel> $pending */
        $pending = [];
        $schedule = function (int $index) use (&$pending, &$lastFetched, $location, $dcId, $connections): void {
            if ($index > $lastFetched || isset($pending[$index])) {
                return;
            }
            $channel = new Channel(1);
            $pending[$index] = $channel;
            $connection = $connections === [] ? null : $connections[$index % count($connections)];
            Coroutine::create(function () use ($channel, $index, $location, $dcId, $connection): void {
                try {
                    $chunk = $connection === null
                        ? $this->getFileChunk($location, $dcId, $index * self::CHUNK, self::CHUNK)
                        : $this->getFileChunkOn($connection, $dcId, $location, $index * self::CHUNK, self::CHUNK);
                    $channel->push((string) ($chunk['bytes'] ?? ''));
                } catch (\Throwable $e) {
                    $channel->push($e);
                }
            });
        };

        $sent = 0;
        for ($index = $first; $index <= $last; $index++) {
            for ($ahead = $index; $ahead <= $index + $readAhead; $ahead++) {
                $schedule($ahead);
            }
            $bytes = $pending[$index]->pop();
            unset($pending[$index]);
            if ($bytes instanceof \Throwable) {
                throw $bytes instanceof MTProtoException ? $bytes : new MTProtoException($bytes->getMessage(), 0, $bytes);
            }

            $chunkStart = $index * self::CHUNK;
            $length = strlen($bytes);
            if ($this->maxSize > 0 && $chunkStart + $length > $this->maxSize) {
                $this->limitExceeded = true;

                throw new MTProtoException('The file is bigger than the download limit.');
            }

            $from = max($start, $chunkStart) - $chunkStart;
            $to = ($end === null ? $chunkStart + $length : min($end + 1, $chunkStart + $length)) - $chunkStart;
            if ($to > $from) {
                $part = substr($bytes, $from, $to - $from);
                $sent += strlen($part);
                if (!$sink($part)) {
                    break;
                }
            }

            // a short chunk is the end of the file
            if ($length < self::CHUNK) {
                $lastFetched = $index;
                break;
            }
        }

        return $sent;
    }

    /**
     * The connections chunks are fetched over concurrently; none when the
     * datacenter only has a connection that can't take concurrent requests.
     *
     * @return list<Client>
     */
    private function connections(int $dcId): array
    {
        try {
            $connections = $this->client->mediaSockets($dcId);
        } catch (\Throwable) {
            $connections = [];
        }

        return array_values(array_filter($connections, static fn (Client $connection): bool => $connection->supportsConcurrentInvoke()));
    }
}
