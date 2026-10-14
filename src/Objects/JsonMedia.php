<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Http\JsonFloat;

/**
 * Bot API media objects built from the td_api shapes of
 * {@see \LaraGram\Server\Messages\MediaConverter} (Client::JsonPhoto,
 * JsonDocument, JsonAudio, JsonVideo, JsonAnimation, JsonVoiceNote,
 * JsonVideoNote, JsonContact, JsonLocation, JsonVenue, JsonDice).
 */
final class JsonMedia
{
    /**
     * Client::json_store_file (without file_path; getFile adds it).
     *
     * @param array<string, mixed> $object
     * @param array{file_id: string, file_unique_id: string, size: int} $file
     */
    public static function storeFile(array &$object, array $file): void
    {
        $object['file_id'] = $file['file_id'];
        $object['file_unique_id'] = $file['file_unique_id'];
        if ($file['size'] !== 0) {
            $object['file_size'] = $file['size'];
        }
    }

    /**
     * Client::json_store_thumbnail: both `thumbnail` and the old `thumb`.
     *
     * @param array<string, mixed> $object
     * @param array<string, mixed>|null $thumbnail
     */
    public static function storeThumbnail(array &$object, ?array $thumbnail): void
    {
        if ($thumbnail === null) {
            return;
        }

        $value = self::photoSize($thumbnail);
        $object['thumbnail'] = $value;
        $object['thumb'] = $value;
    }

    /**
     * @param array<string, mixed> $size
     * @return array<string, mixed>
     */
    public static function photoSize(array $size): array
    {
        $object = [];
        self::storeFile($object, $size['file']);
        $object['width'] = $size['width'];
        $object['height'] = $size['height'];

        return $object;
    }

