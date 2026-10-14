<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Filesystem\Filesystem;
use LaraGram\Server\Http\HttpFile;
use LaraGram\Server\Http\JsonRawString;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Support\HttpUrl;
use LaraGram\Server\Updates\UpdateType;
use LaraGram\Server\Webhook\WebhookActor;

/**
 * setWebhook, deleteWebhook and getWebhookInfo with the webhook life cycle
 * of telegram_bot_api::Client (do_set_webhook, webhook_verified, …).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait HandlesWebhook
{
    private const BOT_UPDATES_WARNING_DELAY = 30.0;
    private const MAX_CERTIFICATE_FILE_SIZE = 3 << 10;
    private const MIN_PENDING_UPDATES_WARNING = 200;

    private ?WebhookActor $webhookActor = null;

    private int $webhookGeneration = 1;

    private ?Query $webhookSetQuery = null;

    private ?Query $activeWebhookSetQuery = null;

    private bool $webhookQueryIsVerify = false;

    private bool $hasWebhookCertificate = false;

    private int $webhookMaxConnections = 0;

    private string $webhookIpAddress = '';

    private bool $webhookFixIpAddress = false;

    private string $webhookSecretToken = '';

    private float $webhookSetTime = 0.0;

    private int $lastWebhookErrorDate = 0;

    private string $lastWebhookError = '';

    private float $nextAllowedSetWebhookTime = 0.0;

    private float $nextBotUpdatesWarningTime = 0.0;

    private float $nextWebhookIsNotModifiedWarningTime = 0.0;

    private float $nextSetWebhookLoggingTime = 0.0;

    private bool $wasBotUpdatesWarning = false;

    private int $lastSynchronizationErrorDate = 0;

    public function processSetWebhookQuery(Query $query): void
    {
        $newUrl = $query->method() === 'setwebhook' ? $query->arg('url') : '';

        $now = microtime(true);
        if ($newUrl !== '' && !$query->isInternal) {
            if ($now < $this->nextAllowedSetWebhookTime) {
                $query->setRetryAfterError(1);

                return;
            }
            $this->nextAllowedSetWebhookTime = $now + 1;
        }

        // do not send warning just after webhook was deleted or set
        $this->nextBotUpdatesWarningTime = max($this->nextBotUpdatesWarningTime, $now + self::BOT_UPDATES_WARNING_DELAY);

        $newHasCertificate = $newUrl !== '' && ($this->getWebhookCertificate($query) !== null
            || ($query->isInternal && $query->arg('certificate') === 'previous'));
        $newMaxConnections = $newUrl === '' ? 0 : $this->getWebhookMaxConnections($query);
        $newIpAddress = $newUrl === '' ? '' : $query->arg('ip_address');
        $newFixIpAddress = $newUrl !== '' && self::getWebhookFixIpAddress($query);
        $newSecretToken = $newUrl === '' ? '' : $query->arg('secret_token');
        $dropPendingUpdates = self::boolArg($query, 'drop_pending_updates');

        if ($this->webhookSetQuery !== null) {
            // already updating webhook; cancel the previous request
            $this->failQueryConflict('Conflict: terminated by other setWebhook', $this->webhookSetQuery);
            $this->webhookSetQuery = null;
        } elseif ($this->activeWebhookSetQuery !== null) {
            $query->setRetryAfterError(1);

            return;
        } elseif ($this->webhookUrl === $newUrl && !$this->hasWebhookCertificate && !$newHasCertificate
            && $newMaxConnections === $this->webhookMaxConnections && $newFixIpAddress === $this->webhookFixIpAddress
            && $newSecretToken === $this->webhookSecretToken
            && (!$newFixIpAddress || $newIpAddress === $this->webhookIpAddress) && !$dropPendingUpdates) {
            if ($this->updateAllowedUpdateTypes($query)) {
                $this->saveWebhook();
            } elseif ($now > $this->nextWebhookIsNotModifiedWarningTime) {
                $this->nextWebhookIsNotModifiedWarningTime = $now + 300;
                $this->parameters->logger->warning("Webhook is not modified: \"{$newUrl}\"");
            }
            $query->answerQuery(true, $newUrl === '' ? 'Webhook is already deleted' : 'Webhook is already set');

            return;
        }

        if ($now > $this->nextSetWebhookLoggingTime || $this->webhookUrl !== $newUrl) {
            $this->nextSetWebhookLoggingTime = $now + 300;
            $this->parameters->logger->warning("Set webhook to {$newUrl}, max_connections = {$newMaxConnections}, IP address = {$newIpAddress}, drop_pending_updates = ".($dropPendingUpdates ? 'true' : 'false'));
        }

        if ($newUrl !== '') {
            $this->abortLongPoll(true);
        }

        $this->webhookGeneration++;
        // need to close the old webhook first
        if ($this->webhookUrl !== '') {
            $this->webhookActor?->close();

            // wait for webhook_closed
            $this->webhookQueryIsVerify = false;
            $this->webhookSetQuery = $query;

            return;
        }
        $this->doSetWebhook($query, false);
    }

    public function processDeleteWebhookQuery(Query $query): void
    {
        $this->processSetWebhookQuery($query);
    }

    public function processGetWebhookInfoQuery(Query $query): void
    {
        $this->updateLastSynchronizationErrorDate();

        $object = [
            'url' => mb_check_encoding($this->webhookUrl, 'UTF-8') ? $this->webhookUrl : new JsonRawString($this->webhookUrl),
            'has_custom_certificate' => $this->hasWebhookCertificate,
            'pending_update_count' => $this->getPendingUpdateCount(),
        ];
        if ($this->lastWebhookErrorDate > 0) {
            $object['last_error_date'] = $this->lastWebhookErrorDate;
            $object['last_error_message'] = mb_check_encoding($this->lastWebhookError, 'UTF-8') ? $this->lastWebhookError : new JsonRawString($this->lastWebhookError);
        }
        if ($this->webhookMaxConnections > 0) {
            $object['max_connections'] = $this->webhookMaxConnections;
        }
        if ($this->webhookUrl !== '') {
            $object['ip_address'] = $this->webhookIpAddress === '' ? '<unknown>' : $this->webhookIpAddress;
        }
        if ($this->allowedUpdateTypes !== UpdateType::DEFAULT_ALLOWED) {
            $types = [];
            for ($i = 0; $i < UpdateType::SIZE; $i++) {
                if ((($this->allowedUpdateTypes >> $i) & 1) !== 0) {
                    $types[] = UpdateType::NAMES[$i];
                }
            }
            $object['allowed_updates'] = $types;
        }
        if ($this->lastSynchronizationErrorDate > 0) {
            $object['last_synchronization_error_date'] = $this->lastSynchronizationErrorDate;
        }

        $query->answerQuery($object);
    }

    public function getPendingUpdateCount(): int
    {
        return $this->tqueue?->size() ?? 0;
    }

    /**
     * Client::update_last_synchronization_error_date.
     */
    private function updateLastSynchronizationErrorDate(): void
    {
        // the MTProto connection keeps its updates in sync; no synchronization errors are tracked
    }

    private function hasActiveWebhook(): bool
    {
        return $this->webhookUrl !== '' || $this->webhookSetQuery !== null || $this->activeWebhookSetQuery !== null;
    }

    private function getWebhookCertificatePath(): string
    {
        return $this->dir.'cert.pem';
    }

    private function getWebhookCertificate(Query $query): ?HttpFile
    {
        $file = $query->file('certificate');
        if ($file === null) {
            $attachName = $query->arg('certificate');
            if (str_starts_with($attachName, 'attach://')) {
                $file = $query->file(substr($attachName, strlen('attach://')));
            }
        }

        return $file;
    }

    private function getWebhookMaxConnections(Query $query): int
    {
        $default = $this->parameters->defaultMaxWebhookConnections;
        $max = $this->parameters->localMode ? 100000 : 100;

        return self::getIntegerArg($query, 'max_connections', $default, 1, $max);
    }

    private static function getWebhookFixIpAddress(Query $query): bool
    {
        if ($query->isInternal) {
            return $query->hasArg('fix_ip_address');
        }

        return $query->arg('ip_address') !== '';
    }

    /**
     * Client::do_set_webhook.
     */
    private function doSetWebhook(Query $query, bool $wasDeleted): void
    {
        if ($this->loggingOut || $this->closing) {
            $this->failQueryClosing($query);

            return;
        }
        if (self::boolArg($query, 'drop_pending_updates')) {
            $this->tqueue?->clear(0);
        }
        $newUrl = $query->method() === 'setwebhook' ? $query->arg('url') : '';
        if ($newUrl === '') {
            $query->answerQuery(true, $wasDeleted ? 'Webhook was deleted' : 'Webhook is already deleted');

            return;
        }

        try {
            HttpUrl::parse($newUrl, HttpUrl::HTTPS);
        } catch (\InvalidArgumentException) {
            $query->fail(400, 'Bad Request: invalid webhook URL specified');

            return;
        }
        $secretToken = $query->arg('secret_token');
        if (strlen($secretToken) > 256) {
            $query->fail(400, 'Bad Request: secret token is too long');

            return;
        }
        if (!self::isBase64UrlCharacters($secretToken)) {
            $query->fail(400, 'Bad Request: secret token contains illegal characters');

            return;
        }

        if ($this->activeWebhookSetQuery !== null) {
            // shouldn't happen, unless the active setWebhook request took more than 1 second
            $query->setRetryAfterError(1);

            return;
        }

        if ($query->isInternal) {
            $this->hasWebhookCertificate = $query->arg('certificate') === 'previous';
        } else {
            $certificate = $this->getWebhookCertificate($query);
            if ($certificate !== null) {
                $size = $certificate->size;
                if ($size > self::MAX_CERTIFICATE_FILE_SIZE) {
                    $query->fail(400, "Bad Request: certificate size is too big ({$size} bytes)");

                    return;
                }
                $files = new Filesystem();
                try {
                    $files->ensureDirectoryExists($this->dir, 0700);
                    $copied = @copy($certificate->tempFileName, $this->getWebhookCertificatePath());
                } catch (\Throwable) {
                    $copied = false;
                }
                if (!$copied) {
                    $query->fail(500, 'Internal Server Error: failed to save certificate');

                    return;
                }
                $this->hasWebhookCertificate = true;
            }
        }
        $this->finishSetWebhook($query);
    }

    /**
     * Client::finish_set_webhook.
     */
    private function finishSetWebhook(Query $query): void
    {
        if ($this->loggingOut || $this->closing) {
            $this->failQueryClosing($query);

            return;
        }
        $newUrl = $query->arg('url');
        $this->webhookUrl = $newUrl;
        $this->webhookSetTime = microtime(true);
        $this->webhookMaxConnections = $this->getWebhookMaxConnections($query);
        $this->webhookSecretToken = $query->arg('secret_token');
        $this->webhookIpAddress = $query->arg('ip_address');
        $this->webhookFixIpAddress = self::getWebhookFixIpAddress($query);
        $this->lastWebhookErrorDate = 0;
        $this->lastWebhookError = '';

        $this->updateAllowedUpdateTypes($query);

        $url = HttpUrl::parse($newUrl, HttpUrl::HTTPS);
        $this->parameters->logger->warning('Create '.($this->hasWebhookCertificate ? 'self-signed ' : '')."webhook: {$newUrl}");

        $generation = $this->webhookGeneration;
        $this->webhookActor = new WebhookActor(
            function (string $method, mixed ...$arguments) use ($generation): void {
                // only webhook_verified checks the generation (get_link_token)
                if ($method !== 'webhookVerified' || $generation === $this->webhookGeneration) {
                    $this->{$method}(...$arguments);
                }
            },
            $this->tqueue,
            $url,
            $this->hasWebhookCertificate ? $this->getWebhookCertificatePath() : '',
            $this->webhookMaxConnections,
            $query->isInternal,
            $this->webhookIpAddress,
            $this->webhookFixIpAddress,
            $this->webhookSecretToken,
            $this->parameters,
        );
        // wait for webhook_verified or webhook_closed
        $this->webhookQueryIsVerify = true;
        $this->webhookSetQuery = $query;
        $this->webhookActor->start();
    }

    /**
     * Client::webhook_verified.
     */
    public function webhookVerified(string $cachedIpAddress): void
    {
        $needSave = $this->webhookSetQuery !== null || $cachedIpAddress !== $this->webhookIpAddress;
        $this->webhookIpAddress = $cachedIpAddress;
        if ($this->webhookSetQuery !== null) {
            $this->parameters->logger->warning('Webhook verified');
            $this->webhookSetQuery->answerQuery(true, 'Webhook was set');
            $this->webhookSetQuery = null;
        }
        if ($needSave) {
            $this->saveWebhook();
        }
    }

    /**
     * Client::save_webhook.
     */
    private function saveWebhook(): void
    {
        $value = '';
        if ($this->hasWebhookCertificate) {
            $value .= 'cert/';
        }
        $value .= '#maxc'.$this->webhookMaxConnections.'/';
        if ($this->webhookIpAddress !== '') {
            $value .= '#ip'.$this->webhookIpAddress.'/';
        }
        if ($this->webhookFixIpAddress) {
            $value .= '#fix_ip/';
        }
        if ($this->webhookSecretToken !== '') {
            $value .= '#secret'.$this->webhookSecretToken.'/';
        }
        if ($this->allowedUpdateTypes !== UpdateType::DEFAULT_ALLOWED) {
            $value .= '#allow'.$this->allowedUpdateTypes.'/';
        }
        $value .= $this->webhookUrl;
        $this->parameters->webhookDb?->set($this->botTokenWithDc, $value);
    }

    /**
     * Client::webhook_success.
     */
    public function webhookSuccess(): void
    {
        $this->nextBotUpdatesWarningTime = microtime(true) + self::BOT_UPDATES_WARNING_DELAY;
        if ($this->wasBotUpdatesWarning) {
            $this->setBotUpdatesStatus(0, '');
            $this->wasBotUpdatesWarning = false;
        }
    }

    /**
     * Client::webhook_error.
     */
    public function webhookError(string $error): void
    {
        $this->lastWebhookErrorDate = $this->getUnixTime();
        $this->lastWebhookError = $error;

        $pendingUpdateCount = $this->getPendingUpdateCount();
        if ($pendingUpdateCount >= self::MIN_PENDING_UPDATES_WARNING && microtime(true) > $this->nextBotUpdatesWarningTime) {
            $this->setBotUpdatesStatus($pendingUpdateCount, 'Webhook error. '.$error);
            $this->nextBotUpdatesWarningTime = microtime(true) + self::BOT_UPDATES_WARNING_DELAY;
            $this->wasBotUpdatesWarning = true;
        }
    }

    private function setBotUpdatesStatus(int $pendingUpdateCount, string $message): void
    {
        \Swoole\Coroutine::create(function () use ($pendingUpdateCount, $message): void {
            try {
                $this->invoke('help.setBotUpdatesStatus', ['pending_updates_count' => $pendingUpdateCount, 'message' => $message]);
            } catch (\Throwable) {
                // TdOnOkCallback ignores the result
            }
        });
    }

    /**
     * Client::webhook_closed + on_webhook_closed.
     */
    public function webhookClosed(?string $error): void
    {
        if ($this->hasWebhookCertificate) {
            @unlink($this->getWebhookCertificatePath());
        }

        $this->parameters->logger->warning('Webhook closed: '.($error ?? 'OK').', webhook_query_type = '.($this->webhookQueryIsVerify ? 'verify' : 'change'));
        $this->webhookActor = null;
        $this->webhookUrl = '';
        $this->hasWebhookCertificate = false;
        $this->webhookMaxConnections = 0;
        $this->webhookIpAddress = '';
        $this->webhookFixIpAddress = false;
        $this->webhookSecretToken = '';
        $this->webhookSetTime = microtime(true);
        $this->lastWebhookErrorDate = 0;
        $this->lastWebhookError = '';
        $this->parameters->webhookDb?->erase($this->botTokenWithDc);

        if ($this->webhookSetQuery !== null) {
            $query = $this->webhookSetQuery;
            $this->webhookSetQuery = null;
            if ($this->webhookQueryIsVerify) {
                $query->fail(400, 'Bad Request: bad webhook: '.($error ?? 'OK'));
            } else {
                $this->doSetWebhook($query, true);
            }
        }
    }

    /**
     * A request in the webhook response, executed like an API call (Callback::send).
     *
     * @param list<array{0: string, 1: string}> $args
     * @param list<array{0: string, 1: string}> $headers
     * @param list<HttpFile> $files
     */
    public function sendResponseQuery(array $args, array $headers, array $files): void
    {
        $query = new Query($this->botToken, $this->isTestDc, '', $args, $headers, $files, '', false);
        $this->send($query);
        \Swoole\Coroutine::create(static function () use ($query): void {
            $query->wait();
            $query->cleanup();
        });
    }

    /**
     * Client::on_closed: the webhook is dropped while the bot is closing.
     */
    private function closeWebhook(): void
    {
        if ($this->webhookSetQuery !== null) {
            $this->failQueryClosing($this->webhookSetQuery);
            $this->webhookSetQuery = null;
        }
        if ($this->activeWebhookSetQuery !== null) {
            $this->failQueryClosing($this->activeWebhookSetQuery);
            $this->activeWebhookSetQuery = null;
        }
        if ($this->webhookUrl !== '') {
            $this->webhookGeneration++;
            $this->webhookActor?->hangup();
            $this->webhookActor = null;
        }
        if ($this->loggingOut) {
            $this->parameters->webhookDb?->erase($this->botTokenWithDc);
        }
    }

    /**
     * New updates were queued: wake the webhook (Client::add_update_impl).
     */
    private function notifyWebhook(): void
    {
        $this->webhookActor?->update();
    }
}
