<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot;

use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Contracts\Store;
use LaraGram\MTProto\Store\FileStore;
use LaraGram\Server\Bot\Concerns\BuildsRequests;
use LaraGram\Server\Bot\Concerns\ForwardsMessages;
use LaraGram\Server\Bot\Concerns\HandlesBusiness;
use LaraGram\Server\Bot\Concerns\HandlesFiles;
use LaraGram\Server\Bot\Concerns\HandlesRichMessages;
use LaraGram\Server\Bot\Concerns\HandlesWebhook;
use LaraGram\Server\Bot\Concerns\ParsesArguments;
use LaraGram\Server\Bot\Concerns\ParsesMedia;
use LaraGram\Server\Bot\Concerns\ReceivesChatUpdates;
use LaraGram\Server\Bot\Concerns\ReceivesQueries;
use LaraGram\Server\Bot\Concerns\ReceivesUpdates;
use LaraGram\Server\Bot\Concerns\ResolvesChats;
use LaraGram\Server\Bot\Concerns\SendsMessages;
use LaraGram\Server\Bot\Methods\BotManagementMethods;
use LaraGram\Server\Bot\Methods\BotSettingsMethods;
use LaraGram\Server\Bot\Methods\BusinessMethods;
use LaraGram\Server\Bot\Methods\ChatMethods;
use LaraGram\Server\Bot\Methods\EditMethods;
use LaraGram\Server\Bot\Methods\EphemeralMethods;
use LaraGram\Server\Bot\Methods\FileMethods;
use LaraGram\Server\Bot\Methods\ForumTopicMethods;
use LaraGram\Server\Bot\Methods\ForwardMethods;
use LaraGram\Server\Bot\Methods\GameMethods;
use LaraGram\Server\Bot\Methods\GiftMethods;
use LaraGram\Server\Bot\Methods\InlineMethods;
use LaraGram\Server\Bot\Methods\InviteLinkMethods;
use LaraGram\Server\Bot\Methods\MessageMethods;
use LaraGram\Server\Bot\Methods\PassportMethods;
use LaraGram\Server\Bot\Methods\PaymentMethods;
use LaraGram\Server\Bot\Methods\PollMethods;
use LaraGram\Server\Bot\Methods\SendMethods;
use LaraGram\Server\Bot\Methods\StarMethods;
use LaraGram\Server\Bot\Methods\StickerMethods;
use LaraGram\Server\Bot\Methods\StoryMethods;
use LaraGram\Server\Bot\Methods\UserMethods;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Updates\UpdateQueue;
use LaraGram\Server\Updates\UpdateType;
use LaraGram\MTProto\Auth\Authorization;
use LaraGram\MTProto\Core\Client as MTProtoClient;
use LaraGram\MTProto\Core\DeviceProfile;
use LaraGram\MTProto\Updates\PumpLoop;
use LaraGram\Server\Errors\TdError;
use LaraGram\Server\Foundation\ServerParameters;
use LaraGram\Server\Generated\MethodTable;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Stats\BotStatActor;
use LaraGram\Server\Objects\JsonUser;
use LaraGram\Server\Td\PollManager;
use Swoole\Coroutine;
use Swoole\Timer;

/**
 * One bot: its MTProto connection, its state and the handlers of every
 * Bot API method. A port of telegram_bot_api::Client, with TDLib requests
 * replaced by MTProto calls.
 */
final class Client
{
    use BuildsRequests;
    use ForwardsMessages;
    use HandlesBusiness;
    use HandlesRichMessages;
    use HandlesFiles;
    use HandlesWebhook;
    use ParsesArguments;
    use ParsesMedia;
    use ReceivesChatUpdates;
    use ReceivesQueries;
    use ReceivesUpdates;
    use ResolvesChats;
    use SendsMessages;
    use SendMethods;
    use ForwardMethods;
    use EditMethods;
    use MessageMethods;
    use ChatMethods;
    use PollMethods;
    use PaymentMethods;
    use InlineMethods;
    use BotSettingsMethods;
    use InviteLinkMethods;
    use ForumTopicMethods;
    use UserMethods;
    use FileMethods;
    use StarMethods;
    use BusinessMethods;
    use GiftMethods;
    use StickerMethods;
    use GameMethods;
    use PassportMethods;
    use BotManagementMethods;
    use EphemeralMethods;
    use StoryMethods;

