<?php

declare(strict_types=1);

namespace LaraGram\Server\Files;

/**
 * td::PhotoSizeSource: where a photo-class file comes from. Stored as a
 * variant (int32 index, then the fields of that variant).
 */
final class PhotoSizeSource
{
    public const LEGACY = 0;
    public const THUMBNAIL = 1;
    public const DIALOG_PHOTO_SMALL = 2;
    public const DIALOG_PHOTO_BIG = 3;
    public const STICKER_SET_THUMBNAIL = 4;
    public const FULL_LEGACY = 5;
    public const DIALOG_PHOTO_SMALL_LEGACY = 6;
    public const DIALOG_PHOTO_BIG_LEGACY = 7;
    public const STICKER_SET_THUMBNAIL_LEGACY = 8;
    public const STICKER_SET_THUMBNAIL_VERSION = 9;

    private const VERSION_REMOVE_PHOTO_VOLUME_AND_LOCAL_ID = 32;

    /**
     * A thumbnail or photo size of a photo/document; $type is the size letter ('s', 'm', 'x', …).
     *
     * @return array<string, int>
     */
    public static function thumbnail(int $fileType, string|int $type): array
    {
        return ['type' => self::THUMBNAIL, 'file_type' => $fileType, 'thumbnail_type' => is_string($type) ? ord($type) : $type];
    }

    /**
     * A chat photo (small or big) of the dialog $dialogId.
     *
     * @return array<string, int>
     */
    public static function dialogPhoto(int $dialogId, int $dialogAccessHash, bool $isBig): array
    {
        return ['type' => $isBig ? self::DIALOG_PHOTO_BIG : self::DIALOG_PHOTO_SMALL, 'dialog_id' => $dialogId, 'dialog_access_hash' => $dialogAccessHash];
    }

    /**
     * @return array<string, int>
     */
    public static function stickerSetThumbnail(int $stickerSetId, int $stickerSetAccessHash, int $version): array
    {
        return ['type' => self::STICKER_SET_THUMBNAIL_VERSION, 'sticker_set_id' => $stickerSetId, 'sticker_set_access_hash' => $stickerSetAccessHash, 'version' => $version];
    }

    /**
     * @return array<string, int>
     */
    public static function fullLegacy(int $volumeId, int $localId, int $secret): array
    {
        return ['type' => self::FULL_LEGACY, 'volume_id' => $volumeId, 'local_id' => $localId, 'secret' => $secret];
    }

    /**
     * @param array<string, int> $source
     */
    public static function store(array $source): string
    {
        $out = TlBinary::int32($source['type']);

        return $out.match ($source['type']) {
            self::THUMBNAIL => TlBinary::int32($source['file_type']).TlBinary::int32($source['thumbnail_type']),
            self::DIALOG_PHOTO_SMALL, self::DIALOG_PHOTO_BIG => TlBinary::int64($source['dialog_id']).TlBinary::int64($source['dialog_access_hash']),
            self::STICKER_SET_THUMBNAIL => TlBinary::int64($source['sticker_set_id']).TlBinary::int64($source['sticker_set_access_hash']),
            self::FULL_LEGACY => TlBinary::int64($source['volume_id']).TlBinary::int64($source['secret']).TlBinary::int32($source['local_id']),
            self::DIALOG_PHOTO_SMALL_LEGACY, self::DIALOG_PHOTO_BIG_LEGACY => TlBinary::int64($source['dialog_id']).TlBinary::int64($source['dialog_access_hash'])
                .TlBinary::int64($source['volume_id']).TlBinary::int32($source['local_id']),
            self::STICKER_SET_THUMBNAIL_LEGACY => TlBinary::int64($source['sticker_set_id']).TlBinary::int64($source['sticker_set_access_hash'])
                .TlBinary::int64($source['volume_id']).TlBinary::int32($source['local_id']),
            self::STICKER_SET_THUMBNAIL_VERSION => TlBinary::int64($source['sticker_set_id']).TlBinary::int64($source['sticker_set_access_hash'])
                .TlBinary::int32($source['version']),
            default => throw new \LogicException('A legacy photo size source cannot be stored'),
        };
    }

