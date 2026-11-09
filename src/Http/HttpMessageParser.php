<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

use LaraGram\Log\LoggerInterface;

/**
 * Frames HTTP requests out of the bytes of one connection: the request line,
 * headers, Content-Length and chunked bodies of td::HttpReader. Every piece
 * of a body goes on to {@see RequestReader} as it arrives.
 */
final class HttpMessageParser
{
    private const STATE_HEAD = 0;
    private const STATE_CONTENT = 1;
    private const STATE_CHUNK_LENGTH = 2;
    private const STATE_CHUNK_CONTENT = 3;
    private const STATE_CHUNK_END = 4;

    private const MAX_CHUNK_LENGTH_DIGITS = 8;

    private string $input = '';

    private int $state = self::STATE_HEAD;

    private int $headSearchOffset = 0;

    private int $headLength = 0;

    private int $remaining = 0;

    private int $chunkedSize = 0;

    private bool $lastChunk = false;

    private bool $keepAlive = true;

    /** @var list<array{0: string, 1: string}> */
    private array $headers = [];

    private ?RequestReader $reader = null;

    /**
     * @param \Closure(): void $sendContinue answers "Expect: 100-continue"
     */
    public function __construct(
        private readonly string $tempDirectory,
        private readonly ?LoggerInterface $logger,
        private readonly \Closure $sendContinue,
    ) {
    }

    public function append(string $data): void
    {
        $this->input = $this->input === '' ? $data : $this->input.$data;
    }

    /**
     * The next complete request, or null when more bytes are needed.
     *
     * @return array{path: string, args: list<array{0: string, 1: string}>, files: list<HttpFile>, params_size: int, headers: list<array{0: string, 1: string}>, keep_alive: bool, raw_size: int}|null
     *
     * @throws HttpReaderException
     */
    public function next(): ?array
    {
        if ($this->state === self::STATE_HEAD && !$this->readHead()) {
            return null;
        }
        if ($this->reader === null) {
            return null;
        }
        if ($this->state !== self::STATE_HEAD && !$this->readBody()) {
            return null;
        }

        return $this->complete();
    }

    /**
     * The connection is gone: drop the files of the unfinished request.
     */
    public function abort(): void
    {
        $this->reader?->abort();
        $this->reader = null;
        $this->input = '';
    }

    /**
     * @return array<string, mixed>
     */
    private function complete(): array
    {
        $read = $this->reader->finish();
        $this->reader = null;
        $this->state = self::STATE_HEAD;

        $read['headers'] = $this->headers;
        $read['keep_alive'] = $this->keepAlive;
        $read['raw_size'] = $this->headLength + $read['params_size'];

        return $read;
    }

    /**
     * HttpReader::split_header.
     *
     * @return bool whether a head was read
     *
     * @throws HttpReaderException
     */
    private function readHead(): bool
    {
        $end = strpos($this->input, "\r\n\r\n", $this->headSearchOffset);
        if ($end === false) {
            if (strlen($this->input) > RequestReader::MAX_TOTAL_HEADERS_LENGTH) {
                throw new HttpReaderException(431, 'Request Header Fields Too Large: total headers size exceeded');
            }
            $this->headSearchOffset = max(0, strlen($this->input) - 3);

            return false;
        }

        $head = substr($this->input, 0, $end + 2);
        $this->input = (string) substr($this->input, $end + 4);
        $this->headSearchOffset = 0;
        $this->headLength = $end + 4;

        [$target, $contentLength, $transferEncoding] = $this->parseHead($head);

        $this->reader = new RequestReader($this->tempDirectory, $this->logger);
        $this->reader->begin($target, $this->headers);

        if ($transferEncoding === '' && $contentLength <= 0) {
            return true;
        }
        if ($transferEncoding === '') {
            $this->remaining = $contentLength;
            $this->state = self::STATE_CONTENT;
        } elseif ($transferEncoding === 'chunked') {
            $this->chunkedSize = 0;
            $this->lastChunk = false;
            $this->state = self::STATE_CHUNK_LENGTH;
        } else {
            throw new HttpReaderException(501, 'Unimplemented: unsupported transfer-encoding');
        }
        if ($contentLength >= RequestReader::MAX_CONTENT_SIZE) {
            throw new HttpReaderException(413, "Request Entity Too Large: content length is {$contentLength}");
        }

        $this->reader->openBody();
        foreach ($this->headers as [$name, $value]) {
            if ($name === 'expect' && strtolower($value) === '100-continue') {
                ($this->sendContinue)();
            }
        }

        return true;
    }

