<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot;

use LaraGram\Server\Foundation\ServerParameters;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Stats\BotStatActor;
use LaraGram\Server\Stats\ServerCpuStat;
use LaraGram\Server\Stats\Stats;
use LaraGram\Server\Support\FloodControlFast;
use LaraGram\Server\Support\IpAddress;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Webhook\WebhookActor;
use LaraGram\Server\Webhook\WebhookDb;

/**
 * Routes queries to per-bot clients, creating them on first use:
 * a port of telegram_bot_api::ClientManager.
 */
final class ClientManager
{
    /** @var array<string, Client> token (with "/test" for the test DC) => client */
    private array $clients = [];

    /** @var array<string, FloodControlFast> client creation limits per IP address */
    private array $floodControls = [];

    private FloodControlFast $globalFloodControl;

    private bool $isGlobalFloodControlEnabled = false;

    private bool $closeFlag = false;

    /** The statistics of the whole server; each bot's statistics add to it. */
    private BotStatActor $stat;

    /** @var array<string, BotStatActor> client key => its statistics */
    private array $clientStats = [];

    /**
     * @param array{0: int, 1: int} $tokenRange remainder/modulo filter on bot ids (--filter)
     */
    public function __construct(
        private readonly ServerParameters $parameters,
        private readonly array $tokenRange = [0, 1],
    ) {
        $this->globalFloodControl = new FloodControlFast();
        $this->stat = new BotStatActor();
        $this->parameters->webhookDb ??= new WebhookDb($this->parameters->workingDirectory.'webhooks_db.json');
    }

    /**
     * ClientManager::start_up: restore the saved webhooks.
     */
    public function restoreWebhooks(): void
    {
        $webhookDb = $this->parameters->webhookDb;
        foreach ($webhookDb->all() as $key => $value) {
            if (!$this->inTokenRange(TdInteger::toInt64($key))) {
                $this->parameters->logger->warning("DROP WEBHOOK: {$key} ---> {$value}");
                $webhookDb->erase($key);
                continue;
            }
            $this->send(self::getWebhookRestoreQuery($key, $value, $this->parameters));
        }
    }

    /**
     * ClientManager::get_webhook_restore_query.
     */
    private static function getWebhookRestoreQuery(string $token, string $webhookInfo, ServerParameters $parameters): Query
    {
        $parameters->logger->warning("WEBHOOK: {$token} ---> {$webhookInfo}");

        $isTestDc = false;
        if (str_ends_with($token, ':T')) {
            $token = substr($token, 0, -2);
            $isTestDc = true;
        }

        $args = [];
        $rest = $webhookInfo;
        if (str_starts_with($rest, 'cert/')) {
            $args[] = ['certificate', 'previous'];
            $rest = substr($rest, 5);
        }
        foreach (['#maxc' => 'max_connections', '#ip' => 'ip_address', '#fix_ip' => 'fix_ip_address', '#secret' => 'secret_token', '#allow' => 'allowed_updates'] as $prefix => $name) {
            if (str_starts_with($rest, $prefix)) {
                $rest = substr($rest, strlen($prefix));
                $end = strpos($rest, '/');
                $value = $end === false ? $rest : substr($rest, 0, $end);
                $args[] = [$name, $prefix === '#fix_ip' ? '1' : $value];
                $rest = $end === false ? '' : substr($rest, $end + 1);
            }
        }
        $args[] = ['url', $rest];

        return new Query($token, $isTestDc, 'setwebhook', $args, [], [], '', true);
    }

    /**
     * Global client creation limits start shortly after start-up outside
     * local mode (ClientManager::timeout_expired).
     */
    public function tick(): void
    {
        if (!$this->isGlobalFloodControlEnabled && !$this->parameters->localMode) {
            $this->isGlobalFloodControlEnabled = true;
            $this->globalFloodControl->addLimit(60, 1000);
            $this->globalFloodControl->addLimit(60 * 60, 10000);
        }
    }