    /**
     * Methods the official server answers that the documentation does not
     * list: old names kept for compatibility and the custom-request pair.
     */
    public const EXTRA_METHODS = [
        'getchatmemberscount' => 'processGetChatMemberCountQuery',
        'kickchatmember' => 'processBanChatMemberQuery',
        'setstickersetthumb' => 'processSetStickerSetThumbnailQuery',
        'sendcustomrequest' => 'processSendCustomRequestQuery',
        'answercustomquery' => 'processAnswerCustomQueryQuery',
    ];

    private const SESSION_NAME = 'bot';

    /** UserManager::get_service_notifications_user_id */
    private const SERVICE_NOTIFICATIONS_USER_ID = 777000;

    /** Persistent per-bot options (TDLib keeps them in its own database). */
    private ?Store $options = null;

    private float $startTime = 0.0;

    private string $botTokenId = '<unknown>';

    private string $botTokenWithDc;

    private string $dir = '';

    private string $filesDir = '';

    private ?MTProtoClient $td = null;

    private ?FileStore $chatInfoDb = null;

    private ?PumpLoop $pump = null;

    private int $myId = -1;

    private int $authorizationDate = -1;

    private bool $wasAuthorized = false;

    private bool $loggingOut = false;

    private bool $closing = false;

    private bool $closed = false;

    private bool $needClose = false;

    private bool $clearTqueue = false;

    private bool $isApiIdInvalid = false;

    private float $nextAuthorizationTime = 0.0;

    private int $prevRetryAfter = 0;

    private string $retryAfterErrorMessage = '';

    private ?int $stopTimer = null;

    /** @var \SplQueue<Query> */
    private \SplQueue $cmdQueue;

    private ?PollManager $polls = null;

    /** @var array<string, mixed>|null help.getAppConfig values by key (ConfigManager) */
    private ?array $appConfig = null;

    private int $appConfigTime = 0;

    /** @var array<int, array<string, mixed>> TL `user` objects by id */
    private array $users = [];

    /** @var array<int, array<string, mixed>> TL `chat`/`chatForbidden` objects (basic groups) by id */
    private array $basicGroups = [];

    /** @var array<int, array<string, mixed>> TL `channel`/`channelForbidden` objects (supergroups, channels) by id */
    private array $channels = [];

    /** @var array<int, array{title: string}> community id => community */
    private array $communities = [];

    public function __construct(
        private readonly ClientManager $manager,
        private readonly string $key,
        private readonly string $botToken,
        private readonly bool $isTestDc,
        private readonly int $tqueueId,
        private readonly ServerParameters $parameters,
        private readonly BotStatActor $statActor = new BotStatActor(),
    ) {
        $this->cmdQueue = new \SplQueue();
        $this->botTokenWithDc = $botToken.($isTestDc ? ':T' : '');
    }

    // ═════════════════════════════════════════════════════════════════
    //  Lifecycle
    // ═════════════════════════════════════════════════════════════════

    public function startUp(): void
    {
        $this->startTime = microtime(true);

        $colon = strpos($this->botToken, ':');
        $secret = substr($this->botToken, $colon + 1);
        if (strlen($secret) < 24 || !self::isBase64Url($secret)) {
            $this->parameters->logger->warning("Wrong bot token {$this->botToken}");
            $this->loggingOut = true;
            $this->closed = true;
            $this->finishClosing();

            return;
        }

        $this->botTokenId = substr($this->botToken, 0, $colon);

        $suffix = $this->botTokenWithDc.'/';
        if (!$this->parameters->allowColonInFilenames) {
            $suffix = str_replace(':', '~', $suffix);
        }
        $this->dir = $this->parameters->workingDirectory.$suffix;
        $this->filesDir = $this->parameters->filesDirectory.$suffix;

        $this->options = new FileStore($this->dir.'options');
        $this->tqueue = new UpdateQueue(new FileStore($this->dir.'tqueue'));
        $allowed = $this->options->get('allowed_update_types');
        $this->allowedUpdateTypes = $allowed === null ? UpdateType::DEFAULT_ALLOWED : (int) $allowed;
        $this->authorizationDate = (int) ($this->options->get('authorization_date') ?? -1);
        $this->chatInfoDb = new FileStore($this->dir.'chat_info_db');
        $this->loadChatInfo();
        $this->seedSpecialUsers();

        Coroutine::create(fn () => $this->authorize());
    }