    /**
     * @param array{sizes: list<array<string, mixed>>} $photo
     * @return list<array<string, mixed>>
     */
    public static function photo(array $photo): array
    {
        $result = [];
        foreach ($photo['sizes'] as $size) {
            if ($size['type'] !== 'i' && $size['type'] !== 't' && $size['file']['file_id'] !== '') {
                $result[] = self::photoSize($size);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function document(array $document): array
    {
        $object = [];
        if ($document['file_name'] !== '') {
            $object['file_name'] = $document['file_name'];
        }
        if ($document['mime_type'] !== '') {
            $object['mime_type'] = $document['mime_type'];
        }
        self::storeThumbnail($object, $document['thumbnail']);
        self::storeFile($object, $document['file']);

        return $object;
    }

    /**
     * @param array<string, mixed> $animation
     * @return array<string, mixed>
     */
    public static function animation(array $animation, bool $asDocument): array
    {
        $object = [];
        if ($animation['file_name'] !== '') {
            $object['file_name'] = $animation['file_name'];
        }
        if ($animation['mime_type'] !== '') {
            $object['mime_type'] = $animation['mime_type'];
        }
        if (!$asDocument) {
            $object['duration'] = $animation['duration'];
            $object['width'] = $animation['width'];
            $object['height'] = $animation['height'];
        }
        self::storeThumbnail($object, $animation['thumbnail']);
        self::storeFile($object, $animation['file']);

        return $object;
    }

    /**
     * @param array<string, mixed> $audio
     * @return array<string, mixed>
     */
    public static function audio(array $audio): array
    {
        $object = ['duration' => $audio['duration']];
        if ($audio['file_name'] !== '') {
            $object['file_name'] = $audio['file_name'];
        }
        if ($audio['mime_type'] !== '') {
            $object['mime_type'] = $audio['mime_type'];
        }
        if ($audio['title'] !== '') {
            $object['title'] = $audio['title'];
        }
        if ($audio['performer'] !== '') {
            $object['performer'] = $audio['performer'];
        }
        self::storeThumbnail($object, $audio['thumbnail']);
        self::storeFile($object, $audio['file']);

        return $object;
    }

    /**
     * @param array<string, mixed> $video
     * @param array{sizes: list<array<string, mixed>>}|null $cover
     * @return array<string, mixed>
     */
    public static function video(array $video, ?array $cover = null, int $startTimestamp = 0, array $qualities = []): array
    {
        $object = ['duration' => $video['duration'], 'width' => $video['width'], 'height' => $video['height']];
        if ($video['file_name'] !== '') {
            $object['file_name'] = $video['file_name'];
        }
        if ($video['mime_type'] !== '') {
            $object['mime_type'] = $video['mime_type'];
        }
        if ($cover !== null) {
            $object['cover'] = self::photo($cover);
        }
        if ($startTimestamp > 0) {
            $object['start_timestamp'] = $startTimestamp;
        }
        if ($qualities !== []) {
            $object['qualities'] = array_map(static function (array $quality): array {
                $item = ['width' => $quality['width'], 'height' => $quality['height'], 'codec' => $quality['codec']];
                self::storeFile($item, $quality['file']);

                return $item;
            }, $qualities);
        }
        self::storeThumbnail($object, $video['thumbnail']);
        self::storeFile($object, $video['file']);

        return $object;
    }

    /**
     * Client::JsonLivePhoto.
     *
     * @param array{sizes: list<array<string, mixed>>} $photo
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
    public static function livePhoto(array $photo, array $video): array
    {
        $object = [
            'photo' => self::photo($photo),
            'duration' => $video['duration'],
            'width' => $video['width'],
            'height' => $video['height'],
        ];
        if ($video['mime_type'] !== '') {
            $object['mime_type'] = $video['mime_type'];
        }
        self::storeFile($object, $video['file']);

        return $object;
    }

    /**
     * @param array<string, mixed> $videoNote
     * @return array<string, mixed>
     */
    public static function videoNote(array $videoNote): array
    {
        $object = ['duration' => $videoNote['duration'], 'length' => $videoNote['width']];
        self::storeThumbnail($object, $videoNote['thumbnail']);
        self::storeFile($object, $videoNote['file']);

        return $object;
    }

    /**
     * @param array<string, mixed> $voiceNote
     * @return array<string, mixed>
     */
    public static function voiceNote(array $voiceNote): array
    {
        $object = ['duration' => $voiceNote['duration']];
        if ($voiceNote['mime_type'] !== '') {
            $object['mime_type'] = $voiceNote['mime_type'];
        }
        self::storeFile($object, $voiceNote['file']);

        return $object;
    }

    /**
     * @param array<string, mixed> $contact
     * @return array<string, mixed>
     */
    public static function contact(array $contact): array
    {
        $object = ['phone_number' => $contact['phone_number'], 'first_name' => $contact['first_name']];
        if ($contact['last_name'] !== '') {
            $object['last_name'] = $contact['last_name'];
        }
        if ($contact['vcard'] !== '') {
            $object['vcard'] = $contact['vcard'];
        }
        if ($contact['user_id'] !== 0) {
            $object['user_id'] = $contact['user_id'];
        }

        return $object;
    }

    /**
     * @param array{latitude: float, longitude: float, horizontal_accuracy: float} $location
     * @return array<string, mixed>
     */
    public static function location(array $location): array
    {
        $object = ['latitude' => new JsonFloat($location['latitude']), 'longitude' => new JsonFloat($location['longitude'])];
        if ($location['horizontal_accuracy'] > 0) {
            $object['horizontal_accuracy'] = new JsonFloat($location['horizontal_accuracy']);
        }

        return $object;
    }

    /**
     * Client::JsonLiveLocation; the live fields only while the location is
     * still being updated ($expiresIn > 0).
     *
     * @param array<string, mixed> $content td_api messageLiveLocation
     * @return array<string, mixed>
     */
    public static function liveLocation(array $content, int $expiresIn): array
    {
        $location = $content['location'];
        $object = ['latitude' => new JsonFloat($location['latitude']), 'longitude' => new JsonFloat($location['longitude'])];
        if ($expiresIn > 0) {
            $object['live_period'] = $content['live_period'];
            if ($content['heading'] > 0) {
                $object['heading'] = $content['heading'];
            }
            if ($content['proximity_alert_radius'] > 0) {
                $object['proximity_alert_radius'] = $content['proximity_alert_radius'];
            }
        }
        if ($location['horizontal_accuracy'] > 0) {
            $object['horizontal_accuracy'] = new JsonFloat($location['horizontal_accuracy']);
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $venue
     * @return array<string, mixed>
     */
    public static function venue(array $venue): array
    {
        $object = ['location' => self::location($venue['location']), 'title' => $venue['title'], 'address' => $venue['address']];
        if ($venue['provider'] === 'foursquare') {
            if ($venue['id'] !== '') {
                $object['foursquare_id'] = $venue['id'];
            }
            if ($venue['type'] !== '') {
                $object['foursquare_type'] = $venue['type'];
            }
        }
        if ($venue['provider'] === 'gplaces') {
            if ($venue['id'] !== '') {
                $object['google_place_id'] = $venue['id'];
            }
            if ($venue['type'] !== '') {
                $object['google_place_type'] = $venue['type'];
            }
        }

        return $object;
    }
}
