<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Support\FileName;
use LaraGram\Server\Support\HttpUrl;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\MimeType;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Support\TdJson;
use LaraGram\Server\Support\Utf8;

/**
 * sendInvoice, createInvoiceLink and sendPaidMedia with the TDLib parts they
 * rely on (InputInvoice, LabeledPricePart, MessageExtendedMedia).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait PaymentMethods
{
    /** check_currency_amount */
    private const MAX_CURRENCY_AMOUNT = 999999999999;

    /** MAX_PAID_MEDIA (server-side limit) */
    private const MAX_PAID_MEDIA = 10;

    // ═════════════════════════════════════════════════════════════════
    //  Bot API side
    // ═════════════════════════════════════════════════════════════════

    public function processSendInvoiceQuery(Query $query): void
    {
        $invoice = $this->getInputMessageInvoice($query);
        $this->doSendMessage($invoice, $query);
    }

    public function processCreateInvoiceLinkQuery(Query $query): void
    {
        $invoice = $this->getInputMessageInvoice($query);
        $businessConnectionId = $query->arg('business_connection_id');
        $connection = $businessConnectionId !== '' ? $this->checkBusinessConnection($businessConnectionId) : null;

        // export_invoice
        $inputInvoice = $this->processInputMessageInvoice($invoice, 0);
        if ($connection !== null) {
            $this->checkBusinessConnectionDialog($connection, $this->getMyId());
            $result = $this->invokeBusiness($connection, 'payments.exportInvoice', ['invoice_media' => $this->getInputMediaInvoice($inputInvoice)]);
        } else {
            try {
                $result = $this->invoke('payments.exportInvoice', ['invoice_media' => $this->getInputMediaInvoice($inputInvoice)]);
            } catch (\Throwable $e) {
                throw QueryError::fromThrowable($e);
            }
        }

        $query->answerQuery((string) ($result['url'] ?? ''));
    }

    public function processSendPaidMediaQuery(Query $query): void
    {
        $starCount = self::getIntegerArg($query, 'star_count', 0, 0, 1000000000);
        $paidMedia = $this->getPaidMediaArg($query, 'media');
        $caption = $this->getCaption($query);
        $payload = $query->arg('payload');
        $showCaptionAboveMedia = self::boolArg($query, 'show_caption_above_media');

        $this->doSendMessage([
            '_' => 'inputMessagePaidMedia',
            'star_count' => $starCount,
            'paid_media' => $paidMedia,
            'caption' => $caption,
            'show_caption_above_media' => $showCaptionAboveMedia,
            'payload' => $payload,
        ], $query);
    }

    /**
     * Client::get_input_message_invoice.
     *
     * @return array<string, mixed> td_api inputMessageInvoice
     */
    protected function getInputMessageInvoice(Query $query): array
    {
        $title = self::getRequiredStringArg($query, 'title');
        $description = self::getRequiredStringArg($query, 'description');
        $payload = self::getRequiredStringArg($query, 'payload');
        if (!mb_check_encoding($payload, 'UTF-8')) {
            throw QueryError::badRequest('The payload must be encoded in UTF-8');
        }
        $providerToken = $query->arg('provider_token');
        $providerData = $query->arg('provider_data');
        $startParameter = $query->arg('start_parameter');
        $currency = self::getRequiredStringArg($query, 'currency');

        $prices = self::getRequiredStringArg($query, 'prices');
        $prices = $this->getLabeledPriceParts(self::jsonArg($prices, 'Can\'t parse prices JSON object'));

        $maxTipAmount = 0;
        $suggestedTipAmounts = [];
        $maxTipAmountStr = $query->arg('max_tip_amount');
        if ($maxTipAmountStr !== '') {
            $value = TdInteger::toInt64Safe($maxTipAmountStr);
            if ($value === null) {
                throw QueryError::badRequest('Can\'t parse "max_tip_amount" as Number');
            }
            $maxTipAmount = $value;
        }
        $suggestedTipAmountsStr = $query->arg('suggested_tip_amounts');
        if ($suggestedTipAmountsStr !== '') {
            $value = self::jsonArg($suggestedTipAmountsStr, 'Can\'t parse suggested_tip_amounts JSON object');
            $suggestedTipAmounts = $this->getSuggestedTipAmounts($value);
        }

        $photoUrl = $query->arg('photo_url');
        $photoSize = self::getIntegerArg($query, 'photo_size', 0, 0, 1000000000);
        $photoWidth = self::getIntegerArg($query, 'photo_width', 0, 0, self::MAX_LENGTH);
        $photoHeight = self::getIntegerArg($query, 'photo_height', 0, 0, self::MAX_LENGTH);

        $paidMedia = null;
        if ($query->arg('paid_media') !== '') {
            $paidMedia = $this->getInputPaidMediaArg($query, 'paid_media');
        } elseif ($query->arg('extended_media') !== '') {
            $paidMedia = $this->getInputPaidMediaArg($query, 'extended_media');
        }
        $paidMediaCaption = $this->getFormattedText($query->arg('paid_media_caption'), $query->arg('paid_media_caption_parse_mode'), self::getInputEntities($query, 'paid_media_caption_entities'));
        $subscriptionPeriod = self::getIntegerArg($query, 'subscription_period', 0, 0);

        return [
            '_' => 'inputMessageInvoice',
            'invoice' => [
                'currency' => $currency,
                'price_parts' => $prices,
                'subscription_period' => $subscriptionPeriod,
                'max_tip_amount' => $maxTipAmount,
                'suggested_tip_amounts' => $suggestedTipAmounts,
                'recurring_payment_terms_of_service_url' => '',
                'terms_of_service_url' => '',
                'is_test' => false,
                'need_name' => self::boolArg($query, 'need_name'),
                'need_phone_number' => self::boolArg($query, 'need_phone_number'),
                'need_email_address' => self::boolArg($query, 'need_email'),
                'need_shipping_address' => self::boolArg($query, 'need_shipping_address'),
                'send_phone_number_to_provider' => self::boolArg($query, 'send_phone_number_to_provider'),
                'send_email_address_to_provider' => self::boolArg($query, 'send_email_to_provider'),
                'is_flexible' => self::boolArg($query, 'is_flexible'),
            ],
            'title' => $title,
            'description' => $description,
            'photo_url' => $photoUrl,
            'photo_size' => $photoSize,
            'photo_width' => $photoWidth,
            'photo_height' => $photoHeight,
            'payload' => $payload,
            'provider_token' => $providerToken,
            'provider_data' => $providerData,
            'start_parameter' => $startParameter,
            'paid_media' => $paidMedia,
            'paid_media_caption' => $paidMediaCaption,
        ];
    }

    /**
     * Client::get_labeled_price_parts.
     *
     * @return list<array{label: string, amount: int}>
     */
    protected function getLabeledPriceParts(JsonValue $value): array
    {
        return $this->parseArray($value, 'LabeledPrice', static function (JsonValue $value): array {
            if (!$value->isObject()) {
                throw QueryError::badRequest('LabeledPrice must be an Object');
            }
            $object = $value->object();
            $label = $object->getRequiredStringField('label');
            if ($label === '') {
                throw QueryError::badRequest('LabeledPrice label must be non-empty');
            }

            return ['label' => $label, 'amount' => $object->getRequiredLongField('amount')];
        });
    }

    /**
     * Client::get_suggested_tip_amounts.
     *
     * @return list<int>
     */
    protected function getSuggestedTipAmounts(JsonValue $value): array
    {
        return $this->parseArray($value, 'suggested tip amount', static function (JsonValue $value): int {
            if ($value->type !== TdJson::NUMBER && $value->type !== TdJson::STRING) {
                throw QueryError::badRequest('Suggested tip amount must be of type Number or String');
            }
            $amount = TdInteger::toInt64Safe($value->string());
            if ($amount === null) {
                throw QueryError::badRequest('Can\'t parse suggested tip amount as Number');
            }

            return $amount;
        });
    }

    /**
     * Client::get_input_paid_media (query argument).
     *
     * @return array<string, mixed>
     */
    protected function getInputPaidMediaArg(Query $query, string $field): array
    {
        $media = self::getRequiredStringArg($query, $field);
        $value = TdJson::tryDecode($media);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse input paid media JSON object');
        }

        try {
            return $this->getInputPaidMediaJson($query, $value);
        } catch (JsonError|QueryError $e) {
            throw QueryError::badRequest('Can\'t parse InputPaidMedia: '.$e->getMessage());
        }
    }

    /**
     * Client::get_paid_media.
     *
     * @return list<array<string, mixed>>
     */
    protected function getPaidMediaArg(Query $query, string $field): array
    {
        $media = self::getRequiredStringArg($query, $field);
        $value = TdJson::tryDecode($media);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse paid media JSON object');
        }

        return $this->parseArray($value, 'InputPaidMedia', fn (JsonValue $item): array => $this->getInputPaidMediaJson($query, $item));
    }

    /**
     * Client::get_input_paid_media (JSON value).
     *
     * @return array<string, mixed> td_api inputPaidMedia
     */
    protected function getInputPaidMediaJson(Query $query, JsonValue $inputMedia): array
    {
        if (!$inputMedia->isObject()) {
            throw new JsonError('expected an Object');
        }
        $object = $inputMedia->object();
        $media = $object->getOptionalStringField('media');
        $inputFile = $this->getInputFile($query, '', false, $media);
        if ($inputFile === null) {
            throw new JsonError('media not found');
        }
        $thumbnail = $this->getInputThumbnailFromJson($query, $object, true);

        $width = max(0, min(self::MAX_LENGTH, $object->getOptionalIntField('width')));
        $height = max(0, min(self::MAX_LENGTH, $object->getOptionalIntField('height')));

        $type = $object->getRequiredStringField('type');
        if ($type === 'photo') {
            $mediaType = ['_' => 'inputPaidMediaTypePhoto', 'video' => null];
        } elseif ($type === 'live_photo') {
            $photo = $object->getOptionalStringField('photo');
            $mediaType = ['_' => 'inputPaidMediaTypePhoto', 'video' => $inputFile];
            $inputFile = $this->getInputFile($query, 'photo', false, $photo);
            if ($inputFile === null) {
                throw new JsonError('Photo not found');
            }
        } elseif ($type === 'video') {
            $duration = $object->getOptionalIntField('duration');
            $supportsStreaming = $object->getOptionalBoolField('supports_streaming');
            $cover = $object->getOptionalStringField('cover');
            $startTimestamp = $object->getOptionalIntField('start_timestamp');
            $coverInputFile = $this->getInputFile($query, 'cover', false, $cover);
            $mediaType = [
                '_' => 'inputPaidMediaTypeVideo',
                'cover' => $coverInputFile,
                'start_timestamp' => $startTimestamp,
                'duration' => max(0, min(self::MAX_DURATION, $duration)),
                'supports_streaming' => $supportsStreaming,
            ];
        } else {
            throw new JsonError("type \"{$type}\" is unsupported");
        }

        return ['type' => $mediaType, 'media' => $inputFile, 'thumbnail' => $thumbnail, 'width' => $width, 'height' => $height];
    }

    // ═════════════════════════════════════════════════════════════════
    //  TDLib side
    // ═════════════════════════════════════════════════════════════════

    /**
     * create_input_message_content for inputMessageInvoice.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function getInputInvoiceContent(int $chatId, array $content): array
    {
        $invoice = $this->processInputMessageInvoice($content, $chatId);

        return [
            'type' => 'Invoice',
            'text' => $invoice['extended_media'] !== null ? $invoice['extended_media_caption'] : new FormattedText(),
            'media' => $this->getInputMediaInvoice($invoice),
            'no_webpage' => false,
            'invert_media' => false,
        ];
    }

    /**
     * create_input_message_content for inputMessagePaidMedia.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function getInputPaidMediaContent(int $chatId, array $content): array
    {
        $caption = $this->getOutgoingFormattedText($content['caption'] ?? new FormattedText(), true);
        if (Utf8::length($caption->text) > self::MESSAGE_CAPTION_LENGTH_MAX) {
            throw new QueryError(400, 'Message caption is too long');
        }

        $starCount = $content['star_count'];
        if ($starCount <= 0 || $starCount > 1000000) {
            throw new QueryError(400, 'Invalid media price specified');
        }
        $media = [];
        foreach ($content['paid_media'] as $paidMedia) {
            $extended = $this->getMessageExtendedMedia($paidMedia, $chatId);
            if ($extended === null) {
                throw new QueryError(400, 'Paid media must be non-empty');
            }
            $media[] = ['_' => '@uploadMedia', 'media' => $extended];
        }
        if ($media === [] || count($media) > self::MAX_PAID_MEDIA) {
            throw new QueryError(400, 'Invalid number of paid media specified');
        }

        return [
            'type' => 'PaidMedia',
            'text' => $caption,
            'media' => self::tl('inputMediaPaidMedia', [
                'stars_amount' => $starCount,
                'extended_media' => $media,
                'payload' => $content['payload'] !== '' ? $content['payload'] : null,
            ]),
            'no_webpage' => false,
            'invert_media' => $content['show_caption_above_media'],
        ];
    }

    /**
     * MessageExtendedMedia::get_message_extended_media + get_input_media.
     *
     * @param array<string, mixed>|null $paidMedia td_api inputPaidMedia
     * @return array<string, mixed>|null TL InputMedia
     */
    private function getMessageExtendedMedia(?array $paidMedia, int $ownerChatId): ?array
    {
        if ($paidMedia === null) {
            return null;
        }
        if ($ownerChatId === 0) {
            throw new QueryError(400, 'Extended media can\'t be added to the invoice');
        }

        if ($paidMedia['type']['_'] === 'inputPaidMediaTypePhoto') {
            $content = [
                '_' => 'inputMessagePhoto',
                'photo' => ['photo' => $paidMedia['media'], 'video' => $paidMedia['type']['video']],
                'caption' => null, 'show_caption_above_media' => false, 'has_spoiler' => false,
            ];
        } else {
            $type = $paidMedia['type'];
            $content = [
                '_' => 'inputMessageVideo',
                'video' => [
                    'video' => $paidMedia['media'], 'thumbnail' => $paidMedia['thumbnail'], 'cover' => $type['cover'],
                    'start_timestamp' => max(0, $type['start_timestamp']), 'duration' => $type['duration'],
                    'width' => $paidMedia['width'], 'height' => $paidMedia['height'],
                    'supports_streaming' => $type['supports_streaming'],
                ],
                'caption' => null, 'show_caption_above_media' => false, 'has_spoiler' => false,
            ];
        }

        return $this->getInputMediaContent($ownerChatId, $content)['media'];
    }

    /**
     * InputInvoice::process_input_message_invoice.
     *
     * @param array<string, mixed> $content td_api inputMessageInvoice
     * @return array<string, mixed>
     */
    protected function processInputMessageInvoice(array $content, int $ownerChatId): array
    {
        $invoice = $content['invoice'] ?? null;
        if ($invoice === null) {
            throw new QueryError(400, 'Invoice must be non-empty');
        }

        $clean = static function (string $value, string $error): string {
            $result = TextFormatting::cleanInputString($value);
            if ($result === null) {
                throw new QueryError(400, $error);
            }

            return $result;
        };
        $title = $clean($content['title'], 'Invoice title must be encoded in UTF-8');
        $description = $clean($content['description'], 'Invoice description must be encoded in UTF-8');
        $photoUrl = $clean($content['photo_url'], 'Invoice photo URL must be encoded in UTF-8');
        $startParameter = $clean($content['start_parameter'], 'Invoice bot start parameter must be encoded in UTF-8');
        $providerToken = $clean($content['provider_token'], 'Invoice provider token must be encoded in UTF-8');
        $providerData = $clean($content['provider_data'], 'Invoice provider data must be encoded in UTF-8');
        $currency = $clean($invoice['currency'], 'Invoice currency must be encoded in UTF-8');
        $recurringTermsUrl = $clean($invoice['recurring_payment_terms_of_service_url'], 'Invoice terms of service URL must be encoded in UTF-8');
        $termsUrl = $clean($invoice['terms_of_service_url'], 'Invoice terms of service URL must be encoded in UTF-8');

        $photo = null;
        try {
            $url = HttpUrl::parse($photoUrl)->getUrl();
            $photo = [
                'url' => $url,
                'size' => $content['photo_size'],
                'dimensions' => self::dimensions($content['photo_width'], $content['photo_height']),
            ];
        } catch (\InvalidArgumentException) {
            // the photo is skipped
        }

        // LabeledPricePart::get_labeled_price_parts
        $parts = $invoice['price_parts'];
        if ($parts === []) {
            throw new QueryError(400, 'There must be at least one price part');
        }
        $prices = [];
        $totalAmount = 0;
        foreach ($parts as $part) {
            $label = $clean($part['label'], 'Price part label must be encoded in UTF-8');
            if (abs($part['amount']) > self::MAX_CURRENCY_AMOUNT) {
                throw new QueryError(400, 'Too big amount of the currency specified');
            }
            $prices[] = ['_' => 'labeledPrice', 'label' => $label, 'amount' => $part['amount']];
            $totalAmount += $part['amount'];
        }
        if ($totalAmount <= 0) {
            throw new QueryError(400, 'Total price must be positive');
        }
        if ($totalAmount > self::MAX_CURRENCY_AMOUNT) {
            throw new QueryError(400, 'Total price is too big');
        }

        $maxTipAmount = $invoice['max_tip_amount'];
        if ($maxTipAmount < 0 || $maxTipAmount > self::MAX_CURRENCY_AMOUNT) {
            throw new QueryError(400, 'Invalid max_tip_amount of the currency specified');
        }
        foreach ($invoice['suggested_tip_amounts'] as $tipAmount) {
            if ($tipAmount <= 0) {
                throw new QueryError(400, 'Suggested tip amount must be positive');
            }
            if ($tipAmount > $maxTipAmount) {
                throw new QueryError(400, 'Suggested tip amount can\'t be bigger than max_tip_amount');
            }
        }
        if (count($invoice['suggested_tip_amounts']) > 4) {
            throw new QueryError(400, 'There can be at most 4 suggested tip amounts');
        }

        $sendPhoneNumber = $invoice['send_phone_number_to_provider'];
        $sendEmailAddress = $invoice['send_email_address_to_provider'];
        $isFlexible = $invoice['is_flexible'];

        $extendedMedia = $this->getMessageExtendedMedia($content['paid_media'] ?? null, $ownerChatId);
        $extendedMediaCaption = new FormattedText();
        if ($extendedMedia !== null) {
            $extendedMediaCaption = $this->getOutgoingFormattedText($content['paid_media_caption'] ?? new FormattedText(), true);
        }

        return [
            'title' => $title,
            'description' => $description,
            'photo' => $photo,
            'start_parameter' => $startParameter,
            'invoice' => self::tl('invoice', [
                'test' => $invoice['is_test'],
                'name_requested' => $invoice['need_name'],
                'phone_requested' => $invoice['need_phone_number'] || $sendPhoneNumber,
                'email_requested' => $invoice['need_email_address'] || $sendEmailAddress,
                'shipping_address_requested' => $invoice['need_shipping_address'] || $isFlexible,
                'flexible' => $isFlexible,
                'phone_to_provider' => $sendPhoneNumber,
                'email_to_provider' => $sendEmailAddress,
                'recurring' => $recurringTermsUrl !== '',
                'currency' => $currency,
                'prices' => $prices,
                'max_tip_amount' => $maxTipAmount !== 0 ? $maxTipAmount : null,
                'suggested_tip_amounts' => $maxTipAmount !== 0 ? $invoice['suggested_tip_amounts'] : null,
                'terms_url' => $recurringTermsUrl !== '' ? $recurringTermsUrl : ($termsUrl !== '' ? $termsUrl : null),
                'subscription_period' => max($invoice['subscription_period'], 0) !== 0 ? max($invoice['subscription_period'], 0) : null,
            ]),
            'payload' => $content['payload'],
            'provider_token' => $providerToken,
            'provider_data' => $providerData,
            'extended_media' => $extendedMedia,
            'extended_media_caption' => $extendedMediaCaption,
        ];
    }

    /**
     * InputInvoice::get_input_media_invoice.
     *
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    protected function getInputMediaInvoice(array $invoice): array
    {
        return self::tl('inputMediaInvoice', [
            'title' => $invoice['title'],
            'description' => $invoice['description'],
            'photo' => self::getInvoiceInputWebDocument($invoice['photo']),
            'invoice' => $invoice['invoice'],
            'payload' => $invoice['payload'],
            'provider' => $invoice['provider_token'] !== '' ? $invoice['provider_token'] : null,
            'provider_data' => ['_' => 'dataJSON', 'data' => $invoice['provider_data'] === '' ? 'null' : $invoice['provider_data']],
            'start_param' => $invoice['start_parameter'] !== '' ? $invoice['start_parameter'] : null,
            'extended_media' => $invoice['extended_media'],
        ]);
    }

    /**
     * InputInvoice::get_input_bot_inline_message_media_invoice.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>
     */
    protected function getInputBotInlineMessageMediaInvoice(array $invoice, ?array $replyMarkup): array
    {
        return self::tl('inputBotInlineMessageMediaInvoice', [
            'title' => $invoice['title'],
            'description' => $invoice['description'],
            'photo' => self::getInvoiceInputWebDocument($invoice['photo']),
            'invoice' => $invoice['invoice'],
            'payload' => $invoice['payload'],
            'provider' => $invoice['provider_token'],
            'provider_data' => ['_' => 'dataJSON', 'data' => $invoice['provider_data'] === '' ? 'null' : $invoice['provider_data']],
            'reply_markup' => $replyMarkup,
        ]);
    }

    /**
     * get_input_web_document of an invoice photo.
     *
     * @param array<string, mixed>|null $photo
     * @return array<string, mixed>|null
     */
    private static function getInvoiceInputWebDocument(?array $photo): ?array
    {
        if ($photo === null) {
            return null;
        }

        [$width, $height] = $photo['dimensions'];
        $attributes = [];
        if ($width !== 0 && $height !== 0) {
            $attributes[] = ['_' => 'documentAttributeImageSize', 'w' => $width, 'h' => $height];
        }
        $extension = FileName::split(self::getUrlFileName($photo['url']))[1];

        return [
            '_' => 'inputWebDocument',
            'url' => $photo['url'],
            'size' => $photo['size'],
            'mime_type' => MimeType::fromExtension($extension, 'image/jpeg'),
            'attributes' => $attributes,
        ];
    }

    /**
     * td::get_url_file_name.
     */
    protected static function getUrlFileName(string $url): string
    {
        try {
            $query = HttpUrl::parse($url)->query;
        } catch (\InvalidArgumentException) {
            return '';
        }
        $end = strcspn($query, '?#');
        $query = substr($query, 0, $end);
        $slash = strrpos($query, '/');

        return $slash === false ? $query : substr($query, $slash + 1);
    }
}