    /**
     * Connect, log in with the bot token and start receiving updates.
     */
    private function authorize(): void
    {
        try {
            $files = new Filesystem();
            $files->ensureDirectoryExists($this->dir, 0700);
            $files->ensureDirectoryExists($this->filesDir, 0700);

            $this->td = new MTProtoClient($this->parameters->apiId, $this->parameters->apiHash, [
                'test_mode' => $this->isTestDc,
                'session_dir' => $this->dir,
                'use_pump' => true,
                'flood_sleep' => false,
                'prime_peer_cache' => false,
                'logger' => $this->parameters->logger,
                'files' => $files,
                'device' => new DeviceProfile('server', php_uname('s').' '.php_uname('r'), $this->parameters->version, 'en', '', 'en'),
            ]);

            $this->td->connect(self::SESSION_NAME);
            $result = (new Authorization($this->td))->botLogin($this->botToken);
            $me = ($result['_'] ?? '') === 'user' ? $result : ($result['user'] ?? []);

            $this->addUser($me);
            $this->myId = (int) ($me['id'] ?? -1);
            if ($this->myId <= 0) {
                throw new \RuntimeException('getMe returned no user');
            }

            // the update state is saved after each update, as TDLib does, so a restart doesn't repeat updates
            $this->pump = (new PumpLoop($this->td))->onUpdate(function ($update, string $type): void {
                $this->onUpdate($update->toArray());
                $this->pump?->saveState();
            });
            $this->pump->getFeed()->onEntities(fn (array $users, array $chats) => $this->addEntities($users, $chats));
            $this->pump->boot();
        } catch (\Throwable $e) {
            $error = TdError::fromThrowable($e);
            if ($error->code === 500) {
                $this->parameters->logger->error("[bot{$this->botTokenId}] authorization failed: {$e->getMessage()}");
            }
            $this->logOut($error->code, $error->message);

            return;
        }

        $username = JsonUser::activeUsernames($this->users[$this->myId])[0] ?? '';
        $this->parameters->logger->warning("Logged in as @{$username}");
        $this->wasAuthorized = true;
        if ($this->authorizationDate <= 0) {
            $this->authorizationDate = $this->getUnixTime();
            $this->setOption('authorization_date', (string) $this->authorizationDate);
        }
        $this->loop();
    }

    public function send(Query $query): void
    {
        if (!$query->isInternal) {
            $query->setStatActor($this->statActor);
            $now = microtime(true);
            if (!$this->parameters->localMode && !self::isLocalMethod($query->method()) && $now > $this->parameters->startTime + 60) {
                $updatesPerMinute = (int) ($this->statActor->getMinuteUpdateCount($now) * 60);
                if ($this->statActor->getActiveRequestCount() > 1000 + $updatesPerMinute
                    || ($this->statActor->getActiveFileUploadBytes() > (1 << 32) && $query->files !== [])
                    || ($this->statActor->getActiveFileUploadCount() > 100 + intdiv($updatesPerMinute, 5) && $query->files !== [])) {
                    $this->failQueryFloodLimitExceeded($query);

                    return;
                }
            }
        }
        $this->cmdQueue->enqueue($query);
        $this->loop();
    }

    /**
     * Client::is_local_method.
     */
    private static function isLocalMethod(string $method): bool
    {
        return in_array($method, ['close', 'logout', 'getme', 'getupdates', 'setwebhook', 'deletewebhook', 'getwebhookinfo'], true);
    }

    /**
     * Client::get_bot_info: the bot's line of the statistics page.
     *
     * @return array<string, mixed>
     */
    public function getServerBotInfo(): array
    {
        $colon = strpos($this->botToken, ':');
        $user = $this->users[$this->myId] ?? null;
        if ($user !== null) {
            $username = (string) ($user['username'] ?? '');
        } elseif (!$this->wasAuthorized) {
            $username = $this->loggingOut ? '<failed to authorize>' : '<unauthorized>';
        } else {
            $username = '<unknown>';
        }

        return [
            'id' => $colon === false ? '<unknown>' : substr($this->botToken, 0, $colon),
            'token' => $this->botToken,
            'username' => $username,
            'webhook' => $this->webhookUrl,
            'has_webhook_certificate' => $this->hasWebhookCertificate,
            'head_update_id' => $this->tqueue?->getHead() ?? 0,
            'tail_update_id' => $this->tqueue?->getTail() ?? 0,
            'webhook_max_connections' => $this->webhookMaxConnections,
            'pending_update_count' => $this->tqueue?->size() ?? 0,
            'start_time' => $this->startTime,
        ];
    }