    public function send(Query $query): void
    {
        if ($this->closeFlag) {
            // A dropped query is answered "retry after 5" (PromiseDeleter).
            $query->setRetryAfterError(5);

            return;
        }

        $token = $query->token;
        if ($token === '' || $token[0] === '0' || strlen($token) > 80 || str_contains($token, '/') || !str_contains($token, ':')) {
            $query->fail(401, 'Unauthorized: invalid token specified');

            return;
        }

        $idPart = substr($token, 0, strpos($token, ':'));
        $userId = TdInteger::toInt64Safe($idPart);
        if ($userId === null || !$this->inTokenRange($userId)) {
            $query->fail(421, 'Misdirected Request: forbidden token specified');

            return;
        }
        if ($userId <= 0 || $userId >= (1 << 54)) {
            $query->fail(401, 'Unauthorized: invalid token specified');

            return;
        }

        $key = $query->isTestDc ? $token.'/test' : $token;

        if (!isset($this->clients[$key])) {
            if ($query->method() === 'close') {
                $query->fail(400, 'Bad Request: the bot has already been closed');

                return;
            }

            $ip = $query->getPeerIpAddress();
            if ($ip !== '') {
                $ip = IpAddress::normalize($ip);
                $now = microtime(true);
                $floodControl = $this->floodControls[$ip] ??= (new FloodControlFast())
                    ->addLimit(60, 20)
                    ->addLimit(60 * 60, 600);

                $wakeupAt = $floodControl->getWakeupAt();
                if ($wakeupAt > $now) {
                    $this->parameters->logger->info("Failed to create Client from IP address {$ip} with token");
                    $query->setRetryAfterError((int) ($wakeupAt - $now) + 1);

                    return;
                }
                $floodControl->addEvent($now);
            }

            if ($this->isGlobalFloodControlEnabled) {
                $now = microtime(true);
                $wakeupAt = $this->globalFloodControl->getWakeupAt();
                if ($wakeupAt > $now) {
                    $this->parameters->logger->warning("Failed to create Client with token {$token}");
                    $query->setRetryAfterError((int) ($wakeupAt - $now) + 1);

                    return;
                }
                $this->globalFloodControl->addEvent($now);
            }

            $tqueueId = self::getTqueueId($userId, $query->isTestDc);
            $this->clientStats[$key] = new BotStatActor($this->stat);
            $client = new Client($this, $key, $token, $query->isTestDc, $tqueueId, $this->parameters, $this->clientStats[$key]);
            $this->clients[$key] = $client;
            $client->startUp();

            $method = $query->method();
            if ($method !== 'deletewebhook' && $method !== 'setwebhook') {
                $botTokenWithDc = $token.($query->isTestDc ? ':T' : '');
                $webhookInfo = $this->parameters->webhookDb->get($botTokenWithDc);
                if ($webhookInfo !== '') {
                    $client->send(self::getWebhookRestoreQuery($botTokenWithDc, $webhookInfo, $this->parameters));
                }
            }
        }

        $this->clients[$key]->send($query);
    }

    /**
     * Close every bot (on SIGTERM/SIGINT). Resolves once all of them stopped.
     */
    public function close(): void
    {
        if ($this->closeFlag) {
            return;
        }

        $this->closeFlag = true;
        foreach ($this->clients as $client) {
            $client->close();
        }
    }

    public function isClosed(): bool
    {
        return $this->closeFlag && $this->clients === [];
    }

    /**
     * A client finished and stopped (ClientManager::hangup_shared).
     */
    public function onClientStopped(string $key): void
    {
        unset($this->clients[$key], $this->clientStats[$key]);
    }

