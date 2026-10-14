<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonObject;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\TdJson;

/**
 * InputMedia JSON arguments: Client::get_input_media, get_input_message_contents,
 * get_input_thumbnail (JSON), get_input_photo/video/animation/audio/voice_note.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait ParsesMedia
{
    /**
     * Client::get_input_media (query argument): the content for editMessageMedia.
     *
     * @return array<string, mixed> td_api InputMessageContent
     */
    protected function getInputMediaArg(Query $query, string $field): array
    {
        $media = self::getRequiredStringArg($query, $field);
        $value = TdJson::tryDecode($media);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse input media JSON object');
        }

        try {
            return $this->getInputMediaJson($query, $value, false);
        } catch (JsonError|QueryError $e) {
            throw QueryError::badRequest('Can\'t parse InputMedia: '.$e->getMessage());
        }
    }

    /**
     * Client::get_input_message_contents: the contents of sendMediaGroup.
     *
     * @return list<array<string, mixed>>
     */
    protected function getInputMessageContents(Query $query, string $field): array
    {
        $media = self::getRequiredStringArg($query, $field);
        $value = TdJson::tryDecode($media);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse media JSON object');
        }

        return $this->parseArray($value, 'InputMedia', fn (JsonValue $item): array => $this->getInputMediaJson($query, $item, true));
    }

    /**
     * Client::get_input_media (JSON value).
     *
     * @return array<string, mixed>
     */
    protected function getInputMediaJson(Query $query, JsonValue $inputMedia, bool $forAlbum): array
    {
        if (!$inputMedia->isObject()) {
            throw new JsonError('expected an Object');
        }
        $object = $inputMedia->object();

        $inputCaption = $object->getOptionalStringField('caption');
        $parseMode = $object->getOptionalStringField('parse_mode');
        $entities = $object->extractField('caption_entities');
        $caption = $this->getFormattedText($inputCaption, $parseMode, $entities);
        $showCaptionAboveMedia = $object->getOptionalBoolField('show_caption_above_media');
        $hasSpoiler = $object->getOptionalBoolField('has_spoiler');
        $type = $object->getRequiredStringField('type');

        return $this->getInputMediaOfType($query, $object, $type, $caption, $showCaptionAboveMedia, $hasSpoiler, $forAlbum, false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getInputMediaOfType(Query $query, JsonObject $object, string $type, mixed $caption,
        bool $showCaptionAboveMedia, bool $hasSpoiler, bool $forAlbum, bool $forRichMessage): array
    {
        $media = $object->getOptionalStringField('media');
        $inputFile = $this->getInputFile($query, '', false, $media);
        if ($inputFile === null) {
            throw new JsonError('media not found');
        }
        $thumbnail = $this->getInputThumbnailFromJson($query, $object, true);

        if ($type === 'photo' || $type === 'live_photo') {
            return [
                '_' => 'inputMessagePhoto',
                'photo' => $this->getInputPhotoJson($query, $object, $inputFile, $type === 'live_photo'),
                'caption' => $caption, 'show_caption_above_media' => $showCaptionAboveMedia, 'has_spoiler' => $hasSpoiler,
            ];
        }
        if ($type === 'video') {
            return [
                '_' => 'inputMessageVideo',
                'video' => $this->getInputVideoJson($query, $object, $inputFile, $thumbnail),
                'caption' => $caption, 'show_caption_above_media' => $showCaptionAboveMedia, 'has_spoiler' => $hasSpoiler,
            ];
        }
        if ($type === 'animation') {
            if ($forAlbum) {
                throw new JsonError("type \"{$type}\" can't be used in sendMediaGroup");
            }

            return [
                '_' => 'inputMessageAnimation',
                'animation' => self::getInputAnimationJson($object, $inputFile, $thumbnail),
                'caption' => $caption, 'show_caption_above_media' => $showCaptionAboveMedia, 'has_spoiler' => $hasSpoiler,
            ];
        }
        if ($type === 'audio') {
            return ['_' => 'inputMessageAudio', 'audio' => self::getInputAudioJson($object, $inputFile, $thumbnail), 'caption' => $caption];
        }
        if ($type === 'document') {
            $disableContentTypeDetection = $object->getOptionalBoolField('disable_content_type_detection');

            return ['_' => 'inputMessageDocument', 'document' => [
                'document' => $inputFile, 'thumbnail' => $thumbnail,
                'disable_content_type_detection' => $disableContentTypeDetection || $forAlbum,
            ], 'caption' => $caption];
        }
        if ($type === 'voice_note') {
            if (!$forRichMessage) {
                throw new JsonError("type \"{$type}\" is not allowed");
            }

            return ['_' => 'inputMessageVoiceNote', 'voice_note' => [
                'voice_note' => $inputFile, 'duration' => max(0, min(self::MAX_DURATION, $object->getOptionalIntField('duration'))), 'waveform' => '',
            ], 'caption' => $caption];
        }

        throw new JsonError("type \"{$type}\" is unsupported");
    }

    /**
     * Client::get_input_thumbnail (JSON object).
     *
     * @return array<string, mixed>|null td_api InputFile of the thumbnail
     */
    protected function getInputThumbnailFromJson(Query $query, JsonObject $object, bool $allowLegacy): ?array
    {
        $thumbnail = $object->getOptionalStringField('thumbnail');
        if ($allowLegacy) {
            if ($thumbnail === '') {
                $thumbnail = $object->getOptionalStringField('thumb');
            }

            return $this->getInputFile($query, '', true, $thumbnail)
                ?? $this->getInputFile($query, 'thumbnail', true, '')
                ?? $this->getInputFile($query, 'thumb', true, '');
        }

        return $this->getInputFile($query, 'thumbnail', true, $thumbnail);
    }

    /**
     * Client::get_input_photo.
     *
     * @param array<string, mixed> $inputFile
     * @return array<string, mixed>
     */
    protected function getInputPhotoJson(Query $query, JsonObject $object, array $inputFile, bool $isLive): array
    {
        if ($isLive) {
            $photo = $object->getOptionalStringField('photo');
            $inputPhoto = $this->getInputFile($query, '', false, $photo);
            if ($inputPhoto === null) {
                throw new JsonError('Photo not found');
            }

            return ['photo' => $inputPhoto, 'video' => $inputFile];
        }

        return ['photo' => $inputFile, 'video' => null];
    }

    /**
     * Client::get_input_video.
     *
     * @param array<string, mixed> $inputFile
     * @param array<string, mixed>|null $thumbnail
     * @return array<string, mixed>
     */
    protected function getInputVideoJson(Query $query, JsonObject $object, array $inputFile, ?array $thumbnail): array
    {
        $width = $object->getOptionalIntField('width');
        $height = $object->getOptionalIntField('height');
        $duration = $object->getOptionalIntField('duration');
        $cover = $object->getOptionalStringField('cover');
        $startTimestamp = $object->getOptionalIntField('start_timestamp');
        $supportsStreaming = $object->getOptionalBoolField('supports_streaming');
        $inputCover = $this->getInputFile($query, '', false, $cover);

        return [
            'video' => $inputFile, 'thumbnail' => $thumbnail, 'cover' => $inputCover,
            'start_timestamp' => max(0, min(self::MAX_DURATION, $startTimestamp)),
            'duration' => max(0, min(self::MAX_DURATION, $duration)),
            'width' => max(0, min(self::MAX_LENGTH, $width)),
            'height' => max(0, min(self::MAX_LENGTH, $height)),
            'supports_streaming' => $supportsStreaming,
        ];
    }

    /**
     * Client::get_input_animation.
     *
     * @param array<string, mixed> $inputFile
     * @param array<string, mixed>|null $thumbnail
     * @return array<string, mixed>
     */
    protected static function getInputAnimationJson(JsonObject $object, array $inputFile, ?array $thumbnail): array
    {
        $width = $object->getOptionalIntField('width');
        $height = $object->getOptionalIntField('height');
        $duration = $object->getOptionalIntField('duration');

        return [
            'animation' => $inputFile, 'thumbnail' => $thumbnail,
            'duration' => max(0, min(self::MAX_DURATION, $duration)),
            'width' => max(0, min(self::MAX_LENGTH, $width)),
            'height' => max(0, min(self::MAX_LENGTH, $height)),
        ];
    }

    /**
     * Client::get_input_audio.
     *
     * @param array<string, mixed> $inputFile
     * @param array<string, mixed>|null $thumbnail
     * @return array<string, mixed>
     */
    protected static function getInputAudioJson(JsonObject $object, array $inputFile, ?array $thumbnail): array
    {
        $duration = $object->getOptionalIntField('duration');
        $title = $object->getOptionalStringField('title');
        $performer = $object->getOptionalStringField('performer');

        return [
            'audio' => $inputFile, 'album_cover_thumbnail' => $thumbnail,
            'duration' => max(0, min(self::MAX_DURATION, $duration)), 'title' => $title, 'performer' => $performer,
        ];
    }
}