    private function loop(): void
    {
        if ($this->wasAuthorized || $this->loggingOut || $this->closing) {
            while (!$this->cmdQueue->isEmpty()) {
                $this->onCmd($this->cmdQueue->dequeue());
            }
        }
    }

    private function onCmd(Query $query): void
    {
        if (!$this->closed && $this->wasAuthorized) {
            if ($query->method() === 'close') {
                $this->processCloseQuery($query);

                return;
            }
            if ($query->method() === 'logout') {
                $this->processLogOutQuery($query);

                return;
            }
        }

        if ($this->loggingOut || $this->closing) {
            $this->failQueryClosing($query);

            return;
        }

        $handler = MethodTable::METHODS[$query->method()] ?? self::EXTRA_METHODS[$query->method()] ?? null;
        if ($handler === null || $query->method() === 'close' || $query->method() === 'logout') {
            $query->fail(404, 'Not Found: method not found');

            return;
        }

        if (!method_exists($this, $handler)) {
            $query->fail(501, 'Not Implemented: the method is not supported yet');

            return;
        }

        try {
            $this->{$handler}($query);
        } catch (QueryError $e) {
            if ($query->isReady()) {
                return;
            }
            if ($e->parameters !== []) {
                $query->fail($e->getCode(), $e->getMessage(), $e->parameters);

                return;
            }
            $this->failQueryWithError($query, $e->getCode(), $e->getMessage(), $e->defaultMessage);
        } catch (\Throwable $e) {
            $this->parameters->logger->error("[bot{$this->botTokenId}] {$query->method()} failed: {$e->getMessage()}", ['exception' => $e]);
            if (!$query->isReady()) {
                $error = TdError::fromThrowable($e);
                $this->failQueryWithError($query, $error->code, $error->message);
            }
        }
    }

    /**
     * Log out after a failed authorization (Client::log_out).
     */
    private function logOut(int $errorCode, string $errorMessage): void
    {
        $this->parameters->logger->warning("Logging out due to error {$errorCode}: {$errorMessage}");

        if ($errorMessage === 'API_ID_INVALID') {
            $this->isApiIdInvalid = true;
        } elseif ($errorCode === 429) {
            if (($retryAfter = self::getRetryAfterTime($errorMessage)) > 0) {
                $this->nextAuthorizationTime = max($this->nextAuthorizationTime, microtime(true) + $retryAfter);
            }
        } elseif ($errorCode >= 500) {
            $this->nextAuthorizationTime = max($this->nextAuthorizationTime, microtime(true) + 1);
        }

        if (!$this->loggingOut && !$this->closing) {
            $this->loggingOut = true;
            $this->disconnect();
            $this->onClosed();
        }
    }

    /**
     * Close the bot without logging out (server shutdown).
     */
    public function close(): void
    {
        $this->needClose = true;
        if ($this->closed) {
            $this->stop();

            return;
        }

        if (!$this->loggingOut && !$this->closing) {
            $this->closing = true;
            $this->disconnect();
            $this->onClosed();
        }
    }

    private function disconnect(): void
    {
        try {
            $this->pump?->stop();
            $this->td?->disconnect();
        } catch (\Throwable) {
        }
        $this->pump = null;
    }

    /**
     * Client::on_closed: every waiting query fails with the closing error.
     */
    private function onClosed(): void
    {
        $this->parameters->logger->warning('Closed');
        $this->closed = true;
        $this->td = null;

        $this->closeWebhook();
        if ($this->longPollQuery !== null) {
            $this->longPollWakeup(true);
        }

        while (!$this->cmdQueue->isEmpty()) {
            $this->failQueryClosing($this->cmdQueue->dequeue());
        }

        if ($this->loggingOut) {
            (new Filesystem())->deleteDirectory($this->dir);
            if ($this->filesDir !== $this->dir) {
                (new Filesystem())->deleteDirectory($this->filesDir);
            }
        }

        $this->finishClosing();
    }

