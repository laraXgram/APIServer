<?php

declare(strict_types=1);

namespace LaraGram\Server\Foundation;

use LaraGram\Log\LoggerInterface;
use LaraGram\Server\Generated\MethodTable;

/**
 * Process-wide settings shared by every bot (ClientParameters + the parts
 * of SharedData a PHP worker needs).
 */
final class ServerParameters
{
    public string $version = MethodTable::VERSION;

    public string $workingDirectory = './';

    public string $filesDirectory = './';

    public string $tempDirectory = '';

    public bool $allowColonInFilenames = true;

    public bool $localMode = false;

    public int $apiId = 0;

    public string $apiHash = '';

    public int $defaultMaxWebhookConnections = 0;

    /** HTTP proxy for outgoing webhook requests, "host:port". */
    public string $webhookProxy = '';

    public float $startTime;

    /** @var (\Closure(int): mixed)|null changes the log verbosity level */
    public ?\Closure $setVerbosity = null;

    /** The webhooks database (SharedData::webhook_db_). */
    public ?\LaraGram\Server\Webhook\WebhookDb $webhookDb = null;

    /** Largest local-minus-server clock difference reported by any bot (SharedData::unix_time_difference_). */
    public float $unixTimeDifference = -1e100;

    public function __construct(public LoggerInterface $logger)
    {
        $this->startTime = microtime(true);
    }

    /**
     * SharedData::get_unix_time().
     */
    public function getUnixTime(float $now): int
    {
        $result = $this->unixTimeDifference + $now;
        if ($result <= 0) {
            return 0;
        }

        return $result >= 2147483647 ? 2147483647 : (int) $result;
    }
}
