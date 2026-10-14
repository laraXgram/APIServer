<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Http\JsonRawString;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MessageConverter;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonChat;
use LaraGram\Server\Objects\JsonGift;
use LaraGram\Server\Objects\JsonMessage;
use LaraGram\Server\Objects\JsonUser;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\TdInteger;

/**
 * answerShippingQuery, answerPreCheckoutQuery and the Telegram Stars methods,
 * with the TDLib parts they rely on (Payments, StarManager).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait StarMethods
{
    public function processAnswerShippingQueryQuery(Query $query): void
    {
        $shippingQueryId = TdInteger::toInt64($query->arg('shipping_query_id'));
        $ok = self::boolArg($query, 'ok');
        $options = [];
        $errorMessage = '';
        if ($ok) {
            $options = $this->getShippingOptions($query);
        } else {
            $errorMessage = self::getRequiredStringArg($query, 'error_message');
        }

        $errorMessage = self::cleanInputStringOrFail($errorMessage);
        // answer_shipping_query
        $inputOptions = [];
        foreach ($options as $option) {
            $id = TextFormatting::cleanInputString($option['id']);
            if ($id === null) {
                throw new QueryError(400, 'Shipping option identifier must be encoded in UTF-8');
            }
            $title = TextFormatting::cleanInputString($option['title']);
            if ($title === null) {
                throw new QueryError(400, 'Shipping option title must be encoded in UTF-8');
            }
            $inputOptions[] = ['_' => 'shippingOption', 'id' => $id, 'title' => $title, 'prices' => $this->getInputLabeledPrices($option['prices'])];
        }
        if ($inputOptions === []) {
            throw new QueryError(400, 'There must be at least one shipping option');
        }

        $this->invokeOrFail('messages.setBotShippingResults', self::untl(self::tl('messages.setBotShippingResults', [
            'query_id' => $shippingQueryId,
            'error' => $errorMessage !== '' ? $errorMessage : null,
            'shipping_options' => $inputOptions,
        ])));
        $query->answerQuery(true);
    }

    public function processAnswerPreCheckoutQueryQuery(Query $query): void
    {
        $preCheckoutQueryId = TdInteger::toInt64($query->arg('pre_checkout_query_id'));
        $ok = self::boolArg($query, 'ok');
        $errorMessage = $ok ? '' : self::getRequiredStringArg($query, 'error_message');

        $errorMessage = self::cleanInputStringOrFail($errorMessage);
        $this->invokeOrFail('messages.setBotPrecheckoutResults', self::untl(self::tl('messages.setBotPrecheckoutResults', [
            'success' => $errorMessage === '',
            'query_id' => $preCheckoutQueryId,
            'error' => $errorMessage !== '' ? $errorMessage : null,
        ])));
        $query->answerQuery(true);
    }

    /**
     * Client::get_shipping_options.
     *
     * @return list<array{id: string, title: string, prices: list<array{label: string, amount: int}>}>
     */
    private function getShippingOptions(Query $query): array
    {
        $options = self::getRequiredStringArg($query, 'shipping_options');
        $value = self::jsonArg($options, 'Can\'t parse shipping options JSON object');

        return $this->parseArray($value, 'ShippingOption', function (JsonValue $option): array {
            if (!$option->isObject()) {
                throw QueryError::badRequest('ShippingOption must be an Object');
            }
            $object = $option->object();
            $id = $object->getRequiredStringField('id');
            if ($id === '') {
                throw QueryError::badRequest('ShippingOption identifier must be non-empty');
            }
            $title = $object->getRequiredStringField('title');
            if ($title === '') {
                throw QueryError::badRequest('ShippingOption title must be non-empty');
            }
            $pricesJson = $object->extractRequiredField('prices', \LaraGram\Server\Support\TdJson::ARRAY);
            try {
                $prices = $this->getLabeledPriceParts($pricesJson);
            } catch (QueryError $e) {
                throw QueryError::badRequest('Can\'t parse shipping option prices: '.$e->getMessage());
            }

            return ['id' => $id, 'title' => $title, 'prices' => $prices];
        });
    }

    /**
     * LabeledPricePart::get_labeled_price_parts without the total.
     *
     * @param list<array{label: string, amount: int}> $parts
     * @return list<array<string, mixed>>
     */
    private function getInputLabeledPrices(array $parts): array
    {
        if ($parts === []) {
            throw new QueryError(400, 'There must be at least one price part');
        }
        $prices = [];
        $total = 0;
        foreach ($parts as $part) {
            $label = TextFormatting::cleanInputString($part['label']);
            if ($label === null) {
                throw new QueryError(400, 'Price part label must be encoded in UTF-8');
            }
            if (abs($part['amount']) > self::MAX_CURRENCY_AMOUNT) {
                throw new QueryError(400, 'Too big amount of the currency specified');
            }
            $prices[] = ['_' => 'labeledPrice', 'label' => $label, 'amount' => $part['amount']];
            $total += $part['amount'];
        }
        if ($total <= 0) {
            throw new QueryError(400, 'Total price must be positive');
        }
        if ($total > self::MAX_CURRENCY_AMOUNT) {
            throw new QueryError(400, 'Total price is too big');
        }

        return $prices;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Telegram Stars
    // ═════════════════════════════════════════════════════════════════

    public function processGetMyStarBalanceQuery(Query $query): void
    {
        $result = $this->getStarsTransactions('', 0);
        $query->answerQuery(self::starAmountObject(self::starAmount($result['balance'] ?? null)));
    }

    public function processGetStarTransactionsQuery(Query $query): void
    {
        $offset = self::getIntegerArg($query, 'offset', 0, 0);
        $limit = self::getIntegerArg($query, 'limit', 100, 1, 100);
        $result = $this->getStarsTransactions((string) $offset, $limit);

        $transactions = [];
        foreach ($result['history'] ?? [] as $transaction) {
            if (($transaction['amount']['_'] ?? '') !== 'starsAmount') {
                continue;
            }
            $transactions[] = $this->starTransactionObject($transaction);
        }
        $query->answerQuery(['transactions' => $transactions]);
    }

    public function processRefundStarPaymentQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $chargeId = self::getRequiredStringArg($query, 'telegram_payment_charge_id');
        $this->checkUserNoFail($userId);

        $chargeId = self::cleanInputStringOrFail($chargeId);
        $this->invokeOrFail('payments.refundStarsCharge', ['user_id' => $this->inputUser($userId), 'charge_id' => $chargeId]);
        $query->answerQuery(true);
    }

    public function processEditUserStarSubscriptionQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $chargeId = $query->arg('telegram_payment_charge_id');
        $isCanceled = self::boolArg($query, 'is_canceled');
        $this->checkUserNoFail($userId);

        $chargeId = self::cleanInputStringOrFail($chargeId);
        $this->invokeOrFail('payments.botCancelStarsSubscription', self::untl(self::tl('payments.botCancelStarsSubscription', [
            'restore' => !$isCanceled,
            'user_id' => $this->inputUser($userId),
            'charge_id' => $chargeId,
        ])));
        $query->answerQuery(true);
    }

    /**
     * GetStarsTransactionsQuery for the bot itself.
     *
     * @return array<string, mixed> TL payments.starsStatus
     */
    private function getStarsTransactions(string $offset, int $limit): array
    {
        $offset = self::cleanInputStringOrFail($offset);
        $result = $this->invokeOrFail('payments.getStarsTransactions', [
            'ascending' => true,
            'peer' => ['_' => 'inputPeerSelf'],
            'offset' => $offset,
            'limit' => $limit,
        ]);
        if (($result['balance']['_'] ?? '') !== 'starsAmount') {
            throw new QueryError(500, 'Receive invalid response');
        }

        return $result;
    }

    /**
     * StarAmount(telegram_api::starsAmount, allow_negative = true).
     *
     * @param array<string, mixed>|null $amount
     * @return array{0: int, 1: int}
     */
    private static function starAmount(?array $amount): array
    {
        if ($amount === null) {
            return [0, 0];
        }
        $max = 1 << 51;
        $stars = max(-$max, min($max, (int) ($amount['amount'] ?? 0)));
        $nanos = (int) ($amount['nanos'] ?? 0);
        if ($nanos <= -1000000000 || $nanos >= 1000000000) {
            return [$stars, $nanos];
        }
        if ($stars < 0 && $nanos > 0) {
            $stars++;
            $nanos -= 1000000000;
        }
        if ($stars > 0 && $nanos < 0) {
            $stars--;
            $nanos += 1000000000;
        }
        if (($stars < 0 && $nanos > 0) || ($stars > 0 && $nanos < 0)) {
            $nanos = 0;
        }

        return [$stars, $nanos];
    }

    /**
     * Client::JsonStarAmount.
     *
     * @param array{0: int, 1: int} $amount
     * @return array<string, int>
     */
    private static function starAmountObject(array $amount): array
    {
        $object = ['amount' => $amount[0]];
        if ($amount[1] !== 0) {
            $object['nanostar_amount'] = $amount[1];
        }

        return $object;
    }

    /**
     * The star transaction conversion of GetStarsTransactionsQuery for a bot, then Client::JsonStarTransaction.
     *
     * @param array<string, mixed> $transaction TL starsTransaction
     * @return array<string, mixed>
     */
    private function starTransactionObject(array $transaction): array
    {
        $hasProductInfo = (string) ($transaction['title'] ?? '') !== '' || (string) ($transaction['description'] ?? '') !== '' || isset($transaction['photo']);
        $botPayload = (string) ($transaction['bot_payload'] ?? '');

        $affiliate = null;
        $commissionPerMille = 0;
        $permille = (int) ($transaction['starref_commission_permille'] ?? 0);
        if (isset($transaction['starref_peer'])) {
            $referrerId = ChatId::fromPeer($transaction['starref_peer']);
            if ($permille > 0 && $permille < 1000 && $referrerId !== 0 && ($transaction['starref_amount']['_'] ?? '') === 'starsAmount') {
                $affiliate = ['chat_id' => $referrerId, 'commission_per_mille' => $permille, 'amount' => self::starAmount($transaction['starref_amount'])];
            }
        } elseif ($permille > 0 && $permille < 1000) {
            $commissionPerMille = $permille;
        }

        $amount = self::starAmount($transaction['amount']);
        $isRefund = !empty($transaction['refund']);
        $isPositive = $amount[0] > 0 || $amount[1] > 0;
        $isPurchase = $isPositive === $isRefund;

        $type = $this->starTransactionType($transaction, $isPurchase, $isRefund, $hasProductInfo, $botPayload, $affiliate, $commissionPerMille);

        $object = ['id' => (string) $transaction['id'], 'date' => (int) $transaction['date']];
        [$stars, $nanos] = $amount;
        if ($stars > 0 || $nanos > 0) {
            $object['source'] = $type;
        } else {
            $stars = -$stars;
            $nanos = -$nanos;
            $object['receiver'] = $type;
        }
        $object['amount'] = $stars;
        if ($nanos !== 0) {
            $object['nanostar_amount'] = $nanos;
        }

        return $object;
    }

    /**
     * The StarTransactionType of a bot's transaction, as Client::JsonStarTransactionType.
     *
     * @param array<string, mixed> $transaction
     * @param array<string, mixed>|null $affiliate
     * @return array<string, mixed>
     */
    private function starTransactionType(array $transaction, bool $isPurchase, bool $isRefund, bool $hasProductInfo, string $botPayload,
        ?array $affiliate, int $commissionPerMille): array
    {
        $other = ['type' => 'other'];
        $peer = $transaction['peer'] ?? [];

        switch ($peer['_'] ?? '') {
            case 'starsTransactionPeerFragment':
                if (!empty($transaction['gift'])) {
                    return $other;
                }
                $state = null;
                if ((int) ($transaction['transaction_date'] ?? 0) > 0) {
                    $state = ['type' => 'succeeded', 'date' => (int) $transaction['transaction_date'], 'url' => (string) ($transaction['transaction_url'] ?? '')];
                } elseif (!empty($transaction['pending'])) {
                    $state = ['type' => 'pending'];
                } elseif (!empty($transaction['failed'])) {
                    $state = ['type' => 'failed'];
                }
                $object = ['type' => 'fragment'];
                if ($state !== null) {
                    $object['withdrawal_state'] = $state;
                }

                return $object;

            case 'starsTransactionPeerAds':
                return ['type' => 'telegram_ads'];

            case 'starsTransactionPeerAPI':
                return ['type' => 'telegram_api', 'request_count' => (int) ($transaction['floodskip_number'] ?? 0)];

            case 'starsTransactionPeer':
                break;

            default:
                return $other;
        }

        $dialogId = ChatId::fromPeer($peer['peer'] ?? null);
        if ($dialogId === 0) {
            return $other;
        }
        if ($commissionPerMille !== 0) {
            $object = ['type' => 'affiliate_program'];
            if (ChatId::type($dialogId) === ChatId::TYPE_PRIVATE) {
                $object['sponsor_user'] = JsonUser::make($dialogId, $this);
            } else {
                $object['sponsor_chat'] = JsonChat::make($dialogId, $this);
            }
            $object['commission_per_mille'] = $commissionPerMille;

            return $object;
        }
        if (!empty($transaction['paid_messages']) || !empty($transaction['posts_search'])) {
            return $other;
        }

        if (ChatId::type($dialogId) !== ChatId::TYPE_PRIVATE) {
            if (isset($transaction['stargift'])) {
                $gift = JsonGift::fromServer($transaction['stargift'], true);
                if ($gift !== null && $isPurchase && !$gift['is_unique'] && empty($transaction['stargift_prepaid_upgrade'])) {
                    return ['type' => 'chat', 'chat' => JsonChat::make($dialogId, $this), 'gift' => JsonGift::gift($gift, $this)];
                }
            }

            return $other;
        }

        $user = JsonUser::make($dialogId, $this);
        if (!empty($transaction['business_transfer'])) {
            return $isPurchase ? $other : ['transaction_type' => 'business_account_transfer', 'type' => 'user', 'user' => $user];
        }
        if (isset($transaction['stargift'])) {
            $gift = JsonGift::fromServer($transaction['stargift'], true);
            if ($gift !== null && $isPurchase && !$gift['is_unique'] && empty($transaction['stargift_prepaid_upgrade']) && empty($transaction['stargift_auction_bid'])) {
                return ['transaction_type' => 'gift_purchase', 'type' => 'user', 'user' => $user, 'gift' => JsonGift::gift($gift, $this)];
            }

            return $other;
        }
        if ((int) ($transaction['subscription_period'] ?? 0) > 0) {
            if ($isPurchase) {
                return $other;
            }
            $object = ['transaction_type' => 'invoice_payment', 'type' => 'user', 'user' => $user];
            $this->storeInvoicePayload($object, $botPayload);
            $object['subscription_period'] = (int) $transaction['subscription_period'];
            $this->storeAffiliate($object, $affiliate);

            return $object;
        }
        if (!empty($transaction['gift']) || !empty($transaction['reaction'])) {
            return $other;
        }
        if ((int) ($transaction['premium_gift_months'] ?? 0) > 0 && $isPurchase) {
            return ['transaction_type' => 'premium_purchase', 'type' => 'user', 'user' => $user, 'premium_subscription_duration' => (int) $transaction['premium_gift_months']];
        }
        if ($hasProductInfo) {
            if ($isPurchase) {
                return $other;
            }
            $object = ['transaction_type' => 'invoice_payment', 'type' => 'user', 'user' => $user];
            $this->storeInvoicePayload($object, $botPayload);
            $this->storeAffiliate($object, $affiliate);

            return $object;
        }
        if ($isPurchase) {
            return $other;
        }

        // starTransactionTypeBotPaidMediaSale
        $converter = new MessageConverter($this);
        $media = [];
        foreach ($transaction['extended_media'] ?? [] as $item) {
            $paid = $converter->paidMedia(['_' => 'messageExtendedMedia', 'media' => $item]);
            if ($paid !== null) {
                $media[] = JsonMessage::paidMedia($paid);
            }
        }
        $object = ['transaction_type' => 'paid_media_payment', 'type' => 'user', 'user' => $user, 'paid_media' => $media];
        if ($botPayload !== '') {
            $object['paid_media_payload'] = $botPayload;
        }
        $this->storeAffiliate($object, $affiliate);

        return $object;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function storeInvoicePayload(array &$object, string $payload): void
    {
        if ($payload !== '') {
            $object['invoice_payload'] = mb_check_encoding($payload, 'UTF-8') ? $payload : new JsonRawString($payload);
        }
    }

    /**
     * Client::JsonAffiliateInfo.
     *
     * @param array<string, mixed> $object
     * @param array<string, mixed>|null $affiliate
     */
    private function storeAffiliate(array &$object, ?array $affiliate): void
    {
        if ($affiliate === null) {
            return;
        }
        $info = [];
        if (ChatId::type($affiliate['chat_id']) === ChatId::TYPE_PRIVATE) {
            $info['affiliate_user'] = JsonUser::make($affiliate['chat_id'], $this);
        } else {
            $info['affiliate_chat'] = JsonChat::make($affiliate['chat_id'], $this);
        }
        $info['commission_per_mille'] = $affiliate['commission_per_mille'];
        $info += self::starAmountObject($affiliate['amount']);
        $object['affiliate'] = $info;
    }
}