    /**
     * Stop now when asked to close; otherwise keep the closed client around
     * for a while, so new queries fail fast instead of logging in again.
     */
    private function finishClosing(): void
    {
        if ($this->needClose) {
            $this->stop();

            return;
        }

        if ($this->nextAuthorizationTime <= 0.0) {
            $timeout = $this->wasAuthorized && $this->authorizationDate < $this->getUnixTime() - 1800 ? 1.0 : 1800.0;
        } else {
            $timeout = min($this->nextAuthorizationTime - microtime(true), 1800.0);
        }

        $this->parameters->logger->info("Keep client opened for {$timeout} seconds");
        $this->stopTimer = Timer::after(max(1, (int) ($timeout * 1000)), fn () => $this->stop());
    }

    private function stop(): void
    {
        if ($this->stopTimer !== null) {
            Timer::clear($this->stopTimer);
            $this->stopTimer = null;
        }

        $this->manager->onClientStopped($this->key);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Errors
    // ═════════════════════════════════════════════════════════════════

    private function failQueryClosing(Query $query): void
    {
        [$code, $message, $retryAfter] = $this->getClosingError();
        if ($retryAfter > 0) {
            $query->setRetryAfterError($retryAfter);
        } else {
            $query->fail($code, $message);
        }
    }

    /**
     * @return array{0: int, 1: string, 2: int} code, message, retry_after
     */
    private function getClosingError(): array
    {
        if (!$this->loggingOut) {
            return [500, 'Internal Server Error: restart', 0];
        }

        if ($this->isApiIdInvalid) {
            return [401, 'Unauthorized: invalid api-id/api-hash', 0];
        }

        if ($this->nextAuthorizationTime > 0.0) {
            $retryAfter = max((int) ($this->nextAuthorizationTime - microtime(true)), 0) + 1;
            if ($retryAfter !== $this->prevRetryAfter) {
                $this->prevRetryAfter = $retryAfter;
                $this->retryAfterErrorMessage = "Too Many Requests: retry after {$retryAfter}";
            }

            return [429, $this->retryAfterErrorMessage, $retryAfter];
        }

        if ($this->clearTqueue) {
            return [400, 'Logged out', 0];
        }

        return [401, 'Unauthorized', 0];
    }

    public static function getRetryAfterTime(string $errorMessage): int
    {
        $prefix = 'Too Many Requests: retry after ';
        if (str_starts_with($errorMessage, $prefix)) {
            $value = substr($errorMessage, strlen($prefix));
            if (ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * Client::fail_query_with_error: turn a TDLib/Telegram error into the
     * Bot API status code and description.
     */
    public function failQueryWithError(Query $query, int $errorCode, string $errorMessage, string $defaultMessage = ''): void
    {
        if ($errorCode === 429) {
            if (($retryAfter = self::getRetryAfterTime($errorMessage)) > 0) {
                $query->setRetryAfterError($retryAfter);

                return;
            }
            $this->parameters->logger->error("Wrong error message: {$errorMessage} from {$query->method()}");
            $query->fail(500, $errorMessage);

            return;
        }

        $realErrorCode = $errorCode;
        $realErrorMessage = $errorMessage;

        if ($errorCode < 400 || $errorCode === 404) {
            if ($errorCode < 200) {
                $this->parameters->logger->error("Receive error \"{$realErrorMessage}\" with code {$errorCode} from {$query->method()}");
            }
            $errorCode = 400;
        } elseif ($errorCode === 403 && strspn($errorMessage, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_') === strlen($errorMessage)) {
            $errorCode = 400;
        }

        if ($errorCode === 400) {
            if ($defaultMessage !== '') {
                $errorMessage = $defaultMessage;
            }

            [$errorCode, $errorMessage] = match ($errorMessage) {
                'MESSAGE_NOT_MODIFIED' => [400, 'message is not modified: specified new message content and reply markup are exactly the same as a current content and reply markup of the message'],
                'WC_CONVERT_URL_INVALID', 'EXTERNAL_URL_INVALID' => [400, 'Wrong HTTP URL specified'],
                'WEBPAGE_CURL_FAILED' => [400, 'Failed to get HTTP URL content'],
                'WEBPAGE_MEDIA_EMPTY' => [400, 'Wrong type of the web page content'],
                'MEDIA_GROUPED_INVALID' => [400, "Can't use the media of the specified type in the album"],
                'REPLY_MARKUP_TOO_LONG' => [400, 'reply markup is too long'],
                'INPUT_USER_DEACTIVATED' => [403, 'Forbidden: user is deactivated'],
                'USER_IS_BLOCKED' => [403, 'bot was blocked by the user'],
                'USER_ADMIN_INVALID' => [400, 'user is an administrator of the chat'],
                'File generation failed' => [400, "can't upload file by URL"],
                'CHAT_ABOUT_NOT_MODIFIED' => [400, 'chat description is not modified'],
                'PACK_SHORT_NAME_INVALID' => [400, 'invalid sticker set name is specified'],
                'PACK_SHORT_NAME_OCCUPIED' => [400, 'sticker set name is already occupied'],
                'STICKER_EMOJI_INVALID' => [400, 'invalid sticker emojis'],
                'QUERY_ID_INVALID' => [400, 'query is too old and response timeout expired or query ID is invalid'],
                'MESSAGE_DELETE_FORBIDDEN' => [400, "message can't be deleted"],
                'RANK_CHANGE_FORBIDDEN', 'RANK_EMOJI_NOT_ALLOWED', 'RANK_INVALID' => [
                    400,
                    ($query->method() === 'setchatmembertag' ? 'TAG' : 'CUSTOM_TITLE').substr($errorMessage, 4),
                ],
                default => [400, $errorMessage],
            };
        }

        $prefix = match ($errorCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            500 => 'Internal Server Error',
            default => null,
        };

        if ($prefix === null) {
            if (!($errorCode === 406 && $errorMessage === 'FROZEN_METHOD_INVALID')) {
                $this->parameters->logger->error("Unsupported error {$realErrorCode}: {$realErrorMessage} from {$query->method()}");
            }
            $query->fail(400, 'Bad Request: '.$errorMessage);

            return;
        }

        if ($errorCode === 500 && $realErrorMessage !== 'Request aborted') {
            $this->parameters->logger->error("Receive Internal Server Error \"{$realErrorMessage}\" from {$query->method()}");
        }

        if (str_starts_with($errorMessage, $prefix)) {
            $query->fail($errorCode, $errorMessage);

            return;
        }

        $description = $prefix;
        if ($errorMessage === '') {
            $this->parameters->logger->error("Empty error message with code {$realErrorCode} from {$query->method()}");
        } else {
            $description .= ': ';
            if (strlen($errorMessage) >= 2 && ($errorMessage[1] === '_' || ctype_upper($errorMessage[1]))) {
                $description .= $errorMessage;
            } else {
                $description .= strtolower($errorMessage[0]).substr($errorMessage, 1);
            }
        }

        $query->fail($errorCode, $description);
    }

    // ═════════════════════════════════════════════════════════════════
    //  State
    // ═════════════════════════════════════════════════════════════════

    /**
     * @return array<string, mixed>|null
     */
    /**
     * A value of the server app config (ConfigManager::process_app_config),
     * reloaded once an hour.
     */
    public function getAppConfigValue(string $key, mixed $default): mixed
    {
        $now = $this->getUnixTime();
        if ($this->appConfig === null || $now - $this->appConfigTime >= 3600) {
            try {
                $result = $this->invoke('help.getAppConfig', ['hash' => 0]);
                if (($result['_'] ?? '') === 'help.appConfig') {
                    $config = self::jsonValueToPhp($result['config'] ?? []);
                    $this->appConfig = is_array($config) ? $config : [];
                    $this->appConfigTime = $now;
                }
            } catch (\Throwable) {
                $this->appConfig ??= [];
                $this->appConfigTime = $now - 3600 + 60;
            }
        }

        return $this->appConfig[$key] ?? $default;
    }

    public function getAppConfigInteger(string $key, int $default): int
    {
        $value = $this->getAppConfigValue($key, null);

        return is_int($value) || is_float($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $value TL JSONValue
     */
    private static function jsonValueToPhp(array $value): mixed
    {
        switch ($value['_'] ?? '') {
            case 'jsonObject':
                $result = [];
                foreach ($value['value'] ?? [] as $item) {
                    $result[(string) $item['key']] = self::jsonValueToPhp($item['value']);
                }

                return $result;
            case 'jsonArray':
                return array_map(static fn (array $item): mixed => self::jsonValueToPhp($item), $value['value'] ?? []);
            case 'jsonNumber':
                return (float) $value['value'];
            case 'jsonString':
                return (string) $value['value'];
            case 'jsonBool':
                return (bool) $value['value'];
            default:
                return null;
        }
    }

    public function polls(): PollManager
    {
        return $this->polls ??= new PollManager($this);
    }

    public function getUser(int $userId): ?array
    {
        return $this->users[$userId] ?? null;
    }

    /**
     * Call a Telegram method, keeping the users and chats of its result.
     *
     * @param array<string, mixed> $params
     */
    public function invoke(string $method, array $params = []): mixed
    {
        $result = $this->td->invoke($method, $params);

        if (is_array($result) && (isset($result['users']) || isset($result['chats']))) {
            $this->addEntities($result['users'] ?? [], $result['chats'] ?? []);
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<string, mixed>> $chats
     */
    private function addEntities(array $users, array $chats): void
    {
        foreach ($users as $user) {
            $this->addUser($user);
        }
        foreach ($chats as $chat) {
            $this->addChat($chat);
        }
    }

    /**
     * @param array<string, mixed> $chat
     */
    private function addChat(array $chat): void
    {
        $type = $chat['_'] ?? '';
        if (!empty($chat['id']) && ($type === 'community' || $type === 'communityForbidden')) {
            // CommunityManager: the Bot API needs only the community name
            if (!empty($chat['min']) && isset($this->communities[$chat['id']])) {
                return;
            }
            $community = ['title' => (string) ($chat['title'] ?? '')];
            if (($this->communities[$chat['id']] ?? null) !== $community) {
                $this->communities[$chat['id']] = $community;
                $this->saveChatInfo('co'.$chat['id'], $community);
            }

            return;
        }
        if (empty($chat['id']) || !in_array($type, ['chat', 'chatForbidden', 'channel', 'channelForbidden'], true)) {
            return;
        }

        if ($type === 'chat' || $type === 'chatForbidden') {
            if (($this->basicGroups[$chat['id']] ?? null) !== $chat) {
                $this->basicGroups[$chat['id']] = $chat;
                $this->saveChatInfo('c'.$chat['id'], $chat);
            }

            return;
        }

        // A "min" channel carries a partial profile; keep the full one we have.
        if (!empty($chat['min']) && isset($this->channels[$chat['id']])) {
            return;
        }

        if (($this->channels[$chat['id']] ?? null) !== $chat) {
            $this->channels[$chat['id']] = $chat;
            $this->saveChatInfo('ch'.$chat['id'], $chat);
        }
    }

    /**
     * The chat info database (use_chat_info_database): users and chats survive restarts.
     */
    private function loadChatInfo(): void
    {
        foreach (glob($this->dir.'chat_info_db/*') ?: [] as $path) {
            $key = basename($path);
            $value = @unserialize((string) @file_get_contents($path), ['allowed_classes' => false]);
            if (!is_array($value) || preg_match('/^(u|c|ch|co)(\d+)$/', $key, $match) !== 1) {
                continue;
            }
            $id = (int) $match[2];
            match ($match[1]) {
                'u' => $this->users[$id] = $value,
                'c' => $this->basicGroups[$id] = $value,
                'ch' => $this->channels[$id] = $value,
                'co' => $this->communities[$id] = $value,
            };
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function saveChatInfo(string $key, array $value): void
    {
        try {
            $this->chatInfoDb?->put($key, serialize($value));
        } catch (\Throwable $e) {
            $this->parameters->logger->warning("Can't save chat info {$key}: {$e->getMessage()}");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBasicGroup(int $chatId): ?array
    {
        return $this->basicGroups[$chatId] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getChannel(int $channelId): ?array
    {
        return $this->channels[$channelId] ?? null;
    }

    /**
     * Client::get_community_info()->name.
     */
    public function getCommunityName(int $communityId): string
    {
        return $this->communities[$communityId]['title'] ?? '';
    }

    /**
     * @param array<string, mixed> $user
     */
    private function addUser(array $user): void
    {
        if (($user['_'] ?? '') !== 'user' || empty($user['id'])) {
            return;
        }

        // A "min" user carries a partial profile; keep the full one we have.
        if (!empty($user['min']) && isset($this->users[$user['id']])) {
            return;
        }

        if (($this->users[$user['id']] ?? null) !== $user) {
            $this->users[$user['id']] = $user;
            $this->saveChatInfo('u'.$user['id'], $user);
        }
    }

    public function getMyId(): int
    {
        return $this->myId;
    }

    public function getTqueueId(): int
    {
        return $this->tqueueId;
    }

    public function getGroupAnonymousBotUserId(): int
    {
        return $this->isTestDc ? 552888 : 1087968824;
    }

    public function getChannelBotUserId(): int
    {
        return $this->isTestDc ? 936174 : 136817688;
    }

    public function getServiceNotificationsUserId(): int
    {
        return self::SERVICE_NOTIFICATIONS_USER_ID;
    }

    /**
     * Whether $chatId is a broadcast channel (not a supergroup).
     */
    public function isBroadcastChannel(int $chatId): bool
    {
        if (ChatId::type($chatId) !== ChatId::TYPE_SUPERGROUP) {
            return false;
        }
        $channel = $this->getChannel(ChatId::toChannelId($chatId));

        return $channel !== null && empty($channel['megagroup']);
    }

    /**
     * An InputChannel; bots may use access_hash 0 for channels they never saw
     * (ChatManager::get_input_channel).
     *
     * @return array<string, mixed>
     */
    public function inputChannel(int $channelId): array
    {
        return ['_' => 'inputChannel', 'channel_id' => $channelId, 'access_hash' => (int) ($this->channels[$channelId]['access_hash'] ?? 0)];
    }

    /**
     * @return array<string, mixed>
     */
    public function inputUser(int $userId): array
    {
        if ($userId === $this->myId) {
            return ['_' => 'inputUserSelf'];
        }

        return ['_' => 'inputUser', 'user_id' => $userId, 'access_hash' => (int) ($this->users[$userId]['access_hash'] ?? 0)];
    }

    private function setOption(string $name, ?string $value): void
    {
        $value === null ? $this->options?->forget($name) : $this->options?->put($name, $value);
    }

    /**
     * UserManager::get_user_force: the service accounts TDLib knows offline,
     * so messages sent on behalf of chats can name a sender.
     */
    private function seedSpecialUsers(): void
    {
        $test = $this->isTestDc;
        $special = [
            ['id' => self::SERVICE_NOTIFICATIONS_USER_ID, 'first_name' => 'Telegram', 'last_name' => $test ? 'Notifications' : null, 'username' => null, 'bot' => false, 'phone' => '42777'],
            ['id' => $this->getGroupAnonymousBotUserId(), 'first_name' => 'Group', 'username' => $test ? 'izgroupbot' : 'GroupAnonymousBot', 'bot' => true],
            ['id' => $this->getChannelBotUserId(), 'first_name' => $test ? 'Channels' : 'Channel', 'username' => $test ? 'channelsbot' : 'Channel_Bot', 'bot' => true],
        ];

        foreach ($special as $user) {
            if (!isset($this->users[$user['id']])) {
                $this->users[$user['id']] = array_filter(['_' => 'user'] + $user, static fn ($v) => $v !== null && $v !== false);
            }
        }
    }

    public function getUnixTime(): int
    {
        return time();
    }

    /**
     * td::is_base64url_characters.
     */
    public static function isBase64UrlCharacters(string $value): bool
    {
        return strspn($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_') === strlen($value);
    }

    private static function isBase64Url(string $value): bool
    {
        return strspn($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_') === strlen(rtrim($value, '='))
            && base64_decode(strtr($value, '-_', '+/'), true) !== false;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Handlers
    // ═════════════════════════════════════════════════════════════════

    public function processGetMeQuery(Query $query): void
    {
        $query->answerQuery(JsonUser::make($this->myId, $this, true));
    }

    public function processCloseQuery(Query $query): void
    {
        $retryAfter = (int) (10 * 60 - (microtime(true) - $this->startTime));
        if ($retryAfter > 0 && $this->startTime > $this->parameters->startTime + 10 * 60) {
            $query->setRetryAfterError($retryAfter);

            return;
        }

        $this->needClose = true;
        $this->closing = true;
        $this->disconnect();
        $query->answerQuery(true);
        $this->onClosed();
    }

    public function processLogOutQuery(Query $query): void
    {
        $this->clearTqueue = true;

        try {
            $this->invoke('auth.logOut');
        } catch (\Throwable $e) {
            $error = TdError::fromThrowable($e);
            $this->failQueryWithError($query, $error->code, $error->message);

            return;
        }

        $this->loggingOut = true;
        $this->disconnect();
        $query->answerQuery(true);
        $this->onClosed();
    }
}
