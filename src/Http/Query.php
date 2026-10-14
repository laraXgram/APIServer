<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

use LaraGram\Server\Stats\BotStatActor;
use LaraGram\Server\Support\IpAddress;

/**
 * One Bot API request and its answer: a port of telegram_bot_api::Query.
 *
 * Every argument is a raw string exactly as received; each method parses
 * the arguments it needs, so parsing quirks match the official server.
 */
final class Query
{
    private const STATE_QUERY = 0;
    private const STATE_OK = 1;
    private const STATE_ERROR = 2;

    private int $state = self::STATE_QUERY;

    private string $method;

    /** @var array<string, string> first value of every argument name */
    private array $argMap = [];

    /** @var array<string, HttpFile> first file of every field name */
    private array $fileMap = [];

    private string $answer = '';

    private int $httpStatusCode = 0;

    private int $retryAfter = 0;

    /** Woken when the answer is set while the connection is waiting for it. */
    private ?\Swoole\Coroutine\Channel $done = null;

    public readonly float $startTimestamp;

    private ?BotStatActor $statActor = null;

    /** The size of the whole HTTP request (Query::query_size), when known. */
    public int $rawSize = 0;

    /**
     * @param list<array{0: string, 1: string}> $args
     * @param list<array{0: string, 1: string}> $headers lower-cased names
     * @param list<HttpFile> $files
     */
    public function __construct(
        public readonly string $token,
        public readonly bool $isTestDc,
        string $method,
        public readonly array $args,
        public readonly array $headers,
        public readonly array $files,
        public readonly string $peerIpAddress,
        public readonly bool $isInternal = false,
    ) {
        foreach ($args as [$key, $value]) {
            $this->argMap[$key] ??= $value;
        }
        foreach ($files as $file) {
            $this->fileMap[$file->fieldName] ??= $file;
        }

        $this->method = strtolower($method === '' ? $this->arg('method') : $method);
        $this->startTimestamp = microtime(true);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function hasArg(string $key): bool
    {
        return isset($this->argMap[$key]);
    }

    /**
     * The first value of an argument, or an empty string when it is absent.
     */
    public function arg(string $key): string
    {
        return $this->argMap[$key] ?? '';
    }

    public function getHeader(string $key): string
    {
        foreach ($this->headers as [$name, $value]) {
            if ($name === $key) {
                return $value;
            }
        }

        return '';
    }

    public function file(string $key): ?HttpFile
    {
        return $this->fileMap[$key] ?? null;
    }

    public function filesSize(): int
    {
        $size = 0;
        foreach ($this->files as $file) {
            $size += $file->size;
        }

        return $size;
    }

    public function filesMaxSize(): int
    {
        $max = 0;
        foreach ($this->files as $file) {
            $max = max($max, $file->size);
        }

        return $max;
    }

    /**
     * The client address: the peer itself when it is public, otherwise the
     * X-Real-IP header set by a reverse proxy on the local network.
     */
    public function getPeerIpAddress(): string
    {
        if ($this->peerIpAddress !== '' && IpAddress::isValid($this->peerIpAddress)
            && !IpAddress::isReserved($this->peerIpAddress)) {
            return $this->peerIpAddress;
        }

        return $this->getHeader('x-real-ip');
    }

    public function isReady(): bool
    {
        return $this->state !== self::STATE_QUERY;
    }

    public function answerBody(): string
    {
        return $this->answer;
    }

    public function httpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function setOk(string $result): void
    {
        $this->answer = $result;
        $this->state = self::STATE_OK;
        $this->httpStatusCode = 200;
        $this->sendResponseStat();
        $this->done?->push(true);
    }

    public function setError(int $httpStatusCode, string $result): void
    {
        $this->answer = $result;
        $this->state = self::STATE_ERROR;
        $this->httpStatusCode = $httpStatusCode;
        $this->sendResponseStat();
        $this->done?->push(true);
    }

    /**
     * Query::set_stat_actor: count the request in the bot statistics.
     */
    public function setStatActor(BotStatActor $statActor): void
    {
        $this->statActor = $statActor;
        $size = $this->rawSize;
        if ($size === 0) {
            $size = strlen($this->token) + strlen($this->method);
            foreach ($this->args as [$key, $value]) {
                $size += strlen($key) + strlen($value);
            }
        }
        $statActor->addRequest($size, count($this->files), $this->filesSize(), $this->filesMaxSize());
    }

    /**
     * Query::send_response_stat.
     */
    private function sendResponseStat(): void
    {
        $this->statActor?->addResponse($this->state === self::STATE_OK, strlen($this->answer), count($this->files), $this->filesSize());
    }

    /**
     * Suspend the calling coroutine until the query is answered. Most
     * queries are answered before this is reached and return at once.
     */
    public function wait(): void
    {
        if ($this->isReady()) {
            return;
        }

        $this->done = new \Swoole\Coroutine\Channel(1);
        $this->done->pop();
        $this->done = null;
    }

    public function setRetryAfterError(int $retryAfter): void
    {
        $this->retryAfter = $retryAfter;
        $this->setError(429, self::errorJson(429, "Too Many Requests: retry after {$retryAfter}", ['retry_after' => $retryAfter]));
    }

    /**
     * answer_query(): `{"ok":true,"result":…[,"description":…]}`.
     *
     * @param string $resultJson the already encoded result
     */
    public function answerJson(string $resultJson, string $description = ''): void
    {
        $json = '{"ok":true,"result":'.$resultJson;
        if ($description !== '') {
            $json .= ',"description":'.JsonWriter::encode($description);
        }

        $this->setOk($json.'}');
    }

    public function answerQuery(mixed $result, string $description = ''): void
    {
        $this->answerJson(JsonWriter::encode($result), $description);
    }

    /**
     * fail_query(): `{"ok":false,"error_code":…,"description":…[,"parameters":{…}]}`.
     *
     * @param array<string, int|string> $parameters
     */
    public function fail(int $httpStatusCode, string $description, array $parameters = []): void
    {
        $this->setError($httpStatusCode, self::errorJson($httpStatusCode, $description, $parameters));
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public static function errorJson(int $errorCode, string $description, array $parameters = []): string
    {
        $json = '{"ok":false,"error_code":'.$errorCode.',"description":'
            .(mb_check_encoding($description, 'UTF-8') ? JsonWriter::encode($description) : JsonWriter::rawString($description));

        if ($parameters !== []) {
            $json .= ',"parameters":'.JsonWriter::encode($parameters);
        }

        return $json.'}';
    }

    /**
     * Remove the temporary files of the attached uploads.
     */
    public function cleanup(): void
    {
        foreach ($this->files as $file) {
            $file->delete();
        }
    }
}
