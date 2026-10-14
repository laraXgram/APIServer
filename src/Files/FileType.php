<?php

declare(strict_types=1);

namespace LaraGram\Server\Files;

/**
 * td::FileType values, as stored in file identifiers.
 */
final class FileType
{
    public const THUMBNAIL = 0;
    public const PROFILE_PHOTO = 1;
    public const PHOTO = 2;
    public const VOICE_NOTE = 3;
    public const VIDEO = 4;
    public const DOCUMENT = 5;
    public const ENCRYPTED = 6;
    public const TEMP = 7;
    public const STICKER = 8;
    public const AUDIO = 9;
    public const ANIMATION = 10;
    public const ENCRYPTED_THUMBNAIL = 11;
    public const WALLPAPER = 12;
    public const VIDEO_NOTE = 13;
    public const SECURE_DECRYPTED = 14;
    public const SECURE_ENCRYPTED = 15;
    public const BACKGROUND = 16;
    public const DOCUMENT_AS_FILE = 17;
    public const RINGTONE = 18;
    public const CALL_LOG = 19;
    public const PHOTO_STORY = 20;
    public const VIDEO_STORY = 21;
    public const SELF_DESTRUCTING_PHOTO = 22;
    public const SELF_DESTRUCTING_VIDEO = 23;
    public const SELF_DESTRUCTING_VIDEO_NOTE = 24;
    public const SELF_DESTRUCTING_VOICE_NOTE = 25;
    public const LIVE_PHOTO = 26;
    public const SELF_DESTRUCTING_LIVE_PHOTO = 27;
    public const SIZE = 28;
    public const NONE = 29;

    /** td::FileTypeClass */
    public const CLASS_PHOTO = 0;
    public const CLASS_DOCUMENT = 1;
    public const CLASS_SECURE = 2;
    public const CLASS_ENCRYPTED = 3;
    public const CLASS_TEMP = 4;

    public static function typeClass(int $type): int
    {
        return match ($type) {
            self::PHOTO, self::PROFILE_PHOTO, self::THUMBNAIL, self::ENCRYPTED_THUMBNAIL, self::WALLPAPER,
            self::PHOTO_STORY, self::SELF_DESTRUCTING_PHOTO => self::CLASS_PHOTO,
            self::SECURE_DECRYPTED, self::SECURE_ENCRYPTED => self::CLASS_SECURE,
            self::ENCRYPTED => self::CLASS_ENCRYPTED,
            self::TEMP => self::CLASS_TEMP,
            default => self::CLASS_DOCUMENT,
        };
    }

    public static function isDocument(int $type): bool
    {
        return $type >= 0 && $type < self::SIZE && self::typeClass($type) === self::CLASS_DOCUMENT;
    }

    public static function isBackground(int $type): bool
    {
        return $type === self::WALLPAPER || $type === self::BACKGROUND;
    }
}
