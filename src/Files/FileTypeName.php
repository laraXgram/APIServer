<?php

declare(strict_types=1);

namespace LaraGram\Server\Files;

/**
 * File type names as TDLib prints them in error messages.
 */
final class FileTypeName
{
    private const NAMES = [
        'Thumbnail', 'ChatPhoto', 'Photo', 'VoiceNote', 'Video', 'Document', 'Secret', 'Temp', 'Sticker', 'Audio',
        'Animation', 'SecretThumbnail', 'Wallpaper', 'VideoNote', 'Passport', 'Passport', 'Background',
        'DocumentAsFile', 'NotificationSound', 'CallLog', 'PhotoStory', 'VideoStory', 'SelfDestructingPhoto',
        'SelfDestructingVideo', 'SelfDestructingVideoNote', 'SelfDestructingVoiceNote', 'LivePhotoVideo',
        'SelfDestructingLivePhotoVideo',
    ];

    public static function of(int $fileType): string
    {
        return self::NAMES[$fileType] ?? '<invalid>';
    }
}
