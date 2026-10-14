<?php

declare(strict_types=1);

namespace LaraGram\Server\Webhook;

use LaraGram\Server\Foundation\ServerParameters;
use LaraGram\Server\Http\HttpReaderException;
use LaraGram\Server\Http\RequestReader;
use LaraGram\Server\Support\FloodControlFast;
use LaraGram\Server\Support\HttpUrl;
use LaraGram\Server\Support\IpAddress;
use LaraGram\Server\Updates\UpdateQueue;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client as HttpClient;

/**
 * Delivers the queued updates of one bot to its webhook: a port of
 * telegram_bot_api::WebhookActor on Swoole coroutines.
 *
 * Updates sharing a queue identifier are delivered one after another; up to
 * max_connections are in flight. Failed updates are retried with a growing
 * delay until they expire.
 */
final class WebhookActor
{
    private const MIN_PENDING_UPDATES_WARNING = 50;
    private const IP_ADDRESS_CACHE_TIME = 30 * 60;
    private const WEBHOOK_MAX_RESEND_TIMEOUT = 60;
    private const WEBHOOK_DROP_TIMEOUT = 60 * 60 * 23;
    private const IDLE_TIMEOUT = 60;

    private static int $totalConnectionCount = 0;

    private string $ipAddress = '';

    private int $ipGeneration = 0;

    private bool $wasChecked = false;

    private bool $stopFlag = false;

    private bool $closed = false;

    private bool $tqueueEmpty = false;

    private int $tqueueOffset = 0;

    private int $maxLoadedUpdates;

    /** @var array<int, array{id: int, json: string, delay: int, wakeup_at: float, expires_at: int, queue_id: int, fail_count: int, last_send_time: float}> */
    private array $updateMap = [];

    /** @var array<int, list<int>> queue id → event ids in order */
    private array $queueUpdates = [];

    /** @var array<int, float> queue id → time its first event can be sent */
    private array $queues = [];

    private int $uniqueQueueId = 1 << 60;

    /** @var array<int, array{channel: Channel, ip_generation: int, event_id: int, busy: bool}> */
    private array $connections = [];

    private int $nextConnectionId = 1;

    private int $pendingConnections = 0;

    private float $lastSuccessTime;

    private float $nextIpAddressResolveTime;

    private bool $isIpAddressBeingResolved = false;

    private float $wakeupAt = 0.0;

    private bool $lastUpdateWasSuccessful = true;

    private int $lastPendingUpdateCount = self::MIN_PENDING_UPDATES_WARNING;

    private float $first410ErrorTime = 0.0;

    private FloodControlFast $activeNewConnectionFlood;

    private FloodControlFast $pendingNewConnectionFlood;

    private string $hostHeader;

    private Channel $events;

