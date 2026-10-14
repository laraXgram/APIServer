<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Files\RemoteFileLocation;
use LaraGram\Server\Http\JsonFloat;

/**
 * The Bot API `Sticker` (Client::JsonSticker) from a classified document,
 * following StickersManager::create_sticker / get_sticker_object.
 */
final class JsonSticker
{
    private const MASK_POINTS = ['forehead', 'eyes', 'mouth', 'chin'];

    /**
     * @param array<string, mixed> $document a sticker from MediaConverter::document
     * @return array<string, mixed>
     */
    public static function make(array $document, Client $client): array
    {
        $info = self::info($document);

        $object = ['width' => $info['width'], 'height' => $info['height']];
        if ($info['emoji'] !== '') {
            $object['emoji'] = $info['emoji'];
        }
        $setName = $client->getStickerSetName($info['set_id']);
        if ($setName !== '') {
            $object['set_name'] = $setName;
        }
        $object['is_animated'] = $info['format'] === 'tgs';
        $object['is_video'] = $info['format'] === 'webm';

        switch ($info['type']) {
            case 'regular':
                $object['type'] = 'regular';
                if ($info['premium_animation'] !== null) {
                    $animation = [];
                    JsonMedia::storeFile($animation, $info['premium_animation']);
                    $object['premium_animation'] = $animation;
                }
                break;
            case 'mask':
                $object['type'] = 'mask';
                if ($info['mask_position'] !== null) {
                    $object['mask_position'] = $info['mask_position'];
                }
                break;
            case 'custom_emoji':
                $object['type'] = 'custom_emoji';
                $object['custom_emoji_id'] = (string) $info['custom_emoji_id'];
                if ($info['needs_repainting']) {
                    $object['needs_repainting'] = true;
                }
                break;
        }

        JsonMedia::storeThumbnail($object, $info['thumbnail']);
        JsonMedia::storeFile($object, $document['file']);

        return $object;
    }

    /**
     * The sticker set a sticker belongs to, or 0.
     *
     * @param array<string, mixed> $document
     */
    public static function setId(array $document): int
    {
        $attribute = $document['sticker'] ?? $document['custom_emoji'] ?? null;
        $set = $attribute['stickerset'] ?? null;

        return ($set['_'] ?? '') === 'inputStickerSetID' ? (int) $set['id'] : 0;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null the TL inputStickerSet of the sticker
     */
    public static function inputStickerSet(array $document): ?array
    {
        $attribute = $document['sticker'] ?? $document['custom_emoji'] ?? null;
        $set = $attribute['stickerset'] ?? null;

        return ($set['_'] ?? '') === 'inputStickerSetID' ? $set : null;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function info(array $document): array
    {
        $sticker = $document['sticker'];
        $customEmoji = $document['custom_emoji'];

        $format = match (true) {
            $document['mime_type'] === 'application/x-tgsticker' => 'tgs',
            $document['mime_type'] === 'video/webm' => 'webm',
            default => 'webp',
        };

        $width = $document['width'];
        $height = $document['height'];
        if ($format !== 'webp' && $width === 0) {
            $width = $height = $customEmoji !== null ? 100 : 512;
        }

        $thumbnail = $document['thumbnail'];
        if ($thumbnail !== null && !in_array($thumbnail['type'], ['m', 's', 't'], true)) {
            $thumbnail = null;
        }

        $type = 'regular';
        $maskPosition = null;
        if ($sticker !== null) {
            if (!empty($sticker['mask'])) {
                $type = 'mask';
                $coords = $sticker['mask_coords'] ?? null;
                if ($coords !== null && isset(self::MASK_POINTS[(int) $coords['n']])) {
                    $maskPosition = [
                        'point' => self::MASK_POINTS[(int) $coords['n']],
                        'x_shift' => new JsonFloat((float) $coords['x']),
                        'y_shift' => new JsonFloat((float) $coords['y']),
                        'scale' => new JsonFloat((float) $coords['zoom']),
                    ];
                }
            }
        } elseif ($customEmoji !== null) {
            $type = 'custom_emoji';
        }

        return [
            'width' => $width,
            'height' => $height,
            'emoji' => (string) ($sticker['alt'] ?? $customEmoji['alt'] ?? ''),
            'set_id' => self::setId($document),
            'format' => $format,
            'type' => $type,
            'mask_position' => $maskPosition,
            'custom_emoji_id' => $type === 'custom_emoji' ? self::documentId($document) : 0,
            'needs_repainting' => !empty($customEmoji['text_color']),
            'premium_animation' => $document['premium_animation'] ?? null,
            'thumbnail' => $thumbnail,
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function documentId(array $document): int
    {
        return RemoteFileLocation::fromFileId($document['file']['file_id'])->id;
    }
}
