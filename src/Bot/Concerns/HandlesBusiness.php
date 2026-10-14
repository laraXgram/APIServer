<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\JsonWriter;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonChat;
use LaraGram\Server\Objects\JsonUser;
use LaraGram\Server\Updates\UpdateType;

/**
 * Business connections: the Bot API side (Client::check_business_connection,
 * the business updates) and the TDLib side (BusinessConnectionManager) of
 * messages sent, edited and managed on behalf of a business account.
 *
 * Requests of a connection are wrapped in invokeWithBusinessConnection and
 * sent to the datacenter of the connection.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait HandlesBusiness
{
    /**
     * BusinessConnectionManager::BusinessConnection by connection identifier.
     *
     * @var array<string, array{id: string, user_id: int, dc_id: int, date: int, rights: array<string, mixed>, is_disabled: bool}>
     */
    private array $businessConnections = [];

    /** @var array<string, array{queue: list<array{0: MessageInfo, 1: bool}>, active: bool}> */
    private array $newBusinessMessageQueues = [];

    /** The connection files are uploaded for (messages.uploadMedia business_connection_id). */
    protected string $uploadBusinessConnectionId = '';

    // ═════════════════════════════════════════════════════════════════
    //  Connections
    // ═════════════════════════════════════════════════════════════════

    /**
     * BusinessConnection(telegram_api::botBusinessConnection); null when invalid.
     *
     * @param array<string, mixed> $connection TL botBusinessConnection
     * @return array{id: string, user_id: int, dc_id: int, date: int, rights: array<string, mixed>, is_disabled: bool}|null
     */
    private static function businessConnectionFromServer(array $connection): ?array
    {
        $result = [
            'id' => (string) ($connection['connection_id'] ?? ''),
            'user_id' => (int) ($connection['user_id'] ?? 0),
            'dc_id' => (int) ($connection['dc_id'] ?? 0),
            'date' => (int) ($connection['date'] ?? 0),
            'rights' => $connection['rights'] ?? [],
            'is_disabled' => !empty($connection['disabled']),
        ];
        if ($result['id'] === '' || $result['user_id'] <= 0 || $result['dc_id'] <= 0 || $result['date'] <= 0) {
            return null;
        }

        return $result;
    }

    /**
     * BusinessConnectionManager::on_update_bot_business_connect + Client::add_update_business_connection.
     *
     * @param array<string, mixed> $connection TL botBusinessConnection
     */
    private function onUpdateBotBusinessConnect(array $connection): void
    {
        $businessConnection = self::businessConnectionFromServer($connection);
        if ($businessConnection === null) {
            $this->parameters->logger->error('Receive invalid business connection '.json_encode($connection));

            return;
        }
        $this->businessConnections[$businessConnection['id']] = $businessConnection;

        $this->addUpdate(UpdateType::BUSINESS_CONNECTION, JsonWriter::encode($this->businessConnectionObject($businessConnection)),
            86400, $businessConnection['user_id'] + (10 << 33));
    }

    /**
     * Client::check_business_connection: the connection, loaded with
     * BusinessConnectionManager::get_business_connection when unknown.
     *
     * @return array{id: string, user_id: int, dc_id: int, date: int, rights: array<string, mixed>, is_disabled: bool}
     */
    protected function checkBusinessConnection(string $connectionId): array
    {
        if (isset($this->businessConnections[$connectionId])) {
            return $this->businessConnections[$connectionId];
        }

        try {
            if ($connectionId === '') {
                throw new QueryError(400, 'Connection iedntifier must be non-empty');
            }
            $updates = $this->invoke('account.getBotBusinessConnection', ['connection_id' => $connectionId]);
            if (isset($this->businessConnections[$connectionId])) {
                return $this->businessConnections[$connectionId];
            }
            if (($updates['_'] ?? '') !== 'updates') {
                throw new QueryError(500, 'Receive invalid business connection info');
            }
            $list = $updates['updates'] ?? [];
            if (count($list) !== 1 || ($list[0]['_'] ?? '') !== 'updateBotBusinessConnect') {
                if ($list === []) {
                    throw new QueryError(400, 'Business connection not found');
                }
                throw new QueryError(500, 'Receive invalid business connection info');
            }
            $connection = self::businessConnectionFromServer($list[0]['connection'] ?? []);
            if ($connection === null || $connection['id'] !== $connectionId) {
                throw new QueryError(500, 'Receive invalid business connection info');
            }
        } catch (\Throwable $e) {
            // Client::TdOnCheckBusinessConnectionCallback
            $error = QueryError::fromThrowable($e);
            throw new QueryError($error->getCode(), $error->getMessage(), 'business connection not found');
        }

        return $this->businessConnections[$connectionId] = $connection;
    }

    /**
     * Client::check_business_connection_chat_id.
     *
     * @return array{0: array{id: string, user_id: int, dc_id: int, date: int, rights: array<string, mixed>, is_disabled: bool}, 1: int}
     */
    protected function checkBusinessConnectionChatId(string $connectionId, string $chatIdStr): array
    {
        // Client::get_business_connection_chat_id
        if ($chatIdStr === '') {
            throw new QueryError(400, 'Bad Request: chat identifier is empty');
        }
        if (preg_match('/^-?\d+$/', $chatIdStr) !== 1 || ($chatId = filter_var($chatIdStr, FILTER_VALIDATE_INT)) === false) {
            throw new QueryError(400, 'Bad Request: chat identifier must be a valid Integer');
        }

        return [$this->checkBusinessConnection($connectionId), $chatId];
    }

    /**
     * BusinessConnectionManager::check_business_connection(connection_id, dialog_id).
     *
     * @param array<string, mixed> $connection
     */
    private function checkBusinessConnectionDialog(array $connection, int $chatId): void
    {
        if (ChatId::type($chatId) !== ChatId::TYPE_PRIVATE) {
            throw new QueryError(400, 'Chat must be a private chat');
        }
        if ($chatId === $connection['user_id']) {
            throw new QueryError(400, 'Messages must not be sent to self');
        }
    }

    /**
     * BusinessConnectionManager::check_business_message_id.
     */
    private static function checkBusinessMessageId(int $messageId): void
    {
        if ($messageId <= 0) {
            throw new QueryError(400, 'Invalid message identifier specified');
        }
    }

    /**
     * A request on behalf of the business account: invokeWithBusinessConnection
     * on the datacenter of the connection.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $params
     */
    protected function invokeBusiness(array $connection, string $method, array $params): mixed
    {
        unset($params['_']);
        $params = $this->withBusinessUploads($connection, fn (): array => $this->uploadPendingFiles($params, 0) ?? []);
        $wrapped = ['connection_id' => $connection['id'], 'query' => ['_' => $method] + $params];

        try {
            $dcId = (int) $connection['dc_id'];
            if ($dcId === $this->td->getDcId()) {
                return $this->invoke('invokeWithBusinessConnection', $wrapped);
            }
            $result = $this->td->pool()->connection($dcId)->invoke('invokeWithBusinessConnection', $wrapped);
            if (is_array($result) && (isset($result['users']) || isset($result['chats']))) {
                $this->addEntities($result['users'] ?? [], $result['chats'] ?? []);
            }

            return $result;
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
    }

    /**
     * Run $callback with files uploaded for the connection.
     *
     * @template T
     * @param array<string, mixed> $connection
     * @param callable(): T $callback
     * @return T
     */
    private function withBusinessUploads(array $connection, callable $callback): mixed
    {
        $previous = $this->uploadBusinessConnectionId;
        $this->uploadBusinessConnectionId = $connection['id'];
        try {
            return $callback();
        } finally {
            $this->uploadBusinessConnectionId = $previous;
        }
    }

    /**
     * Client::JsonBusinessConnection.
     *
     * @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    public function businessConnectionObject(array $connection): array
    {
        $object = [
            'id' => $connection['id'],
            'user' => JsonUser::make($connection['user_id'], $this),
            'user_chat_id' => $connection['user_id'],
            'date' => $connection['date'],
            'is_enabled' => !$connection['is_disabled'],
        ];
        if (!$connection['is_disabled']) {
            $rights = $connection['rights'];
            $object['can_reply'] = !empty($rights['reply']);
            $object['rights'] = self::businessBotRightsObject($rights);
        } else {
            $object['can_reply'] = false;
        }

        return $object;
    }

    /**
     * BusinessBotRights + Client::JsonBusinessBotRights.
     *
     * @param array<string, mixed> $rights TL businessBotRights
     * @return array<string, true>|\stdClass
     */
    private static function businessBotRightsObject(array $rights): array|\stdClass
    {
        $object = [];
        foreach ([
            'reply' => 'can_reply', 'read_messages' => 'can_read_messages', 'delete_sent_messages' => 'can_delete_sent_messages',
            'delete_received_messages' => 'can_delete_all_messages', 'edit_name' => 'can_edit_name', 'edit_bio' => 'can_edit_bio',
            'edit_profile_photo' => 'can_edit_profile_photo', 'edit_username' => 'can_edit_username',
            'view_gifts' => 'can_view_gifts_and_stars', 'sell_gifts' => 'can_convert_gifts_to_stars',
            'change_gift_settings' => 'can_change_gift_settings', 'transfer_and_upgrade_gifts' => 'can_transfer_and_upgrade_gifts',
            'transfer_stars' => 'can_transfer_stars', 'manage_stories' => 'can_manage_stories',
        ] as $flag => $field) {
            if (!empty($rights[$flag])) {
                $object[$field] = true;
            }
        }

        return $object === [] ? new \stdClass() : $object;
    }

    /**
     * Client::TdOnReturnBusinessMessageCallback.
     */
    protected function answerBusinessMessage(\LaraGram\Server\Http\Query $query, MessageInfo $message): void
    {
        $query->answerQuery(\LaraGram\Server\Objects\JsonMessage::make($message, true, $this));
    }

    // ═════════════════════════════════════════════════════════════════
    //  Business updates
    // ═════════════════════════════════════════════════════════════════

    /**
     * @param array<string, mixed> $update
     */
    private function onBusinessUpdate(array $update): bool
    {
        switch ($update['_'] ?? '') {
            case 'updateBotBusinessConnect':
                $this->onUpdateBotBusinessConnect($update['connection'] ?? []);

                return true;
            case 'updateBotNewBusinessMessage':
            case 'updateBotEditBusinessMessage':
                $connectionId = (string) ($update['connection_id'] ?? '');
                if ($connectionId === '') {
                    return true;
                }
                $message = $this->createBusinessMessage($connectionId, $update['message'] ?? [], $update['reply_to_message'] ?? null);
                if ($message === null) {
                    return true;
                }
                $this->newBusinessMessageQueues[$connectionId] ??= ['queue' => [], 'active' => false];
                $this->newBusinessMessageQueues[$connectionId]['queue'][] = [$message, $update['_'] === 'updateBotEditBusinessMessage'];
                $this->processNewBusinessMessageQueue($connectionId);

                return true;
            case 'updateBotDeleteBusinessMessage':
                // BusinessConnectionManager::on_update_bot_delete_business_messages + Client::add_update_business_messages_deleted
                $connectionId = (string) ($update['connection_id'] ?? '');
                $chatId = ChatId::fromPeer($update['peer'] ?? null);
                if ($connectionId === '' || ChatId::type($chatId) !== ChatId::TYPE_PRIVATE) {
                    return true;
                }
                $object = [
                    'business_connection_id' => $connectionId,
                    'chat' => JsonChat::make($chatId, $this),
                    'message_ids' => array_map('intval', $update['messages'] ?? []),
                ];
                $this->addUpdate(UpdateType::BUSINESS_MESSAGES_DELETED, JsonWriter::encode($object), 86400, $chatId + (11 << 33));

                return true;
        }

        return false;
    }

    /**
     * MessagesManager::get_business_message_object + Client::create_business_message.
     *
     * @param array<string, mixed> $message TL Message
     * @param array<string, mixed>|null $replyToMessage TL Message
     */
    private function createBusinessMessage(string $connectionId, array $message, ?array $replyToMessage): ?MessageInfo
    {
        $converter = new MessageConverter($this);
        $info = $converter->convert($message, true);
        if ($info === null || ChatId::type($info->chatId) !== ChatId::TYPE_PRIVATE) {
            return null;
        }
        $info->businessConnectionId = $connectionId;
        if ($replyToMessage !== null) {
            $reply = $converter->convert($replyToMessage, true);
            if ($reply !== null && ChatId::type($reply->chatId) === ChatId::TYPE_PRIVATE) {
                $reply->businessConnectionId = $connectionId;
                $info->businessReplyToMessage = $reply;
            }
        }

        return $info;
    }

    /**
     * Client::process_new_business_message_queue.
     */
    private function processNewBusinessMessageQueue(string $connectionId): void
    {
        if (!isset($this->newBusinessMessageQueues[$connectionId]) || $this->newBusinessMessageQueues[$connectionId]['active']) {
            return;
        }
        $this->newBusinessMessageQueues[$connectionId]['active'] = true;

        while ($this->newBusinessMessageQueues[$connectionId]['queue'] !== []) {
            if ($this->loggingOut || $this->closing) {
                break;
            }
            [$message, $isEdited] = $this->newBusinessMessageQueues[$connectionId]['queue'][0];
            $this->loadStickerSetNames($this->messageStickerSets($message));
            array_shift($this->newBusinessMessageQueues[$connectionId]['queue']);

            if ($this->needSkipUpdateMessage(0, $message, $isEdited)) {
                continue;
            }
            $this->addMessageUpdate($isEdited ? UpdateType::EDITED_BUSINESS_MESSAGE : UpdateType::BUSINESS_MESSAGE,
                $message, $message->chatId + (11 << 33));
        }

        unset($this->newBusinessMessageQueues[$connectionId]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Sending
    // ═════════════════════════════════════════════════════════════════

    /**
     * td_api::sendBusinessMessage (BusinessConnectionManager::send_message).
     *
     * @param array<string, mixed> $replyParameters
     * @param array<string, mixed>|null $replyMarkup
     * @param array<string, mixed> $content
     */
    protected function sendBusinessMessage(string $businessConnectionId, string $chatIdStr, array $replyParameters,
        bool $disableNotification, bool $protectContent, int $effectId, ?array $replyMarkup, array $content): MessageInfo
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        $prepared = $this->processBusinessInputMessageContent($content);
        $replyTo = $this->getBusinessInputReplyTo($replyParameters);
        $tlReplyMarkup = $this->getChatInputReplyMarkup($replyMarkup, $chatId, false, false);

        $params = [
            'silent' => $disableNotification,
            'noforwards' => $protectContent,
            'invert_media' => $prepared['invert_media'],
            'peer' => $this->inputPeer($chatId),
            'reply_to' => $replyTo,
            'message' => $prepared['text']->text,
            'random_id' => self::randomId(),
            'reply_markup' => $tlReplyMarkup,
            'entities' => $this->inputEntitiesOrNull($prepared['text']),
            'effect' => $effectId !== 0 ? $effectId : null,
        ];
        if (isset($prepared['rich_message'])) {
            $params['rich_message'] = $this->getBusinessInputRichMessage($connection, $prepared['rich_message'], $chatId);
            $method = 'messages.sendMessage';
        } elseif ($prepared['media'] === null) {
            $params['no_webpage'] = $prepared['no_webpage'];
            $method = 'messages.sendMessage';
        } else {
            // a new file is uploaded for the connection first (UploadBusinessMediaQuery)
            $params['media'] = in_array($prepared['media']['_'], ['inputMediaUploadedPhoto', 'inputMediaUploadedDocument'], true)
                ? $this->getBusinessUploadedInputMedia($connection, $prepared['media'], $chatId, false)
                : $prepared['media'];
            $method = 'messages.sendMedia';
        }

        $result = $this->invokeBusiness($connection, $method, self::tl($method, $params));

        return $this->processSentBusinessMessages($connection['id'], $result, false)[0];
    }

    /**
     * td_api::sendBusinessMessageAlbum (BusinessConnectionManager::send_message_album).
     *
     * @param array<string, mixed> $replyParameters
     * @param list<array<string, mixed>> $contents
     * @return list<MessageInfo>
     */
    protected function sendBusinessMessageAlbum(string $businessConnectionId, string $chatIdStr, array $replyParameters,
        bool $disableNotification, bool $protectContent, int $effectId, array $contents): array
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        $prepared = [];
        foreach ($contents as $content) {
            $prepared[] = $this->processBusinessInputMessageContent($content);
        }
        $this->checkMessageGroupMessageContents($prepared);
        $replyTo = $this->getBusinessInputReplyTo($replyParameters);

        $multiMedia = [];
        foreach ($prepared as $item) {
            $multiMedia[] = self::tl('inputSingleMedia', [
                'media' => $this->getBusinessUploadedInputMedia($connection, $item['media'], $chatId, true),
                'random_id' => self::randomId(),
                'message' => $item['text']->text,
                'entities' => $this->inputEntitiesOrNull($item['text']),
            ]);
        }
        $params = self::tl('messages.sendMultiMedia', [
            'silent' => $disableNotification,
            'noforwards' => $protectContent,
            'invert_media' => $prepared[0]['invert_media'],
            'peer' => $this->inputPeer($chatId),
            'reply_to' => $replyTo,
            'multi_media' => $multiMedia,
            'effect' => $effectId !== 0 ? $effectId : null,
        ]);
        $result = $this->invokeBusiness($connection, 'messages.sendMultiMedia', $params);

        return $this->processSentBusinessMessages($connection['id'], $result, true);
    }

    /**
     * check_message_group_message_contents.
     *
     * @param list<array<string, mixed>> $prepared
     */
    private function checkMessageGroupMessageContents(array $prepared): void
    {
        if (count($prepared) > 10) {
            throw new QueryError(400, 'Too many messages to send as an album');
        }
        if ($prepared === []) {
            throw new QueryError(400, 'There are no messages to send');
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
    }

    /**
     * BusinessConnectionManager::process_input_message_content.
     *
     * @param array<string, mixed> $content
     * @return array{type: string, text: FormattedText, media: array<string, mixed>|null, no_webpage: bool, invert_media: bool, rich_message?: array<string, mixed>}
     */
    private function processBusinessInputMessageContent(array $content): array
    {
        if ($content['_'] === 'inputMessageForwarded') {
            throw new QueryError(400, 'Can\'t forward messages as business');
        }

        return $this->getInputMessageContent($this->getMyId(), $content);
    }

    /**
     * get_input_message_reply_to_unchecked + create_business_message_input_reply_to
     * + MessageInputReplyTo::get_input_reply_to.
     *
     * @param array<string, mixed> $replyParameters
     * @return array<string, mixed>|null
     */
    private function getBusinessInputReplyTo(array $replyParameters): ?array
    {
        $messageId = (int) $replyParameters['reply_to_message_id'];
        if ($replyParameters['reply_in_chat_id'] !== '' || $messageId <= 0) {
            return null;
        }
        $pollOptionId = TextFormatting::cleanInputString($replyParameters['poll_option_id']) ?? '';
        $checklistTaskId = max(0, (int) $replyParameters['checklist_task_id']);
        $quote = $this->getMessageQuote($replyParameters['quote'], (int) $replyParameters['quote_position']);

        $result = self::tl('inputReplyToMessage', [
            'reply_to_msg_id' => $messageId,
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
     * BusinessConnectionManager::upload_media: new files and files by URL are
     * uploaded with UploadBusinessMediaQuery; files already on the server are
     * used as is.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $media prepared InputMedia
     * @return array<string, mixed>
     */
    private function getBusinessUploadedInputMedia(array $connection, array $media, int $chatId, bool $disallowAnimation): array
    {
        return $this->withBusinessUploads($connection, function () use ($media, $chatId, $disallowAnimation): array {
            $media = $this->uploadPendingFiles($media, $chatId) ?? [];
            if (!in_array($media['_'], ['inputMediaUploadedPhoto', 'inputMediaUploadedDocument', 'inputMediaPhotoExternal', 'inputMediaDocumentExternal'], true)) {
                return $media;
            }
            // is_uploaded_input_media
            if ($disallowAnimation && $media['_'] === 'inputMediaUploadedDocument') {
                $media = ['_' => $media['_'], 'nosound_video' => true] + $media;
            }
            try {
                $result = $this->invoke('messages.uploadMedia', $this->uploadMediaParams($chatId, $media));
            } catch (\Throwable $e) {
                unset($this->pendingPhotoHashes[\Swoole\Coroutine::getCid()]);
                throw QueryError::fromThrowable($e);
            }
            $this->rememberUploadedPhotos([$result['photo'] ?? []]);

            return self::uploadedInputMedia($result, $media);
        });
    }

    /**
     * The InputRichMessage of a business message: its new files are uploaded for the connection.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $rich
     * @return array<string, mixed>
     */
    private function getBusinessInputRichMessage(array $connection, array $rich, int $chatId): array
    {
        return $this->getInputRichMessageTl($rich, $chatId, null,
            fn (array $media): array => $this->getBusinessUploadedInputMedia($connection, $media, $chatId, false));
    }

    /**
     * BusinessConnectionManager::process_sent_business_message(_album).
     *
     * @return list<MessageInfo>
     */
    private function processSentBusinessMessages(string $connectionId, mixed $result, bool $isAlbum): array
    {
        $updates = is_array($result) && ($result['_'] ?? '') === 'updates' ? $result['updates'] ?? [] : null;
        if ($updates === null || (!$isAlbum && count($updates) !== 1)) {
            throw new QueryError(500, 'Receive invalid business connection messages');
        }
        foreach ($updates as $update) {
            if (($update['_'] ?? '') !== 'updateBotNewBusinessMessage') {
                throw new QueryError(500, 'Receive invalid business connection messages');
            }
        }

        $messages = [];
        foreach ($updates as $update) {
            $message = $this->createBusinessMessage($connectionId, $update['message'] ?? [], $update['reply_to_message'] ?? null);
            if ($message === null) {
                throw new QueryError(500, 'Receive invalid business connection messages');
            }
            $messages[] = $message;
        }

        return $messages;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Editing
    // ═════════════════════════════════════════════════════════════════

    /**
     * EditBusinessMessageQuery.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed>|null $media
     * @param array<string, mixed>|null $replyMarkup TL ReplyMarkup
     */
    private function editBusinessMessage(array $connection, int $chatId, int $messageId, bool $editText, ?FormattedText $text,
        bool $noWebpage, ?array $media, bool $invertMedia, ?array $replyMarkup, ?array $richMessage = null): MessageInfo
    {
        $params = self::tl('messages.editMessage', [
            'no_webpage' => $noWebpage,
            'invert_media' => $invertMedia,
            'peer' => $this->inputPeer($chatId),
            'id' => $messageId,
            'message' => $editText ? ($text?->text ?? '') : null,
            'media' => $media,
            'reply_markup' => $replyMarkup,
            'entities' => $editText && $text !== null ? $this->inputEntitiesOrNull($text) : null,
            'rich_message' => $richMessage,
        ]);
        $result = $this->invokeBusiness($connection, 'messages.editMessage', $params);

        return $this->processSentBusinessMessages($connection['id'], $result, false)[0];
    }

    /**
     * td_api::editBusinessMessageText.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @param array<string, mixed> $content
     */
    protected function editBusinessMessageText(string $businessConnectionId, string $chatIdStr, int $messageId, ?array $replyMarkup, array $content): MessageInfo
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        self::checkBusinessMessageId($messageId);
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
        if ($content['_'] === 'inputMessageRichMessage') {
            $richMessage = $this->getBusinessInputRichMessage($connection, $this->getInputMessageContent(0, $content)['rich_message'], $chatId);

            return $this->editBusinessMessage($connection, $chatId, $messageId, false, null, false, null, false, $tlReplyMarkup, $richMessage);
        }
        [$text, $noWebpage, $webPage, $above] = $this->processInputMessageTextForEdit(0, $content);

        return $this->editBusinessMessage($connection, $chatId, $messageId, true, $text, $noWebpage, $webPage, $above, $tlReplyMarkup);
    }

    /**
     * td_api::editBusinessMessageLiveLocation.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    protected function editBusinessMessageLiveLocation(string $businessConnectionId, string $chatIdStr, int $messageId, ?array $replyMarkup, callable $buildMedia): MessageInfo
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        self::checkBusinessMessageId($messageId);
        $media = $buildMedia();
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);

        return $this->editBusinessMessage($connection, $chatId, $messageId, false, null, false, $media, false, $tlReplyMarkup);
    }

    /**
     * td_api::editBusinessMessageChecklist.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @param array<string, mixed> $checklist
     */
    protected function editBusinessMessageChecklist(string $businessConnectionId, string $chatIdStr, int $messageId, ?array $replyMarkup, array $checklist): MessageInfo
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        self::checkBusinessMessageId($messageId);
        $media = $this->getInputMediaTodo($checklist);
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);

        return $this->editBusinessMessage($connection, $chatId, $messageId, false, null, false, $media, false, $tlReplyMarkup);
    }

    /**
     * td_api::editBusinessMessageMedia.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @param array<string, mixed> $content
     */
    protected function editBusinessMessageMedia(string $businessConnectionId, string $chatIdStr, int $messageId, ?array $replyMarkup, array $content): MessageInfo
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        self::checkBusinessMessageId($messageId);
        $prepared = $this->getInputMessageContent(0, $content);
        if (!in_array($prepared['type'], ['Animation', 'Audio', 'Document', 'Photo', 'Video'], true)) {
            throw new QueryError(400, 'Unsupported input message content type');
        }
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
        $media = $this->getBusinessUploadedInputMedia($connection, $prepared['media'], $chatId, $prepared['type'] !== 'Animation');

        return $this->editBusinessMessage($connection, $chatId, $messageId, true, $prepared['text'], false, $media,
            $prepared['invert_media'], $tlReplyMarkup);
    }

    /**
     * td_api::editBusinessMessageCaption.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    protected function editBusinessMessageCaption(string $businessConnectionId, string $chatIdStr, int $messageId, ?array $replyMarkup,
        FormattedText $caption, bool $invertMedia): MessageInfo
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        self::checkBusinessMessageId($messageId);
        $caption = $this->getOutgoingFormattedText($caption, true);
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);

        return $this->editBusinessMessage($connection, $chatId, $messageId, true, $caption, false, null, $invertMedia, $tlReplyMarkup);
    }

    /**
     * td_api::editBusinessMessageReplyMarkup.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    protected function editBusinessMessageReplyMarkup(string $businessConnectionId, string $chatIdStr, int $messageId, ?array $replyMarkup): MessageInfo
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        self::checkBusinessMessageId($messageId);
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);

        return $this->editBusinessMessage($connection, $chatId, $messageId, false, null, false, null, false, $tlReplyMarkup);
    }

    /**
     * td_api::stopBusinessPoll (StopBusinessPollQuery).
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    protected function stopBusinessPoll(string $businessConnectionId, string $chatIdStr, int $messageId, ?array $replyMarkup): MessageInfo
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        self::checkBusinessMessageId($messageId);
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);

        $params = self::tl('messages.editMessage', [
            'peer' => $this->inputPeer($chatId),
            'id' => $messageId,
            'media' => ['_' => 'inputMediaPoll', 'poll' => [
                '_' => 'poll',
                'id' => 0,
                'closed' => true,
                'creator' => true,
                'question' => ['_' => 'textWithEntities', 'text' => '', 'entities' => []],
                'answers' => [],
                'hash' => 0,
            ]],
            'reply_markup' => $tlReplyMarkup,
        ]);
        $result = $this->invokeBusiness($connection, 'messages.editMessage', $params);

        return $this->processSentBusinessMessages($connection['id'], $result, false)[0];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Chat actions and pins
    // ═════════════════════════════════════════════════════════════════

    /**
     * DialogActionManager::send_dialog_action as business.
     *
     * @param array<string, mixed> $action TL SendMessageAction
     */
    protected function sendBusinessChatAction(string $businessConnectionId, string $chatIdStr, array $action): void
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        $this->invokeBusiness($connection, 'messages.setTyping', ['peer' => $this->inputPeer($chatId), 'action' => $action]);
    }

    /**
     * td_api::setBusinessMessageIsPinned (MessagesManager::pin_dialog_message as business).
     */
    protected function setBusinessMessageIsPinned(string $businessConnectionId, string $chatIdStr, int $messageId, bool $isPinned): void
    {
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);
        $this->checkBusinessConnectionDialog($connection, $chatId);
        $this->invokeBusiness($connection, 'messages.updatePinnedMessage', self::tl('messages.updatePinnedMessage', [
            'silent' => true,
            'unpin' => !$isPinned,
            'peer' => $this->inputPeer($chatId),
            'id' => $messageId,
        ]));
    }
}
