<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

use LaraGram\Server\Bot\ClientManager;
use LaraGram\Server\Foundation\ServerParameters;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Handles one Bot API HTTP request: a port of telegram_bot_api::HttpConnection
 * plus the response writing of td::HttpConnectionBase.
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
        private readonly Server $server,
        private readonly ClientManager $clientManager,
        private readonly ServerParameters $parameters,
    ) {
    }

    /**
     * HttpStatConnection: the statistics page, for any path of the statistics port.
     */
    public function handleStat(Request $request, Response $response): void
    {
        $read = $this->readRequest($request, $response);
        if ($read === null) {
            return;
        }
        $content = $this->clientManager->getStats($read['args']);

        $response->status(200, 'OK');
        $response->header('Server', '', false);
        $response->header('Date', '', false);
        $response->header('Connection', 'keep-alive');
        $response->header('Content-Type', 'text/plain');
        $response->end($content);
    }

    /**
     * HttpReader: the parsed request, or null after answering a malformed one.
     *
     * @return array<string, mixed>|null
     */
    private function readRequest(Request $request, Response $response): ?array
    {
        // HttpReader::parse_head: only GET and POST requests are served
        $method = (string) ($request->server['request_method'] ?? '');
        if ($method !== 'GET' && $method !== 'POST') {
            $this->sendReaderError($request, $response, 501);

            return null;
        }

        $headers = [];
        foreach ($request->header ?? [] as $name => $value) {
            $headers[] = [(string) $name, RequestReader::trim((string) $value)];
        }

        $target = (string) ($request->server['request_uri'] ?? '/');
        if (isset($request->server['query_string'])) {
            $target .= '?'.$request->server['query_string'];
        }

        try {
            $read = (new RequestReader($this->parameters->tempDirectory, $this->parameters->logger))
                ->read($target, $headers, (string) $request->rawContent());
        } catch (HttpReaderException $e) {
            $this->parameters->logger->info($e->getMessage());
            $this->sendReaderError($request, $response, $e->getCode());

            return null;
        }
        $read['headers'] = $headers;

        return $read;
    }

    public function handle(Request $request, Response $response): void
    {
        $read = $this->readRequest($request, $response);
        if ($read === null) {
            return;
        }
        $headers = $read['headers'];

        $path = $read['path'];
        if (!str_starts_with($path, '/')) {
            $this->sendHttpError($response, 404, 'Not Found: absolute URI is specified in the Request-Line');

            return;
        }
        if (!str_starts_with($path, '/bot')) {
            $this->sendHttpError($response, 404, 'Not Found');

            return;
        }

        // "/bot<token>[/test]/<method>"
        $rest = substr($path, 4);
        $slash = strpos($rest, '/');
        if ($slash === false) {
            $this->sendHttpError($response, 404, 'Not Found');

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
            $this->sendHttpError($response, 404, 'Not Found');

            return;
        }

        $query = new Query(
            $token,
            $isTestDc,
            substr($rest, 1),
            $read['args'],
            $headers,
            $read['files'],
            (string) ($request->server['remote_addr'] ?? ''),
        );
        $query->rawSize = strlen((string) $request->getData());

        try {
            $this->clientManager->send($query);
            $query->wait();
        } finally {
            $query->cleanup();
        }

        $this->sendResponse($response, $query->httpStatusCode(), $query->answerBody(), $query->retryAfter());
    }

    private function sendResponse(Response $response, int $status, string $content, int $retryAfter = 0): void
    {
        $response->status($status, self::REASONS[$status] ?? 'Internal Server Error');
        $response->header('Server', '', false);
        $response->header('Date', '', false);
        $response->header('Connection', 'keep-alive');
        $response->header('Content-Type', 'application/json');
        if ($retryAfter > 0) {
            $response->header('Retry-After', (string) $retryAfter);
        }
        $response->end($content);
    }

    private function sendHttpError(Response $response, int $status, string $description): void
    {
        $this->sendResponse($response, $status, Query::errorJson($status, $description));
    }

    /**
     * A request that could not be read gets the bare status line and an empty
     * body, and the connection is closed.
     */
    private function sendReaderError(Request $request, Response $response, int $status): void
    {
        $response->status($status, self::REASONS[$status] ?? 'Internal Server Error');
        $response->header('Server', '', false);
        $response->header('Date', '', false);
        $response->header('Connection', '', false);
        $response->header('Content-Type', '', false);
        $response->end();
        $this->server->close($request->fd);
    }
}
