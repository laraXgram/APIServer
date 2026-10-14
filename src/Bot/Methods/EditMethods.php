<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonMessage;
use LaraGram\Server\Support\Utf8;
use LaraGram\Server\Td\InlineMessageId;

/**
 * editMessageText, editMessageCaption, editMessageMedia,
 * editMessageLiveLocation, stopMessageLiveLocation, editMessageReplyMarkup
 * (Client::process_edit_message_*_query with TDLib's MessagesManager::edit_message_*
 * and InlineMessageManager::edit_inline_message_*).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait EditMethods
{
    public function processEditMessageTextQuery(Query $query): void
    {
        $content = $query->hasArg('rich_message')
            ? ['_' => 'inputMessageRichMessage', 'message' => $this->getInputRichMessage($query), 'clear_draft' => false]
            : ['_' => 'inputMessageText', 'clear_draft' => false] + $this->getInputMessageText($query);
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $replyMarkup = $this->getReplyMarkup($query);

        if ($chatIdStr === '' && $messageId === 0) {
            $inlineMessageId = self::getInlineMessageId($query);
            $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
            $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
            $id = self::parseInlineMessageId($inlineMessageId);
            if ($content['_'] === 'inputMessageRichMessage') {
                $rich = $this->getInputRichContent(0, $content)['rich_message'];
                $richMessage = $this->getInputRichMessageTl($rich, 0, 'Invalid message content specified');
                $this->editInlineMessage($id, false, null, false, null, false, $tlReplyMarkup, $richMessage);
            } else {
                [$text, $noWebpage, $webPage, $above] = $this->processInputMessageTextForEdit(0, $content);
                $this->editInlineMessage($id, true, $text, $noWebpage, $webPage, $above, $tlReplyMarkup);
            }
            $query->answerQuery(true);

            return;
        }

        $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
        if ($businessConnectionId !== '') {
            $this->answerBusinessMessage($query, $this->editBusinessMessageText($businessConnectionId, $chatIdStr, $messageId, $replyMarkup, $content));

            return;
        }
        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_EDIT, 'message to edit');

        $message = $this->getEditableMessage($chatId, $messageId);
        $type = self::contentTypeOf($message->content);
        if (!in_array($type, ['Text', 'RichText', 'Game'], true)) {
            throw new QueryError(400, 'There is no text in the message to edit');
        }
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, $this->hasMessageSenderUserId($chatId, $message));
        if ($content['_'] === 'inputMessageRichMessage') {
            // process_input_message_content + do_edit_message_media
            $rich = $this->processInputMessageContent($chatId, $content, true)['rich_message'];
            $this->answerEditedMessage($query, $this->editMessage($chatId, $messageId, false, null, false, null, false, $tlReplyMarkup,
                $this->getInputRichMessageTl($rich, $chatId)));

            return;
        }
        [$text, $noWebpage, $webPage, $above] = $this->processInputMessageTextForEdit($chatId, $content);

        $this->answerEditedMessage($query, $this->editMessage($chatId, $messageId, true, $text, $noWebpage, $webPage, $above, $tlReplyMarkup));
    }

    public function processEditMessageCaptionQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $replyMarkup = $this->getReplyMarkup($query);
        $caption = $this->getCaption($query);
        $showCaptionAboveMedia = self::boolArg($query, 'show_caption_above_media');

        if ($chatIdStr === '' && $messageId === 0) {
            $inlineMessageId = self::getInlineMessageId($query);
            $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
            $caption = $this->getOutgoingFormattedText($caption, true);
            $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
            $id = self::parseInlineMessageId($inlineMessageId);
            $this->editInlineMessage($id, true, $caption, false, null, $showCaptionAboveMedia, $tlReplyMarkup);
            $query->answerQuery(true);

            return;
        }

        $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
        if ($businessConnectionId !== '') {
            $this->answerBusinessMessage($query, $this->editBusinessMessageCaption($businessConnectionId, $chatIdStr, $messageId, $replyMarkup, $caption, $showCaptionAboveMedia));

            return;
        }
        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_EDIT, 'message to edit');

        $message = $this->getEditableMessage($chatId, $messageId);
        $type = self::contentTypeOf($message->content);
        if (!in_array($type, ['Animation', 'Audio', 'Document', 'PaidMedia', 'Photo', 'Video', 'VoiceNote'], true)) {
            throw new QueryError(400, 'There is no caption in the message to edit');
        }
        if ($showCaptionAboveMedia && !in_array($type, ['Animation', 'PaidMedia', 'Photo', 'Video'], true)) {
            $showCaptionAboveMedia = false;
        }
        $caption = $this->getOutgoingFormattedText($caption, true);
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, $this->hasMessageSenderUserId($chatId, $message));

        $this->answerEditedMessage($query, $this->editMessage($chatId, $messageId, true, $caption, false, null, $showCaptionAboveMedia, $tlReplyMarkup));
    }

    public function processEditMessageMediaQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $replyMarkup = $this->getReplyMarkup($query);
        $content = $this->getInputMediaArg($query, 'media');

        if ($chatIdStr === '' && $messageId === 0) {
            $inlineMessageId = self::getInlineMessageId($query);
            $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
            $prepared = $this->getInputMediaContent(0, $content);
            if (!in_array($prepared['type'], ['Animation', 'Audio', 'Document', 'Photo', 'Video'], true)) {
                throw new QueryError(400, 'Unsupported input message content type');
            }
            $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
            $id = self::parseInlineMessageId($inlineMessageId);
            // a file can't be uploaded to another datacenter: use messages.uploadMedia first
            $media = $this->getUploadedInputMedia($prepared['media'], 0, false);
            $this->editInlineMessage($id, true, $prepared['text'], false, $media, $prepared['invert_media'], $tlReplyMarkup);
            $query->answerQuery(true);

            return;
        }

        $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
        if ($businessConnectionId !== '') {
            $this->answerBusinessMessage($query, $this->editBusinessMessageMedia($businessConnectionId, $chatIdStr, $messageId, $replyMarkup, $content));

            return;
        }
        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_EDIT, 'message to edit');

        $message = $this->getMessage($chatId, $messageId, true);
        if (!$this->canEditMessage($chatId, $message, true)) {
            throw new QueryError(400, 'Message media can\'t be edited');
        }
        $oldType = self::contentTypeOf($message->content);
        if ($message->isSelfDestruct || !in_array($oldType, ['Animation', 'Audio', 'Document', 'Photo', 'Video', 'RichText', 'Text'], true)) {
            throw new QueryError(400, 'Message media can\'t be edited');
        }

        $prepared = $this->processInputMessageContent($chatId, $content, true);
        $newType = $prepared['type'];
        if (!in_array($newType, ['Animation', 'Audio', 'Document', 'Photo', 'Video'], true)) {
            throw new QueryError(400, 'Unsupported input message content type');
        }
        if ($message->mediaAlbumId !== 0 && $oldType !== $newType) {
            if (!in_array($newType, ['Audio', 'Document', 'Photo', 'Video'], true)) {
                throw new QueryError(400, 'Message content type can\'t be used in an album');
            }
            if (in_array($oldType, ['Audio', 'Document'], true) || in_array($newType, ['Audio', 'Document'], true)) {
                throw new QueryError(400, 'Can\'t change media type in the album');
            }
        }
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, $this->hasMessageSenderUserId($chatId, $message));

        $this->answerEditedMessage($query, $this->editMessage($chatId, $messageId, true, $prepared['text'], false, $prepared['media'],
            $prepared['invert_media'], $tlReplyMarkup));
    }

    public function processEditMessageLiveLocationQuery(Query $query): void
    {
        $this->doEditMessageLiveLocation($query);
    }

    public function processStopMessageLiveLocationQuery(Query $query): void
    {
        $this->doEditMessageLiveLocation($query);
    }

    private function doEditMessageLiveLocation(Query $query): void
    {
        $location = null;
        $livePeriod = self::getIntegerArg($query, 'live_period', 0);
        $heading = self::getIntegerArg($query, 'heading', 0);
        $proximityAlertRadius = self::getIntegerArg($query, 'proximity_alert_radius', 0);
        if ($query->method() === 'editmessagelivelocation') {
            $location = self::getLocation($query);
        }
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $replyMarkup = $this->getReplyMarkup($query);

        $buildMedia = static function () use ($location, $livePeriod, $heading, $proximityAlertRadius): array {
            // process_live_location(for_edit = true)
            if ($location === null) {
                return ['_' => 'inputMediaGeoLive', 'stopped' => true, 'geo_point' => ['_' => 'inputGeoPointEmpty'],
                    'heading' => 0, 'period' => 0, 'proximity_notification_radius' => 0];
            }
            if (!self::isValidLocation($location)) {
                throw new QueryError(400, 'Invalid live location specified');
            }
            if ($heading !== 0 && ($heading < 1 || $heading > 360)) {
                throw new QueryError(400, 'Wrong live location heading specified');
            }
            if ($proximityAlertRadius < 0 || $proximityAlertRadius > 100000) {
                throw new QueryError(400, 'Wrong live location proximity alert radius specified');
            }

            return self::tl('inputMediaGeoLive', [
                'geo_point' => self::inputGeoPoint($location),
                'heading' => $heading,
                'period' => $livePeriod,
                'proximity_notification_radius' => $proximityAlertRadius,
            ], ['heading', 'period', 'proximity_notification_radius']);
        };

        if ($chatIdStr === '' && $messageId === 0) {
            $inlineMessageId = self::getInlineMessageId($query);
            $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
            $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
            $id = self::parseInlineMessageId($inlineMessageId);
            $media = $buildMedia();
            $this->editInlineMessage($id, false, null, false, $media, false, $tlReplyMarkup);
            $query->answerQuery(true);

            return;
        }

        $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
        if ($businessConnectionId !== '') {
            $this->answerBusinessMessage($query, $this->editBusinessMessageLiveLocation($businessConnectionId, $chatIdStr, $messageId, $replyMarkup, $buildMedia));

            return;
        }
        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_EDIT, 'message to edit');

        $message = $this->getEditableMessage($chatId, $messageId);
        if ($message->content['_'] !== 'messageLiveLocation') {
            throw new QueryError(400, 'There is no live location in the message to edit');
        }
        $media = $buildMedia();
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, $this->hasMessageSenderUserId($chatId, $message));

        $this->answerEditedMessage($query, $this->editMessage($chatId, $messageId, false, null, false, $media, false, $tlReplyMarkup));
    }

    public function processEditMessageReplyMarkupQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $chatIdStr = $query->arg('chat_id');
        $messageId = self::getMessageId($query);
        $replyMarkup = $this->getReplyMarkup($query);

        if ($chatIdStr === '' && $messageId === 0) {
            $inlineMessageId = self::getInlineMessageId($query);
            $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
            $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
            $id = self::parseInlineMessageId($inlineMessageId);
            $this->editInlineMessage($id, false, null, false, null, false, $tlReplyMarkup);
            $query->answerQuery(true);

            return;
        }

        $replyMarkup = $this->resolveReplyMarkupBotUsernames($replyMarkup);
        if ($businessConnectionId !== '') {
            $this->answerBusinessMessage($query, $this->editBusinessMessageReplyMarkup($businessConnectionId, $chatIdStr, $messageId, $replyMarkup));

            return;
        }
        [$chatId, $messageId] = $this->checkMessage($chatIdStr, $messageId, false, self::ACCESS_EDIT, 'message to edit');

        $message = $this->getMessage($chatId, $messageId, true);
        if (!$this->canEditMessage($chatId, $message, true, true)) {
            throw new QueryError(400, 'Message can\'t be edited');
        }
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, $this->hasMessageSenderUserId($chatId, $message));
        $noWebpage = ($message->content['link_preview_options']['is_disabled'] ?? false) === true;
        $invertMedia = (bool) ($message->content['show_caption_above_media'] ?? ($message->content['link_preview_options']['show_above_text'] ?? false));

        $this->answerEditedMessage($query, $this->editMessage($chatId, $messageId, false, null, $noWebpage, null, $invertMedia, $tlReplyMarkup));
    }

    // ═════════════════════════════════════════════════════════════════
    //  Helpers
    // ═════════════════════════════════════════════════════════════════

    /**
     * Client::get_inline_message_id.
     */
    protected static function getInlineMessageId(Query $query, string $field = 'inline_message_id'): string
    {
        $value = $query->arg($field);
        if ($value === '') {
            throw QueryError::badRequest('Message identifier is not specified');
        }

        return $value;
    }

    /**
     * get_input_bot_inline_message_id.
     *
     * @return array<string, mixed>
     */
    protected static function parseInlineMessageId(string $inlineMessageId): array
    {
        $id = InlineMessageId::decode($inlineMessageId);
        if ($id === null) {
            throw new QueryError(400, 'Invalid inline message identifier specified');
        }

        return $id;
    }

    /**
     * MessagesManager::has_message_sender_user_id.
     */
    private function hasMessageSenderUserId(int $chatId, MessageInfo $message): bool
    {
        // a chat sender is not a user sender; a bot sees discussion messages as sent by their channel
        if ($message->senderUserId === 0 || $message->senderChatId !== 0) {
            return false;
        }

        return !$message->isAutomaticForward;
    }

    /**
     * The message to edit, or "Message can't be edited".
     */
    private function getEditableMessage(int $chatId, int $messageId): MessageInfo
    {
        $message = $this->getMessage($chatId, $messageId, true);
        if ($message === null) {
            throw new QueryError(400, 'Message not found');
        }
        if (!$this->canEditMessage($chatId, $message, true)) {
            throw new QueryError(400, 'Message can\'t be edited');
        }

        return $message;
    }

    /**
     * MessagesManager::can_edit_message for a bot.
     */
    public function canEditMessage(int $chatId, ?MessageInfo $message, bool $isEditing, bool $onlyReplyMarkup = false): bool
    {
        if ($message === null || $message->forwardOrigin !== null) {
            return false;
        }
        if ($message->replyMarkup !== null && ($message->replyMarkup['_'] ?? '') !== 'replyInlineMarkup') {
            return false;
        }
        if ($message->isPaidPost) {
            return false;
        }

        $myId = $this->getMyId();
        $isInlineMessage = $message->viaBotUserId !== 0;
        if ($isInlineMessage && $message->viaBotUserId !== $myId) {
            return false;
        }

        $contentType = self::contentTypeOf($message->content);
        $hasEditTimeLimit = !$message->isOutgoing && $chatId !== $myId
            && !in_array($contentType, ['Poll', 'ToDoList', 'LiveLocation'], true);

        switch (ChatId::type($chatId)) {
            case ChatId::TYPE_PRIVATE:
                if (!$message->isOutgoing && $chatId !== $myId && !$isInlineMessage) {
                    return false;
                }
                break;
            case ChatId::TYPE_GROUP:
                if (!$message->isOutgoing && !$isInlineMessage) {
                    return false;
                }
                break;
            case ChatId::TYPE_SUPERGROUP:
                if ($isInlineMessage) {
                    break;
                }
                $status = $this->getChatPermissions($chatId);
                if ($this->isBroadcastChannel($chatId)) {
                    if (!$status->canEditMessages() && !($status->canPostMessages() && $message->isOutgoing)) {
                        return false;
                    }
                    if ($status->canEditMessages() || $onlyReplyMarkup) {
                        $hasEditTimeLimit = false;
                    }
                } else {
                    if (!$message->isOutgoing) {
                        return false;
                    }
                    if ($status->canPinMessages()) {
                        $hasEditTimeLimit = false;
                    }
                }
                break;
            default:
                return false;
        }

        if ($hasEditTimeLimit && $this->getUnixTime() - $message->date - ($isEditing ? 300 : 0) >= 2 * 86400) {
            return false;
        }

        if (in_array($contentType, ['Animation', 'Audio', 'Document', 'Game', 'PaidMedia', 'Photo', 'RichText', 'Text', 'ToDoList', 'Video', 'VoiceNote'], true)) {
            return true;
        }
        if ($onlyReplyMarkup && $contentType !== 'Unsupported' && !str_starts_with($message->content['_'], 'messageExpired')) {
            return true;
        }

        return match ($contentType) {
            'LiveLocation' => $this->getUnixTime() - $message->date < (int) ($message->content['live_period'] ?? 0),
            'Poll' => empty($message->content['poll']['is_closed']),
            default => false,
        };
    }

    /**
     * process_input_message_text for an edit.
     *
     * @param array<string, mixed> $content
     * @return array{0: FormattedText, 1: bool, 2: array<string, mixed>|null, 3: bool}
     */
    private function processInputMessageTextForEdit(int $chatId, array $content): array
    {
        $prepared = $this->getInputMessageContent($chatId, $content);

        return [$prepared['text'], $prepared['no_webpage'], $prepared['media'], $prepared['invert_media']];
    }

    /**
     * EditMessageQuery: messages.editMessage; the edited message.
     *
     * @param array<string, mixed>|null $media
     * @param array<string, mixed>|null $replyMarkup
     */
    private function editMessage(int $chatId, int $messageId, bool $editText, ?FormattedText $text, bool $noWebpage,
        ?array $media, bool $invertMedia, ?array $replyMarkup, ?array $richMessage = null): MessageInfo
    {
        $params = self::tl('messages.editMessage', [
            'no_webpage' => $noWebpage,
            'invert_media' => $invertMedia,
            'peer' => $this->inputPeer($chatId),
            'id' => $messageId,
            'message' => $editText ? ($text?->text ?? '') : null,
            'media' => $media === null ? null : $this->getUploadedInputMedia($media, $chatId, true),
            'reply_markup' => $replyMarkup,
            'entities' => $editText && $text !== null ? $this->inputEntitiesOrNull($text) : null,
            'rich_message' => $richMessage,
        ]);
        unset($params['_']);

        try {
            $result = $this->invoke('messages.editMessage', $params);
        } catch (\Throwable $e) {
            unset($this->pendingPhotoHashes[\Swoole\Coroutine::getCid()]);
            throw QueryError::fromThrowable($e);
        }
        $this->rememberUploadedPhotosOf(is_array($result) ? $result : null);

        foreach ($this->updatesOf(is_array($result) ? $result : []) as $update) {
            if (in_array($update['_'] ?? '', ['updateEditMessage', 'updateEditChannelMessage'], true)) {
                $info = (new MessageConverter($this))->convert($update['message'] ?? []);
                if ($info !== null && $info->chatId === $chatId && $info->id === $messageId) {
                    return $this->addSentMessage($info);
                }
            }
        }

        $message = $this->getMessage($chatId, $messageId, true);
        if ($message === null) {
            throw new QueryError(400, 'message not found');
        }

        return $message;
    }

    /**
     * Client::TdOnEditMessageCallback.
     */
    private function answerEditedMessage(Query $query, MessageInfo $message): void
    {
        $message->isContentChanged = false;
        $query->answerQuery(JsonMessage::make($message, false, $this));
    }

    /**
     * EditInlineMessageQuery: messages.editInlineBotMessage on the message's datacenter.
     *
     * @param array<string, mixed> $id
     * @param array<string, mixed>|null $media
     * @param array<string, mixed>|null $replyMarkup
     */
    private function editInlineMessage(array $id, bool $forceEditText, ?FormattedText $text, bool $noWebpage, ?array $media,
        bool $invertMedia, ?array $replyMarkup, ?array $richMessage = null): void
    {
        $editText = $forceEditText || ($text !== null && $text->text !== '');
        $params = self::tl('messages.editInlineBotMessage', [
            'no_webpage' => $noWebpage,
            'invert_media' => $invertMedia,
            'id' => $id,
            'message' => $editText ? ($text?->text ?? '') : null,
            'media' => $media,
            'reply_markup' => $replyMarkup,
            'entities' => $editText && $text !== null ? $this->inputEntitiesOrNull($text) : null,
            'rich_message' => $richMessage,
        ]);
        unset($params['_']);

        $this->invokeOnDc((int) $id['dc_id'], 'messages.editInlineBotMessage', $params);
    }

    /**
     * Invoke a method on a datacenter, through an authorized connection of the pool.
     *
     * @param array<string, mixed> $params
     */
    public function invokeOnDc(int $dcId, string $method, array $params): mixed
    {
        try {
            if ($dcId === $this->td->getDcId()) {
                return $this->invoke($method, $params);
            }

            return $this->td->pool()->connection($dcId)->invoke($method, $params);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
    }

    /**
     * The updates of a TL Updates object.
     *
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    protected function updatesOf(array $result): array
    {
        return match ($result['_'] ?? '') {
            'updates', 'updatesCombined' => $result['updates'] ?? [],
            'updateShort' => [$result['update']],
            default => [],
        };
    }

    /**
     * Media with files uploaded: files of an album or of an inline message
     * must be uploaded with messages.uploadMedia first (is_uploaded_input_media).
     *
     * @param array<string, mixed> $media prepared InputMedia
     * @return array<string, mixed>
     */
    protected function getUploadedInputMedia(array $media, int $chatId, bool $allowUploadedFiles): array
    {
        $media = $this->uploadPendingFiles($media, $chatId);
        if ($allowUploadedFiles || in_array($media['_'], ['inputMediaPhoto', 'inputMediaDocument'], true)) {
            return $media;
        }

        try {
            $result = $this->invoke('messages.uploadMedia', $this->uploadMediaParams($chatId, $media));
        } catch (\Throwable $e) {
            unset($this->pendingPhotoHashes[\Swoole\Coroutine::getCid()]);
            throw $this->sendError($e, $chatId, 'Photo');
        }
        $this->rememberUploadedPhotos([$result['photo'] ?? []]);

        return self::uploadedInputMedia($result, $media);
    }

    /**
     * The InputMedia of a file uploaded with messages.uploadMedia
     * (get_uploaded_message_content + get_message_content_input_media).
     *
     * @param array<string, mixed> $result TL MessageMedia
     * @param array<string, mixed> $media the uploaded InputMedia
     * @return array<string, mixed>
     */
    protected static function uploadedInputMedia(array $result, array $media): array
    {
        $spoiler = !empty($media['spoiler']);
        if (($result['_'] ?? '') === 'messageMediaPhoto') {
            $photo = $result['photo'] ?? [];

            return self::tl('inputMediaPhoto', ['spoiler' => $spoiler, 'live_photo' => !empty($media['live_photo']), 'id' => [
                '_' => 'inputPhoto', 'id' => (int) ($photo['id'] ?? 0), 'access_hash' => (int) ($photo['access_hash'] ?? 0),
                'file_reference' => (string) ($photo['file_reference'] ?? ''),
            ], 'video' => $media['video'] ?? null]);
        }

        $document = $result['document'] ?? [];

        return self::tl('inputMediaDocument', [
            'spoiler' => $spoiler,
            'id' => ['_' => 'inputDocument', 'id' => (int) ($document['id'] ?? 0), 'access_hash' => (int) ($document['access_hash'] ?? 0),
                'file_reference' => (string) ($document['file_reference'] ?? '')],
            'video_cover' => $media['video_cover'] ?? null,
            'video_timestamp' => $media['video_timestamp'] ?? null,
        ]);
    }
}
