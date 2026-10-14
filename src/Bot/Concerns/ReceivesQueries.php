<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\JsonRawString;
use LaraGram\Server\Http\JsonWriter;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Messages\MessageInfo;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonMedia;
use LaraGram\Server\Objects\JsonMessage;
use LaraGram\Server\Objects\JsonServiceMessage;
use LaraGram\Server\Objects\JsonUser;
use LaraGram\Server\Td\InlineMessageId;
use LaraGram\Server\Updates\UpdateType;

/**
 * Queries from users: callback queries, inline queries and chosen inline
 * results (CallbackQueriesManager / InlineQueriesManager on_new_* and the
 * Client::add_new_* handlers).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ReceivesQueries
{
    /** @var array<int, array{queue: list<array<string, mixed>>, active: bool}> user id => callback queries */
    private array $newCallbackQueryQueues = [];

    /**
     * @param array<string, mixed> $update TL update
     */
    private function onQueryUpdate(array $update): bool
    {
        switch ($update['_']) {
            case 'updateBotCallbackQuery':
                $this->onNewCallbackQuery($update);

                return true;
            case 'updateBusinessBotCallbackQuery':
                // CallbackQueriesManager::on_new_business_callback_query + Client::add_new_business_callback_query
                $userId = (int) ($update['user_id'] ?? 0);
                $message = $userId > 0 ? $this->createBusinessMessage((string) ($update['connection_id'] ?? ''), $update['message'] ?? [],
                    $update['reply_to_message'] ?? null) : null;
                if ($message !== null) {
                    $this->queueCallbackQuery($userId, $update, $message->chatId, 0, $message,
                        ['_' => 'callbackQueryPayloadData', 'data' => (string) ($update['data'] ?? '')]);
                }

                return true;
            case 'updateEphemeralBotCallbackQuery':
                // CallbackQueriesManager::on_new_ephemeral_callback_query
                $userId = (int) ($update['user_id'] ?? 0);
                $payload = self::getCallbackQueryPayload($update);
                $message = $userId > 0 && $payload !== null ? $this->convertEphemeralMessage($update['message'] ?? []) : null;
                if ($message !== null) {
                    $this->queueCallbackQuery($userId, $update, $message->chatId, 0, $message, $payload);
                }

                return true;
            case 'updateInlineBotCallbackQuery':
                $this->onNewInlineCallbackQuery($update);

                return true;
            case 'updateBotInlineQuery':
                $this->onNewInlineQuery($update);

                return true;
            case 'updateBotInlineSend':
                $this->onChosenInlineResult($update);

                return true;
            case 'updateBotShippingQuery':
                $this->onNewShippingQuery($update);

                return true;
            case 'updateBotPrecheckoutQuery':
                $this->onNewPreCheckoutQuery($update);

                return true;
            case 'updateUserTyping':
                // DialogActionManager::on_dialog_action + Client::add_update_stopped_message_generation
                $action = $update['action'] ?? [];
                if (($action['_'] ?? '') === 'sendMessageStopDraftAction' && (int) ($action['random_id'] ?? 0) !== 0) {
                    $chatId = (int) $update['user_id'];
                    $object = ['chat' => \LaraGram\Server\Objects\JsonChat::make($chatId, $this)];
                    if ((int) ($update['top_msg_id'] ?? 0) !== 0) {
                        $object['message_thread_id'] = (int) $update['top_msg_id'];
                    }
                    $object['draft_id'] = (string) (int) $action['random_id'];
                    $this->addUpdate(UpdateType::STOP_MESSAGE_DRAFT, JsonWriter::encode($object), 150, $chatId + (15 << 33));
                }

                return true;
            case 'updateBotWebhookJSON':
                // Client::add_new_custom_event
                $this->addUpdate(UpdateType::CUSTOM_EVENT, (string) ($update['data']['data'] ?? ''), 600, 0);

                return true;
            case 'updateBotWebhookJSONQuery':
                // Client::add_new_custom_query
                $timeout = (int) ($update['timeout'] ?? 0);
                $this->addUpdate(UpdateType::CUSTOM_QUERY, (string) ($update['data']['data'] ?? ''), $timeout <= 0 ? 86400 : $timeout, 0);

                return true;
        }

        return false;
    }

    /**
     * CallbackQueriesManager::get_query_payload.
     *
     * @param array<string, mixed> $update
     * @return array<string, mixed>|null
     */
    private static function getCallbackQueryPayload(array $update): ?array
    {
        $data = (string) ($update['data'] ?? '');
        $gameShortName = (string) ($update['game_short_name'] ?? '');
        if (($data !== '') === ($gameShortName !== '')) {
            return null;
        }

        return $data !== '' ? ['_' => 'callbackQueryPayloadData', 'data' => $data] : ['_' => 'callbackQueryPayloadGame', 'game_short_name' => $gameShortName];
    }

    /**
     * CallbackQueriesManager::on_new_callback_query → Client::add_new_callback_query.
     *
     * @param array<string, mixed> $update
     */
    private function onNewCallbackQuery(array $update): void
    {
        $chatId = ChatId::fromPeer($update['peer'] ?? null);
        $userId = (int) ($update['user_id'] ?? 0);
        $messageId = (int) ($update['msg_id'] ?? 0);
        if ($chatId === 0 || $userId <= 0 || $messageId <= 0) {
            return;
        }
        $payload = self::getCallbackQueryPayload($update);
        if ($payload === null) {
            return;
        }

        $this->queueCallbackQuery($userId, $update, $chatId, $messageId, null, $payload);
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $payload
     */
    private function queueCallbackQuery(int $userId, array $update, int $chatId, int $messageId, ?MessageInfo $message, array $payload): void
    {
        $this->newCallbackQueryQueues[$userId] ??= ['queue' => [], 'active' => false];
        $this->newCallbackQueryQueues[$userId]['queue'][] = [
            'id' => (int) $update['query_id'],
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'message' => $message,
            'chat_instance' => (int) ($update['chat_instance'] ?? 0),
            'payload' => $payload,
        ];
        $this->processNewCallbackQueryQueue($userId);
    }

    /**
     * Client::process_new_callback_query_queue: queries of a user are delivered
     * in order, after their message and its replied message have been loaded.
     */
    private function processNewCallbackQueryQueue(int $userId): void
    {
        if (!isset($this->newCallbackQueryQueues[$userId]) || $this->newCallbackQueryQueues[$userId]['active']) {
            return;
        }
        $this->newCallbackQueryQueues[$userId]['active'] = true;

        while ($this->newCallbackQueryQueues[$userId]['queue'] !== []) {
            if ($this->loggingOut || $this->closing) {
                break;
            }
            $query = $this->newCallbackQueryQueues[$userId]['queue'][0];
            $chatId = $query['chat_id'];
            $messageId = $query['message_id'];

            $message = $query['message'] ?? $this->getMessage($chatId, $messageId, false);
            if ($query['message'] !== null) {
                // a business or ephemeral message comes with the query
                $this->loadStickerSetNames($this->messageStickerSets($message));
            } elseif ($message === null) {
                // the callback message can be already deleted in the bot outbox
                $this->loadCallbackQueryMessage($chatId, $messageId, $query['id']);
                $message = $this->getMessage($chatId, $messageId, true);
            }
            if ($query['message'] === null && $message !== null) {
                $replyToMessageId = $this->getSameChatReplyToMessageId($message, false);
                if ($replyToMessageId > 0 && $this->getMessage($chatId, $replyToMessageId, false) === null) {
                    $this->loadRepliedMessage($message);
                }
                $message = $this->getMessage($chatId, $messageId, true);
            }
            if ($query['message'] === null && $message !== null) {
                $replyToMessageId = $this->getSameChatReplyToMessageId($message, false);
                $this->loadStickerSetNames(array_merge(
                    $this->messageStickerSets($message),
                    $this->messageStickerSets($this->getMessage($chatId, $replyToMessageId, true)),
                ));
            }

            $object = [
                'id' => (string) $query['id'],
                'from' => JsonUser::make($userId, $this),
                'message' => $message !== null
                    ? JsonMessage::make($message, true, $this)
                    : JsonServiceMessage::inaccessibleMessage($chatId, $messageId, $this),
                'chat_instance' => (string) $query['chat_instance'],
            ];
            self::storeCallbackQueryPayload($object, $query['payload']);
            $this->addUpdate(UpdateType::CALLBACK_QUERY, JsonWriter::encode($object), 150, $userId + (3 << 33));

            array_shift($this->newCallbackQueryQueues[$userId]['queue']);
        }

        unset($this->newCallbackQueryQueues[$userId]);
    }

    /**
     * td_api::getCallbackQueryMessage: the message of a callback query from the server.
     */
    private function loadCallbackQueryMessage(int $chatId, int $messageId, int $queryId): void
    {
        $input = [['_' => 'inputMessageCallbackQuery', 'id' => $messageId, 'query_id' => $queryId]];

        try {
            if (ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP) {
                $result = $this->invoke('channels.getMessages', [
                    'channel' => $this->inputChannel(ChatId::toChannelId($chatId)),
                    'id' => $input,
                ]);
            } else {
                $result = $this->invoke('messages.getMessages', ['id' => $input]);
            }
        } catch (\Throwable $e) {
            $this->parameters->logger->info("Can't find callback query message {$messageId} in chat {$chatId}: {$e->getMessage()}");

            return;
        }

        foreach ($result['messages'] ?? [] as $message) {
            $info = (new MessageConverter($this))->convert($message);
            if ($info !== null && $info->chatId === $chatId && $info->id === $messageId) {
                $this->addMessage($info);
            }
        }
    }

    /**
     * CallbackQueriesManager::on_new_inline_callback_query → Client::add_new_inline_callback_query.
     *
     * @param array<string, mixed> $update
     */
    private function onNewInlineCallbackQuery(array $update): void
    {
        $userId = (int) ($update['user_id'] ?? 0);
        if ($userId <= 0 || !isset($update['msg_id'])) {
            return;
        }
        $payload = self::getCallbackQueryPayload($update);
        if ($payload === null) {
            return;
        }

        $object = [
            'id' => (string) $update['query_id'],
            'from' => JsonUser::make($userId, $this),
            'inline_message_id' => InlineMessageId::encode($update['msg_id']),
            'chat_instance' => (string) ($update['chat_instance'] ?? 0),
        ];
        self::storeCallbackQueryPayload($object, $payload);
        $this->addUpdate(UpdateType::CALLBACK_QUERY, JsonWriter::encode($object), 150, $userId + (3 << 33));
    }

    /**
     * Client::json_store_callback_query_payload.
     *
     * @param array<string, mixed> $object
     * @param array<string, mixed> $payload
     */
    private static function storeCallbackQueryPayload(array &$object, array $payload): void
    {
        if ($payload['_'] === 'callbackQueryPayloadData') {
            // non-UTF-8 data is written as it is (td::JsonRawString)
            $object['data'] = mb_check_encoding($payload['data'], 'UTF-8') ? $payload['data'] : new JsonRawString($payload['data']);
        } else {
            $object['game_short_name'] = $payload['game_short_name'];
        }
    }

    /**
     * InlineQueriesManager::on_new_inline_query → Client::add_new_inline_query.
     *
     * @param array<string, mixed> $update
     */
    private function onNewInlineQuery(array $update): void
    {
        $userId = (int) ($update['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $object = ['id' => (string) $update['query_id'], 'from' => JsonUser::make($userId, $this)];
        $location = isset($update['geo']) ? MessageConverter::location($update['geo']) : null;
        if ($location !== null) {
            $object['location'] = JsonMedia::location($location);
        }
        $chatType = match ($update['peer_type']['_'] ?? '') {
            'inlineQueryPeerTypeSameBotPM' => 'sender',
            'inlineQueryPeerTypeBotPM', 'inlineQueryPeerTypePM' => 'private',
            'inlineQueryPeerTypeChat' => 'group',
            'inlineQueryPeerTypeMegagroup' => 'supergroup',
            'inlineQueryPeerTypeBroadcast' => 'channel',
            default => '',
        };
        if ($chatType !== '') {
            $object['chat_type'] = $chatType;
        }
        $object['query'] = (string) ($update['query'] ?? '');
        $object['offset'] = (string) ($update['offset'] ?? '');

        $this->addUpdate(UpdateType::INLINE_QUERY, JsonWriter::encode($object), 30, $userId + (1 << 33));
    }

    /**
     * InlineQueriesManager::on_chosen_result → Client::add_new_chosen_inline_result.
     *
     * @param array<string, mixed> $update
     */
    private function onChosenInlineResult(array $update): void
    {
        $userId = (int) ($update['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $object = ['from' => JsonUser::make($userId, $this)];
        $location = isset($update['geo']) ? MessageConverter::location($update['geo']) : null;
        if ($location !== null) {
            $object['location'] = JsonMedia::location($location);
        }
        if (isset($update['msg_id'])) {
            $object['inline_message_id'] = InlineMessageId::encode($update['msg_id']);
        }
        $object['query'] = (string) ($update['query'] ?? '');
        $object['result_id'] = (string) ($update['id'] ?? '');

        $this->addUpdate(UpdateType::CHOSEN_INLINE_RESULT, JsonWriter::encode($object), 600, $userId + (2 << 33));
    }

    /**
     * UpdatesManager::on_update(updateBotShippingQuery) → Client::add_new_shipping_query.
     *
     * @param array<string, mixed> $update
     */
    private function onNewShippingQuery(array $update): void
    {
        $userId = (int) ($update['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        $payload = (string) ($update['payload'] ?? '');
        $object = [
            'id' => (string) $update['query_id'],
            'from' => JsonUser::make($userId, $this),
            'invoice_payload' => mb_check_encoding($payload, 'UTF-8') ? $payload : new JsonRawString($payload),
            'shipping_address' => self::addressObject($update['shipping_address'] ?? []),
        ];
        $this->addUpdate(UpdateType::SHIPPING_QUERY, JsonWriter::encode($object), 150, $userId + (4 << 33));
    }

    /**
     * UpdatesManager::on_update(updateBotPrecheckoutQuery) → Client::add_new_pre_checkout_query.
     *
     * @param array<string, mixed> $update
     */
    private function onNewPreCheckoutQuery(array $update): void
    {
        $userId = (int) ($update['user_id'] ?? 0);
        $totalAmount = (int) ($update['total_amount'] ?? 0);
        if ($userId <= 0 || $totalAmount <= 0 || $totalAmount > 999999999999) {
            return;
        }
        $payload = (string) ($update['payload'] ?? '');
        $object = [
            'id' => (string) $update['query_id'],
            'from' => JsonUser::make($userId, $this),
            'currency' => (string) $update['currency'],
            'total_amount' => $totalAmount,
            'invoice_payload' => mb_check_encoding($payload, 'UTF-8') ? $payload : new JsonRawString($payload),
        ];
        if ((string) ($update['shipping_option_id'] ?? '') !== '') {
            $object['shipping_option_id'] = (string) $update['shipping_option_id'];
        }
        $info = $update['info'] ?? null;
        if ($info !== null && (count($info) > 1)) {
            // get_order_info: an info without fields is no info
            $order = [];
            if ((string) ($info['name'] ?? '') !== '') {
                $order['name'] = (string) $info['name'];
            }
            if ((string) ($info['phone'] ?? '') !== '') {
                $order['phone_number'] = (string) $info['phone'];
            }
            if ((string) ($info['email'] ?? '') !== '') {
                $order['email'] = (string) $info['email'];
            }
            if (isset($info['shipping_address'])) {
                $order['shipping_address'] = self::addressObject($info['shipping_address']);
            }
            $object['order_info'] = $order === [] ? new \stdClass() : $order;
        }
        $this->addUpdate(UpdateType::PRE_CHECKOUT_QUERY, JsonWriter::encode($object), 150, $userId + (4 << 33));
    }

    /**
     * Client::JsonAddress of a TL postAddress.
     *
     * @param array<string, mixed> $address
     * @return array<string, string>
     */
    private static function addressObject(array $address): array
    {
        return [
            'country_code' => (string) ($address['country_iso2'] ?? ''),
            'state' => (string) ($address['state'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'street_line1' => (string) ($address['street_line1'] ?? ''),
            'street_line2' => (string) ($address['street_line2'] ?? ''),
            'post_code' => (string) ($address['post_code'] ?? ''),
        ];
    }

    public function processSendCustomRequestQuery(Query $query): void
    {
        $method = self::getRequiredStringArg($query, 'method');
        $parameters = $query->arg('parameters');
        $method = self::cleanInputStringOrFail($method);
        $parameters = self::cleanInputStringOrFail($parameters);

        try {
            $result = $this->invoke('bots.sendCustomRequest', ['custom_method' => $method, 'params' => ['_' => 'dataJSON', 'data' => $parameters]]);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
        $query->answerJson((string) ($result['data'] ?? ''));
    }

    public function processAnswerCustomQueryQuery(Query $query): void
    {
        $customQueryId = self::toInteger64($query->arg('custom_query_id'));
        $data = self::cleanInputStringOrFail($query->arg('data'));

        try {
            $this->invoke('bots.answerWebhookJSONQuery', ['query_id' => $customQueryId, 'data' => ['_' => 'dataJSON', 'data' => $data]]);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
        $query->answerQuery(true);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Methods
    // ═════════════════════════════════════════════════════════════════

    public function processAnswerCallbackQueryQuery(Query $query): void
    {
        $callbackQueryId = self::toInteger64($query->arg('callback_query_id'));
        $text = $query->arg('text');
        $showAlert = self::boolArg($query, 'show_alert');
        $url = $query->arg('url');
        $cacheTime = self::getIntegerArg($query, 'cache_time', 0, 0, 24 * 30 * 60 * 60);

        $text = self::cleanInputStringOrFail($text);
        $url = self::cleanInputStringOrFail($url);

        try {
            $this->invoke('messages.setBotCallbackAnswer', self::untl(self::tl('messages.setBotCallbackAnswer', [
                'alert' => $showAlert,
                'query_id' => $callbackQueryId,
                'message' => $text !== '' ? $text : null,
                'url' => $url !== '' ? $url : null,
                'cache_time' => $cacheTime,
            ])));
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }

        $query->answerQuery(true);
    }

    /**
     * CLEAN_INPUT_STRING of a request field.
     */
    protected static function cleanInputStringOrFail(string $value): string
    {
        $clean = TextFormatting::cleanInputString($value);
        if ($clean === null) {
            throw new QueryError(400, 'Strings must be encoded in UTF-8');
        }

        return $clean;
    }

    /**
     * td::to_integer<int64>: leading digits, wrapping like the C++ conversion.
     */
    protected static function toInteger64(string $value): int
    {
        return \LaraGram\Server\Support\TdInteger::toInt64($value);
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    protected static function untl(array $object): array
    {
        unset($object['_']);

        return $object;
    }
}
