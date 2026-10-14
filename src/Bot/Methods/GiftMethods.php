<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\FormattingError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonEntity;
use LaraGram\Server\Objects\JsonGift;
use LaraGram\Server\Objects\JsonPoll;
use LaraGram\Server\Objects\JsonSticker;
use LaraGram\Server\Support\TdInteger;

/**
 * Gifts: getAvailableGifts, sendGift, giftPremiumSubscription, the received
 * gift lists and the gift management of business accounts, with the
 * StarGiftManager parts they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait GiftMethods
{
    public function processGetAvailableGiftsQuery(Query $query): void
    {
        // GetStarGiftsQuery
        $result = $this->invokeOrFail('payments.getStarGifts', ['hash' => 0]);
        if (($result['_'] ?? '') !== 'payments.starGifts') {
            throw new QueryError(500, 'Receive unexpected response');
        }
        $now = $this->getUnixTime();
        $gifts = [];
        foreach ($result['gifts'] ?? [] as $gift) {
            if (($gift['_'] ?? '') === 'starGift') {
                if ((int) ($gift['availability_total'] ?? 0) > 0 && (int) ($gift['availability_remains'] ?? 0) === 0) {
                    continue;
                }
                if ((int) ($gift['per_user_total'] ?? 0) > 0 && (int) ($gift['per_user_remains'] ?? 0) === 0) {
                    continue;
                }
                if (!empty($gift['require_premium']) || !empty($gift['auction']) || (int) ($gift['locked_until_date'] ?? 0) > $now) {
                    continue;
                }
            }
            $starGift = JsonGift::fromServer($gift, false);
            if ($starGift !== null) {
                $gifts[] = $starGift;
            }
        }

        // Client::TdOnGetGiftsCallback
        $sets = [];
        foreach ($gifts as $gift) {
            $sets = array_merge($sets, self::giftStickerSets($gift));
        }
        $this->loadStickerSetNames($sets);
        $query->answerQuery(['gifts' => array_map(fn (array $gift): array => JsonGift::gift($gift, $this), $gifts)]);
    }

    public function processSendGiftQuery(Query $query): void
    {
        $giftId = TdInteger::toInt64($query->arg('gift_id'));
        $payForUpgrade = self::boolArg($query, 'pay_for_upgrade');
        $text = $this->getFormattedText($query->arg('text'), $query->arg('text_parse_mode'), self::getInputEntities($query, 'text_entities'));
        if ($query->hasArg('chat_id') && $query->arg('chat_id') !== '') {
            $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_READ);
        } else {
            $chatId = self::getUserIdArg($query);
            $this->checkUser($chatId);
        }

        // StarGiftManager::send_gift
        $message = $this->getGiftText($text);
        $invoice = self::tl('inputInvoiceStarGift', [
            'include_upgrade' => $payForUpgrade,
            'peer' => $this->inputPeer($chatId),
            'gift_id' => $giftId,
            'message' => $message,
        ]);
        // GetGiftPaymentFormQuery
        $form = $this->invokeOrFail('payments.getPaymentForm', ['invoice' => $invoice]);
        if (($form['_'] ?? '') !== 'payments.paymentFormStarGift') {
            throw new QueryError(500, 'Unsupported');
        }
        $this->sendStarsForm((int) $form['form_id'], $invoice);
        $query->answerQuery(true);
    }

    public function processGiftPremiumSubscriptionQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $starCount = TdInteger::toInt64($query->arg('star_count'));
        $monthCount = self::getIntegerArg($query, 'month_count', 0, 0, 1000);
        $text = $this->getFormattedText($query->arg('text'), $query->arg('text_parse_mode'), self::getInputEntities($query, 'text_entities'));
        $this->checkUser($userId);

        // gift_premium_with_stars
        if ($starCount <= 0 || $starCount > self::MAX_CURRENCY_AMOUNT) {
            throw new QueryError(400, 'Invalid amount of the currency specified');
        }
        $message = $this->getGiftText($text);
        $invoice = ['_' => 'inputInvoicePremiumGiftStars', 'user_id' => $this->inputUser($userId), 'months' => $monthCount];
        // GetPremiumGiftPaymentFormQuery
        $form = $this->invokeOrFail('payments.getPaymentForm', ['invoice' => $invoice]);
        if (($form['_'] ?? '') !== 'payments.paymentFormStars') {
            throw new QueryError(500, 'Unsupported');
        }
        $prices = $form['invoice']['prices'] ?? [];
        if (count($prices) !== 1 || (int) ($prices[0]['amount'] ?? 0) !== $starCount) {
            throw new QueryError(400, 'Wrong Premium subscription price specified');
        }
        if ($message !== null) {
            $invoice['message'] = $message;
        }
        $this->sendStarsForm((int) $form['form_id'], $invoice);
        $query->answerQuery(true);
    }

    public function processGetUserGiftsQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $this->checkUser($userId);
        $filters = self::receivedGiftFilters($query);
        $filters['exclude_unsaved'] = true;
        $filters['exclude_saved'] = false;
        $this->answerReceivedGifts($query, null, $userId, $filters, false);
    }

    public function processGetChatGiftsQuery(Query $query): void
    {
        $chatId = $this->checkChat($query->arg('chat_id'), self::ACCESS_READ);
        $filters = self::receivedGiftFilters($query);
        $filters['exclude_unsaved'] = self::boolArg($query, 'exclude_unsaved');
        $filters['exclude_saved'] = self::boolArg($query, 'exclude_saved');
        $this->answerReceivedGifts($query, null, $chatId, $filters, false);
    }

    public function processGetBusinessAccountGiftsQuery(Query $query): void
    {
        $connection = $this->checkBusinessConnection($query->arg('business_connection_id'));
        $filters = self::receivedGiftFilters($query);
        $filters['exclude_unsaved'] = self::boolArg($query, 'exclude_unsaved');
        $filters['exclude_saved'] = self::boolArg($query, 'exclude_saved');
        if (self::boolArg($query, 'exclude_limited')) {
            $filters['exclude_upgradable'] = true;
            $filters['exclude_non_upgradable'] = true;
        }
        $this->answerReceivedGifts($query, $connection, $connection['user_id'], $filters, true);
    }

    public function processConvertGiftToStarsQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $ownedGiftId = $query->arg('owned_gift_id');
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // StarGiftManager::convert_gift
        $inputGift = $this->getInputSavedStarGift($ownedGiftId);
        if ($inputGift === null) {
            throw new QueryError(400, 'Invalid gift identifier specified');
        }
        $this->invokeBusiness($connection, 'payments.convertStarGift', ['stargift' => $inputGift]);
        $query->answerQuery(true);
    }

    public function processUpgradeGiftQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $ownedGiftId = $query->arg('owned_gift_id');
        $keepOriginalDetails = self::boolArg($query, 'keep_original_details');
        $starCount = self::getIntegerArg($query, 'star_count', 0, 0, 1000000);
        $connection = $this->checkBusinessConnection($businessConnectionId);

        // StarGiftManager::upgrade_gift
        $inputGift = $this->getInputSavedStarGift($ownedGiftId);
        if ($inputGift === null) {
            throw new QueryError(400, 'Invalid gift identifier specified');
        }
        if ($starCount !== 0) {
            $invoice = self::tl('inputInvoiceStarGiftUpgrade', ['keep_original_details' => $keepOriginalDetails, 'stargift' => $inputGift]);
            // GetGiftUpgradePaymentFormQuery
            $form = $this->invokeBusiness($connection, 'payments.getPaymentForm', ['invoice' => $invoice]);
            if (($form['_'] ?? '') !== 'payments.paymentFormStarGift') {
                throw new QueryError(500, 'Unsupported');
            }
            $prices = $form['invoice']['prices'] ?? [];
            if (count($prices) !== 1 || (int) ($prices[0]['amount'] ?? 0) > $starCount) {
                throw new QueryError(400, 'Wrong upgrade price specified');
            }
            $this->sendStarsForm((int) $form['form_id'], $invoice, $connection);
        } else {
            $this->invokeBusiness($connection, 'payments.upgradeStarGift', self::tl('payments.upgradeStarGift', [
                'keep_original_details' => $keepOriginalDetails,
                'stargift' => $inputGift,
            ]));
        }
        $query->answerQuery(true);
    }

    public function processTransferGiftQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $ownedGiftId = $query->arg('owned_gift_id');
        $chatIdStr = $query->arg('new_owner_chat_id');
        $starCount = self::getIntegerArg($query, 'star_count', 0, 0, 1000000);
        [$connection, $chatId] = $this->checkBusinessConnectionChatId($businessConnectionId, $chatIdStr);

        // get_message_sender_dialog_id + StarGiftManager::transfer_gift
        $this->checkGiftReceiver($chatId);
        $inputGift = $this->getInputSavedStarGift($ownedGiftId);
        if ($inputGift === null) {
            throw new QueryError(400, 'Invalid gift identifier specified');
        }
        if ($starCount !== 0) {
            $invoice = ['_' => 'inputInvoiceStarGiftTransfer', 'stargift' => $inputGift, 'to_id' => $this->inputPeer($chatId)];
            // GetGiftTransferPaymentFormQuery
            $form = $this->invokeBusiness($connection, 'payments.getPaymentForm', ['invoice' => $invoice]);
            if (($form['_'] ?? '') !== 'payments.paymentFormStarGift') {
                throw new QueryError(500, 'Unsupported');
            }
            $prices = $form['invoice']['prices'] ?? [];
            if (count($prices) !== 1 || (int) ($prices[0]['amount'] ?? 0) !== $starCount) {
                throw new QueryError(400, 'Wrong transfer price specified');
            }
            $this->sendStarsForm((int) $form['form_id'], $invoice, $connection);
        } else {
            $this->invokeBusiness($connection, 'payments.transferStarGift', ['stargift' => $inputGift, 'to_id' => $this->inputPeer($chatId)]);
        }
        $query->answerQuery(true);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Helpers
    // ═════════════════════════════════════════════════════════════════

    /**
     * payments.sendStarsForm of a gift payment (SendGiftQuery, UpgradeGiftQuery, …).
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed>|null $connection
     */
    private function sendStarsForm(int $formId, array $invoice, ?array $connection = null): void
    {
        $params = ['form_id' => $formId, 'invoice' => $invoice];
        $result = $connection !== null
            ? $this->invokeBusiness($connection, 'payments.sendStarsForm', $params)
            : $this->invokeOrFail('payments.sendStarsForm', $params);
        if (($result['_'] ?? '') !== 'payments.paymentResult') {
            throw new QueryError(500, 'Receive invalid response');
        }
    }

    /**
     * get_formatted_text(is_bot = false, allow_empty = true, skip_media_timestamps = true)
     * + remove_unallowed_quote_entities + get_input_text_with_entities of a gift text.
     *
     * @return array<string, mixed>|null TL textWithEntities
     */
    private function getGiftText(FormattedText $text): ?array
    {
        try {
            [$value, $entities] = TextFormatting::fixFormattedText($text->text, $text->entities, true, false, false, false, true, false);
        } catch (FormattingError $e) {
            throw new QueryError(400, $e->getMessage());
        }
        $message = (new FormattedText($value, $entities))->removeUnallowedQuoteEntities();
        if ($message->text === '') {
            return null;
        }

        return ['_' => 'textWithEntities', 'text' => $message->text, 'entities' => $message->inputEntities(fn (int $userId): array => $this->inputUser($userId))];
    }

    /**
     * get_message_sender_dialog_id(check_access = true) of a chat.
     */
    private function checkGiftReceiver(int $chatId): void
    {
        $known = match (ChatId::type($chatId)) {
            ChatId::TYPE_PRIVATE => $this->getUser($chatId) !== null,
            ChatId::TYPE_GROUP => $this->getBasicGroup(ChatId::toBasicGroupId($chatId)) !== null,
            ChatId::TYPE_SUPERGROUP => $this->getChannel(ChatId::toChannelId($chatId)) !== null,
            default => null,
        };
        if ($known === null) {
            throw new QueryError(400, 'Invalid chat identifier specified');
        }
        if (!$known) {
            throw new QueryError(400, 'Unknown chat identifier specified');
        }
    }

    /**
     * StarGiftId(received_gift_id) + StarGiftId::get_input_saved_star_gift.
     *
     * @return array<string, mixed>|null
     */
    private function getInputSavedStarGift(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        if ($id[0] === '@') {
            return ['_' => 'inputSavedStarGiftSlug', 'slug' => substr($id, 1)];
        }
        $underscore = strpos($id, '_');
        if ($underscore === false) {
            $messageId = TdInteger::toInt32($id);
            if ((string) $messageId !== $id) {
                return null;
            }

            return ['_' => 'inputSavedStarGiftUser', 'msg_id' => $messageId];
        }
        $chatId = TdInteger::toInt64(substr($id, 0, $underscore));
        $savedId = TdInteger::toInt64(substr($id, $underscore + 1));
        if ($chatId.'_'.$savedId !== $id) {
            return null;
        }

        return ['_' => 'inputSavedStarGiftChat', 'peer' => $this->inputPeer($chatId), 'saved_id' => $savedId];
    }

    /**
     * @return array<string, bool|string|int>
     */
    private static function receivedGiftFilters(Query $query): array
    {
        return [
            'exclude_unlimited' => self::boolArg($query, 'exclude_unlimited'),
            'exclude_upgradable' => self::boolArg($query, 'exclude_limited_upgradable'),
            'exclude_non_upgradable' => self::boolArg($query, 'exclude_limited_non_upgradable'),
            'exclude_unique' => self::boolArg($query, 'exclude_unique'),
            'exclude_hosted' => self::boolArg($query, 'exclude_from_blockchain'),
            'sort_by_price' => self::boolArg($query, 'sort_by_price'),
            'offset' => $query->arg('offset'),
            'limit' => self::getIntegerArg($query, 'limit', 100, 1, 100),
        ];
    }

    /**
     * GetSavedStarGiftsQuery + Client::TdOnGetReceivedGiftsCallback.
     *
     * @param array<string, mixed>|null $connection
     * @param array<string, mixed> $filters
     */
    private function answerReceivedGifts(Query $query, ?array $connection, int $chatId, array $filters, bool $canBeManaged): void
    {
        $offset = TextFormatting::cleanInputString((string) $filters['offset']);
        if ($offset === null) {
            throw new QueryError(400, 'Strings must be encoded in UTF-8');
        }
        $params = self::tl('payments.getSavedStarGifts', [
            'exclude_unsaved' => $filters['exclude_unsaved'],
            'exclude_saved' => $filters['exclude_saved'],
            'exclude_unlimited' => $filters['exclude_unlimited'],
            'exclude_unique' => $filters['exclude_unique'],
            'sort_by_value' => $filters['sort_by_price'],
            'exclude_upgradable' => $filters['exclude_upgradable'],
            'exclude_unupgradable' => $filters['exclude_non_upgradable'],
            'exclude_hosted' => $filters['exclude_hosted'],
            'peer' => $this->inputPeer($chatId),
            'offset' => $offset,
            'limit' => $filters['limit'],
        ]);
        $result = $connection !== null
            ? $this->invokeBusiness($connection, 'payments.getSavedStarGifts', $params)
            : $this->invokeOrFail('payments.getSavedStarGifts', self::stripConstructor($params));

        $gifts = [];
        foreach ($result['gifts'] ?? [] as $savedGift) {
            $gift = $this->receivedGift($savedGift, $chatId);
            if ($gift !== null) {
                $gifts[] = $gift;
            }
        }
        $totalCount = max((int) ($result['count'] ?? 0), count($result['gifts'] ?? []));

        $sets = [];
        foreach ($gifts as $gift) {
            $sets = array_merge($sets, self::giftStickerSets($gift['gift']));
        }
        $this->loadStickerSetNames($sets);

        $object = ['total_count' => $totalCount, 'gifts' => array_map(fn (array $gift): array => $this->receivedGiftObject($gift, $canBeManaged), $gifts)];
        $nextOffset = (string) ($result['next_offset'] ?? '');
        if ($nextOffset !== '') {
            $object['next_offset'] = $nextOffset;
        }
        $query->answerQuery($object);
    }

    /**
     * UserStarGift(td, savedStarGift, dialog_id); null when invalid.
     *
     * @param array<string, mixed> $saved TL savedStarGift
     * @return array<string, mixed>|null
     */
    private function receivedGift(array $saved, int $chatId): ?array
    {
        $gift = JsonGift::fromServer($saved['gift'] ?? [], true);
        $senderId = isset($saved['from_id']) ? ChatId::fromPeer($saved['from_id']) : 0;
        $isNameHidden = !empty($saved['name_hidden']);
        if ($gift === null || (!$isNameHidden && $senderId === 0)) {
            return null;
        }
        $id = '';
        if (ChatId::type($chatId) === ChatId::TYPE_PRIVATE) {
            if ((int) ($saved['msg_id'] ?? 0) > 0) {
                $id = (string) (int) $saved['msg_id'];
            }
        } elseif ((int) ($saved['saved_id'] ?? 0) !== 0 && ChatId::type($chatId) === ChatId::TYPE_SUPERGROUP) {
            $id = $chatId.'_'.(int) $saved['saved_id'];
        }
        $starCount = static fn (string $field): int => max(0, min((int) ($saved[$field] ?? 0), 1 << 51));
        $upgradeStarCount = $starCount('upgrade_stars');

        return [
            'id' => $id,
            'sender_id' => $senderId,
            'text' => FormattedText::fromTextWithEntities($saved['message'] ?? null, false, false),
            'gift_num' => max(0, (int) ($saved['gift_num'] ?? 0)),
            'is_private' => $isNameHidden,
            'is_saved' => empty($saved['unsaved']),
            'can_be_upgraded' => !empty($saved['can_upgrade']),
            'can_be_transferred' => array_key_exists('transfer_stars', $saved),
            'was_refunded' => !empty($saved['refunded']),
            'date' => (int) ($saved['date'] ?? 0),
            'gift' => $gift,
            'sell_star_count' => $starCount('convert_stars'),
            'prepaid_upgrade_star_count' => $upgradeStarCount,
            'is_upgrade_separate' => $upgradeStarCount > 0 && !empty($saved['upgrade_separate']),
            'transfer_star_count' => $starCount('transfer_stars'),
            'next_transfer_date' => max(0, (int) ($saved['can_transfer_at'] ?? 0)),
        ];
    }

    /**
     * Client::JsonReceivedGift.
     *
     * @param array<string, mixed> $gift
     * @return array<string, mixed>
     */
    private function receivedGiftObject(array $gift, bool $canBeManaged): array
    {
        $object = [];
        if ($gift['id'] !== '' && $canBeManaged) {
            $object['owned_gift_id'] = $gift['id'];
        }
        if ($gift['gift']['is_unique']) {
            $object['type'] = 'unique';
            $object['gift'] = JsonGift::uniqueGift($gift['gift'], $this);
        } else {
            $object['type'] = 'regular';
            $object['gift'] = JsonGift::gift($gift['gift'], $this);
        }
        if ($gift['sender_id'] !== 0) {
            $sender = ChatId::type($gift['sender_id']) === ChatId::TYPE_PRIVATE
                ? ['_' => 'messageSenderUser', 'user_id' => $gift['sender_id']]
                : ['_' => 'messageSenderChat', 'chat_id' => $gift['sender_id']];
            JsonPoll::storeMessageSender($object, $sender, 'sender_user', 'sender_chat', 0, $this);
        }
        $object['send_date'] = $gift['date'];
        // get_formatted_text_object(skip_bot_commands = true, max_media_timestamp = -1)
        $text = $gift['text'];
        $entities = array_values(array_filter($text->entities, static fn (array $entity): bool => $entity['type'] !== 'BotCommand'));
        if ($text->text !== '') {
            $object['text'] = $text->text;
        }
        $entities = JsonEntity::vector($entities, $this);
        if ($entities !== []) {
            $object['entities'] = $entities;
        }
        if ($gift['is_private']) {
            $object['is_private'] = true;
        }
        if ($gift['is_saved']) {
            $object['is_saved'] = true;
        }
        if ($gift['can_be_upgraded']) {
            $object['can_be_upgraded'] = true;
        }
        if ($gift['can_be_transferred']) {
            $object['can_be_transferred'] = true;
        }
        if ($gift['was_refunded']) {
            $object['was_refunded'] = true;
        }
        if ($gift['sell_star_count'] > 0) {
            $object['convert_star_count'] = $gift['sell_star_count'];
        }
        if ($gift['prepaid_upgrade_star_count'] > 0) {
            $object['prepaid_upgrade_star_count'] = $gift['prepaid_upgrade_star_count'];
        }
        if ($gift['transfer_star_count'] > 0) {
            $object['transfer_star_count'] = $gift['transfer_star_count'];
        }
        if ($gift['next_transfer_date'] > 0) {
            $object['next_transfer_date'] = $gift['next_transfer_date'];
        }
        if ($gift['is_upgrade_separate']) {
            $object['is_upgrade_separate'] = true;
        }
        if ($gift['gift_num'] > 0) {
            $object['unique_gift_number'] = $gift['gift_num'];
        }

        return $object;
    }

    /**
     * Client::get_gift_sticker_set_ids / get_upgraded_gift_sticker_set_ids.
     *
     * @param array<string, mixed> $gift
     * @return list<array<string, mixed>>
     */
    private static function giftStickerSets(array $gift): array
    {
        $stickers = $gift['is_unique'] ? [$gift['model']['sticker'], $gift['pattern']['sticker']] : [$gift['sticker']];
        $sets = [];
        foreach ($stickers as $sticker) {
            $set = JsonSticker::inputStickerSet($sticker);
            if ($set !== null) {
                $sets[] = $set;
            }
        }

        return $sets;
    }
}
