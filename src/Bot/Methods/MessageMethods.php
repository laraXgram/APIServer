<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\TdJson;

/**
 * deleteMessage(s), pinChatMessage, unpinChatMessage, unpinAllChatMessages,
 * setMessageReaction, deleteMessageReaction, deleteAllMessageReactions and
 * sendChatAction.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait MessageMethods
{
    public function processDeleteMessageQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_WRITE, 'message to delete');

        $this->tdDeleteMessages($chatId, [$messageId]);
        $query->answerQuery(true);
    }

    public function processDeleteMessagesQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $messageIds = self::getMessageIds($query, 100);
        [$chatId, $messageIds] = $this->checkMessages($chatIdStr, $messageIds, true, self::ACCESS_WRITE, 'message to delete');

        $this->tdDeleteMessages($chatId, $messageIds);
        $query->answerQuery(true);
    }

    /**
     * MessagesManager::delete_messages(revoke = true) for a bot.
     *
     * @param list<int> $messageIds
     */
    public function tdDeleteMessages(int $chatId, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        foreach ($messageIds as $messageId) {
            if ($messageId <= 0) {
                throw new QueryError(400, 'Invalid message identifier');
            }
        }
        foreach ($messageIds as $messageId) {
            $message = $this->getMessage($chatId, $messageId, true);
            if (!$this->canDeleteMessage($chatId, $message)) {
                throw new QueryError(400, 'Message can\'t be deleted');
            }
            if (!$this->canRevokeMessage($chatId, $message)) {
                throw new QueryError(400, 'Message can\'t be deleted for everyone');
            }
        }

        $serverIds = [];
        foreach ($messageIds as $messageId) {
            if ($this->getMessage($chatId, $messageId, true) !== null) {
                $serverIds[] = $messageId;
            }
        }

        foreach (array_chunk($serverIds, 100) as $chunk) {
            try {
                if (ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP) {
                    $this->invoke('channels.deleteMessages', ['channel' => $this->inputChannelOfChat($chatId), 'id' => $chunk]);
                } else {
                    $this->invoke('messages.deleteMessages', ['revoke' => true, 'id' => $chunk]);
                }
            } catch (\Throwable $e) {
                throw QueryError::fromThrowable($e);
            }
        }

        foreach ($messageIds as $messageId) {
            $this->forgetMessage($chatId, $messageId);
        }
    }

    /**
     * MessagesManager::can_delete_message for a bot.
     */
    private function canDeleteMessage(int $chatId, ?MessageInfo $message): bool
    {
        if ($message === null || ChatId::type($chatId) !== ChatId::TYPE_SUPERGROUP) {
            return true;
        }

        $test = $this->isTestDc();
        if ($this->getUnixTime() >= $message->date + ($test ? 900 : 2 * 86400)) {
            return false;
        }
        if ($message->id === 1) {
            return false;
        }
        if (in_array($message->content['_'], ['messageChatUpgradeFrom', 'messageSupergroupChatCreate', 'messageForumTopicCreated'], true)) {
            return false;
        }

        $status = $this->getChatPermissions($chatId);
        if ($status->canDeleteMessages() || $this->isAdminedMonoforum($chatId)) {
            return true;
        }
        if (!$message->isOutgoing) {
            return false;
        }
        if ($this->isBroadcastChannel($chatId) || self::isServiceContent($message->content)) {
            return $status->canPostMessages();
        }

        return true;
    }

    /**
     * MessagesManager::can_revoke_message for a bot.
     */
    private function canRevokeMessage(int $chatId, ?MessageInfo $message): bool
    {
        if ($message === null) {
            return true;
        }
        if ($chatId === $this->getMyId()) {
            return false;
        }

        $age = $this->getUnixTime() - $message->date;
        $isService = self::isServiceContent($message->content);
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                if ($age < 86400 && $message->content['_'] === 'messageDice') {
                    return false;
                }

                return (($message->isOutgoing && !$isService) || $message->content['_'] !== 'messageScreenshotTaken')
                    && $age <= 2 * 86400;
            case ChatId::TYPE_GROUP:
                $isAppointedAdministrator = isset($this->getBasicGroup(ChatId::toBasicGroupId($chatId))['admin_rights']);

                return (($message->isOutgoing && !$isService) || $isAppointedAdministrator) && $age <= 2 * 86400;
            default:
                return true;
        }
    }

    /**
     * is_service_message_content: any content not sent by a user.
     *
     * @param array<string, mixed> $content
     */
    public static function isServiceContent(array $content): bool
    {
        return !in_array($content['_'], [
            'messageText', 'messageAnimation', 'messageAudio', 'messageContact', 'messageDice', 'messageDocument',
            'messageGame', 'messageGiveaway', 'messageGiveawayWinners', 'messageInvoice', 'messageLiveLocation',
            'messageLocation', 'messagePaidMedia', 'messagePhoto', 'messagePoll', 'messageSticker', 'messageStory',
            'messageVenue', 'messageVideo', 'messageVideoNote', 'messageVoiceNote', 'messageChecklist', 'messageRichText',
            'messageUnsupported', 'messageExpiredPhoto', 'messageExpiredVideo', 'messageExpiredVideoNote', 'messageExpiredVoiceNote',
        ], true);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Pins
    // ═════════════════════════════════════════════════════════════════

    public function processPinChatMessageQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $disableNotification = self::boolArg($query, 'disable_notification');

        if ($businessConnectionId !== '') {
            $this->setBusinessMessageIsPinned($businessConnectionId, $chatIdStr, $messageId, true);
            $query->answerQuery(true);

            return;
        }
        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_WRITE, 'message to pin');

        $this->tdPinChatMessage($chatId, $messageId, $disableNotification, false);
        $query->answerQuery(true);
    }

    public function processUnpinChatMessageQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);

        if ($businessConnectionId !== '') {
            $this->setBusinessMessageIsPinned($businessConnectionId, $chatIdStr, $messageId, false);
            $query->answerQuery(true);

            return;
        }

        if ($messageId === 0) {
            $chatId = $this->checkChat($chatIdStr, self::ACCESS_WRITE);
            try {
                $messageId = $this->tdGetChatPinnedMessageId($chatId);
                if ($messageId === 0) {
                    throw new QueryError(404, 'Not Found');
                }
                $this->tdGetMessage($chatId, $messageId);
            } catch (QueryError $e) {
                $code = $e->getCode();
                if ($code === 401 || $code === 429 || $code >= 500) {
                    throw $e;
                }
                throw QueryError::badRequest('Message to unpin not found');
            }
        } else {
            [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_WRITE, 'message to unpin');
        }

        $this->tdPinChatMessage($chatId, $messageId, false, true);
        $query->answerQuery(true);
    }

    public function processUnpinAllChatMessagesQuery(Query $query): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_WRITE);
        $this->tdUnpinAllMessages($chatId, 0);
        $query->answerQuery(true);
    }

    /**
     * MessagesManager::pin_dialog_message.
     */
    protected function tdPinChatMessage(int $chatId, int $messageId, bool $disableNotification, bool $isUnpin): void
    {
        $message = $this->getMessage($chatId, $messageId, true);
        if ($message === null) {
            throw new QueryError(400, 'Message not found');
        }
        $this->checkCanPinMessages($chatId);
        if (self::isServiceContent($message->content) && $message->content['_'] !== 'messageUnsupported') {
            throw new QueryError(400, 'Service messages can\'t be pinned');
        }

        try {
            $this->invoke('messages.updatePinnedMessage', array_filter([
                'silent' => $disableNotification,
                'unpin' => $isUnpin,
                'peer' => $this->inputPeer($chatId),
                'id' => $messageId,
            ], static fn ($value) => $value !== false));
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
    }

    /**
     * DialogManager::can_pin_messages.
     */
    protected function checkCanPinMessages(int $chatId): void
    {
        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_GROUP:
                $status = $this->getChatPermissions($chatId);
                $isAppointedAdministrator = $status->type === \LaraGram\Server\Td\ParticipantStatus::ADMINISTRATOR
                    || $status->type === \LaraGram\Server\Td\ParticipantStatus::CREATOR;
                if (!$status->canPinMessages() || !$isAppointedAdministrator) {
                    throw new QueryError(400, 'Not enough rights to manage pinned messages in the chat');
                }
                break;
            case ChatId::TYPE_SUPERGROUP:
                if ($this->isMonoforum($chatId)) {
                    break;
                }
                $status = $this->getChatPermissions($chatId);
                $canPin = $this->isBroadcastChannel($chatId) ? $status->canEditMessages() : $status->canPinMessages();
                if (!$canPin) {
                    throw new QueryError(400, 'Not enough rights to manage pinned messages in the chat');
                }
                break;
        }
    }

    /**
     * MessagesManager::unpin_all_dialog_messages.
     */
    protected function tdUnpinAllMessages(int $chatId, int $forumTopicId): void
    {
        $this->checkCanPinMessages($chatId);

        $offset = 0;
        do {
            try {
                $result = $this->invoke('messages.unpinAllMessages', array_filter([
                    'peer' => $this->inputPeer($chatId),
                    'top_msg_id' => $forumTopicId !== 0 ? $forumTopicId : null,
                ], static fn ($value) => $value !== null));
            } catch (\Throwable $e) {
                throw QueryError::fromThrowable($e);
            }
            $offset = (int) ($result['offset'] ?? 0);
        } while ($offset > 0);
    }

    /**
     * The pinned message of a chat from its full information (td_api::getChatPinnedMessage).
     */
    protected function tdGetChatPinnedMessageId(int $chatId): int
    {
        $full = $this->getChatFull($chatId, true);

        return (int) ($full['pinned_msg_id'] ?? 0);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Reactions
    // ═════════════════════════════════════════════════════════════════

    public function processSetMessageReactionQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $isBig = self::boolArg($query, 'is_big');
        $reactionTypes = $this->getReactionTypes($query);

        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_READ, 'message to react');

        if ($this->getMessage($chatId, $messageId, true) === null) {
            throw new QueryError(400, 'Message not found');
        }

        try {
            $this->invoke('messages.sendReaction', array_filter([
                'big' => $isBig,
                'peer' => $this->inputPeer($chatId),
                'msg_id' => $messageId,
                'reaction' => $reactionTypes,
            ], static fn ($value) => $value !== false));
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
        $query->answerQuery(true);
    }

    /**
     * Client::get_reaction_types: TL Reaction objects.
     *
     * @return list<array<string, mixed>>
     */
    protected function getReactionTypes(Query $query): array
    {
        $types = $query->arg('reaction');
        if ($types === '') {
            return [];
        }
        $value = TdJson::tryDecode($types);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse reaction types JSON object');
        }

        return $this->parseArray($value, 'ReactionType', static fn (JsonValue $item): array => self::getReactionType($item));
    }

    /**
     * Client::get_reaction_type.
     *
     * @return array<string, mixed>
     */
    protected static function getReactionType(JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('expected an Object');
        }
        $object = $value->object();
        $type = $object->getRequiredStringField('type');
        if ($type === 'emoji') {
            return ['_' => 'reactionEmoji', 'emoticon' => $object->getRequiredStringField('emoji')];
        }
        if ($type === 'custom_emoji') {
            return ['_' => 'reactionCustomEmoji', 'document_id' => $object->getRequiredLongField('custom_emoji_id')];
        }

        throw QueryError::badRequest('invalid reaction type specified');
    }

    public function processDeleteMessageReactionQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $userId = -1;
        $actorChatIdStr = '';
        if ($query->arg('user_id') !== '') {
            $userId = self::getUserIdArg($query);
        } else {
            $actorChatIdStr = $query->arg('actor_chat_id');
        }

        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_WRITE, 'message to delete reactions');
        $sender = $userId >= 0 ? $this->checkUserNoFail($userId) : $this->getReactionSenderInputPeer($this->checkChatNoFail($actorChatIdStr));

        try {
            $this->invoke('messages.deleteParticipantReaction', [
                'peer' => $this->inputPeer($chatId),
                'msg_id' => $messageId,
                'participant' => $sender,
            ]);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
        $query->answerQuery(true);
    }

    public function processDeleteAllMessageReactionsQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $userId = -1;
        $actorChatIdStr = '';
        if ($query->arg('user_id') !== '') {
            $userId = self::getUserIdArg($query);
        } else {
            $actorChatIdStr = $query->arg('actor_chat_id');
        }

        $chatId = $this->checkChat($chatIdStr, self::ACCESS_WRITE);
        $sender = $userId >= 0 ? $this->checkUserNoFail($userId) : $this->getReactionSenderInputPeer($this->checkChatNoFail($actorChatIdStr));

        try {
            $this->invoke('messages.deleteParticipantReactions', [
                'peer' => $this->inputPeer($chatId),
                'participant' => $sender,
            ]);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
        $query->answerQuery(true);
    }

    /**
     * Client::check_user_no_fail: the InputPeer of the user, loading it when needed.
     *
     * @return array<string, mixed>
     */
    protected function checkUserNoFail(int $userId): array
    {
        if ($this->getUser($userId) === null) {
            $this->loadChat($userId);
        }

        return $this->inputPeer($userId);
    }

    /**
     * Client::check_chat_no_fail: the chat identifier; an unknown chat is still used.
     */
    protected function checkChatNoFail(string $chatIdStr): int
    {
        if ($chatIdStr === '') {
            throw new QueryError(400, 'Bad Request: sender_chat_id is empty');
        }
        $chatId = \LaraGram\Server\Support\TdInteger::toInt64Safe($chatIdStr);
        if ($chatId === null) {
            throw new QueryError(400, 'Bad Request: sender_chat_id is not a valid Integer');
        }
        if (!$this->isChatKnown($chatId)) {
            $this->loadChat($chatId);
        }

        return $chatId;
    }

    /**
     * MessageQueryManager::delete_reaction(s)_by_sender: the InputPeer of the reaction sender.
     *
     * @return array<string, mixed>
     */
    private function getReactionSenderInputPeer(int $chatId): array
    {
        return $this->getSenderChatInputPeer($chatId) ?? throw new QueryError(400, 'Reaction sender not found');
    }

    /**
     * get_message_sender_dialog_id(check_access = false) of a chat sender + get_input_peer(AccessRights::Know):
     * the InputPeer, or null when the chat is unknown.
     *
     * @return array<string, mixed>|null
     */
    protected function getSenderChatInputPeer(int $chatId): ?array
    {
        $type = ChatId::type($chatId);
        if ($type === null || $chatId === 0) {
            throw new QueryError(400, 'Invalid chat identifier specified');
        }
        if ($type !== ChatId::TYPE_PRIVATE && !$this->isChatKnown($chatId)) {
            return null;
        }

        return $this->inputPeer($chatId);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Chat actions
    // ═════════════════════════════════════════════════════════════════

    public function processSendChatActionQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $forumTopicId = self::getForumTopicId($query, 'message_thread_id');
        $businessConnectionId = $query->arg('business_connection_id');
        $action = self::getChatAction($query);
        if ($action === null) {
            throw QueryError::badRequest('Wrong parameter action in request');
        }
        if ($businessConnectionId !== '') {
            $this->sendBusinessChatAction($businessConnectionId, $chatIdStr, $action);
            $query->answerQuery(true);

            return;
        }

        $chatId = $this->checkChat($chatIdStr, self::ACCESS_WRITE);

        if ($this->isMonoforum($chatId)) {
            throw new QueryError(400, 'Chat actions can\'t be sent to channel direct messages chats');
        }
        if (!$this->isChatActionUnneeded($chatId)) {
            try {
                $this->invoke('messages.setTyping', array_filter([
                    'peer' => $this->inputPeer($chatId),
                    'top_msg_id' => $forumTopicId !== 0 ? $forumTopicId : null,
                    'action' => $action,
                ], static fn ($value) => $value !== null));
            } catch (\Throwable $e) {
                throw QueryError::fromThrowable($e);
            }
        }
        $query->answerQuery(true);
    }

    public function processSendMessageDraftQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $forumTopicId = self::getForumTopicId($query, 'message_thread_id');
        $draftId = \LaraGram\Server\Support\TdInteger::toInt64($query->arg('draft_id'));
        $canStop = self::boolArg($query, 'can_stop');
        $keepOnStop = self::boolArg($query, 'keep_on_stop');
        $text = $this->getFormattedText($query->arg('text'), $query->arg('parse_mode'), self::getInputEntities($query, 'entities'));
        $chatId = $this->checkChat($chatIdStr, self::ACCESS_WRITE);

        // td_api::sendTextMessageDraft → DialogActionManager::send_dialog_action
        $text = $this->getOutgoingFormattedText($text, true);
        if ($this->isMonoforum($chatId)) {
            throw new QueryError(400, 'Chat actions can\'t be sent to channel direct messages chats');
        }
        if (!$this->isChatActionUnneeded($chatId)) {
            $this->invokeOrFail('messages.setTyping', array_filter([
                'peer' => $this->inputPeer($chatId),
                'top_msg_id' => $forumTopicId !== 0 ? $forumTopicId : null,
                'action' => self::tl('sendMessageTextDraftAction', [
                    'can_stop' => $canStop,
                    'keep_on_stop' => $keepOnStop,
                    'random_id' => $draftId,
                    'text' => ['_' => 'textWithEntities', 'text' => $text->text,
                        'entities' => $text->inputEntities(fn (int $userId): array => $this->inputUser($userId))],
                ]),
            ], static fn ($value) => $value !== null));
        }
        $query->answerQuery(true);
    }

    public function processSendRichMessageDraftQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $forumTopicId = self::getForumTopicId($query, 'message_thread_id');
        $draftId = \LaraGram\Server\Support\TdInteger::toInt64($query->arg('draft_id'));
        $canStop = self::boolArg($query, 'can_stop');
        $keepOnStop = self::boolArg($query, 'keep_on_stop');
        $richMessage = $this->getInputRichMessage($query);
        $chatId = $this->checkChat($chatIdStr, self::ACCESS_WRITE);

        // td_api::sendRichMessageDraft → DialogActionManager::send_dialog_action
        $rich = $this->getInputRichContent($chatId, ['_' => 'inputMessageRichMessage', 'message' => $richMessage])['rich_message'];
        if ($this->isMonoforum($chatId)) {
            throw new QueryError(400, 'Chat actions can\'t be sent to channel direct messages chats');
        }
        if (!$this->isChatActionUnneeded($chatId)) {
            $inputRichMessage = $this->getInputRichMessageTl($rich, $chatId, 'New files can\'t be uploaded using the method');
            $this->invokeOrFail('messages.setTyping', array_filter([
                'peer' => $this->inputPeer($chatId),
                'top_msg_id' => $forumTopicId !== 0 ? $forumTopicId : null,
                'action' => self::tl('inputSendMessageRichMessageDraftAction', [
                    'can_stop' => $canStop,
                    'keep_on_stop' => $keepOnStop,
                    'random_id' => $draftId,
                    'rich_message' => $inputRichMessage,
                ]),
            ], static fn ($value) => $value !== null));
        }
        $query->answerQuery(true);
    }

    /**
     * DialogManager::is_dialog_action_unneeded for a bot.
     */
    private function isChatActionUnneeded(int $chatId): bool
    {
        if ($this->isAnonymousAdministrator($chatId)) {
            return true;
        }
        if (ChatId::type($chatId) === ChatId::TYPE_PRIVATE) {
            $user = $this->getUser($chatId) ?? [];
            if (!empty($user['deleted']) || (!empty($user['bot']) && empty($user['support'])) || $chatId === $this->getMyId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Client::get_chat_action: a TL SendMessageAction, or null.
     *
     * @return array<string, mixed>|null
     */
    protected static function getChatAction(Query $query): ?array
    {
        return match (strtolower($query->arg('action'))) {
            'cancel' => ['_' => 'sendMessageCancelAction'],
            'typing' => ['_' => 'sendMessageTypingAction'],
            'record_video' => ['_' => 'sendMessageRecordVideoAction'],
            'upload_video' => ['_' => 'sendMessageUploadVideoAction', 'progress' => 0],
            'record_audio', 'record_voice' => ['_' => 'sendMessageRecordAudioAction'],
            'upload_audio', 'upload_voice' => ['_' => 'sendMessageUploadAudioAction', 'progress' => 0],
            'upload_photo' => ['_' => 'sendMessageUploadPhotoAction', 'progress' => 0],
            'upload_document' => ['_' => 'sendMessageUploadDocumentAction', 'progress' => 0],
            'choose_sticker' => ['_' => 'sendMessageChooseStickerAction'],
            'pick_up_location', 'find_location' => ['_' => 'sendMessageGeoLocationAction'],
            'record_video_note' => ['_' => 'sendMessageRecordRoundAction'],
            'upload_video_note' => ['_' => 'sendMessageUploadRoundAction', 'progress' => 0],
            default => null,
        };
    }

    // ═════════════════════════════════════════════════════════════════
    //  Suggested posts
    // ═════════════════════════════════════════════════════════════════

    public function processApproveSuggestedPostQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $sendDate = \LaraGram\Server\Support\TdInteger::toInt32($query->arg('send_date'));
        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_WRITE, 'suggested post');
        $this->processSuggestedPost($chatId, $messageId, false, $sendDate, '');
        $query->answerQuery(true);
    }

    public function processDeclineSuggestedPostQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $comment = $query->arg('comment');
        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_WRITE, 'suggested post');
        $comment = self::cleanInputStringOrFail($comment);
        $this->processSuggestedPost($chatId, $messageId, true, 0, $comment);
        $query->answerQuery(true);
    }

    /**
     * MessagesManager::process_suggested_post + ToggleSuggestedPostApprovalQuery.
     */
    private function processSuggestedPost(int $chatId, int $messageId, bool $isRejected, int $scheduleDate, string $comment): void
    {
        $message = $this->getMessage($chatId, $messageId, true) ?? throw new QueryError(400, 'Message not found');
        if ($isRejected ? !$this->canDeclineSuggestedPost($chatId, $message) : !$this->canApproveSuggestedPost($chatId, $message)) {
            throw new QueryError(400, 'Message can\'t be used in the method');
        }
        $result = $this->invokeOrFail('messages.toggleSuggestedPostApproval', self::untl(self::tl('messages.toggleSuggestedPostApproval', [
            'reject' => $isRejected,
            'peer' => $this->inputPeer($chatId),
            'msg_id' => $messageId,
            'schedule_date' => $scheduleDate !== 0 ? $scheduleDate : null,
            'reject_comment' => $comment !== '' ? $comment : null,
        ])));
        // UpdatesManager::on_get_updates: the post gets its new state
        foreach ($this->updatesOf(is_array($result) ? $result : []) as $update) {
            if (in_array($update['_'] ?? '', ['updateEditMessage', 'updateEditChannelMessage'], true)) {
                $info = (new \LaraGram\Server\Messages\MessageConverter($this))->convert($update['message'] ?? []);
                if ($info !== null && $info->chatId === $chatId && $info->id === $messageId) {
                    $this->addSentMessage($info);
                }
            }
        }
    }

    /**
     * MessagesManager::can_approve_or_decline_message.
     */
    private function canApproveOrDeclineSuggestedPost(int $chatId, \LaraGram\Server\Messages\MessageInfo $message): bool
    {
        if ($message->suggestedPostInfo === null || $message->suggestedPostInfo['state'] !== 'pending' || $message->isOutgoing
            || !$this->isMonoforum($chatId) || $message->ephemeralMessageId !== 0) {
            return false;
        }

        return !(!self::isSuggestedPostFromUser($message) && $this->isAdminedMonoforum($chatId));
    }

    private static function isSuggestedPostFromUser(\LaraGram\Server\Messages\MessageInfo $message): bool
    {
        return $message->senderChatId === 0 && $message->senderUserId !== 0;
    }

    /**
     * MessagesManager::can_approve_message.
     */
    private function canApproveSuggestedPost(int $chatId, \LaraGram\Server\Messages\MessageInfo $message): bool
    {
        if (!$this->canApproveOrDeclineSuggestedPost($chatId, $message)) {
            return false;
        }
        if (self::isSuggestedPostFromUser($message)) {
            $channel = $this->getChannel(ChatId::toChannelId($chatId)) ?? [];
            $linked = $this->getChannel((int) ($channel['linked_monoforum_id'] ?? 0)) ?? [];
            if (!\LaraGram\Server\Td\ParticipantStatus::fromServerChannel($linked)->canPostMessages()) {
                return false;
            }
        }
        $scheduleDate = $message->suggestedPostInfo['send_date'];

        return !($scheduleDate !== 0 && $scheduleDate < $this->getUnixTime() - 86400);
    }

    /**
     * MessagesManager::can_decline_message.
     */
    private function canDeclineSuggestedPost(int $chatId, \LaraGram\Server\Messages\MessageInfo $message): bool
    {
        if (!$this->canApproveOrDeclineSuggestedPost($chatId, $message)) {
            return false;
        }

        return !(self::isSuggestedPostFromUser($message) && !$this->isAdminedMonoforum($chatId));
    }
}
