<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

use LaraGram\Log\LoggerInterface;
use Swoole\Server;

/**
 * One accepted connection: bytes in, one request at a time out to the
 * handler (td::HttpInboundConnection).
 *
 * Swoole starts a new coroutine per receive event, and writing an upload to
 * disk yields, so incoming pieces wait in a queue drained by one coroutine.
 * Reading is paused while the queue is long, so a client that outruns the
 * disk fills the socket instead of the memory.
 */
final class HttpInboundConnection
{
    private const PAUSE_AT = 8 << 20;
    private const RESUME_AT = 1 << 20;

    /** @var \SplQueue<string> */
    private \SplQueue $inbox;

    private int $inboxSize = 0;

    private bool $draining = false;

    private bool $paused = false;

    private bool $closed = false;

    private HttpMessageParser $parser;

    /**
     * @param \Closure(array<string, mixed>, self): void $handler answers one request
     */
    public function __construct(
        private readonly Server $server,
        public readonly int $fd,
        public readonly string $remoteAddress,
        string $tempDirectory,
        private readonly ?LoggerInterface $logger,
        private readonly \Closure $handler,
    ) {
        $this->inbox = new \SplQueue();
        $this->parser = new HttpMessageParser($tempDirectory, $logger, function (): void {
            $this->server->send($this->fd, "HTTP/1.1 100 Continue\r\n\r\n");
        });
    }

    public function receive(string $data): void
    {
        if ($this->closed) {
            return;
        }

        $this->inbox->enqueue($data);
        $this->inboxSize += strlen($data);
        if (!$this->paused && $this->inboxSize >= self::PAUSE_AT) {
            $this->paused = $this->server->pause($this->fd);
        }

        if (!$this->draining) {
            $this->draining = true;
            try {
                $this->drain();
            } finally {
                $this->draining = false;
                if ($this->closed) {
                    $this->parser->abort();
                }
            }
        }
    }

    /**
     * The peer disconnected. A request being read is dropped by the coroutine
     * that is reading it.
     */
    public function onClosed(): void
    {
        $this->closed = true;
        if (!$this->draining) {
            $this->parser->abort();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Write a whole response.
     */
    public function write(string $response, bool $keepAlive = true): void
    {
        if ($this->closed) {
            return;
        }
        $this->server->send($this->fd, $response);
        if (!$keepAlive) {
            $this->close();
        }
    }

    private function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->server->close($this->fd);
        }
    }

    private function drain(): void
    {
        while (!$this->closed && !$this->inbox->isEmpty()) {
            $data = $this->inbox->dequeue();
            $this->inboxSize -= strlen($data);
            if ($this->paused && $this->inboxSize <= self::RESUME_AT) {
                $this->paused = !$this->server->resume($this->fd);
            }

            $this->parser->append($data);
            unset($data);

            try {
                while (!$this->closed && ($request = $this->parser->next()) !== null) {
                    ($this->handler)($request, $this);
                }
            } catch (HttpReaderException $e) {
                // HttpConnectionBase::loop: a bare status line, then the connection closes
                if ($e->getCode() === 500) {
                    $this->logger?->warning('Failed to process an HTTP query: '.$e->getMessage());
                } else {
                    $this->logger?->info($e->getMessage());
                }
                $this->parser->abort();
                $this->write('HTTP/1.1 '.$e->getCode().' '.HttpConnection::reason($e->getCode())."\r\nContent-Length: 0\r\n\r\n", false);

                return;
            }
        }
    }
}