    /**
     * @return array<string, int>
     */
    public static function parse(TlReader $reader, int $version): array
    {
        $type = $reader->int32();

        $source = match ($type) {
            self::LEGACY => ['type' => $type, 'secret' => $reader->int64()],
            self::THUMBNAIL => self::parseThumbnail($reader),
            self::DIALOG_PHOTO_SMALL, self::DIALOG_PHOTO_BIG => ['type' => $type, 'dialog_id' => $reader->int64(), 'dialog_access_hash' => $reader->int64()],
            self::STICKER_SET_THUMBNAIL => ['type' => $type, 'sticker_set_id' => $reader->int64(), 'sticker_set_access_hash' => $reader->int64()],
            self::FULL_LEGACY => ['type' => $type, 'volume_id' => $reader->int64(), 'secret' => $reader->int64(), 'local_id' => $reader->int32()],
            self::DIALOG_PHOTO_SMALL_LEGACY, self::DIALOG_PHOTO_BIG_LEGACY => self::checkLocalId([
                'type' => $type, 'dialog_id' => $reader->int64(), 'dialog_access_hash' => $reader->int64(),
                'volume_id' => $reader->int64(), 'local_id' => $reader->int32(),
            ]),
            self::STICKER_SET_THUMBNAIL_LEGACY => self::checkLocalId([
                'type' => $type, 'sticker_set_id' => $reader->int64(), 'sticker_set_access_hash' => $reader->int64(),
                'volume_id' => $reader->int64(), 'local_id' => $reader->int32(),
            ]),
            self::STICKER_SET_THUMBNAIL_VERSION => [
                'type' => $type, 'sticker_set_id' => $reader->int64(), 'sticker_set_access_hash' => $reader->int64(), 'version' => $reader->int32(),
            ],
            default => throw new \UnexpectedValueException('Invalid photo size source type'),
        };

        if ($version >= self::VERSION_REMOVE_PHOTO_VOLUME_AND_LOCAL_ID && ($type === self::LEGACY || $type === self::STICKER_SET_THUMBNAIL)) {
            throw new \UnexpectedValueException('Invalid photo size source stored');
        }

        return $source;
    }

    /**
     * PhotoSizeSource::get_unique: the size-specific part of a file_unique_id.
     *
     * @param array<string, int> $source
     */
    public static function unique(array $source): string
    {
        $compareType = self::compareType($source);
        if ($compareType !== 2 && $compareType !== 3) {
            return chr($compareType);
        }

        [$volumeId, $localId] = match ($source['type']) {
            self::STICKER_SET_THUMBNAIL_VERSION => [$source['sticker_set_id'], $source['version']],
            default => [$source['volume_id'], $source['local_id']],
        };

        return ($compareType === 2 ? "\x02" : '').TlBinary::int64($volumeId).TlBinary::int32($localId);
    }

    /**
     * @param array<string, int> $source
     */
    private static function compareType(array $source): int
    {
        return match ($source['type']) {
            self::THUMBNAIL => match ($source['thumbnail_type']) {
                ord('a') => 0,
                ord('c') => 1,
                default => $source['thumbnail_type'] + 5,
            },
            self::DIALOG_PHOTO_SMALL => 0,
            self::DIALOG_PHOTO_BIG => 1,
            self::FULL_LEGACY, self::DIALOG_PHOTO_SMALL_LEGACY, self::DIALOG_PHOTO_BIG_LEGACY, self::STICKER_SET_THUMBNAIL_LEGACY => 3,
            self::STICKER_SET_THUMBNAIL_VERSION => 2,
            default => throw new \LogicException('Photo size source has no unique form'),
        };
    }

    /**
     * @return array<string, int>
     */
    private static function parseThumbnail(TlReader $reader): array
    {
        $fileType = $reader->int32();
        if ($fileType < 0 || $fileType >= FileType::SIZE) {
            throw new \UnexpectedValueException('Wrong file type in PhotoSizeSource::Thumbnail');
        }

        return ['type' => self::THUMBNAIL, 'file_type' => $fileType, 'thumbnail_type' => $reader->int32()];
    }

    /**
     * @param array<string, int> $source
     * @return array<string, int>
     */
    private static function checkLocalId(array $source): array
    {
        if ($source['local_id'] < 0) {
            throw new \UnexpectedValueException('Wrong local_id');
        }

        return $source;
    }
}
