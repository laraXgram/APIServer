<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Objects\JsonChat;
use LaraGram\Server\Support\Emoji;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\TdJson;

/**
 * Stories of business accounts: postStory, repostStory, editStory and
 * deleteStory with the StoryManager parts they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait StoryMethods
{
    public function processPostStoryQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $content = $this->getInputStoryContentArg($query);
        $areas = $this->getInputStoryAreas($query);
        $caption = $this->getCaption($query);
        $connection = $this->checkBusinessConnection($businessConnectionId);
        $activePeriod = self::getIntegerArg($query, 'active_period', 0, 0, 1000000000);
        $isPinned = self::boolArg($query, 'post_to_chat_page');
        $protectContent = self::boolArg($query, 'protect_content');

        // StoryManager::send_story
        $chatId = $connection['user_id'];
        $this->checkStoryActivePeriod($activePeriod);
        $media = $this->getStoryInputMedia($content);
        $caption = $this->getOutgoingFormattedText($caption, true);
        $params = self::tl('stories.sendStory', [
            'pinned' => $isPinned,
            'noforwards' => $protectContent,
            'peer' => $this->inputPeer($chatId),
            'media' => $media,
            'media_areas' => $areas !== [] ? $areas : null,
            'caption' => $caption->text !== '' ? $caption->text : null,
            'entities' => $this->inputEntitiesOrNull($caption),
            'privacy_rules' => [['_' => 'inputPrivacyValueAllowAll']],
            'random_id' => self::randomId(),
            'period' => $activePeriod !== 86400 ? $activePeriod : null,
        ]);
        $result = $this->invokeOrFail('stories.sendStory', self::untl($params));

        // updateStorySendSucceeded + Client::JsonStory
        $storyId = self::getSentStoryId($result, $chatId, false) ?? throw new QueryError(500, 'Failed to post story');
        $query->answerQuery(['chat' => JsonChat::make($chatId, $this), 'id' => $storyId]);
    }

    public function processRepostStoryQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $fromChatIdStr = self::getRequiredStringArg($query, 'from_chat_id');
        $connection = $this->checkBusinessConnection($businessConnectionId);
        $chatId = $connection['user_id'];
        $fromChatId = $this->checkChat($fromChatIdStr, self::ACCESS_READ);
        $fromStoryId = self::getIntegerArg($query, 'story_id', 0, 0, 1000000000);
        $activePeriod = self::getIntegerArg($query, 'active_period', 0, 0, 1000000000);
        $isPinned = self::boolArg($query, 'post_to_chat_page');
        $protectContent = self::boolArg($query, 'protect_content');

        // StoryManager::send_story for a bot repost
        $this->checkStoryActivePeriod($activePeriod);
        if ($fromStoryId <= 0 || ChatId::type($fromChatId) !== ChatId::TYPE_PRIVATE) {
            throw new QueryError(400, 'Invalid story to repost specified');
        }
        // RepostBusinessStoryQuery
        $params = self::tl('stories.sendStory', [
            'pinned' => $isPinned,
            'noforwards' => $protectContent,
            'peer' => $this->inputPeer($chatId),
            'media' => ['_' => 'inputMediaEmpty'],
            'media_areas' => [],
            'caption' => '',
            'entities' => [],
            'privacy_rules' => [['_' => 'inputPrivacyValueAllowAll']],
            'random_id' => self::randomId(),
            'period' => $activePeriod !== 86400 ? $activePeriod : null,
            'fwd_from_id' => $this->inputPeer($fromChatId),
            'fwd_from_story' => $fromStoryId,
        ]);
        $result = $this->invokeOrFail('stories.sendStory', self::untl($params));

        $storyId = self::getSentStoryId($result, $chatId, true) ?? throw new QueryError(400, 'Failed to repost story');
        $query->answerQuery(['chat' => JsonChat::make($chatId, $this), 'id' => $storyId]);
    }

    public function processEditStoryQuery(Query $query): void
    {
        $businessConnectionId = $query->arg('business_connection_id');
        $content = $this->getInputStoryContentArg($query);
        $areas = $this->getInputStoryAreas($query);
        $caption = $this->getCaption($query);
        $connection = $this->checkBusinessConnection($businessConnectionId);
        $storyId = self::getIntegerArg($query, 'story_id', 0, 0, 1000000000);

        // StoryManager::edit_business_story
        $chatId = $connection['user_id'];
        if ($storyId <= 0) {
            throw new QueryError(400, 'Story can\'t be edited');
        }
        $media = $this->getStoryInputMedia($content);
        $caption = $this->getOutgoingFormattedText($caption, true);
        // EditBusinessStoryQuery
        $result = $this->invokeOrFail('stories.editStory', [
            'peer' => $this->inputPeer($chatId),
            'id' => $storyId,
            'media' => $media,
            'media_areas' => $areas,
            'caption' => $caption->text,
            'entities' => $this->inputEntitiesOrNull($caption) ?? [],
            'privacy_rules' => [['_' => 'inputPrivacyValueAllowAll']],
        ]);

        $editedStoryId = self::getSentStoryId($result, $chatId, true) ?? throw new QueryError(400, 'Failed to edit story');
        $query->answerQuery(['chat' => JsonChat::make($chatId, $this), 'id' => $editedStoryId]);
    }

    public function processDeleteStoryQuery(Query $query): void
    {
        $connection = $this->checkBusinessConnection($query->arg('business_connection_id'));
        $storyId = self::getIntegerArg($query, 'story_id', 0, 0, 1000000000);

        // BusinessConnectionManager::delete_business_story
        if ($storyId <= 0) {
            throw new QueryError(400, 'Invalid story identifier specified');
        }
        $this->invokeBusiness($connection, 'stories.deleteStories', ['peer' => $this->inputPeer($connection['user_id']), 'id' => [$storyId]]);
        $query->answerQuery(true);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Helpers
    // ═════════════════════════════════════════════════════════════════

    private function checkStoryActivePeriod(int $activePeriod): void
    {
        if ($activePeriod !== 86400 && !($this->isTestDc() && ($activePeriod === 60 || $activePeriod === 300))
            && !in_array($activePeriod, [6 * 3600, 12 * 3600, 2 * 86400], true)) {
            throw new QueryError(400, 'Invalid story active period specified');
        }
    }

    /**
     * UpdatesManager::extract_story: the identifier of the story in the result.
     */
    private static function getSentStoryId(mixed $result, int $chatId, bool $isBusiness): ?int
    {
        $updates = match ($result['_'] ?? '') {
            'updates', 'updatesCombined' => $result['updates'] ?? [],
            'updateShort' => [$result['update']],
            default => [],
        };
        if ($isBusiness && count($updates) !== 1 && count($updates) !== 2) {
            return null;
        }
        foreach ($updates as $update) {
            if (($update['_'] ?? '') === 'updateStory' && ChatId::fromPeer($update['peer'] ?? null) === $chatId
                && ($isBusiness || ($update['story']['_'] ?? '') === 'storyItem')) {
                return (int) $update['story']['id'];
            }
        }

        return null;
    }

    /**
     * Client::get_input_story_content(query).
     *
     * @return array<string, mixed>
     */
    private function getInputStoryContentArg(Query $query): array
    {
        $content = $query->arg('content');
        if ($content === '') {
            throw QueryError::badRequest('Story content isn\'t specified');
        }
        $value = TdJson::tryDecode($content);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse story content JSON object');
        }

        return self::json(function () use ($query, $value): array {
            if (!$value->isObject()) {
                throw QueryError::badRequest('Object expected as story content');
            }
            $object = $value->object();
            $type = $object->getRequiredStringField('type');
            if ($type === 'photo') {
                $file = $this->getInputFile($query, '', true, $object->getRequiredStringField('photo'));
                if ($file === null) {
                    throw QueryError::badRequest('Story photo must be uploaded as a file');
                }

                return ['_' => 'photo', 'file' => $file];
            }
            if ($type === 'video') {
                $video = $object->getRequiredStringField('video');
                $duration = $object->getOptionalDoubleField('duration');
                $coverFrameTimestamp = $object->getOptionalDoubleField('cover_frame_timestamp');
                $isAnimation = $object->getOptionalBoolField('is_animation');
                $file = $this->getInputFile($query, '', true, $video);
                if ($file === null) {
                    throw QueryError::badRequest('Story video must be uploaded as a file');
                }

                return ['_' => 'video', 'file' => $file, 'duration' => $duration, 'cover_frame_timestamp' => $coverFrameTimestamp,
                    'is_animation' => $isAnimation];
            }

            throw QueryError::badRequest('Invalid story content type specified');
        });
    }

    /**
     * get_input_story_content + get_story_content_input_media with the file uploaded.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function getStoryInputMedia(array $content): array
    {
        if ($content['_'] === 'photo') {
            $file = $this->getInputFileId(FileType::PHOTO, $content['file']);

            return ['_' => 'inputMediaUploadedPhoto', 'file' => $this->uploadStoryFile($file)];
        }

        $file = $this->getInputFileId(FileType::VIDEO, $content['file']);
        if ($content['duration'] < 0 || $content['duration'] > 60.0) {
            throw new QueryError(400, 'Invalid video duration specified');
        }
        if ($content['cover_frame_timestamp'] < 0.0) {
            throw new QueryError(400, 'Wrong cover timestamp specified');
        }

        return [
            '_' => 'inputMediaUploadedDocument',
            'nosound_video' => true,
            'file' => $this->uploadStoryFile($file),
            'mime_type' => 'video/mp4',
            'attributes' => [
                self::tl('documentAttributeVideo', [
                    'supports_streaming' => true,
                    'nosound' => $content['is_animation'],
                    'duration' => (float) $content['duration'],
                    'w' => 720,
                    'h' => 1280,
                    'video_start_ts' => $content['cover_frame_timestamp'] > 0.0 ? (float) $content['cover_frame_timestamp'] : null,
                ]),
                ['_' => 'documentAttributeFilename', 'file_name' => 'story.mp4'],
            ],
        ];
    }

    /**
     * A story file is always uploaded (StoryManager::on_upload_story reuploads files already on the server).
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function uploadStoryFile(array $file): array
    {
        if ($file['kind'] !== 'local') {
            throw new QueryError(400, 'Can\'t use web photo as a story');
        }

        return $this->uploadFile($file);
    }

    /**
     * Client::get_input_story_areas + MediaArea::get_media_areas + get_input_media_areas.
     *
     * @return list<array<string, mixed>>
     */
    private function getInputStoryAreas(Query $query): array
    {
        $areas = $query->arg('areas');
        if ($areas === '') {
            return [];
        }
        $value = TdJson::tryDecode($areas);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse story areas JSON object');
        }
        $parsed = $this->parseArray($value, 'InputStoryArea', fn (JsonValue $area): ?array => self::getInputStoryArea($area));

        return array_values(array_filter($parsed));
    }

    /**
     * Client::get_input_story_area + MediaArea(td, inputStoryArea): the TL MediaArea, or null when unusable.
     *
     * @return array<string, mixed>|null
     */
    private static function getInputStoryArea(JsonValue $value): ?array
    {
        if (!$value->isObject()) {
            throw new JsonError('expected an Object');
        }
        $object = $value->object();
        $position = $object->extractRequiredField('position', TdJson::OBJECT)->object();
        $coordinates = [
            $position->getRequiredDoubleField('x_percentage'),
            $position->getRequiredDoubleField('y_percentage'),
            $position->getRequiredDoubleField('width_percentage'),
            $position->getRequiredDoubleField('height_percentage'),
            $position->getRequiredDoubleField('rotation_angle'),
            $position->getRequiredDoubleField('corner_radius_percentage'),
        ];
        $type = $object->extractRequiredField('type', TdJson::OBJECT)->object();
        $area = self::getInputStoryAreaType($type);

        // MediaAreaCoordinates
        $fix = static fn (float $value, float $min = 0.0, float $max = 100.0): float => is_finite($value) ? max($min, min($max, $value)) : 0.0;
        [$x, $y, $width, $height, $rotation, $radius] = $coordinates;
        $rotation = $fix($rotation, -360.0, 360.0);
        if ($rotation < 0) {
            $rotation += 360.0;
        }
        $mediaCoordinates = self::tl('mediaAreaCoordinates', [
            'x' => $fix($x), 'y' => $fix($y), 'w' => $fix($width), 'h' => $fix($height), 'rotation' => $rotation,
            'radius' => $fix($radius) > 0 ? $fix($radius) : null,
        ]);
        if ($mediaCoordinates['w'] <= 0.0 || $mediaCoordinates['h'] <= 0.0 || $area === null) {
            return null;
        }

        return ['_' => $area['_'], 'coordinates' => $mediaCoordinates] + $area;
    }

    /**
     * Client::get_input_story_area_type + MediaArea validation.
     *
     * @return array<string, mixed>|null
     */
    private static function getInputStoryAreaType(\LaraGram\Server\Support\JsonObject $object): ?array
    {
        $type = $object->getRequiredStringField('type');
        if ($type === 'location') {
            $location = self::getLocationFromJson($object);
            $address = null;
            $addressValue = $object->extractOptionalField('address', TdJson::OBJECT);
            if ($addressValue->isObject()) {
                $addressObject = $addressValue->object();
                $address = [
                    'country_iso2' => $addressObject->getRequiredStringField('country_code'),
                    'state' => $addressObject->getOptionalStringField('state'),
                    'city' => $addressObject->getOptionalStringField('city'),
                    'street' => $addressObject->getOptionalStringField('street'),
                ];
                foreach ($address as $part) {
                    if (TextFormatting::cleanInputString($part) !== $part) {
                        return null;
                    }
                }
            }
            if (!self::isValidLocation($location)) {
                return null;
            }

            return self::tl('mediaAreaGeoPoint', [
                'geo' => self::tl('geoPoint', [
                    'long' => $location['longitude'],
                    'lat' => $location['latitude'],
                    'access_hash' => 0,
                    'accuracy_radius' => $location['horizontal_accuracy'] > 0 ? (int) ceil($location['horizontal_accuracy']) : null,
                ], ['access_hash']),
                'address' => $address === null ? null : self::tl('geoPointAddress', [
                    'country_iso2' => $address['country_iso2'],
                    'state' => $address['state'] !== '' ? $address['state'] : null,
                    'city' => $address['city'] !== '' ? $address['city'] : null,
                    'street' => $address['street'] !== '' ? $address['street'] : null,
                ]),
            ]);
        }
        if ($type === 'suggested_reaction') {
            $reactionType = $object->extractRequiredField('reaction_type', TdJson::OBJECT);
            try {
                $reaction = self::getReactionType($reactionType);
            } catch (QueryError $e) {
                throw new JsonError('can\'t parse ReactionType: '.$e->getMessage());
            }
            $isDark = $object->getOptionalBoolField('is_dark');
            $isFlipped = $object->getOptionalBoolField('is_flipped');

            return self::tl('mediaAreaSuggestedReaction', ['dark' => $isDark, 'flipped' => $isFlipped, 'reaction' => $reaction]);
        }
        if ($type === 'link') {
            $url = $object->getRequiredStringField('url');
            $clean = TextFormatting::cleanInputString($url);

            return $clean === null ? null : ['_' => 'mediaAreaUrl', 'url' => $clean];
        }
        if ($type === 'weather') {
            $temperature = $object->getRequiredDoubleField('temperature');
            $emoji = $object->getRequiredStringField('emoji');
            $backgroundColor = $object->getRequiredLongField('background_color');
            if ($backgroundColor < 0 || $backgroundColor > 0xFFFFFFFF) {
                throw new JsonError('invalid background color specified');
            }
            $color = $backgroundColor > 0x7FFFFFFF ? $backgroundColor - 0x100000000 : $backgroundColor;
            if (TextFormatting::cleanInputString($emoji) !== $emoji || !Emoji::isEmoji($emoji) || !is_finite($temperature)) {
                return null;
            }

            return ['_' => 'mediaAreaWeather', 'emoji' => $emoji, 'temperature_c' => $temperature, 'color' => $color];
        }
        if ($type === 'unique_gift') {
            $name = $object->getRequiredStringField('name');
            $clean = TextFormatting::cleanInputString($name);

            return $clean === null || $clean === '' ? null : ['_' => 'mediaAreaStarGift', 'slug' => $clean];
        }

        throw new JsonError('invalid story area type specified');
    }
}
