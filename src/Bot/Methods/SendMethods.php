<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Support\JsonObject;

/**
 * sendMessage, sendPhoto, sendVideo, ... (Client::process_send_*_query).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait SendMethods
{
    /** Client::MAX_LENGTH: max width or height */
    private const MAX_LENGTH = 10000;

    /** Client::MAX_DURATION */
    private const MAX_DURATION = 24 * 60 * 60;

    public function processSendMessageQuery(Query $query): void
    {
        if ($this->isSendFloodLimited($query)) {
            return;
        }

        $this->doSendMessage(['_' => 'inputMessageText', 'clear_draft' => false] + $this->getInputMessageText($query), $query);
    }

    public function processSendRichMessageQuery(Query $query): void
    {
        if ($this->isSendFloodLimited($query)) {
            return;
        }

        $this->doSendMessage(['_' => 'inputMessageRichMessage', 'message' => $this->getInputRichMessage($query), 'clear_draft' => false], $query);
    }

    public function processSendAnimationQuery(Query $query): void
    {
        $animation = $this->getInputFile($query, 'animation');
        if ($animation === null) {
            throw QueryError::badRequest('There is no animation in the request');
        }
        $thumbnail = $this->getInputThumbnail($query);
        $duration = self::getIntegerArg($query, 'duration', 0, 0, self::MAX_DURATION);
        $width = self::getIntegerArg($query, 'width', 0, 0, self::MAX_LENGTH);
        $height = self::getIntegerArg($query, 'height', 0, 0, self::MAX_LENGTH);
        $caption = $this->getCaption($query);

        $this->doSendMessage([
            '_' => 'inputMessageAnimation',
            'animation' => ['animation' => $animation, 'thumbnail' => $thumbnail, 'duration' => $duration, 'width' => $width, 'height' => $height],
            'caption' => $caption,
            'show_caption_above_media' => self::boolArg($query, 'show_caption_above_media'),
            'has_spoiler' => self::boolArg($query, 'has_spoiler'),
        ], $query);
    }

    public function processSendAudioQuery(Query $query): void
    {
        $audio = $this->getInputFile($query, 'audio');
        if ($audio === null) {
            throw QueryError::badRequest('There is no audio in the request');
        }
        $thumbnail = $this->getInputThumbnail($query);
        $duration = self::getIntegerArg($query, 'duration', 0, 0, self::MAX_DURATION);
        $title = $query->arg('title');
        $performer = $query->arg('performer');
        $caption = $this->getCaption($query);

        $this->doSendMessage([
            '_' => 'inputMessageAudio',
            'audio' => ['audio' => $audio, 'album_cover_thumbnail' => $thumbnail, 'duration' => $duration, 'title' => $title, 'performer' => $performer],
            'caption' => $caption,
        ], $query);
    }

    public function processSendDiceQuery(Query $query): void
    {
        $this->doSendMessage(['_' => 'inputMessageDice', 'emoji' => $query->arg('emoji'), 'clear_draft' => false], $query);
    }

    public function processSendDocumentQuery(Query $query): void
    {
        $document = $this->getInputFile($query, 'document');
        if ($document === null) {
            throw QueryError::badRequest('There is no document in the request');
        }
        $thumbnail = $this->getInputThumbnail($query);
        $caption = $this->getCaption($query);

        $this->doSendMessage([
            '_' => 'inputMessageDocument',
            'document' => [
                'document' => $document,
                'thumbnail' => $thumbnail,
                'disable_content_type_detection' => self::boolArg($query, 'disable_content_type_detection'),
            ],
            'caption' => $caption,
        ], $query);
    }

    public function processSendPhotoQuery(Query $query): void
    {
        $photo = $this->getInputFile($query, 'photo');
        if ($photo === null) {
            throw QueryError::badRequest('There is no photo in the request');
        }
        $caption = $this->getCaption($query);

        $this->doSendMessage([
            '_' => 'inputMessagePhoto',
            'photo' => ['photo' => $photo, 'video' => null],
            'caption' => $caption,
            'show_caption_above_media' => self::boolArg($query, 'show_caption_above_media'),
            'has_spoiler' => self::boolArg($query, 'has_spoiler'),
        ], $query);
    }

    public function processSendLivePhotoQuery(Query $query): void
    {
        $livePhoto = $this->getInputFile($query, 'live_photo');
        if ($livePhoto === null) {
            throw QueryError::badRequest('There is no live photo in the request');
        }
        $photo = $this->getInputFile($query, 'photo');
        if ($photo === null) {
            throw QueryError::badRequest('There is no photo in the request');
        }
        $caption = $this->getCaption($query);

        $this->doSendMessage([
            '_' => 'inputMessagePhoto',
            'photo' => ['photo' => $photo, 'video' => $livePhoto],
            'caption' => $caption,
            'show_caption_above_media' => self::boolArg($query, 'show_caption_above_media'),
            'has_spoiler' => self::boolArg($query, 'has_spoiler'),
        ], $query);
    }

    public function processSendStickerQuery(Query $query): void
    {
        $sticker = $this->getInputFile($query, 'sticker');
        if ($sticker === null) {
            throw QueryError::badRequest('There is no sticker in the request');
        }

        $this->doSendMessage([
            '_' => 'inputMessageSticker',
            'sticker' => ['sticker' => $sticker, 'thumbnail' => null, 'width' => 0, 'height' => 0],
            'emoji' => $query->arg('emoji'),
        ], $query);
    }

    public function processSendVideoQuery(Query $query): void
    {
        $video = $this->getInputFile($query, 'video');
        if ($video === null) {
            throw QueryError::badRequest('There is no video in the request');
        }
        $thumbnail = $this->getInputThumbnail($query);
        $duration = self::getIntegerArg($query, 'duration', 0, 0, self::MAX_DURATION);
        $width = self::getIntegerArg($query, 'width', 0, 0, self::MAX_LENGTH);
        $height = self::getIntegerArg($query, 'height', 0, 0, self::MAX_LENGTH);
        $cover = $this->getInputFile($query, 'cover');
        $startTimestamp = self::getIntegerArg($query, 'start_timestamp', 0, 0, self::MAX_DURATION);
        $supportsStreaming = self::boolArg($query, 'supports_streaming');
        $caption = $this->getCaption($query);

        $this->doSendMessage([
            '_' => 'inputMessageVideo',
            'video' => [
                'video' => $video, 'thumbnail' => $thumbnail, 'cover' => $cover, 'start_timestamp' => $startTimestamp,
                'duration' => $duration, 'width' => $width, 'height' => $height, 'supports_streaming' => $supportsStreaming,
            ],
            'caption' => $caption,
            'show_caption_above_media' => self::boolArg($query, 'show_caption_above_media'),
            'has_spoiler' => self::boolArg($query, 'has_spoiler'),
        ], $query);
    }

    public function processSendVideoNoteQuery(Query $query): void
    {
        $videoNote = $this->getInputFile($query, 'video_note');
        if ($videoNote === null) {
            throw QueryError::badRequest('There is no video note in the request');
        }
        $thumbnail = $this->getInputThumbnail($query);
        $duration = self::getIntegerArg($query, 'duration', 0, 0, self::MAX_DURATION);
        $length = self::getIntegerArg($query, 'length', 0, 0, self::MAX_LENGTH);

        $this->doSendMessage([
            '_' => 'inputMessageVideoNote',
            'video_note' => ['video_note' => $videoNote, 'thumbnail' => $thumbnail, 'duration' => $duration, 'length' => $length],
        ], $query);
    }

    public function processSendVoiceQuery(Query $query): void
    {
        $voiceNote = $this->getInputFile($query, 'voice');
        if ($voiceNote === null) {
            throw QueryError::badRequest('There is no voice in the request');
        }
        $duration = self::getIntegerArg($query, 'duration', 0, 0, self::MAX_DURATION);
        $caption = $this->getCaption($query);

        $this->doSendMessage([
            '_' => 'inputMessageVoiceNote',
            'voice_note' => ['voice_note' => $voiceNote, 'duration' => $duration, 'waveform' => ''],
            'caption' => $caption,
        ], $query);
    }

    public function processSendLocationQuery(Query $query): void
    {
        $location = self::getLocation($query);
        $livePeriod = self::getIntegerArg($query, 'live_period', 0);
        $heading = self::getIntegerArg($query, 'heading', 0);
        $proximityAlertRadius = self::getIntegerArg($query, 'proximity_alert_radius', 0);

        if ($livePeriod !== 0) {
            $this->doSendMessage([
                '_' => 'inputMessageLiveLocation',
                'location' => $location,
                'live_period' => $livePeriod,
                'heading' => $heading,
                'proximity_alert_radius' => $proximityAlertRadius,
            ], $query);

            return;
        }

        $this->doSendMessage(['_' => 'inputMessageLocation', 'location' => $location], $query);
    }

    public function processSendVenueQuery(Query $query): void
    {
        $location = self::getLocation($query);
        $title = $query->arg('title');
        $address = $query->arg('address');
        $provider = $venueId = $venueType = '';

        $googlePlaceId = $query->arg('google_place_id');
        $googlePlaceType = $query->arg('google_place_type');
        if ($googlePlaceId !== '' || $googlePlaceType !== '') {
            $provider = 'gplaces';
            $venueId = $googlePlaceId;
            $venueType = $googlePlaceType;
        }
        $foursquareId = $query->arg('foursquare_id');
        $foursquareType = $query->arg('foursquare_type');
        if ($foursquareId !== '' || $foursquareType !== '') {
            $provider = 'foursquare';
            $venueId = $foursquareId;
            $venueType = $foursquareType;
        }

        $this->doSendMessage(['_' => 'inputMessageVenue', 'venue' => [
            'location' => $location, 'title' => $title, 'address' => $address,
            'provider' => $provider, 'id' => $venueId, 'type' => $venueType,
        ]], $query);
    }

    public function processSendContactQuery(Query $query): void
    {
        $phoneNumber = self::getRequiredStringArg($query, 'phone_number');
        $firstName = self::getRequiredStringArg($query, 'first_name');

        $this->doSendMessage(['_' => 'inputMessageContact', 'contact' => [
            'phone_number' => $phoneNumber,
            'first_name' => $firstName,
            'last_name' => $query->arg('last_name'),
            'vcard' => $query->arg('vcard'),
            'user_id' => 0,
        ]], $query);
    }

    /**
     * Client::get_location (query arguments).
     *
     * @return array{latitude: float, longitude: float, horizontal_accuracy: float}
     */
    protected static function getLocation(Query $query): array
    {
        $latitude = trim($query->arg('latitude'), " \t\r\n\0\x0B");
        if ($latitude === '') {
            throw new QueryError(400, 'Bad Request: latitude is empty');
        }
        $longitude = trim($query->arg('longitude'), " \t\r\n\0\x0B");
        if ($longitude === '') {
            throw new QueryError(400, 'Bad Request: longitude is empty');
        }
        $horizontalAccuracy = trim($query->arg('horizontal_accuracy'), " \t\r\n\0\x0B");

        return [
            'latitude' => JsonObject::toDouble($latitude),
            'longitude' => JsonObject::toDouble($longitude),
            'horizontal_accuracy' => JsonObject::toDouble($horizontalAccuracy),
        ];
    }

    /**
     * Client::get_location (JSON object).
     *
     * @return array{latitude: float, longitude: float, horizontal_accuracy: float}
     */
    protected static function getLocationFromJson(JsonObject $object): array
    {
        return [
            'latitude' => $object->getRequiredDoubleField('latitude'),
            'longitude' => $object->getRequiredDoubleField('longitude'),
            'horizontal_accuracy' => $object->getOptionalDoubleField('horizontal_accuracy'),
        ];
    }

    /**
     * Client::get_required_string_arg.
     */
    protected static function getRequiredStringArg(Query $query, string $field): string
    {
        $value = $query->arg($field);
        if ($value === '') {
            throw QueryError::badRequest("Parameter \"{$field}\" is required");
        }

        return $value;
    }

    public function processSendMediaGroupQuery(Query $query): void
    {
        $chatIdStr = $query->arg('chat_id');
        $forumTopicId = self::getForumTopicId($query, 'message_thread_id');
        $replyParameters = $this->getReplyParameters($query);
        $businessConnectionId = $query->arg('business_connection_id');
        $options = [
            'disable_notification' => self::boolArg($query, 'disable_notification'),
            'protect_content' => self::boolArg($query, 'protect_content'),
            'allow_paid_broadcast' => self::boolArg($query, 'allow_paid_broadcast'),
            'effect_id' => \LaraGram\Server\Support\TdInteger::toInt64($query->arg('message_effect_id')),
        ];
        $directMessagesTopicId = \LaraGram\Server\Support\TdInteger::toInt64($query->arg('direct_messages_topic_id'));
        $options['suggested_post_info'] = $this->getInputSuggestedPostInfo($query);
        $contents = $this->getInputMessageContents($query, 'media');

        if ($businessConnectionId !== '') {
            // Client::TdOnSendBusinessMessageAlbumCallback
            $messages = $this->sendBusinessMessageAlbum($businessConnectionId, $chatIdStr, $replyParameters,
                $options['disable_notification'], $options['protect_content'], $options['effect_id'], $contents);
            $query->answerQuery(array_map(fn (\LaraGram\Server\Messages\MessageInfo $message): array
                => \LaraGram\Server\Objects\JsonMessage::make($message, true, $this), $messages));

            return;
        }

        [$chatId, $topic, $replyTo] = $this->checkReplyParameters($chatIdStr, $replyParameters, $forumTopicId,
            $directMessagesTopicId, $options['allow_paid_broadcast']);

        $count = $this->yetUnsentMessageCount[$chatId] ?? 0;
        if ($count >= self::MAX_CONCURRENTLY_SENT_CHAT_MESSAGES) {
            $this->failQueryFloodLimitExceeded($query);

            return;
        }
        $messageCount = count($contents);
        $this->yetUnsentMessageCount[$chatId] = $count + $messageCount;

        try {
            $messages = $this->tdSendMessageAlbum($chatId, $topic, $replyTo, $options, $contents);
        } finally {
            $this->decreaseYetUnsentMessageCount($chatId, $messageCount);
        }

        $query->answerQuery(array_map(fn (\LaraGram\Server\Messages\MessageInfo $message): array
            => \LaraGram\Server\Objects\JsonMessage::make($message, true, $this), $messages));
    }
}