    /**
     * HttpReader::parse_head.
     *
     * @return array{0: string, 1: int, 2: string} request target, content length (-1 if absent), transfer encoding
     *
     * @throws HttpReaderException
     */
    private function parseHead(string $head): array
    {
        $space = strpos($head, ' ');
        $type = $space === false ? $head : substr($head, 0, $space);
        if ($type !== 'GET' && $type !== 'POST') {
            throw new HttpReaderException(501, 'Not Implemented');
        }

        $lineEnd = strpos($head, "\r");
        $urlVersion = substr($head, $space + 1, $lineEnd - $space - 1);
        $versionSpace = strrpos($urlVersion, ' ');
        if ($versionSpace === false) {
            throw new HttpReaderException(400, 'Bad Request: wrong request line');
        }
        $target = substr($urlVersion, 0, $versionSpace);
        $version = substr($urlVersion, $versionSpace + 1);
        if ($version === 'HTTP/1.1') {
            $this->keepAlive = true;
        } elseif ($version === 'HTTP/1.0') {
            $this->keepAlive = false;
        } else {
            throw new HttpReaderException(505, 'HTTP Version Not Supported');
        }

        $this->headers = [];
        $contentLength = -1;
        $transferEncoding = '';

        $pos = $lineEnd + 2;
        $length = strlen($head);
        while ($pos < $length) {
            $colon = strpos($head, ':', $pos);
            if ($colon === false) {
                throw new HttpReaderException(400, 'Bad Request');
            }
            $name = strtolower(RequestReader::trim(substr($head, $pos, $colon - $pos)));
            $pos = $colon + 1;

            // The value runs to "\r\n", continued by lines starting with a space or tab.
            $valueStart = $pos;
            do {
                $cr = strpos($head, "\r", $pos);
                if ($cr === false || $cr + 1 >= $length || $head[$cr + 1] !== "\n") {
                    throw new HttpReaderException(400, 'Bad Request');
                }
                $valueEnd = $cr;
                $pos = $cr + 2;
            } while ($pos < $length && ($head[$pos] === ' ' || $head[$pos] === "\t"));

            $value = RequestReader::trim(substr($head, $valueStart, $valueEnd - $valueStart));
            $this->headers[] = [$name, $value];

            switch ($name) {
                case 'content-length':
                    $digits = strspn($value, '0123456789');
                    $contentLength = $digits > 10 ? RequestReader::MAX_CONTENT_SIZE : min((int) substr($value, 0, $digits), RequestReader::MAX_CONTENT_SIZE);
                    break;
                case 'connection':
                    $this->keepAlive = strtolower($value) !== 'close';
                    break;
                case 'transfer-encoding':
                    $transferEncoding = strtolower($value);
                    break;
            }
        }

        return [$target, $contentLength, $transferEncoding];
    }

    /**
     * @return bool whether the body has ended
     *
     * @throws HttpReaderException
     */
    private function readBody(): bool
    {
        while (true) {
            switch ($this->state) {
                case self::STATE_CONTENT:
                    if ($this->input !== '' && $this->remaining > 0) {
                        $this->remaining -= $this->forward($this->remaining);
                    }
                    if ($this->remaining > 0) {
                        return false;
                    }
                    $this->state = self::STATE_HEAD;

                    return true;

                case self::STATE_CHUNK_LENGTH:
                    $end = strpos($this->input, "\r\n");
                    if (($end === false ? strlen($this->input) : $end) > self::MAX_CHUNK_LENGTH_DIGITS) {
                        throw new HttpReaderException(400, 'Bad Request: Too long length in chunked');
                    }
                    if ($end === false) {
                        return false;
                    }
                    $digits = substr($this->input, 0, $end);
                    $this->input = (string) substr($this->input, $end + 2);
                    if ($digits !== '' && !ctype_xdigit($digits)) {
                        throw new HttpReaderException(400, 'Bad Request: Wrong length in chunked');
                    }
                    $this->remaining = $digits === '' ? 0 : (int) hexdec($digits);
                    $this->lastChunk = $this->remaining === 0;
                    $this->chunkedSize += $this->remaining;
                    if ($this->chunkedSize >= RequestReader::MAX_CONTENT_SIZE) {
                        throw new HttpReaderException(400, "Bad Request: Too big query");
                    }
                    $this->state = self::STATE_CHUNK_CONTENT;
                    break;

                case self::STATE_CHUNK_CONTENT:
                    if ($this->input !== '' && $this->remaining > 0) {
                        $this->remaining -= $this->forward($this->remaining);
                    }
                    if ($this->remaining > 0) {
                        return false;
                    }
                    $this->state = self::STATE_CHUNK_END;
                    break;

                case self::STATE_CHUNK_END:
                    if (strlen($this->input) < 2) {
                        return false;
                    }
                    $this->input = (string) substr($this->input, 2);
                    if ($this->lastChunk) {
                        $this->state = self::STATE_HEAD;

                        return true;
                    }
                    $this->state = self::STATE_CHUNK_LENGTH;
                    break;
            }
        }
    }

    /**
     * Hand up to $limit buffered bytes to the reader.
     *
     * @return int the number of bytes handed over
     *
     * @throws HttpReaderException
     */
    private function forward(int $limit): int
    {
        if (strlen($this->input) <= $limit) {
            $chunk = $this->input;
            $this->input = '';
        } else {
            $chunk = substr($this->input, 0, $limit);
            $this->input = substr($this->input, $limit);
        }
        $this->reader->write($chunk);

        return strlen($chunk);
    }
}