    /**
     * ClientManager::get_stats: the statistics page.
     *
     * @param list<array{0: string, 1: string}> $args
     */
    public function getStats(array $args): string
    {
        if ($this->closeFlag) {
            return 'Closing';
        }
        $idFilter = '';
        foreach ($args as [$key, $value]) {
            if ($key === 'id') {
                $idFilter = $value;
            }
            if ($key === 'v' && ($level = TdInteger::toInt32Safe($value)) !== null && $level > 0) {
                ($this->parameters->setVerbosity)?->__invoke($level);
            }
        }

        $now = microtime(true);
        [$activeCount, $topKeys] = $this->getTopClients(50, $idFilter);
        $lines = [BotStatActor::getDescription()];
        if ($idFilter === '') {
            $lines[] = "uptime\t".Stats::double($now - $this->parameters->startTime);
            $lines[] = "bot_count\t".count($this->clients);
            $lines[] = "active_bot_count\t".$activeCount;
            $memory = Stats::memory();
            if ($memory !== null) {
                $lines[] = "rss\t".Stats::size($memory['rss']);
                $lines[] = "vm\t".Stats::size($memory['vm']);
                $lines[] = "rss_peak\t".Stats::size($memory['rss_peak']);
                $lines[] = "vm_peak\t".Stats::size($memory['vm_peak']);
            }
            foreach (ServerCpuStat::instance()->asVector($now) as [$key, $value]) {
                $lines[] = $key."\t".$value;
            }
            $lines[] = "buffer_memory\t".Stats::size(memory_get_usage());
            $lines[] = "active_webhook_connections\t".WebhookActor::getTotalConnectionCount();
            $lines[] = "active_requests\t".$this->stat->getActiveRequestCount();
            $lines[] = "active_network_queries\t0";
            foreach ($this->stat->asVector($now) as [$key, $value]) {
                $lines[] = $key."\t".$value;
            }
        }

        foreach ($topKeys as $key) {
            $client = $this->clients[$key];
            $stat = $this->clientStats[$key];
            $info = $client->getServerBotInfo();
            $lines[] = '';
            $lines[] = "id\t".$info['id'];
            $lines[] = "uptime\t".Stats::double($now - $info['start_time']);
            $lines[] = "token\t".$info['token'];
            $lines[] = "username\t".$info['username'];
            if ($stat->getActiveRequestCount() !== 0) {
                $lines[] = "active_request_count\t".$stat->getActiveRequestCount();
            }
            if ($stat->getActiveFileUploadBytes() !== 0) {
                $lines[] = "active_file_upload_bytes\t".$stat->getActiveFileUploadBytes();
            }
            if ($stat->getActiveFileUploadCount() !== 0) {
                $lines[] = "active_file_upload_count\t".$stat->getActiveFileUploadCount();
            }
            if ($info['webhook'] !== '') {
                $lines[] = "webhook\t".$info['webhook'];
                if ($info['has_webhook_certificate']) {
                    $lines[] = "has_custom_certificate\t1";
                }
                if ($info['webhook_max_connections'] !== $this->parameters->defaultMaxWebhookConnections) {
                    $lines[] = "webhook_max_connections\t".$info['webhook_max_connections'];
                }
            }
            $lines[] = "head_update_id\t".$info['head_update_id'];
            if ($info['pending_update_count'] !== 0) {
                $lines[] = "tail_update_id\t".$info['tail_update_id'];
                $lines[] = "pending_update_count\t".$info['pending_update_count'];
            }
            foreach ($stat->asVector($now) as [$name, $value]) {
                if ($name === 'update_count' || $name === 'request_count') {
                    $lines[] = $name."/sec\t".$value;
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * ClientManager::get_top_clients: the number of active bots and the busiest bots.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function getTopClients(int $maxCount, string $tokenFilter): array
    {
        $now = microtime(true);
        $activeCount = 0;
        $scores = [];
        foreach ($this->clients as $key => $client) {
            $stat = $this->clientStats[$key] ?? null;
            if ($stat === null) {
                continue;
            }
            if ($stat->isActive($now)) {
                $activeCount++;
            }
            if (!str_starts_with($key, $tokenFilter)) {
                continue;
            }
            $score = (int) ($stat->getScore($now) * -1e9);
            if ($score === 0 && count($scores) >= $maxCount) {
                continue;
            }
            $scores[] = [$score, $key];
        }
        usort($scores, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return [$activeCount, array_map(static fn (array $item): string => $item[1], array_slice($scores, 0, $maxCount))];
    }

    /**
     * @return array<string, Client>
     */
    public function clients(): array
    {
        return $this->clients;
    }

    public static function getTqueueId(int $userId, bool $isTestDc): int
    {
        return $userId + ((int) $isTestDc << 54);
    }

    private function inTokenRange(int $userId): bool
    {
        [$remainder, $modulo] = $this->tokenRange;

        return $userId % $modulo === $remainder;
    }
}