    /**
     * @param \Closure(string, mixed...): void $callback receives the Callback method name and its arguments
     */
    public function __construct(
        private readonly \Closure $callback,
        private readonly UpdateQueue $tqueue,
        private HttpUrl $url,
        private readonly string $certPath,
        private readonly int $maxConnections,
        private readonly bool $fromDbFlag,
        string $cachedIpAddress,
        private readonly bool $fixIpAddress,
        private readonly string $secretToken,
        private readonly ServerParameters $parameters,
    ) {
        if ($cachedIpAddress !== '' && IpAddress::isValid($cachedIpAddress)) {
            $this->ipAddress = $cachedIpAddress;
        }

        $asciiHost = function_exists('idn_to_ascii') ? idn_to_ascii($url->host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
        if (is_string($asciiHost) && $asciiHost !== '') {
            $this->url = new HttpUrl($url->protocol, $url->userinfo, $asciiHost, $url->isIpv6, $url->specifiedPort, $url->port, $url->query);
        }
        $this->hostHeader = self::getHostHeader($this->url);
        $this->events = new Channel(1024);
        $this->maxLoadedUpdates = $maxConnections * 2;

        $this->activeNewConnectionFlood = (new FloodControlFast())->addLimit(0.5, 10);
        $this->pendingNewConnectionFlood = (new FloodControlFast())->addLimit(2, 1);
    }

    public static function getTotalConnectionCount(): int
    {
        return self::$totalConnectionCount;
    }

    /**
     * HttpHeaderCreator::get_host_header.
     */
    private static function getHostHeader(HttpUrl $url): string
    {
        $host = $url->isIpv6 ? '['.$url->host.']' : $url->host;
        $defaultPort = $url->protocol === HttpUrl::HTTP ? 80 : 443;

        return $url->port === $defaultPort ? $host : $host.':'.$url->port;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Life cycle
    // ═════════════════════════════════════════════════════════════════

    /**
     * WebhookActor::start_up.
     */
    public function start(): void
    {
        Coroutine::create(function (): void {
            $this->startUp();
            $this->run();
        });
    }

    private function startUp(): void
    {
        $now = microtime(true);
        $this->lastSuccessTime = $now - 2 * self::IP_ADDRESS_CACHE_TIME;
        $this->nextIpAddressResolveTime = $this->fromDbFlag ? $now + mt_rand(0, self::IP_ADDRESS_CACHE_TIME) : $this->lastSuccessTime;

        if (!$this->parameters->localMode) {
            if ($this->url->protocol === HttpUrl::HTTPS) {
                if (!in_array($this->url->port, [443, 88, 80, 8443], true)) {
                    $this->onError('Webhook can be set up only on ports 80, 88, 443 or 8443');
                }
            } else {
                $this->onError('An HTTPS URL must be provided for webhook');
            }
        }

        if ($this->fixIpAddress && !$this->stopFlag) {
            $error = $this->checkIpAddress($this->ipAddress);
            if ($error !== null) {
                $this->onError($error);
            }
        }

        if ($this->fromDbFlag && !$this->stopFlag) {
            $this->wasChecked = true;
            $this->onWebhookVerified();
        }
    }

    /**
     * New updates were added to the queue.
     */
    public function update(): void
    {
        if (!$this->closed) {
            $this->events->push(['update'], 0.001);
        }
    }

    /**
     * WebhookActor::close: the webhook is deleted.
     */
    public function close(): void
    {
        if (!$this->closed) {
            $this->events->push(['close'], 0.001);
        }
    }

    /**
     * Drop the webhook without notifying the client (the bot is closing).
     */
    public function hangup(): void
    {
        if (!$this->closed) {
            $this->events->push(['hangup'], 0.001);
        }
    }

    private function run(): void
    {
        $this->loop();
        while (!$this->closed) {
            $timeout = $this->wakeupAt === 0.0 ? -1 : max(0.001, $this->wakeupAt - microtime(true));
            $event = $this->events->pop($timeout);
            if ($event !== false) {
                switch ($event[0]) {
                    case 'update':
                        $this->tqueueEmpty = false;
                        break;
                    case 'close':
                        $this->call('webhookClosed', null);
                        $this->stop();

                        return;
                    case 'hangup':
                        $this->stop();

                        return;
                    case 'ip':
                        $this->onResolvedIpAddress($event[1]);
                        break;
                    case 'response':
                        if ($this->handle($event[1], $event[2])) {
                            $this->call('webhookClosed', null);
                            $this->stop();

                            return;
                        }
                        break;
                }
            }
            $this->loop();
        }
    }

    /**
     * WebhookActor::loop.
     */
    private function loop(): void
    {
        $this->wakeupAt = 0.0;
        if (!$this->stopFlag) {
            $this->loadUpdates();
        }
        if (!$this->stopFlag) {
            $this->resolveIpAddress();
        }
        if (!$this->stopFlag) {
            $this->createNewConnections();
        }
        if (!$this->stopFlag) {
            $this->sendUpdates();
        }
        if ($this->stopFlag) {
            $this->stop();
        }
    }

    private function stop(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->connections as $connection) {
            $connection['channel']->close();
        }
        self::$totalConnectionCount -= count($this->connections);
        $this->connections = [];
    }

    private function relaxWakeupAt(float $wakeupAt): void
    {
        if ($this->wakeupAt === 0.0 || $wakeupAt < $this->wakeupAt) {
            $this->wakeupAt = $wakeupAt;
        }
    }

    /**
     * @param mixed ...$arguments
     */
    private function call(string $method, mixed ...$arguments): void
    {
        ($this->callback)($method, ...$arguments);
    }

    // ═════════════════════════════════════════════════════════════════
    //  IP address
    // ═════════════════════════════════════════════════════════════════

    private function resolveIpAddress(): void
    {
        if ($this->fixIpAddress || $this->isIpAddressBeingResolved) {
            return;
        }
        if (microtime(true) < $this->nextIpAddressResolveTime) {
            $this->relaxWakeupAt($this->nextIpAddressResolveTime);

            return;
        }

        $this->isIpAddressBeingResolved = true;
        $host = $this->url->host;
        Coroutine::create(function () use ($host): void {
            $result = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : Coroutine\System::gethostbyname($host, AF_INET, 30);
            if (!$this->closed) {
                $this->events->push(['ip', $result === false || $result === '' ? null : $result]);
            }
        });
    }

    private function onResolvedIpAddress(?string $ipAddress): void
    {
        $this->isIpAddressBeingResolved = false;
        $this->nextIpAddressResolveTime = microtime(true) + self::IP_ADDRESS_CACHE_TIME + mt_rand(0, intdiv(self::IP_ADDRESS_CACHE_TIME, 10));
        $this->relaxWakeupAt($this->nextIpAddressResolveTime);

        if ($ipAddress === null) {
            $this->onError('Failed to resolve host: Name or service not known');

            return;
        }
        $error = $this->checkIpAddress($ipAddress);
        if ($error !== null) {
            $this->onError($error);

            return;
        }
        if ($this->ipAddress !== $ipAddress) {
            $this->ipAddress = $ipAddress;
            $this->ipGeneration++;
            if ($this->wasChecked) {
                $this->onWebhookVerified();
            }
        }
    }

    /**
     * WebhookActor::check_ip_address.
     */
    private function checkIpAddress(string $ipAddress): ?string
    {
        if ($ipAddress === '' || !IpAddress::isValid($ipAddress)) {
            return 'Invalid IP address specified';
        }
        if ($this->parameters->localMode) {
            return null;
        }
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return 'IPv6-only addresses are not allowed';
        }
        if (IpAddress::isReserved($ipAddress)) {
            return "IP address {$ipAddress} is reserved";
        }

        return null;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Connections
    // ═════════════════════════════════════════════════════════════════

    /**
     * WebhookActor::create_new_connections.
     */
    private function createNewConnections(): void
    {
        if ($this->ipAddress === '') {
            return;
        }

        $needConnections = min(count($this->queueUpdates), $this->maxConnections);
        if (!$this->wasChecked) {
            $needConnections = 1;
        }

        $now = microtime(true);
        if ($this->lastSuccessTime + 10 < $now) {
            $flood = $this->pendingNewConnectionFlood;
            $needConnections = min($needConnections, 1);
        } else {
            $flood = $this->activeNewConnectionFlood;
            $needConnections = max($needConnections, 1);
        }

        while (count($this->connections) < $needConnections) {
            $wakeupAt = $flood->getWakeupAt();
            if ($now < $wakeupAt) {
                $this->relaxWakeupAt($wakeupAt);
                break;
            }
            $flood->addEvent($now);
            $this->createConnection();
        }
    }

    /**
     * WebhookActor::create_connection: a connection coroutine owning one keep-alive HTTP client.
     */
    private function createConnection(): void
    {
        $id = $this->nextConnectionId++;
        $channel = new Channel(1);
        $this->connections[$id] = ['channel' => $channel, 'ip_generation' => $this->ipGeneration, 'event_id' => 0, 'busy' => false];
        self::$totalConnectionCount++;

        $ipAddress = $this->ipAddress;
        Coroutine::create(fn () => $this->runConnection($id, $channel, $ipAddress));

        if (!$this->wasChecked) {
            $this->wasChecked = true;
            $this->onWebhookVerified();
        }
    }

    private function runConnection(int $id, Channel $channel, string $ipAddress): void
    {
        $client = new HttpClient($ipAddress, $this->url->port, $this->url->protocol === HttpUrl::HTTPS);
        $settings = [
            'timeout' => self::IDLE_TIMEOUT,
            'keep_alive' => true,
            'websocket_mask' => false,
        ];
        if ($this->url->protocol === HttpUrl::HTTPS) {
            $settings['ssl_host_name'] = $this->url->host;
            $settings['ssl_verify_peer'] = true;
            $settings['ssl_allow_self_signed'] = false;
            if ($this->certPath !== '') {
                $settings['ssl_cafile'] = $this->certPath;
            } elseif (($cafile = (openssl_get_cert_locations()['default_cert_file'] ?? '')) !== '' && is_file($cafile)) {
                $settings['ssl_cafile'] = $cafile;
            }
        }
        if ($this->parameters->webhookProxy !== '') {
            [$proxyHost, $proxyPort] = array_pad(explode(':', $this->parameters->webhookProxy, 2), 2, '80');
            $settings['http_proxy_host'] = $proxyHost;
            $settings['http_proxy_port'] = (int) $proxyPort;
        }
        $client->set($settings);

        while (true) {
            $job = $channel->pop();
            if ($job === false) {
                break;
            }

            $client->setMethod('POST');
            $client->setHeaders($job['headers']);
            $client->setData($job['body']);
            $ok = $client->execute($job['path']);

            if (!$ok || $client->statusCode <= 0) {
                $error = self::socketErrorMessage($client);
                $client->close();
                if (!$this->closed) {
                    $this->events->push(['response', $id, ['error' => $error]]);
                }
                break;
            }

            $headers = [];
            foreach ($client->headers ?? [] as $name => $value) {
                if (strtolower((string) $name) === 'content-encoding') {
                    // the body is already decoded by the client
                    continue;
                }
                $headers[] = [strtolower((string) $name), RequestReader::trim(is_array($value) ? implode(', ', $value) : (string) $value)];
            }
            $response = [
                'code' => $client->statusCode,
                'reason' => self::reasonPhrase($client->statusCode),
                'headers' => $headers,
                'body' => (string) $client->body,
                'keep_alive' => strtolower((string) ($client->headers['connection'] ?? 'keep-alive')) !== 'close',
            ];
            if (!$this->closed) {
                $this->events->push(['response', $id, $response]);
            }
        }
        $client->close();
    }

    private static function socketErrorMessage(HttpClient $client): string
    {
        if ($client->statusCode === -2 || $client->errCode === SOCKET_ETIMEDOUT) {
            return 'Read timeout expired';
        }
        $message = $client->errMsg !== '' ? $client->errMsg : swoole_strerror($client->errCode);

        return $message === '' ? 'Connection closed' : $message;
    }

    private static function reasonPhrase(int $code): string
    {
        return match ($code) {
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
            301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
            408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 413 => 'Payload Too Large', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => '',
        };
    }

    // ═════════════════════════════════════════════════════════════════
    //  Updates
    // ═════════════════════════════════════════════════════════════════

    /**
     * WebhookActor::load_updates.
     */
    private function loadUpdates(): void
    {
        if ($this->tqueueEmpty) {
            return;
        }
        $loaded = count($this->updateMap);
        if ($loaded >= $this->maxLoadedUpdates) {
            return;
        }
        if ($this->tqueueOffset === 0) {
            $this->tqueueOffset = $this->tqueue->getHead();
        }

        $now = microtime(true);
        $unixTimeNow = time();
        $limit = min(1000, $this->maxLoadedUpdates - $loaded);
        $result = $this->tqueue->get($this->tqueueOffset, false, $unixTimeNow, $limit);
        if ($result === null) {
            $this->tqueueOffset = $this->tqueue->getHead();
            $result = $this->tqueue->get($this->tqueueOffset, false, $unixTimeNow, $limit) ?? [[], 0];
        }
        [$updates, $totalSize] = $result;
        if ($updates === []) {
            $this->tqueueEmpty = true;
        }

        foreach ($updates as $update) {
            $id = $update['id'];
            if (isset($this->updateMap[$id])) {
                continue;
            }
            $queueId = (int) ($update['extra'] ?? 0);
            if ($queueId === 0) {
                $queueId = $this->uniqueQueueId++;
            }
            $this->updateMap[$id] = [
                'id' => $id, 'json' => $update['data'], 'delay' => 1, 'wakeup_at' => $now,
                'expires_at' => $update['expires_at'], 'queue_id' => $queueId, 'fail_count' => 0, 'last_send_time' => 0.0,
            ];
            $this->tqueueOffset = $id + 1;

            if (!isset($this->queueUpdates[$queueId])) {
                $this->queueUpdates[$queueId] = [];
                $this->queues[$queueId] = $now;
            }
            $this->queueUpdates[$queueId][] = $id;
        }

        $needWarning = false;
        if ($totalSize <= self::MIN_PENDING_UPDATES_WARNING / 2) {
            if ($this->lastPendingUpdateCount > self::MIN_PENDING_UPDATES_WARNING) {
                $needWarning = true;
                $this->lastPendingUpdateCount = self::MIN_PENDING_UPDATES_WARNING;
            }
        } elseif ($totalSize >= $this->lastPendingUpdateCount) {
            $needWarning = true;
            while ($totalSize >= $this->lastPendingUpdateCount) {
                $this->lastPendingUpdateCount *= 2;
            }
        }
        if ($needWarning) {
            $this->parameters->logger->warning('Loaded '.count($updates)." updates out of {$totalSize}");
        }

        if (count($updates) === $totalSize && $this->lastUpdateWasSuccessful) {
            $this->call('webhookSuccess');
        }
    }

    private function dropEvent(int $eventId): void
    {
        $queueId = $this->updateMap[$eventId]['queue_id'];
        unset($this->updateMap[$eventId]);

        array_shift($this->queueUpdates[$queueId]);
        if ($this->queueUpdates[$queueId] === []) {
            unset($this->queueUpdates[$queueId], $this->queues[$queueId]);
        } else {
            $this->queues[$queueId] = $this->updateMap[$this->queueUpdates[$queueId][0]]['wakeup_at'];
        }

        $this->tqueue->forget($eventId);
    }

    private function onUpdateOk(int $eventId): void
    {
        $this->lastUpdateWasSuccessful = true;
        $this->lastSuccessTime = microtime(true);
        $this->dropEvent($eventId);
    }

    /**
     * WebhookActor::on_update_error.
     */
    private function onUpdateError(int $eventId, string $error, int $retryAfter): void
    {
        $this->lastUpdateWasSuccessful = false;
        $now = microtime(true);
        $update = &$this->updateMap[$eventId];

        $retryAfter = max(0, min(3600, $retryAfter));
        $nextDelay = $update['delay'];
        $nextEffectiveDelay = $retryAfter;
        if ($retryAfter === 0 && $update['fail_count'] > 0) {
            $maxTimeout = mt_rand(self::WEBHOOK_MAX_RESEND_TIMEOUT, self::WEBHOOK_MAX_RESEND_TIMEOUT * 2);
            $nextDelay = min($maxTimeout, $nextDelay * 2);
            $nextEffectiveDelay = $nextDelay;
        }
        if (time() + $nextEffectiveDelay > $update['expires_at']) {
            unset($update);
            $this->parameters->logger->warning("Drop update {$eventId}: {$error}");
            $this->dropEvent($eventId);

            return;
        }
        $update['delay'] = $nextDelay;
        $update['wakeup_at'] = $now + $nextEffectiveDelay;
        $update['fail_count']++;
        $this->queues[$update['queue_id']] = $update['wakeup_at'];
    }

    /**
     * WebhookActor::send_updates.
     */
    private function sendUpdates(): void
    {
        while (true) {
            $connectionId = null;
            foreach ($this->connections as $id => $connection) {
                if (!$connection['busy']) {
                    $connectionId = $id;
                    break;
                }
            }
            if ($connectionId === null || $this->queues === []) {
                return;
            }

            $now = microtime(true);
            $queueId = null;
            $wakeupAt = INF;
            foreach ($this->queues as $id => $at) {
                if ($at < $wakeupAt) {
                    $wakeupAt = $at;
                    $queueId = $id;
                }
            }
            if ($wakeupAt > $now) {
                $this->relaxWakeupAt($wakeupAt);

                return;
            }

            unset($this->queues[$queueId]);
            $eventId = $this->queueUpdates[$queueId][0];
            $update = &$this->updateMap[$eventId];
            $update['last_send_time'] = $now;

            $body = '{"update_id":'.$eventId.",\n".$update['json'].'}';
            unset($update);
            $headers = ['Host' => $this->hostHeader];
            if ($this->url->userinfo !== '') {
                $headers['Authorization'] = 'Basic '.base64_encode($this->url->userinfo);
            }
            if ($this->secretToken !== '') {
                $headers['X-Telegram-Bot-Api-Secret-Token'] = $this->secretToken;
            }
            $headers['Content-Type'] = 'application/json';
            $headers['Connection'] = 'keep-alive';
            $headers['Accept-Encoding'] = 'gzip, deflate';

            $this->connections[$connectionId]['busy'] = true;
            $this->connections[$connectionId]['event_id'] = $eventId;
            $this->connections[$connectionId]['channel']->push(['path' => $this->url->query === '' ? '/' : $this->url->query, 'headers' => $headers, 'body' => $body]);
        }
    }

    /**
     * WebhookActor::handle (and on_connection_error + hangup).
     *
     * @param array<string, mixed> $response
     * @return bool whether the webhook must be closed
     */
    private function handle(int $connectionId, array $response): bool
    {
        if (!isset($this->connections[$connectionId])) {
            return false;
        }
        $connection = $this->connections[$connectionId];

        $closeConnection = false;
        $queryError = '';
        $retryAfter = 0;
        $needClose = false;

        if (isset($response['error'])) {
            $this->onWebhookError($response['error']);
            $queryError = 'Webhook connection closed';
            $closeConnection = true;
        } else {
            if (!$response['keep_alive'] || $this->ipGeneration !== $connection['ip_generation']) {
                $closeConnection = true;
            }
            $code = $response['code'];
            if ($code >= 200 && $code <= 299) {
                $this->processResponseQuery($response);
                $this->first410ErrorTime = 0.0;
            } else {
                $queryError = "Wrong response from the webhook: {$code} {$response['reason']}";
                if ($code === 410) {
                    if ($this->first410ErrorTime === 0.0) {
                        $this->first410ErrorTime = microtime(true);
                    } elseif (microtime(true) > $this->first410ErrorTime + self::WEBHOOK_DROP_TIMEOUT) {
                        $this->parameters->logger->warning('Close webhook because of HTTP 410 errors');
                        $needClose = true;
                    }
                } else {
                    $this->first410ErrorTime = 0.0;
                }
                foreach ($response['headers'] as [$name, $value]) {
                    if ($name === 'retry-after') {
                        $value = trim($value);
                        $retryAfter = ctype_digit($value) && (string) (int) $value === $value ? (int) $value : 0;
                    }
                }
                $this->onWebhookError($queryError);
            }
        }

        $eventId = $connection['event_id'];
        if ($eventId !== 0 && isset($this->updateMap[$eventId])) {
            if ($queryError === '') {
                $this->onUpdateOk($eventId);
            } else {
                $this->onUpdateError($eventId, $queryError, $retryAfter);
            }
        }

        if ($needClose || $closeConnection) {
            $connection['channel']->close();
            unset($this->connections[$connectionId]);
            self::$totalConnectionCount--;
        } else {
            $this->connections[$connectionId]['busy'] = false;
            $this->connections[$connectionId]['event_id'] = 0;
        }

        return $needClose;
    }

    /**
     * A request in the response to the webhook is executed like an API call.
     *
     * @param array<string, mixed> $response
     */
    private function processResponseQuery(array $response): void
    {
        $contentType = '';
        foreach ($response['headers'] as [$name, $value]) {
            if ($name === 'content-type') {
                $contentType = $value;
            }
        }
        if ($response['body'] === '' || (!str_contains(strtolower($contentType), 'json') && !str_contains(strtolower($contentType), 'form'))) {
            return;
        }

        try {
            $read = (new RequestReader($this->parameters->tempDirectory, $this->parameters->logger))
                ->read('/', $response['headers'], $response['body']);
        } catch (HttpReaderException) {
            return;
        }

        $method = '';
        foreach ($read['args'] as [$key, $value]) {
            if ($key === 'method') {
                $method = strtolower($value);
                break;
            }
        }
        if ($method === '' || $method === 'deletewebhook' || $method === 'setwebhook' || $method === 'close'
            || $method === 'logout' || str_starts_with($method, 'get')) {
            foreach ($read['files'] as $file) {
                $file->delete();
            }

            return;
        }

        $this->call('sendResponseQuery', $read['args'], $response['headers'], $read['files']);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Callbacks
    // ═════════════════════════════════════════════════════════════════

    private function onWebhookVerified(): void
    {
        $this->call('webhookVerified', $this->ipAddress);
    }

    /**
     * WebhookActor::on_error: before verification the webhook is closed with the error.
     */
    private function onError(string $message): void
    {
        if (!$this->wasChecked && !$this->stopFlag) {
            $this->call('webhookClosed', $message);
            $this->stopFlag = true;
        }
    }

    private function onWebhookError(string $error): void
    {
        if ($this->wasChecked) {
            $this->call('webhookError', $error);
        }
    }
}
