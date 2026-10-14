<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\FormattingError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Errors\TdError;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonMessage;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Support\Utf8;
use LaraGram\Server\Td\ParticipantStatus;
use Swoole\Timer;

/**
 * Sending messages: Client::do_send_message with its checks
 * (check_message_topic, check_reply_parameters) and TDLib's sendMessage
 * (MessagesManager::send_message, process_input_message_content,
 * can_send_message_content, do_send_message) on top of MTProto.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait SendsMessages
{
    /** Client::GENERAL_FORUM_TOPIC_ID */
    private const GENERAL_FORUM_TOPIC_ID = 1;

    /** Client::MAX_CONCURRENTLY_SENT_CHAT_MESSAGES */
    private const MAX_CONCURRENTLY_SENT_CHAT_MESSAGES = 310;

    /** message_text_length_max / message_caption_length_max (bots) */
    private const MESSAGE_TEXT_LENGTH_MAX = 4096;
    private const MESSAGE_CAPTION_LENGTH_MAX = 1024;

    /** @var array<int, int> chat identifier => messages being sent */
    private array $yetUnsentMessageCount = [];

    // ═════════════════════════════════════════════════════════════════
    //  Bot API side
    // ═════════════════════════════════════════════════════════════════

    /**
     * The fast path of process_send_*_query: too many messages being sent to the chat.
     */
    protected function isSendFloodLimited(Query $query): bool
    {
        $chatId = TdInteger::toInt64Safe($query->arg('chat_id'));
        if ($chatId !== null && ($this->yetUnsentMessageCount[$chatId] ?? 0) >= self::MAX_CONCURRENTLY_SENT_CHAT_MESSAGES) {
            $this->failQueryFloodLimitExceeded($query);

            return true;
        }

        return false;
    }

    /**
     * Client::fail_query_flood_limit_exceeded.
     */
    protected function failQueryFloodLimitExceeded(Query $query): void
    {
        Timer::after(3000, static fn () => $query->setRetryAfterError(60));
    }

    /**
     * Client::get_ephemeral_message_parameters.
     *
     * @return array{receiver_user_id: int, callback_query_id: int, replace_callback_query_message: bool}
     */
    protected function getEphemeralMessageParameters(Query $query): array
    {
        $empty = ['receiver_user_id' => 0, 'callback_query_id' => 0, 'replace_callback_query_message' => false];
        if (!$query->hasArg('ephemeral_message_parameters')) {
            return [
                'receiver_user_id' => $query->hasArg('receiver_user_id') ? self::getUserIdArg($query, 'receiver_user_id') : 0,
                'callback_query_id' => TdInteger::toInt64($query->arg('callback_query_id')),
                'replace_callback_query_message' => self::boolArg($query, 'replace_callback_query_message'),
            ];
        }

        $arg = $query->arg('ephemeral_message_parameters');
        if ($arg === '') {
            return $empty;
        }
        $value = self::jsonArg($arg, "Can't parse EphemeralMessageParameters JSON object");
        if (!$value->isObject()) {
            throw QueryError::badRequest('Object expected as EphemeralMessageParameters');
        }
        $object = $value->object();
        if ($object->fieldCount() === 0) {
            return $empty;
        }

        return self::json(static fn (): array => [
            'receiver_user_id' => $object->getRequiredLongField('receiver_user_id'),
            'callback_query_id' => $object->getOptionalLongField('callback_query_id'),
            'replace_callback_query_message' => $object->getOptionalBoolField('replace_callback_query_message'),
        ]);
    }

    /**
     * Client::get_input_suggested_post_info.
     *
     * @return array{price: array<string, mixed>|null, send_date: int}|null
     */
    protected function getInputSuggestedPostInfo(Query $query): ?array
    {
        $arg = $query->arg('suggested_post_parameters');
        if ($arg === '') {
            return null;
        }
        $value = self::jsonArg($arg, "Can't parse suggested post parameters JSON object");
        if (!$value->isObject()) {
            throw QueryError::badRequest('Object expected as suggested post parameters');
        }
        $object = $value->object();

        return self::json(function () use ($object): array {
            $sendDate = $object->getOptionalIntField('send_date');
            $price = null;
            $input = $object->extractOptionalField('price', \LaraGram\Server\Support\TdJson::OBJECT);
            if ($input->isObject()) {
                $price = $this->getSuggestedPostPrice($input->object());
            }

            return ['price' => $price, 'send_date' => $sendDate];
        });
    }

    /**
     * Client::get_suggested_post_price.
     *
     * @return array<string, mixed>
     */
    protected function getSuggestedPostPrice(\LaraGram\Server\Support\JsonObject $object): array
    {
        $currency = $object->getRequiredStringField('currency');
        $amount = $object->getRequiredLongField('amount');
        if ($currency === 'XTR') {
            return ['_' => 'suggestedPostPriceStar', 'star_count' => $amount];
        }
        if ($currency === 'TON') {
            return ['_' => 'suggestedPostPriceTon', 'toncoin_cent_count' => $amount];
        }

        throw new QueryError(400, 'Unsupported currency specified');
    }

    /**
     * Client::do_send_message: send one message and answer with it.
     *
     * @param array<string, mixed> $content td_api InputMessageContent
     */
    protected function doSendMessage(array $content, Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $forumTopicId = self::getForumTopicId($query, 'message_thread_id');
        $businessConnectionId = $query->arg('business_connection_id');
        $ephemeral = $this->getEphemeralMessageParameters($query);
        $replyParameters = $this->getReplyParameters($query);
        $disableNotification = self::boolArg($query, 'disable_notification');
        $protectContent = self::boolArg($query, 'protect_content');
        $allowPaidBroadcast = self::boolArg($query, 'allow_paid_broadcast');
        $effectId = TdInteger::toInt64($query->arg('message_effect_id'));
        $directMessagesTopicId = TdInteger::toInt64($query->arg('direct_messages_topic_id'));
        $suggestedPost = $this->getInputSuggestedPostInfo($query);
        $replyMarkup = $this->getReplyMarkup($query);

        $options = [
            'suggested_post_info' => $suggestedPost,
            'disable_notification' => $disableNotification,
            'protect_content' => $protectContent,
            'allow_paid_broadcast' => $allowPaidBroadcast,
            'effect_id' => $effectId,
        ];
        $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);

        if ($businessConnectionId !== '') {
            $message = $this->sendBusinessMessage($businessConnectionId, $chatIdStr, $replyParameters, $disableNotification,
                $protectContent, $effectId, $replyMarkup, $content);
            $query->answerQuery(JsonMessage::make($message, true, $this));

            return;
        }

        [$chatId, $topic, $replyTo] = $this->checkReplyParameters($chatIdStr, $replyParameters, $forumTopicId,
            $directMessagesTopicId, $allowPaidBroadcast);

        $count = $this->yetUnsentMessageCount[$chatId] ?? 0;
        if ($count >= self::MAX_CONCURRENTLY_SENT_CHAT_MESSAGES) {
            $this->failQueryFloodLimitExceeded($query);

            return;
        }

        $this->yetUnsentMessageCount[$chatId] = $count + 1;
        try {
            if ($ephemeral['receiver_user_id'] !== 0) {
                $message = $this->tdSendEphemeralMessage($chatId, $topic, $ephemeral, $replyTo, $protectContent, $replyMarkup, $content);
            } else {
                $message = $this->tdSendMessage($chatId, $topic, $replyTo, $options, $replyMarkup, $content);
            }
        } finally {
            $this->decreaseYetUnsentMessageCount($chatId, 1);
        }

        if ($query->method() === 'copymessage') {
            $query->answerQuery(['message_id' => $message->id]);

            return;
        }
        $query->answerQuery(JsonMessage::make($message, true, $this));
    }

    protected function decreaseYetUnsentMessageCount(int $chatId, int $count): void
    {
        $left = ($this->yetUnsentMessageCount[$chatId] ?? 0) - $count;
        if ($left <= 0) {
            unset($this->yetUnsentMessageCount[$chatId]);
        } else {
            $this->yetUnsentMessageCount[$chatId] = $left;
        }
    }

    /**
     * Client::check_message_topic.
     *
     * @return array{0: int, 1: array<string, mixed>|null} the chat and its td_api MessageTopic
     */
    protected function checkMessageTopic(string $chatIdStr, int $forumTopicId, int $directMessagesTopicId, bool $allowUnknownUser = false): array
    {
        $chatId = $this->checkChat($chatIdStr, self::ACCESS_WRITE, $allowUnknownUser && $forumTopicId === 0);
        if ($forumTopicId === 0 && $directMessagesTopicId === 0) {
            return [$chatId, null];
        }

        $canBeForum = false;
        $type = ChatId::type($chatId);
        if ($type === ChatId::TYPE_SUPERGROUP) {
            $channel = $this->getChannel(ChatId::toChannelId($chatId)) ?? [];
            if (!empty($channel['monoforum'])) {
                return [$chatId, ['_' => 'messageTopicDirectMessages', 'direct_messages_chat_topic_id' => $directMessagesTopicId]];
            }
            if (!empty($channel['forum'])) {
                if ($forumTopicId === self::GENERAL_FORUM_TOPIC_ID) {
                    throw new QueryError(400, 'Bad Request: message thread not found');
                }
                $canBeForum = true;
            }
        } elseif ($type === ChatId::TYPE_PRIVATE && $this->getUser($chatId) !== null) {
            $canBeForum = true;
        }

        if ($canBeForum && $forumTopicId !== 0) {
            try {
                $this->tdGetForumTopic($chatId, $forumTopicId);
            } catch (QueryError $e) {
                throw new QueryError($e->getCode(), $e->getMessage(), 'Message thread not found');
            }
            if ($type === ChatId::TYPE_SUPERGROUP && $this->getMessage($chatId, $forumTopicId, false) === null) {
                try {
                    $this->tdGetMessage($chatId, $forumTopicId);
                } catch (QueryError) {
                    // "top forum topic message" may be empty
                }
            }

            return [$chatId, ['_' => 'messageTopicForum', 'forum_topic_id' => $forumTopicId]];
        }

        return [$chatId, null];
    }

    /**
     * Client::check_reply_parameters.
     *
     * @param array<string, mixed> $reply InputReplyParameters
     * @return array{0: int, 1: array<string, mixed>|null, 2: array<string, mixed>} chat, topic and CheckedReplyParameters
     */
    protected function checkReplyParameters(string $chatIdStr, array $reply, int $forumTopicId, int $directMessagesTopicId, bool $allowUnknownUser = false): array
    {
        if ($chatIdStr === $reply['reply_in_chat_id']) {
            $reply['reply_in_chat_id'] = '';
        }

        [$chatId, $topic] = $this->checkMessageTopic($chatIdStr, $forumTopicId, $directMessagesTopicId, $allowUnknownUser);

        $checked = [
            'reply_in_chat_id' => 0, 'reply_to_message_id' => 0, 'reply_to_ephemeral_message_id' => 0,
            'quote' => null, 'quote_position' => 0, 'checklist_task_id' => 0, 'poll_option_id' => '',
        ];

        if ($reply['reply_to_ephemeral_message_id'] > 0) {
            $checked['reply_to_ephemeral_message_id'] = $reply['reply_to_ephemeral_message_id'];

            return [$chatId, $topic, $checked];
        }
        if ($reply['reply_to_message_id'] <= 0) {
            return [$chatId, $topic, $checked];
        }

        $replyInChatId = $reply['reply_in_chat_id'] === '' ? $chatId : $this->checkChat($reply['reply_in_chat_id'], self::ACCESS_READ);
        if (!$this->haveMessageAccess($replyInChatId)) {
            throw new QueryError(400, 'MESSAGE_NOT_FOUND', 'message to be replied not found');
        }

        $messageId = $this->checkMessageExists($replyInChatId, $reply['reply_to_message_id'], $reply['allow_sending_without_reply'], 'message to be replied');
        if ($messageId > 0) {
            $checked['reply_to_message_id'] = $messageId;
            $checked['reply_in_chat_id'] = $replyInChatId;
            $checked['quote'] = $reply['quote'];
            $checked['quote_position'] = $reply['quote_position'];
            $checked['checklist_task_id'] = $reply['checklist_task_id'];
            $checked['poll_option_id'] = $reply['poll_option_id'];

            if ($replyInChatId === $chatId) {
                $message = $this->getMessage($replyInChatId, $messageId, true);
                if ($message !== null && self::isReplyInSameTopic($message->topicId, $topic)) {
                    $checked['reply_in_chat_id'] = 0;
                }
            }
        }

        return [$chatId, $topic, $checked];
    }

    /**
     * Client::is_reply_in_same_topic.
     *
     * @param array<string, mixed>|null $replyTopic
     * @param array<string, mixed>|null $topic
     */
    private static function isReplyInSameTopic(?array $replyTopic, ?array $topic): bool
    {
        if ($topic === null) {
            return true;
        }
        if ($topic['_'] === 'messageTopicForum') {
            return JsonMessage::forumTopicId($replyTopic) === $topic['forum_topic_id'];
        }

        return true;
    }

    /**
     * Client::have_message_access.
     */
    public function haveMessageAccess(int $chatId): bool
    {
        if (ChatId::type($chatId) !== ChatId::TYPE_SUPERGROUP) {
            return true;
        }
        $channel = $this->getChannel(ChatId::toChannelId($chatId));

        return $channel !== null && ParticipantStatus::fromServerChannel($channel)->isChatMember();
    }

    /**
     * TdOnCheckMessageCallback: the message identifier, 0 for an allowed
     * missing message; a failure uses "<type> not found" as default message.
     */
    protected function checkMessageExists(int $chatId, int $messageId, bool $allowEmpty, string $messageType): int
    {
        try {
            $this->tdGetMessage($chatId, $messageId);
        } catch (QueryError $e) {
            if ($e->getCode() === 429) {
                $this->parameters->logger->warning("Failed to get message {$messageId} in {$chatId}: {$messageType}");
            }
            if ($allowEmpty) {
                return 0;
            }
            throw new QueryError($e->getCode(), $e->getMessage(), $messageType.' not found');
        }

        return $messageId;
    }

    /**
     * Client::check_message: the chat and the existing message.
     *
     * @return array{0: int, 1: int}
     */
    protected function checkMessage(string $chatIdStr, int $messageId, bool $allowEmpty, int $access, string $messageType): array
    {
        $chatId = $this->checkChat($chatIdStr, $access);
        if (($messageId <= 0 && !$allowEmpty) || !$this->haveMessageAccess($chatId)) {
            throw new QueryError(400, 'MESSAGE_NOT_FOUND', $messageType.' not found');
        }
        if ($messageId <= 0) {
            return [$chatId, 0];
        }

        return [$chatId, $this->checkMessageExists($chatId, $messageId, $allowEmpty, $messageType)];
    }

    // ═════════════════════════════════════════════════════════════════
    //  TDLib side
    // ═════════════════════════════════════════════════════════════════

    /**
     * td_api::getMessage: the known message, or the one loaded from the server.
     */
    public function tdGetMessage(int $chatId, int $messageId): MessageInfo
    {
        $message = $this->getMessage($chatId, $messageId, true);
        if ($message !== null) {
            return $message;
        }

        $message = $this->loadMessages($chatId, [$messageId])[$messageId] ?? null;
        if ($message === null) {
            throw new QueryError(404, 'Not Found');
        }

        return $message;
    }

    /**
     * Load messages of a chat from the server and remember them.
     *
     * @param list<int> $messageIds
     * @return array<int, MessageInfo> by message identifier; missing messages are absent
     */
    public function loadMessages(int $chatId, array $messageIds): array
    {
        $input = array_map(static fn (int $id): array => ['_' => 'inputMessageID', 'id' => $id], $messageIds);

        try {
            if (ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP) {
                $result = $this->invoke('channels.getMessages', ['channel' => $this->inputChannelOfChat($chatId), 'id' => $input]);
            } else {
                $result = $this->invoke('messages.getMessages', ['id' => $input]);
            }
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }

        $messages = [];
        foreach ($result['messages'] ?? [] as $tlMessage) {
            $info = (new MessageConverter($this))->convert($tlMessage);
            if ($info !== null && $info->chatId === $chatId) {
                $messages[$info->id] = $this->addMessage($info);
            }
        }

        return $messages;
    }

    /**
     * td_api::getForumTopic.
     *
     * @return array<string, mixed> TL forumTopic
     */
    public function tdGetForumTopic(int $chatId, int $forumTopicId): array
    {
        try {
            $result = $this->invoke('messages.getForumTopicsByID', ['peer' => $this->inputPeer($chatId), 'topics' => [$forumTopicId]]);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }

        foreach ($result['topics'] ?? [] as $topic) {
            if (($topic['_'] ?? '') === 'forumTopic' && (int) $topic['id'] === $forumTopicId) {
                return $topic;
            }
        }

        throw new QueryError(400, 'Topic not found');
    }

    /**
     * The effective permissions of the bot in a chat
     * (get_user_default_permissions / get_chat_permissions / get_channel_permissions).
     */
    public function getChatPermissions(int $chatId): ParticipantStatus
    {
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                if ($this->getUser($chatId) === null && $chatId !== $this->getMyId()) {
                    return new ParticipantStatus(ParticipantStatus::MEMBER, 0);
                }

                return new ParticipantStatus(ParticipantStatus::MEMBER, ParticipantStatus::restrictedRights([
                    'can_send_basic_messages' => true, 'can_send_audios' => true, 'can_send_documents' => true,
                    'can_send_photos' => true, 'can_send_videos' => true, 'can_send_video_notes' => true,
                    'can_send_voice_notes' => true, 'can_send_other_messages' => true, 'can_add_link_previews' => true,
                    'can_send_polls' => true, 'can_pin_messages' => true, 'can_react_to_messages' => true,
                ], 'unknown'));

            case ChatId::TYPE_GROUP:
                $chat = $this->getBasicGroup(ChatId::toBasicGroupId($chatId));
                if ($chat === null || !self::isBasicGroupActive($chat)) {
                    return ParticipantStatus::banned();
                }

                return ParticipantStatus::fromServerChat($chat)->applyRestrictions(
                    ParticipantStatus::restrictedRightsFromServer($chat['default_banned_rights'] ?? null, 'unknown'),
                    $this->getUnixTime(),
                );

            case ChatId::TYPE_SUPERGROUP:
                $channel = $this->getChannel(ChatId::toChannelId($chatId));
                if ($channel === null) {
                    return ParticipantStatus::banned();
                }
                $channelType = empty($channel['megagroup']) ? 'broadcast' : 'megagroup';

                return ParticipantStatus::fromServerChannel($channel)->applyRestrictions(
                    ParticipantStatus::restrictedRightsFromServer($channel['default_banned_rights'] ?? null, $channelType),
                    $this->getUnixTime(),
                );
        }

        return ParticipantStatus::banned();
    }

    /**
     * @param array<string, mixed> $chat TL chat
     */
    public static function isBasicGroupActive(array $chat): bool
    {
        return ($chat['_'] ?? '') === 'chat' && empty($chat['deactivated']) && !isset($chat['migrated_to']);
    }

    /**
     * ChatManager::is_admined_monoforum_channel.
     */
    public function isAdminedMonoforum(int $chatId): bool
    {
        if (ChatId::type($chatId) !== ChatId::TYPE_SUPERGROUP) {
            return false;
        }
        $channel = $this->getChannel(ChatId::toChannelId($chatId)) ?? [];
        if (empty($channel['monoforum'])) {
            return false;
        }
        $linked = $this->getChannel((int) ($channel['linked_monoforum_id'] ?? 0));
        if ($linked !== null) {
            return ParticipantStatus::fromServerChannel($linked)->canManageDirectMessages();
        }

        return ParticipantStatus::fromServerChannel($channel)->isMember();
    }

    public function isMonoforum(int $chatId): bool
    {
        return ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP && !empty($this->getChannel(ChatId::toChannelId($chatId))['monoforum']);
    }

    /**
     * DialogManager::is_anonymous_administrator for a bot.
     */
    public function isAnonymousAdministrator(int $chatId): bool
    {
        return $this->isBroadcastChannel($chatId) || $this->isAdminedMonoforum($chatId);
    }

    /**
     * DialogManager::can_send_message_to_dialog.
     */
    protected function canSendMessageToChat(int $chatId): void
    {
        if ($this->isBroadcastChannel($chatId) && !$this->getChatPermissions($chatId)->canPostMessages()) {
            throw new QueryError(400, 'Need administrator rights in the channel chat');
        }
    }

    /**
     * MessagesManager::get_dialog_reply_markup.
     *
     * @param array<string, mixed>|null $markup
     * @return array<string, mixed>|null
     */
    public function getDialogReplyMarkup(?array $markup, int $chatId): ?array
    {
        return $this->getChatInputReplyMarkup($markup, $chatId, $this->isAdminedMonoforum($chatId), $this->isAnonymousAdministrator($chatId));
    }

    /**
     * MessageSendOptions::get_message_send_options for a bot.
     *
     * @param array<string, mixed> $options
     */
    protected function checkMessageSendOptions(int $chatId, array $options, bool $allowEffect = true, bool $allowSuggestedPost = true): void
    {
        if (($options['effect_id'] ?? 0) !== 0) {
            if (ChatId::type($chatId) !== ChatId::TYPE_PRIVATE) {
                throw new QueryError(400, 'Can\'t use message effects in the chat');
            }
            if (!$allowEffect) {
                throw new QueryError(400, 'Can\'t use message effects in the method');
            }
        }
        if (($options['suggested_post_info'] ?? null) !== null) {
            if (!$allowSuggestedPost) {
                throw new QueryError(400, 'Can\'t send suggested posts with the method');
            }
            if (!$this->isMonoforum($chatId)) {
                throw new QueryError(400, 'Suggested posts can be sent only to channel direct messages');
            }
        }
    }

    /**
     * MessageTopic::get_send_message_topic.
     *
     * @param array<string, mixed>|null $topic
     */
    protected function checkSendMessageTopic(int $chatId, ?array $topic): void
    {
        if ($this->isAdminedMonoforum($chatId) && ($topic['_'] ?? '') !== 'messageTopicDirectMessages') {
            throw new QueryError(400, 'Channel direct messages topic must be specified');
        }
    }

    /**
     * td_api::sendMessage.
     *
     * @param array<string, mixed>|null $topic td_api MessageTopic
     * @param array<string, mixed> $replyTo CheckedReplyParameters
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $replyMarkup td_api ReplyMarkup
     * @param array<string, mixed> $content td_api InputMessageContent
     */
    public function tdSendMessage(int $chatId, ?array $topic, array $replyTo, array $options, ?array $replyMarkup, array $content): MessageInfo
    {
        if ($content['_'] === 'inputMessageForwarded') {
            $copyOptions = $content['copy_options'] ?? self::noCopyOptions();
            if ($copyOptions['send_copy']) {
                if ($copyOptions['replace_caption']) {
                    $copyOptions['new_caption'] = $this->getOutgoingFormattedText($copyOptions['new_caption'] ?? new FormattedText(), true);
                }
                $copyOptions['reply_to'] = $replyTo;
                $this->getDialogReplyMarkup($replyMarkup, $chatId);
                $copyOptions['reply_markup'] = $replyMarkup;
            }
            $messages = $this->tdForwardMessages($chatId, $topic, $content['from_chat_id'], [$content['message_id']], $options,
                $content['in_game_share'], $content['replace_video_start_timestamp'] ? max(0, $content['new_video_start_timestamp']) : -1,
                [$copyOptions]);
            if ($messages[0] === null) {
                throw new QueryError(400, $copyOptions['send_copy'] ? 'The message can\'t be copied' : 'The message can\'t be forwarded');
            }

            return $messages[0];
        }

        $this->checkSendMessageTopic($chatId, $topic);
        $this->canSendMessageToChat($chatId);
        $tlReplyMarkup = $this->getDialogReplyMarkup($replyMarkup, $chatId);
        $this->checkMessageSendOptions($chatId, $options);
        $prepared = $this->processInputMessageContent($chatId, $content, !($options['allow_paid_broadcast'] ?? false));

        $inputReplyTo = $this->getInputReplyTo($chatId, $topic, $replyTo);

        $params = [
            'silent' => $options['disable_notification'] ?? false,
            'noforwards' => $options['protect_content'] ?? false,
            'invert_media' => $prepared['invert_media'],
            'allow_paid_floodskip' => $options['allow_paid_broadcast'] ?? false,
            'peer' => $this->inputPeer($chatId),
            'reply_to' => $inputReplyTo,
            'message' => $prepared['text']->text,
            'random_id' => self::randomId(),
            'reply_markup' => $tlReplyMarkup,
            'entities' => $this->inputEntitiesOrNull($prepared['text']),
            'effect' => ($options['effect_id'] ?? 0) !== 0 ? $options['effect_id'] : null,
            'suggested_post' => $this->getInputSuggestedPost($options['suggested_post_info'] ?? null),
        ];

        if (isset($prepared['rich_message'])) {
            $params['rich_message'] = $prepared['rich_message'];
            $method = 'messages.sendMessage';
        } elseif ($prepared['media'] === null) {
            $params['no_webpage'] = $prepared['no_webpage'];
            $method = 'messages.sendMessage';
        } else {
            $params['media'] = $prepared['media'];
            $method = 'messages.sendMedia';
        }

        $result = $this->invokeSend($method, self::tl($method, $params), $chatId, $prepared['type']);

        return $this->getSentMessage($result, $params['random_id'], $chatId, $prepared, $tlReplyMarkup, $replyTo, $topic, $options);
    }

    /**
     * Send a request that creates messages, with TDLib's send error mapping.
     *
     * @param array<string, mixed> $params the TL request, its constructor under '_'
     * @return array<string, mixed> TL Updates
     */
    protected function invokeSend(string $method, array $params, int $chatId, string $contentType): array
    {
        unset($params['_']);
        try {
            $params = $this->uploadPendingFiles($params, $chatId);
            $result = $this->invoke($method, $params);
        } catch (\Throwable $e) {
            unset($this->pendingPhotoHashes[\Swoole\Coroutine::getCid()]);
            throw $this->sendError($e, $chatId, $contentType);
        }
        $this->rememberUploadedPhotosOf(is_array($result) ? $result : null);

        return is_array($result) ? $result : [];
    }

    /**
     * MessagesManager::process_send_message_fail_error.
     */
    protected function sendError(\Throwable $e, int $chatId, string $contentType): QueryError
    {
        if ($e instanceof QueryError) {
            return $e;
        }

        $error = TdError::fromThrowable($e);
        $code = $error->code;
        $message = $error->message;
        $isPrivate = ChatId::type($chatId) === ChatId::TYPE_PRIVATE;

        if ($code === 400) {
            switch ($message) {
                case 'MESSAGE_TOO_LONG':
                    $message = 'Message is too long';
                    break;
                case 'MEDIA_CAPTION_TOO_LONG':
                    $message = 'Message caption is too long';
                    break;
                case 'INPUT_USER_DEACTIVATED':
                    $code = 403;
                    $message = 'User is deactivated';
                    break;
                case 'USER_IS_BOT':
                    if ($isPrivate) {
                        $code = 403;
                        $message = 'The bot can\'t send messages to the bot';
                    }
                    break;
                case 'PEER_ID_INVALID':
                    $code = 403;
                    if ($isPrivate) {
                        $message = 'Bot can\'t initiate conversation with a user';
                    }
                    break;
                case 'REPLY_TO_MONOFORUM_PEER_INVALID':
                    $message = 'Wrong direct messages topic identifier specified';
                    break;
                case 'CHAT_FORWARDS_RESTRICTED':
                    $message = 'Message has protected content and can\'t be forwarded';
                    break;
                case 'MEDIA_EMPTY':
                    $message = match ($contentType) {
                        'Game' => 'Wrong game short name specified',
                        'Invoice' => 'Wrong invoice information specified',
                        'Poll' => 'Wrong poll data specified',
                        'Contact' => 'Wrong phone number specified',
                        'Story' => 'Wrong story file specified',
                        default => 'Wrong file identifier/HTTP URL specified',
                    };
                    break;
                case 'EXTENDED_MEDIA_INVALID':
                    $message = 'Invalid paid media file specified';
                    break;
                case 'PHOTO_EXT_INVALID':
                    $message = 'Photo has unsupported extension. Use one of .jpg, .jpeg, .gif, .png, .tif or .bmp';
                    break;
                case 'CHAT_GUEST_SEND_FORBIDDEN':
                    $code = 400;
                    break;
            }
        } elseif ($code === 403 && !in_array($message, ['CHANNEL_PUBLIC_GROUP_NA', 'USER_IS_BLOCKED', 'USER_BOT_INVALID', 'USER_DELETED'], true)) {
            $code = 400;
        }

        return new QueryError($code, $message);
    }

    public static function randomId(): int
    {
        do {
            $id = random_int(PHP_INT_MIN, PHP_INT_MAX);
        } while ($id === 0);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    protected function inputEntitiesOrNull(FormattedText $text): ?array
    {
        $entities = $text->inputEntities(fn (int $userId): array => $this->inputUser($userId));

        return $entities === [] ? null : $entities;
    }

    /**
     * SuggestedPost::get_input_suggested_post.
     *
     * @param array<string, mixed>|null $info
     * @return array<string, mixed>|null
     */
    protected function getInputSuggestedPost(?array $info): ?array
    {
        if ($info === null) {
            return null;
        }
        $price = null;
        if ($info['price'] !== null) {
            $price = $info['price']['_'] === 'suggestedPostPriceStar'
                ? ['_' => 'starsAmount', 'amount' => $info['price']['star_count'], 'nanos' => 0]
                : ['_' => 'starsTonAmount', 'amount' => $info['price']['toncoin_cent_count'] * 10000000];
        }

        return self::tl('suggestedPost', [
            'price' => $price,
            'schedule_date' => $info['send_date'] !== 0 ? $info['send_date'] : null,
        ]);
    }

    /**
     * create_message_input_reply_to + MessageInputReplyTo::get_input_reply_to.
     *
     * @param array<string, mixed>|null $topic
     * @param array<string, mixed> $replyTo CheckedReplyParameters
     * @return array<string, mixed>|null TL InputReplyTo
     */
    public function getInputReplyTo(int $chatId, ?array $topic, array $replyTo): ?array
    {
        if ($replyTo['reply_to_ephemeral_message_id'] > 0) {
            return null;
        }

        $messageId = 0;
        $replyChatId = 0;
        $quote = null;
        $checklistTaskId = 0;
        $pollOptionId = '';

        if ($replyTo['reply_to_message_id'] > 0) {
            $replyChatId = $replyTo['reply_in_chat_id'];
            $message = $this->getMessage($replyChatId === 0 ? $chatId : $replyChatId, $replyTo['reply_to_message_id'], true);
            $sameTopic = $replyChatId !== 0 || $message === null || $topic === null || self::isReplyInSameTopic($message->topicId, $topic);
            if ($message !== null && $sameTopic) {
                $messageId = $message->id;
                $checklistTaskId = max(0, $replyTo['checklist_task_id']);
                if ($checklistTaskId !== 0 && ($message->content['_'] ?? '') !== 'messageChecklist') {
                    $checklistTaskId = 0;
                }
                $pollOptionId = TextFormatting::cleanInputString($replyTo['poll_option_id']) ?? '';
                if (($message->content['_'] ?? '') !== 'messagePoll') {
                    $pollOptionId = '';
                }
                $quote = $this->getMessageQuote($replyTo['quote'], $replyTo['quote_position']);
            } else {
                $replyChatId = 0;
            }
        }

        $isDirectMessagesTopic = ($topic['_'] ?? '') === 'messageTopicDirectMessages';
        $savedInputPeer = $isDirectMessagesTopic ? $this->inputPeer((int) $topic['direct_messages_chat_topic_id']) : null;

        if ($messageId === 0) {
            if ($isDirectMessagesTopic) {
                return ['_' => 'inputReplyToMonoForum', 'monoforum_peer_id' => $savedInputPeer];
            }
            if ($topic === null) {
                return null;
            }
            $messageId = $this->getImplicitReplyToMessageId($chatId, $topic);
        }

        $topMsgId = ($topic['_'] ?? '') === 'messageTopicForum' ? (int) $topic['forum_topic_id'] : 0;

        $result = self::tl('inputReplyToMessage', [
            'reply_to_msg_id' => $messageId,
            'top_msg_id' => $topMsgId !== 0 ? $topMsgId : null,
            'reply_to_peer_id' => $replyChatId !== 0 ? $this->inputPeer($replyChatId) : null,
            'monoforum_peer_id' => $savedInputPeer,
            'todo_item_id' => $checklistTaskId !== 0 ? $checklistTaskId : null,
            'poll_option' => $pollOptionId !== '' ? $pollOptionId : null,
        ]);

        if ($quote !== null) {
            $result['quote_text'] = $quote['text']->text;
            $entities = $this->inputEntitiesOrNull($quote['text']);
            if ($entities !== null) {
                $result['quote_entities'] = $entities;
            }
            if ($quote['position'] !== 0) {
                $result['quote_offset'] = $quote['position'];
            }
        }

        return $result;
    }

    /**
     * MessageTopic::get_implicit_reply_to_message_id.
     *
     * @param array<string, mixed> $topic
     */
    private function getImplicitReplyToMessageId(int $chatId, array $topic): int
    {
        if ($topic['_'] !== 'messageTopicForum') {
            return 0;
        }
        if (ChatId::type($chatId) === ChatId::TYPE_PRIVATE) {
            return 0;
        }
        if ((int) $topic['forum_topic_id'] === self::GENERAL_FORUM_TOPIC_ID) {
            return 0;
        }

        return (int) $topic['forum_topic_id'];
    }

    /**
     * MessageQuote(td, td_api::inputTextQuote): null for an empty quote.
     *
     * @return array{text: FormattedText, position: int}|null
     */
    protected function getMessageQuote(?FormattedText $quote, int $position): ?array
    {
        if ($quote === null) {
            return null;
        }
        $ltrimCount = 0;
        try {
            [$text, $entities] = TextFormatting::fixFormattedText($quote->text, $quote->entities, true, true, false, false, true, false, $ltrimCount);
        } catch (FormattingError) {
            return null;
        }
        if ($text === '') {
            return null;
        }
        if ($position >= 0 && $position <= 1000000) {
            $position += $ltrimCount;
        } else {
            $position = 0;
        }

        return ['text' => new FormattedText($text, $entities), 'position' => $position];
    }

    /**
     * get_formatted_text for an outgoing message text or caption.
     */
    protected function getOutgoingFormattedText(FormattedText $text, bool $allowEmpty, bool $skipTrim = false): FormattedText
    {
        try {
            return $text->fixOutgoing($allowEmpty, $skipTrim);
        } catch (FormattingError $e) {
            throw new QueryError(400, $e->getMessage());
        }
    }

    /**
     * MessagesManager::process_input_message_content + can_send_message_content
     * for contents without files; contents with files are prepared by
     * prepareInputMedia().
     *
     * @param array<string, mixed> $content td_api InputMessageContent
     * @return array{type: string, text: FormattedText, media: array<string, mixed>|null, no_webpage: bool, invert_media: bool, rich_message?: array<string, mixed>}
     */
    public function processInputMessageContent(int $chatId, array $content, bool $checkPermissions): array
    {
        $prepared = $this->getInputMessageContent($chatId, $content);
        if ($checkPermissions) {
            $this->canSendMessageContent($chatId, $prepared['type'], $prepared);
        }

        return $prepared;
    }

    /**
     * get_input_message_content / create_input_message_content.
     *
     * @param array<string, mixed> $content
     * @return array{type: string, text: FormattedText, media: array<string, mixed>|null, no_webpage: bool, invert_media: bool, rich_message?: array<string, mixed>}
     */
    protected function getInputMessageContent(int $chatId, array $content): array
    {
        $prepared = ['type' => '', 'text' => new FormattedText(), 'media' => null, 'no_webpage' => false, 'invert_media' => false];

        switch ($content['_']) {
            case 'inputMessageText':
                ['text' => $text, 'web_page_url' => $webPageUrl, 'disable_web_page_preview' => $disabled,
                    'force_small_media' => $forceSmall, 'force_large_media' => $forceLarge, 'show_above_text' => $above,
                ] = $this->processInputMessageText($chatId, $content);
                if (Utf8::length($text->text) > self::MESSAGE_TEXT_LENGTH_MAX) {
                    throw new QueryError(400, 'Message is too long');
                }
                $prepared['type'] = 'Text';
                $prepared['text'] = $text;
                $prepared['no_webpage'] = $disabled;
                $prepared['invert_media'] = $above;
                if ($webPageUrl !== '') {
                    $prepared['media'] = self::tl('inputMediaWebPage', [
                        'force_large_media' => $forceLarge,
                        'force_small_media' => $forceSmall,
                        'optional' => $text->text !== '',
                        'url' => $webPageUrl,
                    ]);
                }

                return $prepared;

            case 'inputMessageLocation':
            case 'inputMessageLiveLocation':
                $live = $content['_'] === 'inputMessageLiveLocation';
                $location = $content['location'] ?? null;
                if (!self::isValidLocation($location)) {
                    throw new QueryError(400, $live ? 'Invalid live location specified' : 'Invalid location specified');
                }
                $geoPoint = self::inputGeoPoint($location);
                if (!$live) {
                    $prepared['type'] = 'Location';
                    $prepared['media'] = ['_' => 'inputMediaGeoPoint', 'geo_point' => $geoPoint];

                    return $prepared;
                }
                $period = $content['live_period'];
                if ($period !== 2147483647 && ($period < 60 || $period > 86400)) {
                    throw new QueryError(400, 'Wrong live location period specified');
                }
                $heading = $content['heading'];
                if ($heading !== 0 && ($heading < 1 || $heading > 360)) {
                    throw new QueryError(400, 'Wrong live location heading specified');
                }
                $radius = $content['proximity_alert_radius'];
                if ($radius < 0 || $radius > 100000) {
                    throw new QueryError(400, 'Wrong live location proximity alert radius specified');
                }
                $prepared['type'] = 'LiveLocation';
                $prepared['media'] = self::tl('inputMediaGeoLive', [
                    'geo_point' => $geoPoint,
                    'heading' => $heading !== 0 ? $heading : null,
                    'period' => $period,
                    'proximity_notification_radius' => $radius,
                ], ['proximity_notification_radius']);

                return $prepared;

            case 'inputMessageVenue':
                $venue = $content['venue'];
                foreach (['title' => 'Venue title', 'address' => 'Venue address', 'provider' => 'Venue provider', 'id' => 'Venue identifier', 'type' => 'Venue type'] as $field => $name) {
                    $clean = TextFormatting::cleanInputString($venue[$field]);
                    if ($clean === null) {
                        throw new QueryError(400, "{$name} must be encoded in UTF-8");
                    }
                    $venue[$field] = $clean;
                }
                if (!self::isValidLocation($venue['location'])) {
                    throw new QueryError(400, 'Wrong venue location specified');
                }
                $prepared['type'] = 'Venue';
                $prepared['media'] = [
                    '_' => 'inputMediaVenue',
                    'geo_point' => self::inputGeoPoint($venue['location']),
                    'title' => $venue['title'],
                    'address' => $venue['address'],
                    'provider' => $venue['provider'],
                    'venue_id' => $venue['id'],
                    'venue_type' => $venue['type'],
                ];

                return $prepared;

            case 'inputMessageContact':
                $contact = $content['contact'];
                foreach (['phone_number' => 'Phone number', 'first_name' => 'First name', 'last_name' => 'Last name', 'vcard' => 'vCard'] as $field => $name) {
                    $clean = TextFormatting::cleanInputString($contact[$field]);
                    if ($clean === null) {
                        throw new QueryError(400, "{$name} must be encoded in UTF-8");
                    }
                    $contact[$field] = $clean;
                }
                $prepared['type'] = 'Contact';
                $prepared['media'] = [
                    '_' => 'inputMediaContact',
                    'phone_number' => $contact['phone_number'],
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'vcard' => $contact['vcard'],
                ];

                return $prepared;

            case 'inputMessageDice':
                $emoji = TextFormatting::cleanInputString($content['emoji']);
                if ($emoji === null) {
                    throw new QueryError(400, 'Dice emoji must be encoded in UTF-8');
                }
                $prepared['type'] = 'Dice';
                $prepared['media'] = ['_' => 'inputMediaDice', 'emoticon' => $emoji];

                return $prepared;

            case 'inputMessagePoll':
                return $this->getInputPollContent($chatId, $content);

            case 'inputMessageChecklist':
                return $this->getInputChecklistContent($content);

            case 'inputMessageInvoice':
                return $this->getInputInvoiceContent($chatId, $content);

            case 'inputMessagePaidMedia':
                return $this->getInputPaidMediaContent($chatId, $content);

            case 'inputMessageGame':
                return $this->getInputGameContent($content);

            case 'inputMessageRichMessage':
                return $this->getInputRichContent($chatId, $content);
        }

        return $this->getInputMediaContent($chatId, $content);
    }

    /**
     * td::process_input_message_text.
     *
     * @param array<string, mixed> $content td_api inputMessageText
     * @return array{text: FormattedText, web_page_url: string, disable_web_page_preview: bool, force_small_media: bool, force_large_media: bool, show_above_text: bool}
     */
    protected function processInputMessageText(int $chatId, array $content): array
    {
        $options = $content['link_preview_options'] ?? null;
        $webPageUrl = '';
        $disabled = $forceSmall = $forceLarge = $above = false;
        if ($options !== null) {
            $webPageUrl = TextFormatting::cleanInputString($options['url']);
            if ($webPageUrl === null) {
                throw new QueryError(400, 'Link preview URL must be encoded in UTF-8');
            }
            $disabled = $options['is_disabled'];
            $forceSmall = $options['force_small_media'];
            $forceLarge = $options['force_large_media'];
            $above = $options['show_above_text'];
            if ($disabled || (ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP && !$this->getChatPermissions($chatId)->canAddWebPagePreviews())) {
                $webPageUrl = '';
            }
            if ($webPageUrl === '') {
                $forceSmall = $forceLarge = false;
            }
        }

        return [
            'text' => $this->getOutgoingFormattedText($content['text'], $webPageUrl !== ''),
            'web_page_url' => $webPageUrl,
            'disable_web_page_preview' => $disabled,
            'force_small_media' => $forceSmall,
            'force_large_media' => $forceLarge,
            'show_above_text' => $above,
        ];
    }

    /**
     * @param array<string, mixed>|null $location ['latitude', 'longitude', 'horizontal_accuracy']
     */
    public static function isValidLocation(?array $location): bool
    {
        if ($location === null) {
            return false;
        }
        $latitude = $location['latitude'];
        $longitude = $location['longitude'];

        return is_finite($latitude) && is_finite($longitude) && abs($latitude) <= 90 && abs($longitude) <= 180;
    }

    /**
     * Location::get_input_geo_point.
     *
     * @param array<string, mixed> $location
     * @return array<string, mixed>
     */
    public static function inputGeoPoint(array $location): array
    {
        $accuracy = (float) ($location['horizontal_accuracy'] ?? 0.0);
        if (!is_finite($accuracy) || $accuracy <= 0.0) {
            $accuracy = 0.0;
        } elseif ($accuracy >= 1500.0) {
            $accuracy = 1500.0;
        }

        return self::tl('inputGeoPoint', [
            'lat' => (float) $location['latitude'],
            'long' => (float) $location['longitude'],
            'accuracy_radius' => $accuracy > 0 ? (int) ceil($accuracy) : null,
        ]);
    }

    /**
     * can_send_message_content.
     *
     * @param array<string, mixed> $prepared
     */
    protected function canSendMessageContent(int $chatId, string $contentType, array $prepared = [], bool $isForward = false): void
    {
        $permissions = $this->getChatPermissions($chatId);
        $isChannel = ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP;

        $error = match ($contentType) {
            'Animation' => $permissions->canSendAnimations() ? null : 'Not enough rights to send animations to the chat',
            'Audio' => $permissions->canSendAudios() ? null : 'Not enough rights to send music to the chat',
            'Contact' => $permissions->canSendMessages() ? null : 'Not enough rights to send contacts to the chat',
            'Dice' => $permissions->canSendStickers() ? null : 'Not enough rights to send dice to the chat',
            'Document' => $permissions->canSendDocuments() ? null : 'Not enough rights to send documents to the chat',
            'Game' => $isChannel && $this->isMonoforum($chatId) ? 'Games can\'t be sent to channel direct messages chats'
                : ($permissions->canSendGames() ? null : 'Not enough rights to send games to the chat'),
            'Giveaway' => $permissions->canSendMessages() ? null : 'Not enough rights to send giveaways to the chat',
            'GiveawayWinners' => $permissions->canSendMessages() ? null : 'Not enough rights to send giveaway winners to the chat',
            'Invoice' => $permissions->canSendMessages() ? null : 'Not enough rights to send invoice messages to the chat',
            'LiveLocation' => $permissions->canSendMessages() ? null : 'Not enough rights to send live locations to the chat',
            'Location' => $permissions->canSendMessages() ? null : 'Not enough rights to send locations to the chat',
            'PaidMedia' => $isForward && (!$permissions->canSendPhotos() || !$permissions->canSendVideos()) ? 'Not enough rights to send paid media to the chat' : null,
            'Photo' => $permissions->canSendPhotos() ? null : 'Not enough rights to send photos to the chat',
            'Poll' => !$permissions->canSendPolls() ? 'Not enough rights to send polls to the chat'
                : ($isChannel && $this->isBroadcastChannel($chatId) && !($prepared['is_anonymous'] ?? true) ? 'Non-anonymous polls can\'t be sent to channel chats'
                    : ($isChannel && $this->isMonoforum($chatId) ? 'Polls can\'t be sent to channel direct messages chats' : null)),
            'RichText' => isset($prepared['rich_message']) ? $this->richMessageError($chatId, $prepared['rich_message'])
                : ($permissions->canSendMessages() ? null : 'Not enough rights to send the rich message to the chat'),
            'Sticker' => !$permissions->canSendStickers() ? 'Not enough rights to send stickers to the chat'
                : (($prepared['sticker_type'] ?? '') === 'CustomEmoji' ? 'Can\'t send emoji stickers in messages' : null),
            'Story' => $permissions->canSendPhotos() && $permissions->canSendVideos() ? null : 'Not enough rights to send stories to the chat',
            'Text' => $permissions->canSendMessages() ? null : 'Not enough rights to send text messages to the chat',
            'ToDoList' => !$permissions->canSendPolls() ? 'Not enough rights to send checklists to the chat'
                : ($isChannel && $this->isBroadcastChannel($chatId) ? 'Checklists can\'t be sent to channel chats'
                    : ($isChannel && $this->isMonoforum($chatId) ? 'Checklists can\'t be sent to channel direct messages chats' : null)),
            'Venue' => $permissions->canSendMessages() ? null : 'Not enough rights to send venues to the chat',
            'Video' => $permissions->canSendVideos() ? null : 'Not enough rights to send videos to the chat',
            'VideoNote' => !$permissions->canSendVideoNotes() ? 'Not enough rights to send video notes to the chat'
                : (ChatId::type($chatId) === ChatId::TYPE_PRIVATE && !empty($this->getUser($chatId)['voice_messages_forbidden']) ? 'User restricted receiving of video note messages' : null),
            'VoiceNote' => !$permissions->canSendVoiceNotes() ? 'Not enough rights to send voice notes to the chat'
                : (ChatId::type($chatId) === ChatId::TYPE_PRIVATE && !empty($this->getUser($chatId)['voice_messages_forbidden']) ? 'User restricted receiving of voice note messages' : null),
            default => null,
        };

        if ($error !== null) {
            throw new QueryError(400, $error);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Results
    // ═════════════════════════════════════════════════════════════════

    /**
     * The message a send request created: from updateShortSentMessage, built
     * like TDLib keeps the local message, or from the full message in Updates.
     *
     * @param array<string, mixed> $result TL Updates
     * @param array<string, mixed> $prepared
     * @param array<string, mixed>|null $tlReplyMarkup
     * @param array<string, mixed> $replyTo
     * @param array<string, mixed>|null $topic
     * @param array<string, mixed> $options
     */
    protected function getSentMessage(array $result, int $randomId, int $chatId, array $prepared, ?array $tlReplyMarkup,
        array $replyTo, ?array $topic, array $options): MessageInfo
    {
        if (($result['_'] ?? '') === 'updateShortSentMessage') {
            $message = self::tl('message', [
                'out' => true,
                'noforwards' => $options['protect_content'] ?? false,
                'invert_media' => $prepared['invert_media'],
                'id' => (int) $result['id'],
                'peer_id' => self::peerOf($chatId),
                'reply_to' => $this->localReplyHeader($chatId, $replyTo, $topic),
                'date' => (int) $result['date'],
                'message' => $prepared['text']->text,
                'media' => $result['media'] ?? null,
                'reply_markup' => $tlReplyMarkup,
                'entities' => $result['entities'] ?? null,
                'effect' => ($options['effect_id'] ?? 0) !== 0 ? $options['effect_id'] : null,
                'ttl_period' => $result['ttl_period'] ?? null,
            ]);
            $info = (new MessageConverter($this))->convert($message);
            if ($info === null) {
                throw new QueryError(500, 'Request aborted');
            }

            return $this->addSentMessage($info);
        }

        $messages = $this->getSentMessages($result, [$randomId]);
        if ($messages === []) {
            throw new QueryError(500, 'Request aborted');
        }

        return $messages[0];
    }

    /**
     * The messages created by a request with the given random identifiers, in their order.
     *
     * @param array<string, mixed> $result TL Updates
     * @param list<int> $randomIds
     * @return list<MessageInfo>
     */
    protected function getSentMessages(array $result, array $randomIds): array
    {
        $updates = match ($result['_'] ?? '') {
            'updates', 'updatesCombined' => $result['updates'] ?? [],
            'updateShort' => [$result['update']],
            default => [],
        };

        $idsByRandomId = [];
        $messages = [];
        foreach ($updates as $update) {
            switch ($update['_'] ?? '') {
                case 'updateMessageID':
                    $idsByRandomId[(int) $update['random_id']] = (int) $update['id'];
                    break;
                case 'updateNewMessage':
                case 'updateNewChannelMessage':
                case 'updateNewScheduledMessage':
                    $messages[(int) ($update['message']['id'] ?? 0)] = $update['message'];
                    break;
            }
        }

        $result = [];
        foreach ($randomIds as $randomId) {
            $id = $idsByRandomId[$randomId] ?? null;
            if ($id === null || !isset($messages[$id])) {
                continue;
            }
            $info = (new MessageConverter($this))->convert($messages[$id]);
            if ($info !== null) {
                $result[] = $this->addSentMessage($info);
            }
        }

        return $result;
    }

    /**
     * Client::on_message_send_succeeded: remember the sent message.
     */
    protected function addSentMessage(MessageInfo $info): MessageInfo
    {
        $info = $this->addMessage($info);
        $info->isContentChanged = false;

        return $info;
    }

    /**
     * The reply header TDLib keeps for a sent message.
     *
     * @param array<string, mixed> $replyTo
     * @param array<string, mixed>|null $topic
     * @return array<string, mixed>|null
     */
    protected function localReplyHeader(int $chatId, array $replyTo, ?array $topic): ?array
    {
        $header = [];
        if (($topic['_'] ?? '') === 'messageTopicForum') {
            $header['forum_topic'] = true;
            $header['reply_to_top_id'] = (int) $topic['forum_topic_id'];
        }
        if ($replyTo['reply_to_message_id'] > 0) {
            $header['reply_to_msg_id'] = $replyTo['reply_to_message_id'];
            if ($replyTo['reply_in_chat_id'] !== 0 && $replyTo['reply_in_chat_id'] !== $chatId) {
                $header['reply_to_peer_id'] = self::peerOf($replyTo['reply_in_chat_id']);
            }
            $quote = $this->getMessageQuote($replyTo['quote'], $replyTo['quote_position']);
            if ($quote !== null) {
                $header['quote'] = true;
                $header['quote_text'] = $quote['text']->text;
                $header['quote_entities'] = $quote['text']->inputEntities(fn (int $userId): array => ['_' => 'inputUser', 'user_id' => $userId]);
                $header['quote_offset'] = $quote['position'];
            }
        } elseif (isset($header['reply_to_top_id'])) {
            $header['reply_to_msg_id'] = $header['reply_to_top_id'];
            unset($header['reply_to_top_id']);
        }

        return $header === [] ? null : ['_' => 'messageReplyHeader'] + $header;
    }

    /**
     * @return array<string, mixed> TL Peer
     */
    public static function peerOf(int $chatId): array
    {
        return match (ChatId::type($chatId)) {
            ChatId::TYPE_PRIVATE => ['_' => 'peerUser', 'user_id' => $chatId],
            ChatId::TYPE_GROUP => ['_' => 'peerChat', 'chat_id' => ChatId::toBasicGroupId($chatId)],
            default => ['_' => 'peerChannel', 'channel_id' => ChatId::toChannelId($chatId)],
        };
    }

    /**
     * td_api::sendMessageAlbum (MessagesManager::send_message_group).
     *
     * @param array<string, mixed>|null $topic
     * @param array<string, mixed> $replyTo
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>> $contents
     * @return list<MessageInfo>
     */
    public function tdSendMessageAlbum(int $chatId, ?array $topic, array $replyTo, array $options, array $contents): array
    {
        if ($contents === []) {
            throw new QueryError(400, 'There are no messages to send');
        }
        $this->canSendMessageToChat($chatId);
        $this->checkMessageSendOptions($chatId, $options, true, false);

        $prepared = [];
        foreach ($contents as $content) {
            $prepared[] = $this->processInputMessageContent($chatId, $content, !($options['allow_paid_broadcast'] ?? false));
        }

        // check_message_group_message_contents
        if (count($prepared) > 10) {
            throw new QueryError(400, 'Too many messages to send as an album');
        }
        $types = [];
        foreach ($prepared as $item) {
            if (!in_array($item['type'], ['Audio', 'Document', 'Photo', 'Video'], true)) {
                throw new QueryError(400, 'Invalid message content type');
            }
            if ($item['invert_media'] !== $prepared[0]['invert_media']) {
                throw new QueryError(400, 'Parameter show_caption_above_media must be the same for all messages');
            }
            $types[$item['type']] = true;
        }
        if (count($types) > 1) {
            foreach (array_keys($types) as $type) {
                if ($type === 'Audio' || $type === 'Document') {
                    throw new QueryError(400, "{$type} can't be mixed with other media types");
                }
            }
        }

        $this->checkSendMessageTopic($chatId, $topic);
        $inputReplyTo = $this->getInputReplyTo($chatId, $topic, $replyTo);

        $contentType = $prepared[0]['type'];
        if (count($prepared) === 1) {
            // an album of one message is sent as an ordinary message
            $item = $prepared[0];
            $params = [
                'silent' => $options['disable_notification'] ?? false,
                'noforwards' => $options['protect_content'] ?? false,
                'invert_media' => $item['invert_media'],
                'allow_paid_floodskip' => $options['allow_paid_broadcast'] ?? false,
                'peer' => $this->inputPeer($chatId),
                'reply_to' => $inputReplyTo,
                'media' => $item['media'],
                'message' => $item['text']->text,
                'random_id' => self::randomId(),
                'entities' => $this->inputEntitiesOrNull($item['text']),
                'effect' => ($options['effect_id'] ?? 0) !== 0 ? $options['effect_id'] : null,
            ];
            $result = $this->invokeMultiSend('messages.sendMedia', self::tl('messages.sendMedia', $params), $chatId, $contentType, 1);

            return [$this->getSentMessage($result, $params['random_id'], $chatId, $item, null, $replyTo, $topic, $options)];
        }

        $multiMedia = [];
        $randomIds = [];
        foreach ($prepared as $item) {
            $randomId = self::randomId();
            $randomIds[] = $randomId;
            $multiMedia[] = self::tl('inputSingleMedia', [
                'media' => $this->getUploadedInputMediaForAlbum($item['media'], $chatId, $contentType),
                'random_id' => $randomId,
                'message' => $item['text']->text,
                'entities' => $this->inputEntitiesOrNull($item['text']),
            ]);
        }

        $params = [
            'silent' => $options['disable_notification'] ?? false,
            'noforwards' => $options['protect_content'] ?? false,
            'invert_media' => $prepared[0]['invert_media'],
            'allow_paid_floodskip' => $options['allow_paid_broadcast'] ?? false,
            'peer' => $this->inputPeer($chatId),
            'reply_to' => $inputReplyTo,
            'multi_media' => $multiMedia,
            'effect' => ($options['effect_id'] ?? 0) !== 0 ? $options['effect_id'] : null,
        ];
        $result = $this->invokeMultiSend('messages.sendMultiMedia', self::tl('messages.sendMultiMedia', $params), $chatId, $contentType, count($prepared));
        $messages = $this->getSentMessages($result, $randomIds);
        if (count($messages) !== count($prepared)) {
            throw new QueryError(500, 'Request aborted');
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function getUploadedInputMediaForAlbum(array $media, int $chatId, string $contentType): array
    {
        try {
            return $this->getUploadedInputMedia($media, $chatId, false);
        } catch (QueryError $e) {
            throw new QueryError($e->getCode(), 'Failed to send message #1 with the error message "'.$e->getMessage().'"');
        }
    }
}
