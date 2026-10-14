<?php

declare(strict_types=1);

namespace LaraGram\Server\Messages;

use LaraGram\Server\Support\FileName;
use LaraGram\Server\Support\MimeType;

use LaraGram\Server\Files\FileType;
use LaraGram\Server\Files\PhotoSizeSource;
use LaraGram\Server\Files\RemoteFileLocation;

/**
 * TL photos and documents to TDLib's td_api shapes, with Bot API file
 * identifiers: Photo.cpp/PhotoSize.cpp (get_photo, get_photo_size,
 * get_photo_sizes_object) and DocumentsManager::on_get_document.
 *
 * A file is ['file_id' => …, 'file_unique_id' => …, 'size' => int].
 */
final class MediaConverter
{
    public const DOCUMENT_GENERAL = 'document';
    public const DOCUMENT_ANIMATION = 'animation';
    public const DOCUMENT_AUDIO = 'audio';
    public const DOCUMENT_STICKER = 'sticker';
    public const DOCUMENT_VIDEO = 'video';
    public const DOCUMENT_VIDEO_NOTE = 'video_note';
    public const DOCUMENT_VOICE_NOTE = 'voice_note';

    /**
     * td_api::photo: the sizes Bot API clients see, smallest first.
     *
     * @param array<string, mixed> $photo TL photo
     * @return array{sizes: list<array<string, mixed>>}|null
     */
    public static function photo(array $photo, int $fileType = FileType::PHOTO): ?array
    {
        if (($photo['_'] ?? '') !== 'photo') {
            return null;
        }

        $sizes = [];
        foreach ($photo['sizes'] ?? [] as $size) {
            $converted = self::photoSize($size, $fileType, (int) $photo['id'], (int) $photo['access_hash'],
                (string) ($photo['file_reference'] ?? ''), (int) $photo['dc_id'], $fileType);
            if ($converted === null || in_array($converted['type'], ['', 't', 'i', 'p', 'u', 'v'], true)) {
                continue;
            }
            $sizes[] = $converted;
        }

        // get_photo_sizes_object: stable order by file size, then pixel count.
        $indexed = array_map(null, $sizes, array_keys($sizes));
        usort($indexed, static function (array $a, array $b): int {
            [$l, $li] = $a;
            [$r, $ri] = $b;

            return [$l['file']['size'], $l['width'] * $l['height'], $li] <=> [$r['file']['size'], $r['width'] * $r['height'], $ri];
        });

        return ['sizes' => array_column($indexed, 0)];
    }

