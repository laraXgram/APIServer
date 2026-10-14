<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Entities\TgLinks;
use LaraGram\Server\Files\FileIdException;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Files\RemoteFileLocation;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonObject;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\LinkChecker;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Support\TdJson;
use LaraGram\Server\Td\InlineMessageId;

/**
 * answerInlineQuery, answerWebAppQuery, answerGuestQuery and
 * savePreparedInlineMessage with the InlineQueriesManager parts they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait InlineMethods
{
    /** InlineQueriesManager::MAX_INLINE_QUERY_RESULT_COUNT (server-side limit) */
    private const MAX_INLINE_QUERY_RESULT_COUNT = 50;

    // ═════════════════════════════════════════════════════════════════
    //  Bot API side
    // ═════════════════════════════════════════════════════════════════

    public function processAnswerInlineQueryQuery(Query $query): void
    {
        $inlineQueryId = TdInteger::toInt64($query->arg('inline_query_id'));
        $isPersonal = self::boolArg($query, 'is_personal');
        $cacheTime = self::getIntegerArg($query, 'cache_time', 300, 0, 24 * 60 * 60);
        $nextOffset = $query->arg('next_offset');
        $button = $this->getInlineQueryResultsButton($query->arg('button'));
        if ($button === null) {
            $switchPmText = $query->arg('switch_pm_text');
            if ($switchPmText !== '') {
                $button = ['text' => $switchPmText, 'type' => ['_' => 'inlineQueryResultsButtonTypeStartBot', 'parameter' => $query->arg('switch_pm_parameter')]];
            }
        }
        $results = $this->getInlineQueryResults($query);
        $results = array_map(fn (array $result): array => $this->resolveInlineQueryResultBotUsernames($result), $results);

        $this->tdAnswerInlineQuery($inlineQueryId, $isPersonal, $button, $results, $cacheTime, $nextOffset);
        $query->answerQuery(true);
    }

    public function processAnswerWebAppQueryQuery(Query $query): void
    {
        $webAppQueryId = $query->arg('web_app_query_id');
        $result = $this->resolveInlineQueryResultBotUsernames($this->getInlineQueryResultArg($query));

        // InlineQueriesManager::answer_web_view_query
        $inputResult = $this->getInputBotInlineResult($result, $isGallery, $forceVertical, false);
        try {
            $sent = $this->invoke('messages.sendWebViewResultMessage', ['bot_query_id' => $webAppQueryId, 'result' => $inputResult]);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }

        $query->answerQuery(self::inlineMessageIdObject(isset($sent['msg_id']) ? InlineMessageId::encode($sent['msg_id']) : ''));
    }

    public function processAnswerGuestQueryQuery(Query $query): void
    {
        $guestQueryId = TdInteger::toInt64($query->arg('guest_query_id'));
        $result = $this->resolveInlineQueryResultBotUsernames($this->getInlineQueryResultArg($query));

        // InlineQueriesManager::answer_guest_query
        $inputResult = $this->getInputBotInlineResult($result, $isGallery, $forceVertical, false);
        try {
            $id = $this->invoke('messages.setBotGuestChatResult', ['query_id' => $guestQueryId, 'result' => $inputResult]);
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }

        $query->answerQuery(self::inlineMessageIdObject(is_array($id) ? InlineMessageId::encode($id) : ''));
    }

    public function processSavePreparedInlineMessageQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $result = $this->getInlineQueryResultArg($query);
        $types = [
            'allow_user_chats' => self::boolArg($query, 'allow_user_chats'),
            'allow_bot_chats' => self::boolArg($query, 'allow_bot_chats'),
            'allow_group_chats' => self::boolArg($query, 'allow_group_chats'),
            'allow_channel_chats' => self::boolArg($query, 'allow_channel_chats'),
        ];
        $result = $this->resolveInlineQueryResultBotUsernames($result);

        // InlineQueriesManager::save_prepared_inline_message
        $this->checkUser($userId);
        $inputUser = $this->inputUser($userId);
        $inputResult = $this->getInputBotInlineResult($result, $isGallery, $forceVertical, false);
        if (!in_array(true, $types, true)) {
            throw new QueryError(400, 'At least one chat type must be allowed');
        }
        $peerTypes = [];
        if (in_array(false, $types, true)) {
            if ($types['allow_user_chats']) {
                $peerTypes[] = ['_' => 'inlineQueryPeerTypePM'];
            }
            if ($types['allow_bot_chats']) {
                $peerTypes[] = ['_' => 'inlineQueryPeerTypeBotPM'];
            }
            if ($types['allow_group_chats']) {
                $peerTypes[] = ['_' => 'inlineQueryPeerTypeChat'];
                $peerTypes[] = ['_' => 'inlineQueryPeerTypeMegagroup'];
            }
            if ($types['allow_channel_chats']) {
                $peerTypes[] = ['_' => 'inlineQueryPeerTypeBroadcast'];
            }
        }

        try {
            $prepared = $this->invoke('messages.savePreparedInlineMessage', self::untl(self::tl('messages.savePreparedInlineMessage', [
                'result' => $inputResult,
                'user_id' => $inputUser,
                'peer_types' => $peerTypes !== [] ? $peerTypes : null,
            ])));
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }

        $query->answerQuery(['id' => (string) ($prepared['id'] ?? ''), 'expiration_date' => (int) ($prepared['expire_date'] ?? 0)]);
    }

    /**
     * Client::JsonInlineMessageId.
     */
    private static function inlineMessageIdObject(string $inlineMessageId): object|array
    {
        return $inlineMessageId === '' ? new \stdClass() : ['inline_message_id' => $inlineMessageId];
    }

    /**
     * Client::get_inline_query_results_button.
     *
     * @return array<string, mixed>|null
     */
    protected function getInlineQueryResultsButton(string $button): ?array
    {
        if ($button === '') {
            return null;
        }
        $value = TdJson::tryDecode($button);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse inline query results button JSON object');
        }

        try {
            if (!$value->isObject()) {
                throw QueryError::badRequest('InlineQueryResultsButton must be an Object');
            }
            $object = $value->object();
            $text = $object->getRequiredStringField('text');
            if ($object->hasField('start_parameter')) {
                return ['text' => $text, 'type' => ['_' => 'inlineQueryResultsButtonTypeStartBot', 'parameter' => $object->getRequiredStringField('start_parameter')]];
            }
            if ($object->hasField('web_app')) {
                $webApp = $object->extractRequiredField('web_app', TdJson::OBJECT)->object();

                return ['text' => $text, 'type' => ['_' => 'inlineQueryResultsButtonTypeWebApp', 'url' => $webApp->getRequiredStringField('url')]];
            }

            throw QueryError::badRequest('InlineQueryResultsButton must have exactly one optional field');
        } catch (JsonError|QueryError $e) {
            throw QueryError::badRequest('Can\'t parse inline query results button: '.$e->getMessage());
        }
    }

    /**
     * Client::get_inline_query_results.
     *
     * @return list<array<string, mixed>>
     */
    protected function getInlineQueryResults(Query $query): array
    {
        $encoded = $query->arg('results');
        if ($encoded === '') {
            return [];
        }
        try {
            $values = TdJson::decode($encoded);
        } catch (JsonError $e) {
            throw QueryError::badRequest('Can\'t parse JSON encoded inline query results: '.$e->getMessage());
        }

        return $this->parseArray($values, 'InlineQueryResult', fn (JsonValue $value): array => $this->getInlineQueryResultJson($query, $value));
    }

    /**
     * Client::get_inline_query_result (the `result` argument).
     *
     * @return array<string, mixed>
     */
    protected function getInlineQueryResultArg(Query $query): array
    {
        $encoded = $query->arg('result');
        if ($encoded === '') {
            throw QueryError::badRequest('Result isn\'t specified');
        }
        try {
            $value = TdJson::decode($encoded);
        } catch (JsonError $e) {
            throw QueryError::badRequest('Can\'t parse JSON encoded web view query results '.$e->getMessage());
        }

        return self::json(fn (): array => $this->getInlineQueryResultJson($query, $value));
    }

    /**
     * Client::get_inline_query_result (JSON value).
     *
     * @return array<string, mixed> td_api InputInlineQueryResult
     */
    protected function getInlineQueryResultJson(Query $query, JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('Inline query result must be an object');
        }
        $object = $value->object();

        $type = strtolower($object->getRequiredStringField('type'));
        $id = $object->getRequiredStringField('id');

        $isInputMessageContentRequired = $type === 'article';
        $inputMessageContent = null;
        $inputMessageContentObject = $object->extractOptionalField('input_message_content', TdJson::OBJECT);
        if ($inputMessageContentObject->isNull()) {
            // legacy
            $messageText = $isInputMessageContentRequired ? $object->getRequiredStringField('message_text') : $object->getOptionalStringField('message_text');
            $disableWebPagePreview = $object->getOptionalBoolField('disable_web_page_preview');
            $parseMode = $object->getOptionalStringField('parse_mode');
            $entities = $object->extractField('entities');
            if ($isInputMessageContentRequired || $messageText !== '') {
                $inputMessageContent = $this->getInputMessageTextJson($messageText, self::disabledLinkPreviewOptions($disableWebPagePreview), $parseMode, $entities);
            }
        } else {
            $inputMessageContent = $this->getInputMessageContentJson($query, $inputMessageContentObject->object(), $isInputMessageContentRequired);
        }
        $inputCaption = $object->getOptionalStringField('caption');
        $parseMode = $object->getOptionalStringField('parse_mode');
        $entities = $object->extractField('caption_entities');
        $caption = $this->getFormattedText($inputCaption, $parseMode, $entities);
        $showCaptionAboveMedia = $object->getOptionalBoolField('show_caption_above_media');

        $replyMarkupObject = $object->extractOptionalField('reply_markup', TdJson::OBJECT);
        $replyMarkup = $replyMarkupObject->isNull() ? null : $this->replyMarkupFromJson($replyMarkupObject);

        $thumbnailUrlField = 'thumbnail_url';
        $thumbnailWidthField = 'thumbnail_width';
        $thumbnailHeightField = 'thumbnail_height';
        if (!$object->hasField($thumbnailUrlField) && !$object->hasField($thumbnailWidthField) && !$object->hasField($thumbnailHeightField)) {
            $thumbnailUrlField = 'thumb_url';
            $thumbnailWidthField = 'thumb_width';
            $thumbnailHeightField = 'thumb_height';
        }
        $thumbnailUrl = $object->getOptionalStringField($thumbnailUrlField);
        $thumbnailWidth = $object->getOptionalIntField($thumbnailWidthField);
        $thumbnailHeight = $object->getOptionalIntField($thumbnailHeightField);

        $result = ['_' => '', 'id' => $id, 'reply_markup' => $replyMarkup, 'input_message_content' => $inputMessageContent];

        switch ($type) {
            case 'article':
                $url = $object->getOptionalStringField('url');
                $hideUrl = $object->getOptionalBoolField('hide_url');
                $title = $object->getRequiredStringField('title');
                $description = $object->getOptionalStringField('description');

                return ['_' => 'inputInlineQueryResultArticle', 'url' => $hideUrl ? '' : $url, 'title' => $title, 'description' => $description,
                    'thumbnail_url' => $thumbnailUrl, 'thumbnail_width' => $thumbnailWidth, 'thumbnail_height' => $thumbnailHeight] + $result;

            case 'audio':
                $audioUrl = $object->getOptionalStringField('audio_url');
                $audioDuration = $object->getOptionalIntField('audio_duration');
                $title = $audioUrl === '' ? $object->getOptionalStringField('title') : $object->getRequiredStringField('title');
                $performer = $object->getOptionalStringField('performer');
                if ($audioUrl === '') {
                    $audioUrl = $object->getRequiredStringField('audio_file_id');
                }
                $result['input_message_content'] ??= ['_' => 'inputMessageAudio', 'audio' => null, 'caption' => $caption];

                return ['_' => 'inputInlineQueryResultAudio', 'title' => $title, 'performer' => $performer, 'audio_url' => $audioUrl, 'audio_duration' => $audioDuration] + $result;

            case 'contact':
                $phoneNumber = $object->getRequiredStringField('phone_number');
                $firstName = $object->getRequiredStringField('first_name');
                $lastName = $object->getOptionalStringField('last_name');
                $vcard = $object->getOptionalStringField('vcard');
                $contact = ['phone_number' => $phoneNumber, 'first_name' => $firstName, 'last_name' => $lastName, 'vcard' => $vcard, 'user_id' => 0];
                $result['input_message_content'] ??= ['_' => 'inputMessageContact', 'contact' => $contact];

                return ['_' => 'inputInlineQueryResultContact', 'contact' => $contact,
                    'thumbnail_url' => $thumbnailUrl, 'thumbnail_width' => $thumbnailWidth, 'thumbnail_height' => $thumbnailHeight] + $result;

            case 'document':
                $title = $object->getRequiredStringField('title');
                $description = $object->getOptionalStringField('description');
                $documentUrl = $object->getOptionalStringField('document_url');
                $mimeType = $documentUrl === '' ? $object->getOptionalStringField('mime_type') : $object->getRequiredStringField('mime_type');
                if ($documentUrl === '') {
                    $documentUrl = $object->getRequiredStringField('document_file_id');
                }
                $result['input_message_content'] ??= ['_' => 'inputMessageDocument', 'document' => null, 'caption' => $caption];

                return ['_' => 'inputInlineQueryResultDocument', 'title' => $title, 'description' => $description, 'document_url' => $documentUrl,
                    'mime_type' => $mimeType, 'thumbnail_url' => $thumbnailUrl, 'thumbnail_width' => $thumbnailWidth, 'thumbnail_height' => $thumbnailHeight] + $result;

            case 'game':
                $gameShortName = $object->getRequiredStringField('game_short_name');

                return ['_' => 'inputInlineQueryResultGame', 'id' => $id, 'game_short_name' => $gameShortName, 'reply_markup' => $replyMarkup];

            case 'gif':
            case 'mpeg4_gif':
                $prefix = $type === 'gif' ? 'gif' : 'mpeg4';
                $title = $object->getOptionalStringField('title');
                $videoUrl = $object->getOptionalStringField($prefix.'_url');
                $thumbnailMimeTypeField = $object->hasField('thumbnail_mime_type') ? 'thumbnail_mime_type' : 'thumb_mime_type';
                $thumbnailMimeType = $object->getOptionalStringField($thumbnailMimeTypeField);
                $duration = $object->getOptionalIntField($prefix.'_duration');
                $width = $object->getOptionalIntField($prefix.'_width');
                $height = $object->getOptionalIntField($prefix.'_height');
                if ($videoUrl === '') {
                    $videoUrl = $object->getRequiredStringField($prefix.'_file_id');
                }
                $result['input_message_content'] ??= ['_' => 'inputMessageAnimation', 'animation' => null, 'caption' => $caption, 'show_caption_above_media' => $showCaptionAboveMedia, 'has_spoiler' => false];

                return ['_' => 'inputInlineQueryResultAnimation', 'title' => $title, 'thumbnail_url' => $thumbnailUrl, 'thumbnail_mime_type' => $thumbnailMimeType,
                    'video_url' => $videoUrl, 'video_mime_type' => $type === 'gif' ? 'image/gif' : 'video/mp4',
                    'video_duration' => $duration, 'video_width' => $width, 'video_height' => $height] + $result;

            case 'location':
                if ($result['input_message_content'] === null) {
                    $location = self::getLocationFromJson($object);
                    $livePeriod = $object->getOptionalIntField('live_period');
                    $heading = $object->getOptionalIntField('heading');
                    $proximityAlertRadius = $object->getOptionalIntField('proximity_alert_radius');
                    $result['input_message_content'] = $livePeriod !== 0
                        ? ['_' => 'inputMessageLiveLocation', 'location' => $location, 'live_period' => $livePeriod, 'heading' => $heading, 'proximity_alert_radius' => $proximityAlertRadius]
                        : ['_' => 'inputMessageLocation', 'location' => $location];
                }
                $location = self::getLocationFromJson($object);
                $livePeriod = $object->getOptionalIntField('live_period');
                $title = $object->getRequiredStringField('title');

                return ['_' => 'inputInlineQueryResultLocation', 'location' => $location, 'live_period' => $livePeriod, 'title' => $title,
                    'thumbnail_url' => $thumbnailUrl, 'thumbnail_width' => $thumbnailWidth, 'thumbnail_height' => $thumbnailHeight] + $result;

            case 'photo':
                $title = $object->getOptionalStringField('title');
                $description = $object->getOptionalStringField('description');
                $photoUrl = $object->getOptionalStringField('photo_url');
                $photoWidth = $object->getOptionalIntField('photo_width');
                $photoHeight = $object->getOptionalIntField('photo_height');
                if ($photoUrl === '') {
                    $photoUrl = $object->getRequiredStringField('photo_file_id');
                }
                $result['input_message_content'] ??= ['_' => 'inputMessagePhoto', 'photo' => null, 'caption' => $caption, 'show_caption_above_media' => $showCaptionAboveMedia, 'has_spoiler' => false];

                return ['_' => 'inputInlineQueryResultPhoto', 'title' => $title, 'description' => $description, 'thumbnail_url' => $thumbnailUrl,
                    'photo_url' => $photoUrl, 'photo_width' => $photoWidth, 'photo_height' => $photoHeight] + $result;

            case 'sticker':
                $stickerFileId = $object->getRequiredStringField('sticker_file_id');
                $result['input_message_content'] ??= ['_' => 'inputMessageSticker', 'sticker' => null, 'emoji' => ''];

                return ['_' => 'inputInlineQueryResultSticker', 'thumbnail_url' => '', 'sticker_url' => $stickerFileId, 'sticker_width' => 0, 'sticker_height' => 0] + $result;

            case 'venue':
                if ($result['input_message_content'] === null) {
                    $result['input_message_content'] = ['_' => 'inputMessageVenue', 'venue' => self::getVenueFromJson($object)];
                }
                $venue = self::getVenueFromJson($object);

                return ['_' => 'inputInlineQueryResultVenue', 'venue' => $venue,
                    'thumbnail_url' => $thumbnailUrl, 'thumbnail_width' => $thumbnailWidth, 'thumbnail_height' => $thumbnailHeight] + $result;

            case 'video':
                $title = $object->getRequiredStringField('title');
                $description = $object->getOptionalStringField('description');
                $videoUrl = $object->getOptionalStringField('video_url');
                $mimeType = $videoUrl === '' ? $object->getOptionalStringField('mime_type') : $object->getRequiredStringField('mime_type');
                $videoWidth = $object->getOptionalIntField('video_width');
                $videoHeight = $object->getOptionalIntField('video_height');
                $videoDuration = $object->getOptionalIntField('video_duration');
                if ($videoUrl === '') {
                    $videoUrl = $object->getRequiredStringField('video_file_id');
                }
                $result['input_message_content'] ??= ['_' => 'inputMessageVideo', 'video' => null, 'caption' => $caption, 'show_caption_above_media' => $showCaptionAboveMedia, 'has_spoiler' => false];

                return ['_' => 'inputInlineQueryResultVideo', 'title' => $title, 'description' => $description, 'thumbnail_url' => $thumbnailUrl,
                    'video_url' => $videoUrl, 'mime_type' => $mimeType, 'video_width' => $videoWidth, 'video_height' => $videoHeight,
                    'video_duration' => $videoDuration] + $result;

            case 'voice':
                $title = $object->getRequiredStringField('title');
                $voiceNoteUrl = $object->getOptionalStringField('voice_url');
                $voiceNoteDuration = $object->getOptionalIntField('voice_duration');
                if ($voiceNoteUrl === '') {
                    $voiceNoteUrl = $object->getRequiredStringField('voice_file_id');
                }
                $result['input_message_content'] ??= ['_' => 'inputMessageVoiceNote', 'voice_note' => null, 'caption' => $caption];

                return ['_' => 'inputInlineQueryResultVoiceNote', 'title' => $title, 'voice_note_url' => $voiceNoteUrl, 'voice_note_duration' => $voiceNoteDuration] + $result;
        }

        throw QueryError::badRequest("type \"{$type}\" is unsupported for the inline query result");
    }

    /**
     * Client::get_input_message_content (InputMessageContent JSON object).
     *
     * @return array<string, mixed>|null
     */
    protected function getInputMessageContentJson(Query $query, JsonObject $object, bool $isInputMessageContentRequired): ?array
    {
        if ($object->hasField('rich_message')) {
            $richMessage = $object->extractRequiredField('rich_message', TdJson::OBJECT);

            return ['_' => 'inputMessageRichMessage', 'message' => $this->inputRichMessageFromJson($query, $richMessage), 'clear_draft' => false];
        }

        $messageText = $object->getOptionalStringField('message_text');
        if ($messageText !== '') {
            if ($object->hasField('link_preview_options')) {
                $options = $object->extractRequiredField('link_preview_options', TdJson::OBJECT);
                $linkPreviewOptions = self::linkPreviewOptionsFromJson($options);
            } else {
                $linkPreviewOptions = self::disabledLinkPreviewOptions($object->getOptionalBoolField('disable_web_page_preview'));
            }
            $parseMode = $object->getOptionalStringField('parse_mode');
            $entities = $object->extractField('entities');

            return $this->getInputMessageTextJson($messageText, $linkPreviewOptions, $parseMode, $entities);
        }

        if ($object->hasField('latitude') && $object->hasField('longitude')) {
            if ($object->hasField('title') && $object->hasField('address')) {
                return ['_' => 'inputMessageVenue', 'venue' => self::getVenueFromJson($object)];
            }

            $location = self::getLocationFromJson($object);
            $livePeriod = $object->getOptionalIntField('live_period');
            $heading = $object->getOptionalIntField('heading');
            $proximityAlertRadius = $object->getOptionalIntField('proximity_alert_radius');

            return $livePeriod !== 0
                ? ['_' => 'inputMessageLiveLocation', 'location' => $location, 'live_period' => $livePeriod, 'heading' => $heading, 'proximity_alert_radius' => $proximityAlertRadius]
                : ['_' => 'inputMessageLocation', 'location' => $location];
        }

        if ($object->hasField('phone_number')) {
            $phoneNumber = $object->getRequiredStringField('phone_number');
            $firstName = $object->getRequiredStringField('first_name');
            $lastName = $object->getOptionalStringField('last_name');
            $vcard = $object->getOptionalStringField('vcard');

            return ['_' => 'inputMessageContact', 'contact' => ['phone_number' => $phoneNumber, 'first_name' => $firstName, 'last_name' => $lastName, 'vcard' => $vcard, 'user_id' => 0]];
        }

        if ($object->hasField('payload')) {
            $title = $object->getRequiredStringField('title');
            $description = $object->getRequiredStringField('description');
            $payload = $object->getRequiredStringField('payload');
            if (!mb_check_encoding($payload, 'UTF-8')) {
                throw QueryError::badRequest('InputInvoiceMessageContent payload must be encoded in UTF-8');
            }
            $providerToken = $object->getOptionalStringField('provider_token');
            $currency = $object->getRequiredStringField('currency');
            $pricesObject = $object->extractRequiredField('prices', TdJson::ARRAY);
            $prices = $this->getLabeledPriceParts($pricesObject);
            $providerData = $object->getOptionalStringField('provider_data');
            $maxTipAmount = $object->getOptionalLongField('max_tip_amount');
            $suggestedTipAmounts = $this->getSuggestedTipAmounts($object->extractField('suggested_tip_amounts'));
            $photoUrl = $object->getOptionalStringField('photo_url');
            $photoSize = $object->getOptionalIntField('photo_size');
            $photoWidth = $object->getOptionalIntField('photo_width');
            $photoHeight = $object->getOptionalIntField('photo_height');
            $invoice = [
                'currency' => $currency,
                'price_parts' => $prices,
                'subscription_period' => 0,
                'max_tip_amount' => $maxTipAmount,
                'suggested_tip_amounts' => $suggestedTipAmounts,
                'recurring_payment_terms_of_service_url' => '',
                'terms_of_service_url' => '',
                'is_test' => false,
                'need_name' => $object->getOptionalBoolField('need_name'),
                'need_phone_number' => $object->getOptionalBoolField('need_phone_number'),
                'need_email_address' => $object->getOptionalBoolField('need_email'),
                'need_shipping_address' => $object->getOptionalBoolField('need_shipping_address'),
                'send_phone_number_to_provider' => $object->getOptionalBoolField('send_phone_number_to_provider'),
                'send_email_address_to_provider' => $object->getOptionalBoolField('send_email_to_provider'),
                'is_flexible' => $object->getOptionalBoolField('is_flexible'),
            ];

            return [
                '_' => 'inputMessageInvoice', 'invoice' => $invoice, 'title' => $title, 'description' => $description,
                'photo_url' => $photoUrl, 'photo_size' => $photoSize, 'photo_width' => $photoWidth, 'photo_height' => $photoHeight,
                'payload' => $payload, 'provider_token' => $providerToken, 'provider_data' => $providerData,
                'start_parameter' => '', 'paid_media' => null, 'paid_media_caption' => null,
            ];
        }

        if ($isInputMessageContentRequired) {
            throw QueryError::badRequest('Input message content is not specified');
        }

        return null;
    }

    /**
     * Client::get_input_message_text (text, options, parse mode and entities).
     *
     * @param array<string, mixed>|null $linkPreviewOptions
     * @return array<string, mixed>
     */
    protected function getInputMessageTextJson(string $text, ?array $linkPreviewOptions, string $parseMode, JsonValue $entities): array
    {
        if ($text === '') {
            throw QueryError::badRequest('Message text is empty');
        }

        return [
            '_' => 'inputMessageText',
            'text' => $this->getFormattedText($text, $parseMode, $entities),
            'link_preview_options' => $linkPreviewOptions,
            'clear_draft' => false,
        ];
    }

    /**
     * Client::get_link_preview_options(bool).
     *
     * @return array<string, mixed>|null
     */
    protected static function disabledLinkPreviewOptions(bool $disableWebPagePreview): ?array
    {
        return $disableWebPagePreview
            ? ['is_disabled' => true, 'url' => '', 'force_small_media' => false, 'force_large_media' => false, 'show_above_text' => false]
            : null;
    }

    /**
     * Client::resolve_inline_query_results_bot_usernames.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function resolveInlineQueryResultBotUsernames(array $result): array
    {
        $result['reply_markup'] = $this->resolveReplyMarkupBotUsernames($result['reply_markup'] ?? null);

        return $result;
    }

    // ═════════════════════════════════════════════════════════════════
    //  TDLib side
    // ═════════════════════════════════════════════════════════════════

    /**
     * InlineQueriesManager::answer_inline_query.
     *
     * @param array<string, mixed>|null $button
     * @param list<array<string, mixed>> $results
     */
    protected function tdAnswerInlineQuery(int $inlineQueryId, bool $isPersonal, ?array $button, array $results, int $cacheTime, string $nextOffset): void
    {
        $switchPm = null;
        $webView = null;
        if ($button !== null) {
            $text = TextFormatting::cleanInputString($button['text']);
            if ($text === null) {
                throw new QueryError(400, 'Strings must be encoded in UTF-8');
            }
            $type = $button['type'];
            if ($type['_'] === 'inlineQueryResultsButtonTypeStartBot') {
                $parameter = $type['parameter'];
                if ($parameter === '') {
                    throw new QueryError(400, 'Can\'t use empty start_parameter');
                }
                if (strlen($parameter) > 64) {
                    throw new QueryError(400, 'Too long start_parameter specified');
                }
                if (!self::isBase64UrlCharacters($parameter)) {
                    throw new QueryError(400, 'Unallowed characters in start_parameter are used');
                }
                $switchPm = ['_' => 'inlineBotSwitchPM', 'text' => $text, 'start_param' => $parameter];
            } else {
                $url = $type['url'];
                if (TgLinks::userId($url) > 0) {
                    throw new QueryError(400, 'Link to a user can\'t be used in the Web App button');
                }
                try {
                    LinkChecker::check($url, true, !$this->isTestDc());
                } catch (\InvalidArgumentException $e) {
                    throw new QueryError(400, 'Inline query button Web App '.$e->getMessage());
                }
                $webView = ['_' => 'inlineBotWebView', 'text' => $text, 'url' => $url];
            }
        }

        if (count($results) > self::MAX_INLINE_QUERY_RESULT_COUNT) {
            throw new QueryError(400, 'Too many inline query results specified');
        }

        $inputResults = [];
        $isGallery = false;
        $forceVertical = false;
        foreach ($results as $result) {
            $inputResults[] = $this->getInputBotInlineResult($result, $isGallery, $forceVertical, true);
        }

        try {
            $this->invoke('messages.setInlineBotResults', self::untl(self::tl('messages.setInlineBotResults', [
                'gallery' => $isGallery && !$forceVertical,
                'private' => $isPersonal,
                'query_id' => $inlineQueryId,
                'results' => $inputResults,
                'cache_time' => $cacheTime,
                'next_offset' => $nextOffset !== '' ? $nextOffset : null,
                'switch_pm' => $switchPm,
                'switch_webview' => $webView,
            ])));
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
    }

    /**
     * InlineQueriesManager::get_input_bot_inline_result.
     *
     * @param array<string, mixed>|null $result td_api InputInlineQueryResult
     * @return array<string, mixed> TL InputBotInlineResult
     */
    protected function getInputBotInlineResult(?array $result, ?bool &$isGallery, ?bool &$forceVertical, bool $trackLayout): array
    {
        if ($result === null) {
            throw new QueryError(400, 'Inline query result must be non-empty');
        }

        $url = $type = $title = $description = $thumbnailUrl = $contentUrl = $contentType = '';
        $thumbnailType = 'image/jpeg';
        $thumbnailWidth = $thumbnailHeight = $width = $height = $duration = 0;
        $fileType = FileType::TEMP;
        $id = $result['id'];
        $setGallery = static function () use (&$isGallery, $trackLayout): void {
            if ($trackLayout) {
                $isGallery = true;
            }
        };
        $setVertical = static function () use (&$forceVertical, $trackLayout): void {
            if ($trackLayout) {
                $forceVertical = true;
            }
        };

        switch ($result['_']) {
            case 'inputInlineQueryResultAnimation':
                $type = 'gif';
                $title = $result['title'];
                $thumbnailUrl = $result['thumbnail_url'];
                if ($result['thumbnail_mime_type'] !== '') {
                    $thumbnailType = $result['thumbnail_mime_type'];
                }
                $contentUrl = $result['video_url'];
                $contentType = $result['video_mime_type'];
                if ($contentType !== 'image/gif' && $contentType !== 'video/mp4') {
                    throw new QueryError(400, 'Wrong animation MIME type specified');
                }
                $duration = $result['video_duration'];
                $width = $result['video_width'];
                $height = $result['video_height'];
                $setGallery();
                $fileType = FileType::ANIMATION;
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], 'inputMessageAnimation');
                break;

            case 'inputInlineQueryResultArticle':
                $type = 'article';
                $url = $result['url'];
                $title = $result['title'];
                $description = $result['description'];
                $thumbnailUrl = $result['thumbnail_url'];
                if ($thumbnailUrl !== '') {
                    $thumbnailWidth = $result['thumbnail_width'];
                    $thumbnailHeight = $result['thumbnail_height'];
                }
                $setVertical();
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], '');
                break;

            case 'inputInlineQueryResultAudio':
                $type = 'audio';
                $title = $result['title'];
                $description = $result['performer'];
                $contentUrl = $result['audio_url'];
                $contentType = 'audio/mpeg';
                $duration = $result['audio_duration'];
                $setVertical();
                $fileType = FileType::AUDIO;
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], 'inputMessageAudio');
                break;

            case 'inputInlineQueryResultContact':
                $type = 'contact';
                $phoneNumber = trim($result['contact']['phone_number']);
                $firstName = trim($result['contact']['first_name']);
                $lastName = trim($result['contact']['last_name']);
                if ($phoneNumber === '') {
                    throw new QueryError(400, 'Field "phone_number" must contain a valid phone number');
                }
                if ($firstName === '') {
                    throw new QueryError(400, 'Field "first_name" must be non-empty');
                }
                $title = $lastName === '' ? $firstName : $firstName.' '.$lastName;
                $description = $phoneNumber;
                $thumbnailUrl = $result['thumbnail_url'];
                if ($thumbnailUrl !== '') {
                    $thumbnailWidth = $result['thumbnail_width'];
                    $thumbnailHeight = $result['thumbnail_height'];
                }
                $setVertical();
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], '');
                break;

            case 'inputInlineQueryResultDocument':
                $type = 'file';
                $title = $result['title'];
                $description = $result['description'];
                $thumbnailUrl = $result['thumbnail_url'];
                $contentUrl = $result['document_url'];
                $contentType = $result['mime_type'];
                $thumbnailWidth = $result['thumbnail_width'];
                $thumbnailHeight = $result['thumbnail_height'];
                if (str_contains($contentUrl, '.')) {
                    if (str_starts_with($contentType, 'application/pdf')) {
                        $contentType = 'application/pdf';
                    } elseif (str_starts_with($contentType, 'application/zip')) {
                        $contentType = 'application/zip';
                    } else {
                        throw new QueryError(400, 'Unallowed document MIME type');
                    }
                }
                $fileType = FileType::DOCUMENT;
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], 'inputMessageDocument');
                break;

            case 'inputInlineQueryResultGame':
                $tlReplyMarkup = $this->getInlineInputReplyMarkup($result['reply_markup'], true);

                return [
                    '_' => 'inputBotInlineResultGame',
                    'id' => $id,
                    'short_name' => $result['game_short_name'],
                    'send_message' => self::tl('inputBotInlineMessageGame', ['reply_markup' => $tlReplyMarkup]),
                ];

            case 'inputInlineQueryResultLocation':
                $type = 'geo';
                $title = $result['title'];
                $description = self::formatDouble($result['location']['latitude']).' '.self::formatDouble($result['location']['longitude']);
                $thumbnailUrl = $result['thumbnail_url'];
                if ($thumbnailUrl !== '') {
                    $thumbnailWidth = $result['thumbnail_width'];
                    $thumbnailHeight = $result['thumbnail_height'];
                }
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], '');
                break;

            case 'inputInlineQueryResultPhoto':
                $type = 'photo';
                $title = $result['title'];
                $description = $result['description'];
                $thumbnailUrl = $result['thumbnail_url'];
                $contentUrl = $result['photo_url'];
                $contentType = 'image/jpeg';
                $width = $result['photo_width'];
                $height = $result['photo_height'];
                $setGallery();
                $fileType = FileType::PHOTO;
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], 'inputMessagePhoto');
                break;

            case 'inputInlineQueryResultSticker':
                $type = 'sticker';
                $thumbnailUrl = $result['thumbnail_url'];
                $contentUrl = $result['sticker_url'];
                $contentType = 'image/webp';
                $width = $result['sticker_width'];
                $height = $result['sticker_height'];
                $setGallery();
                if (str_contains($contentUrl, '.')) {
                    throw new QueryError(400, 'Wrong sticker_file_id specified');
                }
                $fileType = FileType::STICKER;
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], 'inputMessageSticker');
                break;

            case 'inputInlineQueryResultVenue':
                $type = 'venue';
                $title = $result['venue']['title'];
                $description = $result['venue']['address'];
                $thumbnailUrl = $result['thumbnail_url'];
                if ($thumbnailUrl !== '') {
                    $thumbnailWidth = $result['thumbnail_width'];
                    $thumbnailHeight = $result['thumbnail_height'];
                }
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], '');
                break;

            case 'inputInlineQueryResultVideo':
                $type = 'video';
                $title = $result['title'];
                $description = $result['description'];
                $thumbnailUrl = $result['thumbnail_url'];
                $contentUrl = $result['video_url'];
                $contentType = $result['mime_type'];
                $width = $result['video_width'];
                $height = $result['video_height'];
                $duration = $result['video_duration'];
                if (str_contains($contentUrl, '.')) {
                    if (str_starts_with($contentType, 'video/mp4')) {
                        $contentType = 'video/mp4';
                    } elseif (str_starts_with($contentType, 'text/html')) {
                        $contentType = 'text/html';
                    } else {
                        throw new QueryError(400, 'Unallowed video MIME type');
                    }
                }
                $fileType = FileType::VIDEO;
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], 'inputMessageVideo');
                break;

            case 'inputInlineQueryResultVoiceNote':
                $type = 'voice';
                $title = $result['title'];
                $contentUrl = $result['voice_note_url'];
                $contentType = 'audio/ogg';
                $duration = $result['voice_note_duration'];
                $setVertical();
                $fileType = FileType::VOICE_NOTE;
                $inlineMessage = $this->getInlineMessage($result['input_message_content'], $result['reply_markup'], 'inputMessageVoiceNote');
                break;

            default:
                throw new QueryError(400, 'Inline query result must be non-empty');
        }

        if ($inlineMessage['_'] === 'inputBotInlineMessageMediaAuto' && $fileType === FileType::TEMP) {
            throw new QueryError(400, 'Sent message content must be explicitly specified');
        }
        $duration = max($duration, 0);

        $clean = static function (string $value): string {
            $clean = TextFormatting::cleanInputString($value);
            if ($clean === null) {
                throw new QueryError(400, 'Strings must be encoded in UTF-8');
            }

            return $clean;
        };
        if ($title !== '') {
            $title = $clean($title);
        }
        if ($description !== '') {
            $description = $clean($description);
        }

        if ($fileType !== FileType::TEMP && !str_contains($contentUrl, '.')) {
            try {
                $location = RemoteFileLocation::fromFileId($contentUrl);
                $location->checkType($fileType);
            } catch (FileIdException $e) {
                throw new QueryError(400, $e->getMessage());
            }
            if ($location->isWeb()) {
                throw new QueryError(400, 'Can\'t send web file');
            }

            if ($fileType === FileType::PHOTO) {
                return ['_' => 'inputBotInlineResultPhoto', 'id' => $id, 'type' => $type, 'photo' => self::inputPhoto($location), 'send_message' => $inlineMessage];
            }

            return self::tl('inputBotInlineResultDocument', [
                'id' => $id, 'type' => $type,
                'title' => $title !== '' ? $title : null,
                'description' => $description !== '' ? $description : null,
                'document' => self::inputDocument($location),
                'send_message' => $inlineMessage,
            ]);
        }

        if ($url !== '') {
            $url = $clean($url);
        }
        $thumbnail = null;
        if ($thumbnailUrl !== '') {
            $thumbnailUrl = $clean($thumbnailUrl);
            $attributes = [];
            if ($thumbnailWidth > 0 && $thumbnailHeight > 0) {
                $attributes[] = ['_' => 'documentAttributeImageSize', 'w' => $thumbnailWidth, 'h' => $thumbnailHeight];
            }
            $thumbnail = ['_' => 'inputWebDocument', 'url' => $thumbnailUrl, 'size' => 0, 'mime_type' => $thumbnailType, 'attributes' => $attributes];
        }
        $content = null;
        if ($contentUrl !== '' || $contentType !== '') {
            $contentUrl = $clean($contentUrl);
            $contentType = $clean($contentType);

            $attributes = [];
            if ($width > 0 && $height > 0) {
                if (($duration > 0 || $type === 'video' || $contentType === 'video/mp4') && !str_starts_with($contentType, 'image/')) {
                    $attributes[] = ['_' => 'documentAttributeVideo', 'duration' => (float) $duration, 'w' => $width, 'h' => $height];
                } else {
                    $attributes[] = ['_' => 'documentAttributeImageSize', 'w' => $width, 'h' => $height];
                }
            } elseif ($type === 'audio') {
                $attributes[] = ['_' => 'documentAttributeAudio', 'duration' => $duration, 'title' => $title, 'performer' => $description];
            } elseif ($type === 'voice') {
                $attributes[] = ['_' => 'documentAttributeAudio', 'voice' => true, 'duration' => $duration];
            }
            $attributes[] = ['_' => 'documentAttributeFilename', 'file_name' => self::getUrlFileName($contentUrl)];

            $content = ['_' => 'inputWebDocument', 'url' => $contentUrl, 'size' => 0, 'mime_type' => $contentType, 'attributes' => $attributes];
        }

        return self::tl('inputBotInlineResult', [
            'id' => $id,
            'type' => $type,
            'title' => $title !== '' ? $title : null,
            'description' => $description !== '' ? $description : null,
            'url' => $url !== '' ? $url : null,
            'thumb' => $thumbnail,
            'content' => $content,
            'send_message' => $inlineMessage,
        ]);
    }

    /**
     * InlineQueriesManager::get_inline_message.
     *
     * @param array<string, mixed>|null $content td_api InputMessageContent
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed> TL InputBotInlineMessage
     */
    protected function getInlineMessage(?array $content, ?array $replyMarkup, string $allowedMediaContent): array
    {
        if ($content === null) {
            throw new QueryError(400, 'Inline message must be non-empty');
        }
        $tlReplyMarkup = $this->getInlineInputReplyMarkup($replyMarkup, true);
        $myId = $this->getMyId();
        $inputUser = fn (int $userId): array => $this->inputUser($userId);

        switch ($content['_']) {
            case 'inputMessageText':
                $text = $this->processInputMessageText($myId, $content);
                $entities = $text['text']->inputEntities($inputUser);
                if ($text['web_page_url'] !== '') {
                    return self::tl('inputBotInlineMessageMediaWebPage', [
                        'invert_media' => $text['show_above_text'],
                        'force_large_media' => $text['force_large_media'],
                        'force_small_media' => $text['force_small_media'],
                        'optional' => $text['text']->text !== '',
                        'message' => $text['text']->text,
                        'entities' => $entities !== [] ? $entities : null,
                        'url' => $text['web_page_url'],
                        'reply_markup' => $tlReplyMarkup,
                    ]);
                }

                return self::tl('inputBotInlineMessageText', [
                    'no_webpage' => $text['disable_web_page_preview'],
                    'invert_media' => $text['show_above_text'],
                    'message' => $text['text']->text,
                    'entities' => $entities !== [] ? $entities : null,
                    'reply_markup' => $tlReplyMarkup,
                ]);

            case 'inputMessageRichMessage':
                $rich = $this->getInputRichContent(0, $content)['rich_message'];

                return self::tl('inputBotInlineMessageRichMessage', [
                    'reply_markup' => $tlReplyMarkup,
                    'rich_message' => $this->getInputRichMessageTl($rich, 0, 'Invalid inline message content specified'),
                ]);

            case 'inputMessageContact':
                $media = $this->getInputMessageContent($myId, $content)['media'];

                return self::tl('inputBotInlineMessageMediaContact', [
                    'phone_number' => $media['phone_number'], 'first_name' => $media['first_name'],
                    'last_name' => $media['last_name'], 'vcard' => $media['vcard'], 'reply_markup' => $tlReplyMarkup,
                ]);

            case 'inputMessageInvoice':
                $invoice = $this->processInputMessageInvoice($content, 0);

                return $this->getInputBotInlineMessageMediaInvoice($invoice, $tlReplyMarkup);

            case 'inputMessageLocation':
            case 'inputMessageLiveLocation':
                $media = $this->getInputMessageContent($myId, $content)['media'];
                $isLive = $content['_'] === 'inputMessageLiveLocation';

                return self::tl('inputBotInlineMessageMediaGeo', [
                    'geo_point' => $media['geo_point'],
                    'heading' => $isLive && $content['heading'] !== 0 ? $content['heading'] : null,
                    'period' => $isLive ? $content['live_period'] : null,
                    'proximity_notification_radius' => $isLive ? $content['proximity_alert_radius'] : null,
                    'reply_markup' => $tlReplyMarkup,
                ], ['proximity_notification_radius']);

            case 'inputMessageVenue':
                $media = $this->getInputMessageContent($myId, $content)['media'];

                return self::tl('inputBotInlineMessageMediaVenue', [
                    'geo_point' => $media['geo_point'], 'title' => $media['title'], 'address' => $media['address'],
                    'provider' => $media['provider'], 'venue_id' => $media['venue_id'], 'venue_type' => $media['venue_type'],
                    'reply_markup' => $tlReplyMarkup,
                ]);
        }

        if ($content['_'] === $allowedMediaContent) {
            $caption = $this->getOutgoingFormattedText($content['caption'] ?? new FormattedText(), true);
            $entities = $caption->inputEntities($inputUser);

            return self::tl('inputBotInlineMessageMediaAuto', [
                'invert_media' => (bool) ($content['show_caption_above_media'] ?? false),
                'message' => $caption->text,
                'entities' => $entities !== [] ? $entities : null,
                'reply_markup' => $tlReplyMarkup,
            ]);
        }

        throw new QueryError(400, 'Unallowed inline message content type');
    }

    /**
     * A double printed by td::StringBuilder: fixed notation with 6 decimals.
     */
    private static function formatDouble(float $value): string
    {
        return sprintf('%.6F', $value);
    }

    public function processSavePreparedKeyboardButtonQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $value = \LaraGram\Server\Support\TdJson::tryDecode($query->arg('button'));
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse keyboard button JSON object');
        }
        try {
            $button = self::json(fn (): array => $this->getKeyboardButton($value));
        } catch (QueryError $e) {
            throw QueryError::badRequest('Can\'t parse keyboard button: '.$e->getMessage());
        }

        // InlineQueriesManager::save_prepared_keyboard_button
        if ($userId <= 0) {
            throw new QueryError(400, 'User not found');
        }
        $inputButton = $this->getInputKeyboardButton($button, true);
        $result = $this->invokeOrFail('bots.requestWebViewButton', ['user_id' => $this->inputUser($userId), 'button' => $inputButton]);

        // Client::JsonPreparedKeyboardButton
        $query->answerQuery(['id' => (string) ($result['webapp_req_id'] ?? '')]);
    }

    public function processAnswerChatJoinRequestQueryQuery(Query $query): void
    {
        $queryId = \LaraGram\Server\Support\TdInteger::toInt64($query->arg('chat_join_request_query_id'));
        $result = match (strtolower(trim($query->arg('result')))) {
            'approve' => ['_' => 'joinChatBotResultApproved'],
            'decline' => ['_' => 'joinChatBotResultDeclined'],
            'queue' => ['_' => 'joinChatBotResultQueued'],
            default => throw QueryError::badRequest('Invalid query result specified'),
        };

        // DialogParticipantManager::set_join_chat_result
        $this->invokeOrFail('bots.setJoinChatResults', ['query_id' => $queryId, 'result' => $result]);
        $query->answerQuery(true);
    }

    public function processSendChatJoinRequestWebAppQuery(Query $query): void
    {
        $queryId = \LaraGram\Server\Support\TdInteger::toInt64($query->arg('chat_join_request_query_id'));
        $url = self::getRequiredStringArg($query, 'web_app_url');
        $url = self::cleanInputStringOrFail($url);

        // SetJoinChatResultsQuery with a URL
        $this->invokeOrFail('bots.setJoinChatResults', ['query_id' => $queryId, 'result' => ['_' => 'joinChatBotResultWebView', 'url' => $url]]);
        $query->answerQuery(true);
    }
}
