<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

use LaraGram\Server\Bot\ClientManager;

/**
 * Answers one Bot API HTTP request: a port of telegram_bot_api::HttpConnection
 * and HttpStatConnection.
 */
final class HttpConnection
{
    /** td::HttpHeaderCreator::get_status_line */
    private const REASONS = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 206 => 'Partial Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect', 400 => 'Bad Request', 401 => 'Unauthorized',
        403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable',
        408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required',
        412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type', 416 => 'Range Not Satisfiable', 417 => 'Expectation Failed',
        418 => "I'm a teapot", 421 => 'Misdirected Request', 426 => 'Upgrade Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large', 480 => 'Temporarily Unavailable', 501 => 'Not Implemented',
        502 => 'Bad Gateway', 503 => 'Service Unavailable', 505 => 'HTTP Version Not Supported',
    ];

    public function __construct(
        private readonly ClientManager $clientManager,
    ) {
    }

    public static function reason(int $status): string
    {
        return self::REASONS[$status] ?? 'Internal Server Error';
    }

    /**
     * HttpStatConnection: the statistics page, for any path of the statistics port.
     *
     * @param array<string, mixed> $read
     */
    public function handleStat(array $read, HttpInboundConnection $connection): void
    {
        $this->sendResponse($connection, $read, 200, $this->clientManager->getStats($read['args']), 'text/plain');
    }

    /**
     * @param array<string, mixed> $read
     */
    public function handle(array $read, HttpInboundConnection $connection): void
    {
        $path = $read['path'];
        if (!str_starts_with($path, '/')) {
            $this->sendHttpError($connection, $read, 404, 'Not Found: absolute URI is specified in the Request-Line');

            return;
        }
        if (!str_starts_with($path, '/bot')) {
            $this->sendHttpError($connection, $read, 404, 'Not Found');

            return;
        }

        // "/bot<token>[/test]/<method>"
        $rest = substr($path, 4);
        $slash = strpos($rest, '/');
        if ($slash === false) {
            $this->sendHttpError($connection, $read, 404, 'Not Found');

            return;
        }
        $token = substr($rest, 0, $slash);
        $rest = substr($rest, $slash);

        $isTestDc = false;
        if (str_starts_with($rest, '/test')) {
            $isTestDc = true;
            $rest = substr($rest, 5);
        }
        if (!str_starts_with($rest, '/')) {
            $this->sendHttpError($connection, $read, 404, 'Not Found');

            return;
        }

        $query = new Query(
            $token,
            $isTestDc,
            substr($rest, 1),
            $read['args'],
            $read['headers'],
            $read['files'],
            $connection->remoteAddress,
        );
        $query->rawSize = $read['raw_size'];

        try {
            $this->clientManager->send($query);
            $query->wait();
        } finally {
            $query->cleanup();
        }

        $this->sendResponse($connection, $read, $query->httpStatusCode(), $query->answerBody(), 'application/json', $query->retryAfter());
    }

    /**
     * @param array<string, mixed> $read
     */
    private function sendResponse(HttpInboundConnection $connection, array $read, int $status, string $content, string $contentType, int $retryAfter = 0): void
    {
        $head = "HTTP/1.1 {$status} ".self::reason($status)."\r\nConnection: keep-alive\r\nContent-Type: {$contentType}\r\n";
        if ($retryAfter > 0) {
            $head .= "Retry-After: {$retryAfter}\r\n";
        }
        $head .= 'Content-Length: '.strlen($content)."\r\n\r\n";

        $connection->write($head.$content, $read['keep_alive']);
    }

    /**
     * @param array<string, mixed> $read
     */
    private function sendHttpError(HttpInboundConnection $connection, array $read, int $status, string $description): void
    {
        $this->sendResponse($connection, $read, $status, Query::errorJson($status, $description), 'application/json');
    }
}