    /**
     * td::get_photo_size; null for empty, stripped and path sizes.
     *
     * @param array<string, mixed> $size TL PhotoSize
     * @return array{type: string, file: array<string, mixed>, width: int, height: int}|null
     */
    public static function photoSize(array $size, int $sourceFileType, int $id, int $accessHash, string $fileReference, int $dcId, int $locationFileType): ?array
    {
        switch ($size['_'] ?? '') {
            case 'photoSize':
                $bytes = (int) $size['size'];
                break;
            case 'photoCachedSize':
                $bytes = strlen((string) $size['bytes']);
                break;
            case 'photoSizeProgressive':
                if (empty($size['sizes'])) {
                    return null;
                }
                $bytes = (int) max($size['sizes']);
                break;
            default:
                return null;
        }

        $type = (string) ($size['type'] ?? '');
        $typeCode = strlen($type) === 1 && ord($type) < 128 ? ord($type) : 0;
        if ($bytes < 0 || $bytes > 1000000000) {
            $bytes = 0;
        }
        [$width, $height] = self::dimensions((int) ($size['w'] ?? 0), (int) ($size['h'] ?? 0));

        $location = RemoteFileLocation::photo($locationFileType, $dcId, $id, $accessHash, $fileReference,
            PhotoSizeSource::thumbnail($sourceFileType, $typeCode));

        $file = self::file($location, $bytes);
        self::addFileHint($file['file_unique_id'], 'jpg', $bytes);

        return [
            'type' => $typeCode === 0 ? '' : chr($typeCode),
            'file' => $file,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * DocumentsManager::on_get_document: classify a TL document and build its td_api object.
     *
     * @param array<string, mixed> $document TL document
     * @return array<string, mixed>|null ['kind' => self::DOCUMENT_*, ...fields]
     */
    public static function document(array $document, bool $isSelfDestructing = false, bool $defaultVideo = false, bool $isLivePhoto = false,
        string $subtype = ''): ?array
    {
        if (($document['_'] ?? '') !== 'document') {
            return null;
        }

        $animated = $video = $audio = $sticker = $customEmoji = null;
        $width = $height = 0;
        $fileName = '';
        $typeAttributes = 0;

        foreach ($document['attributes'] ?? [] as $attribute) {
            switch ($attribute['_'] ?? '') {
                case 'documentAttributeImageSize':
                    [$width, $height] = self::dimensions((int) $attribute['w'], (int) $attribute['h']);
                    break;
                case 'documentAttributeAnimated':
                    $animated = $attribute;
                    $typeAttributes++;
                    break;
                case 'documentAttributeSticker':
                    $sticker = $attribute;
                    $typeAttributes++;
                    break;
                case 'documentAttributeVideo':
                    $video = $attribute;
                    $typeAttributes++;
                    break;
                case 'documentAttributeAudio':
                    $audio = $attribute;
                    $typeAttributes++;
                    break;
                case 'documentAttributeFilename':
                    $fileName = (string) $attribute['file_name'];
                    break;
                case 'documentAttributeCustomEmoji':
                    $customEmoji = $attribute;
                    $typeAttributes++;
                    break;
            }
        }

        $videoDuration = 0;
        $waveform = '';
        if ($video !== null) {
            $videoDuration = (int) ceil((float) $video['duration']);
            [$videoWidth, $videoHeight] = self::dimensions((int) $video['w'], (int) $video['h']);
            if ($width === 0 || ($videoWidth !== 0 && [$videoWidth, $videoHeight] !== [$width, $height])) {
                [$width, $height] = [$videoWidth, $videoHeight];
            }
            if ($audio !== null) {
                $waveform = (string) ($audio['waveform'] ?? '');
                $typeAttributes--;
                $audio = null;
            }
            if ($animated !== null && $defaultVideo) {
                $typeAttributes--;
                $animated = null;
            }
            if ($animated !== null) {
                $typeAttributes--;
                if (!empty($video['round_message'])) {
                    $animated = null;
                } elseif ($sticker !== null || $customEmoji !== null) {
                    $typeAttributes--;
                    $animated = null;
                    $video = null;
                } else {
                    $video = null;
                }
            } elseif ($sticker !== null || $customEmoji !== null) {
                $typeAttributes--;
                $video = null;
            }
        }
        if ($animated !== null && $audio !== null) {
            $typeAttributes--;
            $audio = null;
        }
        if ($animated !== null && $sticker !== null) {
            $typeAttributes--;
            $sticker = null;
        }
        if ($animated !== null && $customEmoji !== null) {
            $typeAttributes--;
            $customEmoji = null;
        }

        $kind = self::DOCUMENT_GENERAL;
        $fileType = FileType::DOCUMENT;
        if ($typeAttributes === 1 || $defaultVideo) {
            if ($animated !== null) {
                $kind = self::DOCUMENT_ANIMATION;
                $fileType = FileType::ANIMATION;
            } elseif ($audio !== null) {
                if (!empty($audio['voice'])) {
                    $kind = self::DOCUMENT_VOICE_NOTE;
                    $fileType = $isSelfDestructing ? FileType::SELF_DESTRUCTING_VOICE_NOTE : FileType::VOICE_NOTE;
                    $fileName = '';
                } else {
                    $kind = self::DOCUMENT_AUDIO;
                    $fileType = FileType::AUDIO;
                }
            } elseif ($sticker !== null || $customEmoji !== null) {
                $kind = self::DOCUMENT_STICKER;
                $fileType = FileType::STICKER;
                $fileName = '';
            } elseif ($video !== null || $defaultVideo) {
                if (!empty($video['round_message'])) {
                    $kind = self::DOCUMENT_VIDEO_NOTE;
                    $fileType = $isSelfDestructing ? FileType::SELF_DESTRUCTING_VIDEO_NOTE : FileType::VIDEO_NOTE;
                    $fileName = '';
                } else {
                    $kind = self::DOCUMENT_VIDEO;
                    $fileType = $isLivePhoto
                        ? ($isSelfDestructing ? FileType::SELF_DESTRUCTING_LIVE_PHOTO : FileType::LIVE_PHOTO)
                        : ($isSelfDestructing ? FileType::SELF_DESTRUCTING_VIDEO : FileType::VIDEO);
                }
            }
        }

        $id = (int) $document['id'];
        $accessHash = (int) $document['access_hash'];
        $dcId = (int) $document['dc_id'];
        $fileReference = (string) ($document['file_reference'] ?? '');
        $mimeType = (string) ($document['mime_type'] ?? '');

        if ($mimeType === 'application/x-tgsticker' && $kind === self::DOCUMENT_GENERAL) {
            $kind = self::DOCUMENT_STICKER;
            $fileType = FileType::STICKER;
            $fileName = '';
        }
        // DocumentsManager::Subtype::Background and Subtype::Pattern
        $defaultExtension = '';
        if ($subtype === 'background' || $subtype === 'pattern') {
            $kind = self::DOCUMENT_GENERAL;
            $fileType = FileType::BACKGROUND;
            $defaultExtension = $subtype === 'pattern' ? 'png' : 'jpg';
        }

        $thumbnail = null;
        if ($kind !== self::DOCUMENT_VOICE_NOTE) {
            foreach ($document['thumbs'] ?? [] as $thumb) {
                $converted = self::photoSize($thumb, FileType::THUMBNAIL, $id, $accessHash, $fileReference, $dcId, FileType::THUMBNAIL);
                if ($converted !== null) {
                    $thumbnail = $converted;
                    break;
                }
            }
        }

        // Fix for wrong dimensions sent by the Android application.
        if ($width !== 0 && $thumbnail !== null && $thumbnail['width'] !== 0) {
            if (($thumbnail['width'] < $thumbnail['height'] && $width > $height)
                || ($thumbnail['width'] > $thumbnail['height'] && $width < $height)) {
                [$width, $height] = [$height, $width];
            }
        }

        $premiumAnimation = null;
        foreach ($document['video_thumbs'] ?? [] as $videoSize) {
            if (($videoSize['_'] ?? '') === 'videoSize' && ($videoSize['type'] ?? '') === 'f') {
                $premiumAnimation = self::file(RemoteFileLocation::photo(FileType::THUMBNAIL, $dcId, $id, $accessHash, $fileReference,
                    PhotoSizeSource::thumbnail(FileType::THUMBNAIL, 'f')), (int) ($videoSize['size'] ?? 0));
                break;
            }
        }

        $location = RemoteFileLocation::common($fileType, $dcId, $id, $accessHash, $fileReference);
        $file = self::file($location, (int) ($document['size'] ?? 0));
        $cleanName = self::stripEmptyCharacters($fileName);
        $extension = $cleanName !== '' ? FileName::split(FileName::clean($cleanName))[1] : MimeType::toExtension($mimeType, match ($kind) {
            self::DOCUMENT_ANIMATION, self::DOCUMENT_VIDEO, self::DOCUMENT_VIDEO_NOTE => 'mp4',
            self::DOCUMENT_VOICE_NOTE => 'oga',
            self::DOCUMENT_AUDIO => 'mp3',
            self::DOCUMENT_STICKER => $mimeType === 'application/x-tgsticker' ? 'tgs' : ($mimeType === 'video/webm' ? 'webm' : 'webp'),
            default => $defaultExtension,
        });
        self::addFileHint($file['file_unique_id'], $extension, (int) ($document['size'] ?? 0));

        return [
            'kind' => $kind,
            'file' => $file,
            'file_name' => $cleanName,
            'mime_type' => $mimeType,
            'thumbnail' => $thumbnail,
            'width' => $width,
            'height' => $height,
            'duration' => match ($kind) {
                self::DOCUMENT_AUDIO, self::DOCUMENT_VOICE_NOTE => (int) ($audio['duration'] ?? 0),
                default => $videoDuration,
            },
            'title' => (string) ($audio['title'] ?? ''),
            'performer' => (string) ($audio['performer'] ?? ''),
            'waveform' => $kind === self::DOCUMENT_VOICE_NOTE ? (string) ($audio['waveform'] ?? '') : $waveform,
            'sticker' => $sticker,
            'custom_emoji' => $customEmoji,
            'video' => $video,
            'premium_animation' => $kind === self::DOCUMENT_STICKER ? $premiumAnimation : null,
            'id' => $id,
        ];
    }

    /**
     * The StickerFormat of a sticker document: webp, tgs or webm.
     *
     * @param array<string, mixed> $document
     */
    public static function stickerFormat(array $document): string
    {
        return match ($document['mime_type']) {
            'application/x-tgsticker' => 'tgs',
            'video/webm' => 'webm',
            default => 'webp',
        };
    }

    /**
     * get_photo_size of a sticker set thumbnail (PhotoSizeSource::sticker_set_thumbnail).
     *
     * @param array<string, mixed> $size TL PhotoSize
     * @param array<string, int> $source
     * @return array{type: string, file: array<string, mixed>, width: int, height: int}|null
     */
    public static function stickerSetThumbnailSize(array $size, array $source, int $dcId, string $format): ?array
    {
        $bytes = match ($size['_'] ?? '') {
            'photoSize' => (int) $size['size'],
            'photoCachedSize' => strlen((string) $size['bytes']),
            'photoSizeProgressive' => empty($size['sizes']) ? null : (int) max($size['sizes']),
            default => null,
        };
        if ($bytes === null) {
            return null;
        }
        if ($bytes < 0 || $bytes > 1000000000) {
            $bytes = 0;
        }
        $type = (string) ($size['type'] ?? '');
        [$width, $height] = self::dimensions((int) ($size['w'] ?? 0), (int) ($size['h'] ?? 0));
        $location = RemoteFileLocation::photo(FileType::THUMBNAIL, $dcId, 0, 0, '', $source);
        $file = self::file($location, $bytes);
        self::addFileHint($file['file_unique_id'], $format, $bytes);

        return ['type' => $type, 'file' => $file, 'width' => $width, 'height' => $height];
    }

    /**
     * VideosManager::get_live_photo_video_file_id.
     *
     * @param array<string, mixed> $document TL document
     * @return array<string, mixed>|null
     */
    public static function livePhotoVideo(array $document, bool $isSelfDestructing = false): ?array
    {
        $video = self::document($document, $isSelfDestructing, true, true);

        return $video !== null && $video['kind'] === self::DOCUMENT_VIDEO ? $video : null;
    }

    /** Most file hints kept in memory. */
    private const MAX_FILE_HINTS = 100000;

    /** @var array<string, array{0: string, 1: int}> file unique id → [suggested file name extension, size] */
    private static array $fileHints = [];

    /**
     * The extension of the name TDLib registers a remote file with
     * (DocumentsManager::on_get_document, register_photo_size) and its size.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function fileHint(string $uniqueId): ?array
    {
        return self::$fileHints[$uniqueId] ?? null;
    }

    private static function addFileHint(string $uniqueId, string $extension, int $size): void
    {
        unset(self::$fileHints[$uniqueId]);
        self::$fileHints[$uniqueId] = [$extension, $size];
        if (count(self::$fileHints) > self::MAX_FILE_HINTS) {
            unset(self::$fileHints[array_key_first(self::$fileHints)]);
        }
    }

    /**
     * @return array{file_id: string, file_unique_id: string, size: int}
     */
    public static function file(RemoteFileLocation $location, int $size): array
    {
        return ['file_id' => $location->toFileId(), 'file_unique_id' => $location->toUniqueId(), 'size' => $size];
    }

    /**
     * td::get_dimensions: both sides in 1..65535, or 0×0.
     *
     * @return array{0: int, 1: int}
     */
    public static function dimensions(int $width, int $height): array
    {
        if ($width < 0 || $height < 0 || $width > 65535 || $height > 65535) {
            return [0, 0];
        }
        if ($width === 0 || $height === 0) {
            return [0, 0];
        }

        return [$width, $height];
    }

    /**
     * td::strip_empty_characters(file_name, 255, true).
     */
    private static function stripEmptyCharacters(string $value): string
    {
        $value = preg_replace('/^[\s\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}\x{FEFF}\x{00A0}\x{3164}\x{115F}\x{1160}\x{FFA0}]+|[\s\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}\x{FEFF}\x{00A0}\x{3164}\x{115F}\x{1160}\x{FFA0}]+$/u', '', $value) ?? $value;

        return mb_substr($value, 0, 255);
    }
}
