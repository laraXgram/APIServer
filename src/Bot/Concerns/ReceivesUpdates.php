<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Http\JsonWriter;
use LaraGram\Server\Objects\JsonPoll;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonMessage;
use LaraGram\Server\Objects\JsonSticker;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Updates\UpdateQueue;
use LaraGram\Server\Updates\UpdateType;
use Swoole\Timer;

/**
 * Turning Telegram updates into Bot API updates, the update queue and
 * getUpdates long polling (the update half of telegram_bot_api::Client).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ReceivesUpdates
{
    private const LONG_POLL_MAX_TIMEOUT = 50;
    private const LONG_POLL_MAX_DELAY = 0.002;
    private const LONG_POLL_WAIT_AFTER = 0.001;
    private const MAX_CACHED_MESSAGES = 100000;

    private ?UpdateQueue $tqueue = null;

    private int $allowedUpdateTypes = UpdateType::DEFAULT_ALLOWED;

    /** @var array<int, array{queue: list<array{0: MessageInfo, 1: bool}>, active: bool}> */
    private array $newMessageQueues = [];

    /** @var array<string, MessageInfo> "chat_id:message_id" => message */
    private array $messages = [];

    private ?Query $longPollQuery = null;

    private int $longPollOffset = 0;

    private int $longPollLimit = 0;

    private float $longPollHardTimeout = 0.0;

    private bool $longPollWasWakeup = false;

    private ?int $longPollTimer = null;

    private int $previousGetUpdatesOffset = -1;

    private float $previousGetUpdatesStartTime = 0.0;

    private float $nextGetUpdatesConflictTime = 0.0;

    private string $webhookUrl = '';

    /** @var array<int, string> sticker set id => short name ('' when it cannot be loaded) */
    private array $stickerSetNames = [];

    // ═════════════════════════════════════════════════════════════════
    //  Telegram updates
    // ═════════════════════════════════════════════════════════════════

    /**
     * @param array<string, mixed> $update a TL Update
     */
    private function onUpdate(array $update): void
    {
        if ($this->loggingOut || $this->closing) {
            return;
        }

        switch ($update['_'] ?? '') {
            case 'updateNewMessage':
            case 'updateNewChannelMessage':
                $this->addNewMessage($update['message'] ?? [], false);
                break;
            case 'updateEditMessage':
            case 'updateEditChannelMessage':
                $this->addNewMessage($update['message'] ?? [], true);
                break;
            case 'updateMessagePoll':
                $this->polls()->onGetPoll((int) $update['poll_id'], $update['poll'] ?? null, $update['results'] ?? [], new MessageConverter($this));
                break;
            case 'updateMessagePollVote':
                $answer = $this->polls()->onGetPollVote(
                    (int) $update['poll_id'],
                    ChatId::fromPeer($update['peer'] ?? null),
                    array_map('strval', $update['options'] ?? []),
                    array_map('intval', $update['positions'] ?? []),
                );
                if ($answer !== null) {
                    $this->addUpdate(UpdateType::POLL_ANSWER, JsonWriter::encode(JsonPoll::answer($answer, $this)), 86400, (int) $update['poll_id']);
                }
                break;
            case 'updateDeleteMessages':
                // Private chats and basic groups share one id space; the chat is unknown here.
                break;
            case 'updateDeleteChannelMessages':
                $chatId = ChatId::fromChannel((int) $update['channel_id']);
                foreach ($update['messages'] ?? [] as $id) {
                    unset($this->messages[$chatId.':'.$id]);
                }
                break;
            default:
                if (!$this->onChatUpdate($update) && !$this->onBusinessUpdate($update)) {
                    $this->onQueryUpdate($update);
                }
                break;
        }
    }

    /**
     * Client::add_new_message.
     *
     * @param array<string, mixed> $message TL Message
     */
    private function addNewMessage(array $message, bool $isEdited): void
    {
        $info = (new MessageConverter($this))->convert($message);
        if ($info === null) {
            return;
        }

        $this->newMessageQueues[$info->chatId] ??= ['queue' => [], 'active' => false];
        $this->newMessageQueues[$info->chatId]['queue'][] = [$info, $isEdited];
        $this->processNewMessageQueue($info->chatId);
    }

    /**
     * Client::process_new_message_queue: messages of a chat are delivered in
     * order, each after the message it replies to has been loaded.
     */
    private function processNewMessageQueue(int $chatId): void
    {
        if (!isset($this->newMessageQueues[$chatId]) || $this->newMessageQueues[$chatId]['active']) {
            return;
        }
        $this->newMessageQueues[$chatId]['active'] = true;

        while ($this->newMessageQueues[$chatId]['queue'] !== []) {
            if ($this->loggingOut || $this->closing) {
                break;
            }

            [$info, $isEdited] = $this->newMessageQueues[$chatId]['queue'][0];

            $replyToMessageId = $this->getSameChatReplyToMessageId($info, false);
            if ($replyToMessageId > 0 && $this->getMessage($chatId, $replyToMessageId, false) === null) {
                $this->loadRepliedMessage($info);
            }

            $this->loadStickerSetNames(array_merge(
                $this->messageStickerSets($info),
                $this->messageStickerSets($this->getMessage($chatId, $replyToMessageId, true)),
            ));

            array_shift($this->newMessageQueues[$chatId]['queue']);
            $info = $this->addMessage($info);

            if ($this->needSkipUpdateMessage($chatId, $info, $isEdited)) {
                continue;
            }

            $isChannel = $this->isBroadcastChannel($chatId);
            $type = $isChannel
                ? ($isEdited ? UpdateType::EDITED_CHANNEL_POST : UpdateType::CHANNEL_POST)
                : ($isEdited ? UpdateType::EDITED_MESSAGE : UpdateType::MESSAGE);

            $this->addMessageUpdate($type, $info, $chatId);
        }

        unset($this->newMessageQueues[$chatId]);
    }

    /**
     * td_api::getRepliedMessage: load the message a new message replies to.
     */
    private function loadRepliedMessage(MessageInfo $info): void
    {
        $input = [['_' => 'inputMessageReplyTo', 'id' => $info->id]];

        try {
            if (ChatId::type($info->chatId) === ChatId::TYPE_SUPERGROUP) {
                $result = $this->invoke('channels.getMessages', [
                    'channel' => $this->inputChannel(ChatId::toChannelId($info->chatId)),
                    'id' => $input,
                ]);
            } else {
                $result = $this->invoke('messages.getMessages', ['id' => $input]);
            }
        } catch (\Throwable $e) {
            $this->parameters->logger->info("Can't load replied message for {$info->id} in {$info->chatId}: {$e->getMessage()}");

            return;
        }

        foreach ($result['messages'] ?? [] as $message) {
            $replied = (new MessageConverter($this))->convert($message);
            if ($replied !== null && $replied->chatId === $info->chatId) {
                $this->addMessage($replied);
            }
        }
    }

    /**
     * Client::get_message_sticker_set_ids.
     *
     * @return list<array<string, mixed>> TL inputStickerSet objects
     */
    private function messageStickerSets(?MessageInfo $message): array
    {
        if ($message === null) {
            return [];
        }

        // Client::get_message_sticker_set_ids
        $sets = [];
        foreach ([$message->content, $message->replyToMessage['content'] ?? null] as $content) {
            $sets = array_merge($sets, self::contentStickerSets($content));
        }
        if ($message->businessReplyToMessage !== null) {
            $sets = array_merge($sets, $this->messageStickerSets($message->businessReplyToMessage));
        }
        foreach ($message->referenceMessages as $reference) {
            $sets = array_merge($sets, $this->messageStickerSets($reference));
        }

        return $sets;
    }

    /**
     * Client::get_message_content_sticker_set_ids.
     *
     * @param array<string, mixed>|null $content
     * @return list<array<string, mixed>>
     */
    private static function contentStickerSets(?array $content): array
    {
        $stickers = match ($content['_'] ?? '') {
            'messageSticker' => [$content['sticker']],
            'messageGift', 'messageRefundedUpgradedGift' => [$content['gift']['sticker']],
            'messageUpgradedGift' => [$content['gift']['model']['sticker'], $content['gift']['pattern']['sticker']],
            'messagePoll' => array_values(array_filter(array_map(
                static fn (array $option): ?array => ($option['media']['_'] ?? '') === 'pollMediaSticker' ? $option['media']['sticker'] : null,
                $content['poll']['options'] ?? []))),
            default => [],
        };
        $sets = [];
        foreach ($stickers as $sticker) {
            if (($set = JsonSticker::inputStickerSet($sticker)) !== null) {
                $sets[] = $set;
            }
        }

        return $sets;
    }

    /**
     * Client::get_sticker_set_names: load the names of unknown sticker sets.
     *
     * @param list<array<string, mixed>> $sets
     */
    private function loadStickerSetNames(array $sets): void
    {
        foreach ($sets as $set) {
            $id = (int) $set['id'];
            if ($id === 0 || isset($this->stickerSetNames[$id])) {
                continue;
            }

            try {
                $result = $this->invoke('messages.getStickerSet', ['stickerset' => $set, 'hash' => 0]);
                $this->stickerSetNames[$id] = (string) ($result['set']['short_name'] ?? '');
            } catch (\Throwable $e) {
                $this->parameters->logger->info("Can't load sticker set {$id}: {$e->getMessage()}");
            }
        }
    }

    public function getStickerSetName(int $setId): string
    {
        return $this->stickerSetNames[$setId] ?? '';
    }

    /**
     * Client::add_message: remember a message, keeping the old content of an
     * edited message unless it changed.
     */
    private function addMessage(MessageInfo $info): MessageInfo
    {
        $key = $info->chatId.':'.$info->id;

        if (isset($this->messages[$key])) {
            $old = $this->messages[$key];
            $info->isContentChanged = $old->isContentChanged || $old->content != $info->content || $old->senderTag !== $info->senderTag
                || $old->suggestedPostInfo != $info->suggestedPostInfo;
        } elseif (count($this->messages) >= self::MAX_CACHED_MESSAGES) {
            unset($this->messages[array_key_first($this->messages)]);
        }

        unset($this->messages[$key]);

        return $this->messages[$key] = $info;
    }

    public function forgetMessage(int $chatId, int $messageId): void
    {
        unset($this->messages[$chatId.':'.$messageId]);
    }

    public function getMessage(int $chatId, int $messageId, bool $forceCache): ?MessageInfo
    {
        if ($messageId === 0) {
            return null;
        }

        $message = $this->messages[$chatId.':'.$messageId] ?? null;
        if ($message === null || (!$forceCache && $message->content['_'] === 'messagePoll')) {
            return null;
        }

        return $message;
    }

    /**
     * Client::get_same_chat_reply_to_message_id.
     */
    public function getSameChatReplyToMessageId(?MessageInfo $message, bool $onlyExplicit): int
    {
        if ($message === null) {
            return 0;
        }

        if (!$onlyExplicit) {
            $contentMessageId = match ($message->content['_']) {
                'messagePinMessage' => (int) $message->content['message_id'],
                'messageGameScore' => (int) $message->content['game_message_id'],
                default => 0,
            };
            if ($contentMessageId !== 0) {
                return $contentMessageId;
            }
        }

        if ($message->replyToMessage !== null && $message->replyToMessage['origin'] === null && $message->replyToMessage['message_id'] > 0) {
            return $message->replyToMessage['message_id'];
        }

        return 0;
    }

    public function getReplyToMessageInfo(MessageInfo $message, int $messageId): ?MessageInfo
    {
        if ($message->businessConnectionId !== '') {
            return $message->businessReplyToMessage;
        }
        if ($message->guestQueryId !== 0) {
            foreach ($message->referenceMessages as $reference) {
                if ($reference->id === $messageId) {
                    return $reference;
                }
            }

            return null;
        }

        return $this->getMessage($message->chatId, $messageId, true);
    }

    /**
     * Client::need_skip_update_message.
     */
    private function needSkipUpdateMessage(int $chatId, MessageInfo $message, bool $isEdited): bool
    {
        $contentType = $message->content['_'];

        if ($message->isOutgoing && $chatId !== 0) {
            $allowed = [
                'messageChatChangeTitle', 'messageChatChangePhoto', 'messageChatDeletePhoto', 'messageChatDeleteMember',
                'messageChatSetTheme', 'messagePinMessage', 'messageProximityAlertTriggered', 'messageVideoChatScheduled',
                'messageVideoChatStarted', 'messageVideoChatEnded', 'messageInviteVideoChatParticipants',
                'messageForumTopicCreated', 'messageForumTopicEdited', 'messageForumTopicIsClosedToggled',
                'messageForumTopicIsHiddenToggled', 'messageGiveawayCreated', 'messageGiveaway', 'messageGiveawayWinners',
                'messageGiveawayCompleted', 'messagePaymentRefunded', 'messageSuggestedPostApprovalFailed',
                'messageSuggestedPostApproved', 'messageSuggestedPostDeclined', 'messageSuggestedPostPaid',
                'messageSuggestedPostRefunded', 'messagePollOptionAdded', 'messagePollOptionDeleted',
            ];
            if (!in_array($contentType, $allowed, true)) {
                return true;
            }
        }

        $messageDate = $message->editDate === 0 ? $message->date : $message->editDate;
        if ($messageDate <= $this->getUnixTime() - 86400) {
            return true;
        }

        if (ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP) {
            $channel = $this->getChannel(ChatId::toChannelId($chatId));
            if ($channel === null || ($channel['_'] ?? '') === 'channelForbidden' || !empty($channel['left'])) {
                if ($contentType === 'messageChatDeleteMember') {
                    return (int) $message->content['user_id'] !== $this->getMyId();
                }

                return true;
            }

            if ((int) ($channel['date'] ?? 0) > $messageDate || $this->authorizationDate > $messageDate) {
                return true;
            }

            if (empty($channel['megagroup']) && $contentType === 'messageSupergroupChatCreate') {
                return true;
            }
        }

        if ($message->isSelfDestruct || $message->isImported) {
            return true;
        }

        switch ($contentType) {
            case 'messageChatAddMembers':
                if ($message->content['member_user_ids'] === []) {
                    return true;
                }
                break;
            case 'messageSupergroupChatCreate':
                if (ChatId::type($chatId) !== ChatId::TYPE_SUPERGROUP) {
                    return true;
                }
                break;
            case 'messagePinMessage':
                if ((int) $message->content['message_id'] <= 0) {
                    return true;
                }
                break;
            case 'messageProximityAlertTriggered':
                return ($message->content['traveler_id']['_'] ?? '') !== 'messageSenderUser'
                    || ($message->content['watcher_id']['_'] ?? '') !== 'messageSenderUser';
            case 'messageGameScore': case 'messagePaymentSuccessful': case 'messagePassportDataSent':
            case 'messageCall': case 'messageUnsupported': case 'messageContactRegistered':
            case 'messageExpiredPhoto': case 'messageExpiredVideo': case 'messageExpiredVideoNote':
            case 'messageExpiredVoiceNote': case 'messageCustomServiceAction': case 'messageChatSetTheme':
            case 'messageWebAppDataSent': case 'messageGiftedPremium': case 'messageSuggestProfilePhoto':
            case 'messagePremiumGiftCode': case 'messageGiftedStars': case 'messageGiveawayPrizeStars':
            case 'messagePaidMessagesRefunded': case 'messageGroupCall': case 'messageGiftedGrams':
            case 'messageSuggestBirthdate': case 'messageUpgradedGiftPurchaseOffer':
            case 'messageUpgradedGiftPurchaseOfferRejected': case 'messageChatHasProtectedContentToggled':
            case 'messageChatHasProtectedContentDisableRequested':
                return true;
        }

        // The message is already stored: an edit that kept the content is not sent.
        if ($isEdited && $chatId !== 0 && !$message->isContentChanged) {
            return true;
        }

        return false;
    }

    /**
     * Client::add_message_update.
     */
    private function addMessageUpdate(int $type, MessageInfo $message, int $webhookQueueId): void
    {
        $messageDate = $message->editDate === 0 ? $message->date : $message->editDate;
        $leftTime = $messageDate + 86400 - $this->getUnixTime();

        $message->isContentChanged = false;
        $this->addUpdate($type, JsonWriter::encode(JsonMessage::make($message, true, $this)), $leftTime, $webhookQueueId);
    }

    /**
     * Client::add_update_impl: queue `"<type>":<json>` and wake the consumer.
     */
    public function addUpdate(int $type, string $json, int $timeout, int $webhookQueueId): void
    {
        if ((($this->allowedUpdateTypes >> $type) & 1) === 0) {
            return;
        }
        $this->statActor->addUpdate();

        $data = '"'.UpdateType::NAMES[$type].'":'.$json;
        $id = $this->tqueue?->push($data, $this->getUnixTime() + $timeout, $webhookQueueId) ?? 0;

        if ($id !== 0) {
            if ($this->webhookUrl === '') {
                $this->longPollWakeup(false);
            } else {
                $this->notifyWebhook();
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  getUpdates
    // ═════════════════════════════════════════════════════════════════

    public function processGetUpdatesQuery(Query $query): void
    {
        if ($this->hasActiveWebhook()) {
            $this->failQueryConflict('Conflict: can\'t use getUpdates method while webhook is active; use deleteWebhook to delete the webhook first', $query);

            return;
        }

        $offset = self::getIntegerArg($query, 'offset', 0);
        $limit = self::getIntegerArg($query, 'limit', 100, 1, 100);
        $timeout = self::getIntegerArg($query, 'timeout', 0, 0, self::LONG_POLL_MAX_TIMEOUT);

        $this->updateAllowedUpdateTypes($query);

        $now = microtime(true);
        if ($offset === $this->previousGetUpdatesOffset && $timeout < 3 && $now < $this->previousGetUpdatesStartTime + 3.0) {
            $timeout = 3;
        }
        if ($offset === $this->previousGetUpdatesOffset && $now < $this->previousGetUpdatesStartTime + 0.5) {
            $limit = 1;
        }
        $this->previousGetUpdatesOffset = $offset;
        $this->previousGetUpdatesStartTime = $now;

        $this->doGetUpdates($offset, $limit, $timeout, $query);
    }

    private function doGetUpdates(int $offset, int $limit, int $timeout, Query $query): void
    {
        $queue = $this->tqueue;

        if ($offset < 0) {
            $queue->clear(-$offset);
        }
        if ($offset <= 0) {
            $offset = $queue->getHead();
        }

        $updates = [];
        if ($offset > 0) {
            $now = $this->getUnixTime();
            $result = $offset < UpdateQueue::MAX_ID ? $queue->get($offset, true, $now, $limit) : null;
            $result ??= $queue->get($queue->getHead(), true, $now, $limit);
            $updates = $result[0] ?? [];
        }

        if ($timeout !== 0 && $updates === []) {
            $this->abortLongPoll(false);
            $this->longPollOffset = $offset;
            $this->longPollLimit = $limit;
            $this->longPollQuery = $query;
            $this->longPollWasWakeup = false;
            $this->longPollHardTimeout = microtime(true) + $timeout;
            $this->scheduleLongPoll($this->longPollHardTimeout);

            return;
        }

        $query->answerJson(self::jsonUpdates($updates));
    }

    /**
     * Client::JsonUpdates: at most 4 MB of updates per response.
     *
     * @param list<array{id: int, data: string}> $updates
     */
    private static function jsonUpdates(array $updates): string
    {
        $parts = [];
        $leftLength = 1 << 22;
        foreach ($updates as $update) {
            $leftLength -= 50 + strlen($update['data']);
            if ($leftLength <= 0) {
                break;
            }
            $parts[] = '{"update_id":'.$update['id'].",\n".$update['data'].'}';
        }

        return '['.implode(',', $parts).']';
    }

    private function longPollWakeup(bool $force): void
    {
        if ($this->longPollQuery === null) {
            return;
        }

        if ($force) {
            $query = $this->longPollQuery;
            $this->longPollQuery = null;
            $this->clearLongPollTimer();
            $this->doGetUpdates($this->longPollOffset, $this->longPollLimit, 0, $query);

            return;
        }

        $now = microtime(true);
        if (!$this->longPollWasWakeup) {
            $this->longPollHardTimeout = min($now + self::LONG_POLL_MAX_DELAY, $this->longPollHardTimeout);
            $this->longPollWasWakeup = true;
        }
        $this->scheduleLongPoll(min($now + self::LONG_POLL_WAIT_AFTER, $this->longPollHardTimeout));
    }

    private function scheduleLongPoll(float $at): void
    {
        $this->clearLongPollTimer();
        $delay = max(1, (int) ceil(($at - microtime(true)) * 1000));
        $this->longPollTimer = Timer::after($delay, function (): void {
            $this->longPollTimer = null;
            $this->longPollWakeup(true);
        });
    }

    private function clearLongPollTimer(): void
    {
        if ($this->longPollTimer !== null) {
            Timer::clear($this->longPollTimer);
            $this->longPollTimer = null;
        }
    }

    private function abortLongPoll(bool $fromSetWebhook): void
    {
        if ($this->longPollQuery === null) {
            return;
        }

        $query = $this->longPollQuery;
        $this->longPollQuery = null;
        $this->clearLongPollTimer();
        $this->failQueryConflict($fromSetWebhook
            ? 'Conflict: terminated by setWebhook request'
            : 'Conflict: terminated by other getUpdates request; make sure that only one bot instance is running', $query);
    }

    private function failQueryConflict(string $message, Query $query): void
    {
        $now = microtime(true);
        if ($now >= $this->nextGetUpdatesConflictTime) {
            $query->fail(409, $message);
            $this->nextGetUpdatesConflictTime = $now + 3.0;
        } else {
            Timer::after(3000, static fn () => $query->fail(409, $message));
        }
    }

    /**
     * Client::update_allowed_update_types (persisted like TDLib's option).
     */
    private function updateAllowedUpdateTypes(Query $query): bool
    {
        $allowed = UpdateType::parseAllowed($query->arg('allowed_updates'), $query->isInternal);
        if ($allowed !== 0 && $allowed !== $this->allowedUpdateTypes) {
            $this->allowedUpdateTypes = $allowed;
            $this->setOption('allowed_update_types', $allowed === UpdateType::DEFAULT_ALLOWED ? null : (string) $allowed);

            return true;
        }

        return false;
    }

    /**
     * Client::get_integer_arg.
     */
    public static function getIntegerArg(Query $query, string $field, int $default, int $min = -2147483648, int $max = 2147483647): int
    {
        $arg = $query->arg($field);
        $value = $arg === '' ? $default : TdInteger::toInt32($arg);

        return max($min, min($max, $value));
    }
}
