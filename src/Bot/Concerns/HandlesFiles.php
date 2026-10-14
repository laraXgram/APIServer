<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\MTProto\Foundation\FileUploader;
use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Files\FileIdException;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Files\FileTypeName;
use LaraGram\Server\Files\PhotoSizeSource;
use LaraGram\Server\Files\RemoteFileLocation;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Http\RequestReader;
use LaraGram\Server\Objects\ChatId;
use LaraGram\Server\Support\FileName;
use LaraGram\Server\Support\HttpUrl;
use LaraGram\Server\Support\MimeType;
use LaraGram\Server\Support\Utf8;

/**
 * Input files: Client::get_input_file and TDLib's FileManager
 * (get_input_file_id, from_persistent_id, check_full_local_location) with
 * the uploads and the InputMedia of each kind of file (*Manager::get_input_media).
 *
 * A file to upload stays a placeholder ['_' => '@upload', ...] in the
 * prepared TL until the request is sent, as TDLib uploads after its checks.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait HandlesFiles
{
    private const MAX_FILE_SIZE = 4000 << 20;
    private const MAX_THUMBNAIL_SIZE = 200 * 1024 - 1;
    private const MAX_PHOTO_SIZE = 10 << 20;
    private const MAX_VIDEO_NOTE_SIZE = 12 << 20;
    private const MAX_PHOTO_HASH_SIZE = 11000000;

    /** @var array<string, RemoteFileLocation> SHA-256 of an uploaded photo => its photo on the server */
    private array $uploadedPhotoHashes = [];

    /** @var array<int, list<string>> coroutine id => hashes of the photos it is uploading */
    private array $pendingPhotoHashes = [];

    // ═════════════════════════════════════════════════════════════════
    //  Bot API side
    // ═════════════════════════════════════════════════════════════════

    /**
     * Client::get_input_file: a td_api InputFile, or null.
     *
     * @return array<string, mixed>|null
     */
    protected function getInputFile(Query $query, string $fieldName, bool $forceFile = false, ?string $fileId = null): ?array
    {
        $fileId ??= $fieldName === '' ? '' : $query->arg($fieldName);
        if ($fileId !== '') {
            if ($this->parameters->localMode && str_starts_with($fileId, 'file:/')) {
                return ['_' => 'inputFileLocal', 'path' => self::getLocalFilePath(substr($fileId, 6))];
            }
            if (str_starts_with($fileId, 'attach://')) {
                $fieldName = substr($fileId, 9);
            } elseif (!$forceFile) {
                return ['_' => 'inputFileRemote', 'id' => $fileId];
            }
        }

        $file = $fieldName === '' ? null : $query->file($fieldName);
        if ($file !== null) {
            return ['_' => 'inputFileLocal', 'path' => $file->tempFileName];
        }

        return null;
    }

    /**
     * Client::get_local_file_path.
     */
    protected static function getLocalFilePath(string $fileUri): string
    {
        if (str_starts_with($fileUri, '/')) {
            $fileUri = substr($fileUri, str_starts_with($fileUri, '/localhost') ? 10 : 1);
        }

        return RequestReader::urlDecode($fileUri, false);
    }

    /**
     * Client::get_input_thumbnail.
     *
     * @return array<string, mixed>|null td_api InputFile of the thumbnail
     */
    protected function getInputThumbnail(Query $query): ?array
    {
        return $this->getInputFile($query, 'thumbnail', true) ?? $this->getInputFile($query, 'thumb', true);
    }

    // ═════════════════════════════════════════════════════════════════
    //  FileManager
    // ═════════════════════════════════════════════════════════════════

    /**
     * FileManager::get_input_file_id: a file the request can use.
     *
     * @param array<string, mixed>|null $inputFile td_api InputFile
     * @return array<string, mixed>|null ['kind' => 'remote', 'location'] | ['kind' => 'url', 'url'] | ['kind' => 'local', 'path', 'size']
     */
    public function getInputFileId(int $fileType, ?array $inputFile, bool $allowZero = false): ?array
    {
        if ($inputFile === null) {
            if ($allowZero) {
                return null;
            }
            throw new QueryError(400, 'InputFile is not specified');
        }

        if ($inputFile['_'] === 'inputFileLocal') {
            $path = $inputFile['path'];
            if ($allowZero && $path === '') {
                return null;
            }

            // reuse_uploaded_photos_by_hash: a photo uploaded before isn't uploaded again
            $hash = '';
            if ($fileType === FileType::PHOTO) {
                $size = @filesize($path);
                if ($size !== false && $size > 0 && $size < self::MAX_PHOTO_HASH_SIZE) {
                    $content = @file_get_contents($path);
                    if ($content !== false) {
                        $hash = hash('sha256', $content, true);
                        if (isset($this->uploadedPhotoHashes[$hash])) {
                            return ['kind' => 'remote', 'location' => $this->uploadedPhotoHashes[$hash], 'type' => $fileType];
                        }
                    }
                }
            }
            $file = $this->registerLocal($fileType, $path);
            if ($hash !== '') {
                $file['photo_hash'] = $hash;
            }

            return $file;
        }

        $persistentId = $inputFile['id'];
        if ($allowZero && $persistentId === '') {
            return null;
        }

        if (str_contains($persistentId, '.')) {
            try {
                $url = HttpUrl::parse($persistentId)->getUrl();
            } catch (\InvalidArgumentException $e) {
                throw new QueryError(400, 'Invalid file HTTP URL specified: '.$e->getMessage());
            }
            if (TextFormatting::cleanInputString($url) !== $url) {
                throw new QueryError(400, 'URL must be in UTF-8');
            }

            return ['kind' => 'url', 'url' => $url, 'type' => $fileType];
        }

        try {
            $location = RemoteFileLocation::fromFileId($persistentId);
            $location->checkType($fileType);
        } catch (FileIdException $e) {
            throw new QueryError(400, $e->getMessage());
        }

        // check_input_file_id: remote photos and documents may be used as stories and back
        return ['kind' => 'remote', 'location' => $location, 'type' => $fileType];
    }

    /**
     * FileManager::register_local + check_full_local_location.
     *
     * @return array<string, mixed>
     */
    private function registerLocal(int $fileType, string $path): array
    {
        if ($path === '') {
            throw new QueryError(400, 'File must have non-empty path');
        }
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new QueryError(400, 'Can\'t find real file path');
        }
        $stat = @stat($realPath);
        if ($stat === false) {
            throw new QueryError(400, 'Can\'t get stat about the file');
        }
        if (!is_file($realPath)) {
            throw new QueryError(400, 'File must be a regular file');
        }
        $size = (int) $stat['size'];
        if ($size === 0) {
            throw new QueryError(400, 'File must be non-empty');
        }

        $tooBig = static fn (string $reason, int $max): QueryError
            => new QueryError(400, "File of size {$size} bytes is too big{$reason}; the maximum size is {$max} bytes");
        if ($size > self::MAX_FILE_SIZE) {
            throw $tooBig('', self::MAX_FILE_SIZE);
        }
        if (FileType::typeClass($fileType) === FileType::CLASS_PHOTO && $size > self::MAX_PHOTO_SIZE) {
            throw $tooBig(' for a photo', self::MAX_PHOTO_SIZE);
        }
        if (($fileType === FileType::VIDEO_NOTE || $fileType === FileType::SELF_DESTRUCTING_VIDEO_NOTE) && $size > self::MAX_VIDEO_NOTE_SIZE) {
            throw $tooBig(' for a video note', self::MAX_VIDEO_NOTE_SIZE);
        }

        return ['kind' => 'local', 'path' => $realPath, 'size' => $size, 'type' => $fileType];
    }

    /**
     * get_input_thumbnail_photo_size: a usable thumbnail, or null (TDLib
     * ignores a thumbnail it can't use).
     *
     * @param array<string, mixed>|null $thumbnail td_api InputFile
     * @return array<string, mixed>|null
     */
    public function getInputThumbnailFile(?array $thumbnail): ?array
    {
        if ($thumbnail === null || $thumbnail['_'] !== 'inputFileLocal') {
            return null;
        }
        try {
            $file = $this->registerLocal(FileType::THUMBNAIL, $thumbnail['path']);
        } catch (QueryError) {
            return null;
        }
        $name = FileName::baseName($file['path']);
        if ($file['size'] > self::MAX_THUMBNAIL_SIZE && !str_starts_with($name, 'map') && !str_starts_with($name, 'Album cover for ')) {
            return null;
        }

        return $file;
    }

    /**
     * The name and MIME type TDLib derives from a file's path (create_input_message_content).
     *
     * @param array<string, mixed>|null $file
     * @return array{0: string, 1: string} file name and MIME type
     */
    public static function fileNameAndMimeType(?array $file): array
    {
        if (($file['kind'] ?? '') !== 'local') {
            return ['', ''];
        }
        $name = FileName::baseName($file['path']);
        [, $extension] = FileName::split($file['path']);
        $mimeType = MimeType::fromExtension($extension);
        if (!Utf8::check($name)) {
            $name = $extension === '' ? 'file.' : 'file';
        }

        return [$name, $mimeType];
    }

    /**
     * An upload placeholder replaced by the TL InputFile when the request is sent.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private static function uploadPlaceholder(array $file): array
    {
        return ['_' => '@upload', 'file' => $file];
    }

    /**
     * The parameters of messages.uploadMedia; files of a business message are
     * uploaded for its connection (UploadBusinessMediaQuery).
     *
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    protected function uploadMediaParams(int $chatId, array $media): array
    {
        $params = [];
        if ($this->uploadBusinessConnectionId !== '') {
            $params['business_connection_id'] = $this->uploadBusinessConnectionId;
        }
        $params['peer'] = $chatId === 0 ? ['_' => 'inputPeerSelf'] : $this->inputPeer($chatId);
        $params['media'] = $media;

        return $params;
    }

    /**
     * Upload the files of a prepared TL object, replacing their placeholders.
     *
     * @param array<string, mixed>|null $object
     * @return array<string, mixed>|null
     */
    public function uploadPendingFiles(?array $object, int $chatId = 0): ?array
    {
        if ($object === null) {
            return null;
        }
        foreach ($object as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (($value['_'] ?? '') === '@upload') {
                $object[$key] = $this->uploadFile($value['file']);
            } elseif (($value['_'] ?? '') === '@uploadPhoto') {
                $object[$key] = $this->uploadMediaPhoto($value['file'], $chatId);
            } elseif (($value['_'] ?? '') === '@uploadMedia') {
                $object[$key] = $this->getUploadedInputMedia($value['media'], $chatId, false);
            } elseif (($value['_'] ?? '') === '@uploadLivePhotoVideo') {
                $object[$key] = $this->uploadLivePhotoVideo($value['file'], $chatId);
            } elseif (($value['_'] ?? '') === '@richMessage') {
                $object[$key] = $this->getInputRichMessageTl($value, $chatId);
            } else {
                $object[$key] = $this->uploadPendingFiles($value, $chatId);
            }
        }

        return $object;
    }

    /**
     * FileUploadManager: upload a local file; the TL InputFile.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function uploadFile(array $file): array
    {
        if (isset($file['photo_hash'])) {
            $this->pendingPhotoHashes[\Swoole\Coroutine::getCid()][] = $file['photo_hash'];
        }
        try {
            return (new FileUploader($this->td))->fromPath($file['path'], FileName::baseName($file['path']));
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
    }

    /**
     * An InputPhoto for a photo given as a local file or URL (a video cover),
     * through messages.uploadMedia.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function uploadMediaPhoto(array $file, int $chatId): array
    {
        $media = $file['kind'] === 'url'
            ? ['_' => 'inputMediaPhotoExternal', 'url' => $file['url']]
            : ['_' => 'inputMediaUploadedPhoto', 'file' => $this->uploadFile($file)];

        try {
            $result = $this->invoke('messages.uploadMedia', $this->uploadMediaParams($chatId, $media));
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }

        $photo = $result['photo'] ?? [];
        $this->rememberUploadedPhotos([$photo]);

        return ['_' => 'inputPhoto', 'id' => (int) ($photo['id'] ?? 0), 'access_hash' => (int) ($photo['access_hash'] ?? 0),
            'file_reference' => (string) ($photo['file_reference'] ?? '')];
    }

    /**
     * The InputDocument of a live photo video: a file already on the server is
     * used as is, other files are uploaded with messages.uploadMedia
     * (VideosManager::get_input_media of the video created for the live photo).
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function uploadLivePhotoVideo(array $file, int $chatId): array
    {
        if ($file['kind'] === 'remote') {
            return self::inputDocument($file['location']);
        }
        $media = $file['kind'] === 'url'
            ? ['_' => 'inputMediaDocumentExternal', 'url' => $file['url']]
            : self::tl('inputMediaUploadedDocument', [
                'nosound_video' => true,
                'file' => $this->uploadFile($file),
                'mime_type' => 'video/mp4',
                'attributes' => [['_' => 'documentAttributeVideo', 'duration' => 0.0, 'w' => 0, 'h' => 0]],
            ]);

        try {
            $result = $this->invoke('messages.uploadMedia', $this->uploadMediaParams($chatId, $media));
        } catch (\Throwable $e) {
            throw QueryError::fromThrowable($e);
        }
        $document = $result['document'] ?? [];

        return ['_' => 'inputDocument', 'id' => (int) ($document['id'] ?? 0), 'access_hash' => (int) ($document['access_hash'] ?? 0),
            'file_reference' => (string) ($document['file_reference'] ?? '')];
    }

    /**
     * Remember the photos created by the uploads of the current coroutine, in
     * upload order, so the same content is reused (FileManager::file_hash_to_file_id_).
     *
     * @param list<array<string, mixed>> $photos TL Photo
     */
    public function rememberUploadedPhotos(array $photos): void
    {
        $cid = \Swoole\Coroutine::getCid();
        $hashes = $this->pendingPhotoHashes[$cid] ?? [];
        unset($this->pendingPhotoHashes[$cid]);
        foreach ($hashes as $i => $hash) {
            $photo = $photos[$i] ?? null;
            if (($photo['_'] ?? '') !== 'photo' || (int) ($photo['id'] ?? 0) === 0) {
                continue;
            }
            $sizes = $photo['sizes'] ?? [];
            $last = end($sizes);
            $this->uploadedPhotoHashes[$hash] = RemoteFileLocation::photo(FileType::PHOTO, (int) ($photo['dc_id'] ?? 0), (int) $photo['id'],
                (int) ($photo['access_hash'] ?? 0), (string) ($photo['file_reference'] ?? ''),
                PhotoSizeSource::thumbnail(FileType::PHOTO, (string) ($last['type'] ?? 'y')));
        }
    }

    /**
     * rememberUploadedPhotos() for the photos of the messages in a TL Updates.
     *
     * @param array<string, mixed>|null $result
     */
    public function rememberUploadedPhotosOf(?array $result): void
    {
        if (!isset($this->pendingPhotoHashes[\Swoole\Coroutine::getCid()])) {
            return;
        }
        $photos = [];
        $updates = match ($result['_'] ?? '') {
            'updates', 'updatesCombined' => $result['updates'] ?? [],
            'updateShort' => [$result['update']],
            'updateShortSentMessage' => [['message' => ['media' => $result['media'] ?? null]]],
            default => [],
        };
        foreach ($updates as $update) {
            $media = $update['message']['media'] ?? null;
            if (($media['_'] ?? '') === 'messageMediaPhoto') {
                $photos[] = $media['photo'] ?? [];
            }
        }
        $this->rememberUploadedPhotos($photos);
    }

    /**
     * FullRemoteFileLocation::as_input_document.
     *
     * @return array<string, mixed>
     */
    public static function inputDocument(RemoteFileLocation $location): array
    {
        return ['_' => 'inputDocument', 'id' => $location->id, 'access_hash' => $location->accessHash, 'file_reference' => $location->fileReference];
    }

    /**
     * FullRemoteFileLocation::as_input_photo.
     *
     * @return array<string, mixed>
     */
    public static function inputPhoto(RemoteFileLocation $location): array
    {
        return ['_' => 'inputPhoto', 'id' => $location->id, 'access_hash' => $location->accessHash, 'file_reference' => $location->fileReference];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Message contents with files
    // ═════════════════════════════════════════════════════════════════

    /**
     * get_input_message_content + create_input_message_content +
     * get_message_content_input_media for contents with a file.
     *
     * @param array<string, mixed> $content td_api InputMessageContent
     * @return array<string, mixed> prepared content
     */
    protected function getInputMediaContent(int $chatId, array $content): array
    {
        $type = $content['_'];
        [$fileType, $inputFile, $thumbnail, $allowGetByHash] = match ($type) {
            'inputMessageAnimation' => [FileType::ANIMATION, $content['animation']['animation'], $content['animation']['thumbnail'], true],
            'inputMessageAudio' => [FileType::AUDIO, $content['audio']['audio'], $content['audio']['album_cover_thumbnail'], false],
            'inputMessageDocument' => [$content['document']['disable_content_type_detection'] ? FileType::DOCUMENT_AS_FILE : FileType::DOCUMENT,
                $content['document']['document'], $content['document']['thumbnail'], true],
            'inputMessagePhoto' => [FileType::PHOTO, $content['photo']['photo'], null, false],
            'inputMessageSticker' => [FileType::STICKER, $content['sticker']['sticker'], null, false],
            'inputMessageVideo' => [FileType::VIDEO, $content['video']['video'], $content['video']['thumbnail'], false],
            'inputMessageVideoNote' => [FileType::VIDEO_NOTE, $content['video_note']['video_note'], $content['video_note']['thumbnail'], false],
            'inputMessageVoiceNote' => [FileType::VOICE_NOTE, $content['voice_note']['voice_note'], null, false],
            default => throw new QueryError(400, 'Unsupported input message content'),
        };

        $file = $this->getInputFileId($fileType, $inputFile);
        $caption = $this->getOutgoingFormattedText($content['caption'] ?? new FormattedText(), true);
        if (Utf8::length($caption->text) > self::MESSAGE_CAPTION_LENGTH_MAX) {
            throw new QueryError(400, 'Message caption is too long');
        }

        $thumbnailFile = $this->getInputThumbnailFile($thumbnail);
        $prepared = ['type' => '', 'text' => $caption, 'media' => null, 'no_webpage' => false, 'invert_media' => false];

        switch ($type) {
            case 'inputMessageAnimation':
                $animation = $content['animation'];
                $prepared['type'] = 'Animation';
                $prepared['invert_media'] = $content['show_caption_above_media'];
                [$name, $mime] = self::fileNameAndMimeType($file);
                $prepared['media'] = $this->documentInputMedia($file, $thumbnailFile, $content['has_spoiler'], function () use ($animation, $name, $mime): array {
                    [$width, $height] = self::dimensions($animation['width'], $animation['height']);
                    $attributes = $name === '' ? [] : [['_' => 'documentAttributeFilename', 'file_name' => $name]];
                    if ($mime === 'video/mp4') {
                        $attributes[] = ['_' => 'documentAttributeVideo', 'duration' => (float) max($animation['duration'], 0), 'w' => $width, 'h' => $height];
                    } elseif ($width !== 0 && $height !== 0) {
                        if (!str_starts_with($mime, 'image/')) {
                            $mime = 'image/gif';
                        }
                        $attributes[] = ['_' => 'documentAttributeImageSize', 'w' => $width, 'h' => $height];
                    }

                    return [$mime, $attributes, []];
                });
                break;

            case 'inputMessageAudio':
                $audio = $content['audio'];
                $title = TextFormatting::cleanInputString($audio['title']);
                if ($title === null) {
                    throw new QueryError(400, 'Audio title must be encoded in UTF-8');
                }
                $performer = TextFormatting::cleanInputString($audio['performer']);
                if ($performer === null) {
                    throw new QueryError(400, 'Audio performer must be encoded in UTF-8');
                }
                $prepared['type'] = 'Audio';
                [$name, $mime] = self::fileNameAndMimeType($file);
                $prepared['media'] = $this->documentInputMedia($file, $thumbnailFile, false, static function () use ($audio, $title, $performer, $name, $mime): array {
                    $attributes = [['_' => 'documentAttributeAudio', 'duration' => max($audio['duration'], 0), 'title' => $title, 'performer' => $performer]];
                    if ($name !== '') {
                        $attributes[] = ['_' => 'documentAttributeFilename', 'file_name' => $name];
                    }

                    return [str_starts_with($mime, 'audio/') ? $mime : 'audio/mpeg', $attributes, []];
                });
                break;

            case 'inputMessageDocument':
                $prepared['type'] = 'Document';
                [$name, $mime] = self::fileNameAndMimeType($file);
                $forceFile = $fileType === FileType::DOCUMENT_AS_FILE;
                $prepared['media'] = $this->documentInputMedia($file, $thumbnailFile, false, static function () use ($name, $mime, $forceFile): array {
                    $attributes = $name === '' ? [] : [['_' => 'documentAttributeFilename', 'file_name' => $name]];

                    return [$mime, $attributes, ['force_file' => $forceFile]];
                });
                break;

            case 'inputMessagePhoto':
                $prepared['type'] = 'Photo';
                $prepared['invert_media'] = $content['show_caption_above_media'];
                $hasSpoiler = $content['has_spoiler'];
                // a live photo: its video is uploaded first, like a video cover (get_message_content_need_to_upload_covers)
                $liveVideo = null;
                if ($content['photo']['video'] !== null) {
                    $liveVideo = ['_' => '@uploadLivePhotoVideo', 'file' => $this->getInputFileId(FileType::LIVE_PHOTO, $content['photo']['video'])];
                }
                $prepared['media'] = match ($file['kind']) {
                    'remote' => self::tl('inputMediaPhoto', ['spoiler' => $hasSpoiler, 'live_photo' => $liveVideo !== null,
                        'id' => self::inputPhoto($file['location']), 'video' => $liveVideo]),
                    'url' => self::tl('inputMediaPhotoExternal', ['spoiler' => $hasSpoiler, 'url' => $file['url']]),
                    default => self::tl('inputMediaUploadedPhoto', ['spoiler' => $hasSpoiler, 'live_photo' => $liveVideo !== null,
                        'file' => self::uploadPlaceholder($file), 'video' => $liveVideo]),
                };
                break;

            case 'inputMessageSticker':
                $prepared['type'] = 'Sticker';
                $emoji = $content['emoji'];
                if ($file['kind'] === 'remote') {
                    $prepared['media'] = self::tl('inputMediaDocument', ['id' => self::inputDocument($file['location']), 'query' => $emoji === '' ? null : $emoji]);
                } elseif ($file['kind'] === 'url') {
                    $prepared['media'] = ['_' => 'inputMediaDocumentExternal', 'url' => $file['url']];
                } else {
                    $sticker = $content['sticker'];
                    [$width, $height] = self::dimensions($sticker['width'], $sticker['height']);
                    $attributes = [];
                    if ($width !== 0 && $height !== 0) {
                        $attributes[] = ['_' => 'documentAttributeImageSize', 'w' => $width, 'h' => $height];
                    }
                    $attributes[] = ['_' => 'documentAttributeSticker', 'alt' => $emoji, 'stickerset' => ['_' => 'inputStickerSetEmpty']];
                    $prepared['media'] = self::tl('inputMediaUploadedDocument', [
                        'file' => self::uploadPlaceholder($file),
                        'thumb' => $thumbnailFile === null ? null : self::uploadPlaceholder($thumbnailFile),
                        'mime_type' => 'image/webp',
                        'attributes' => $attributes,
                    ]);
                }
                break;

            case 'inputMessageVideo':
                $video = $content['video'];
                $prepared['type'] = 'Video';
                $prepared['invert_media'] = $content['show_caption_above_media'];
                $hasSpoiler = $content['has_spoiler'];
                $cover = $this->getInputFileId(FileType::PHOTO, $video['cover'], true);
                $inputCover = match ($cover['kind'] ?? null) {
                    null => null,
                    'remote' => self::inputPhoto($cover['location']),
                    default => ['_' => '@uploadPhoto', 'file' => $cover],
                };
                $startTimestamp = max(0, $video['start_timestamp']);
                [$name, $mime] = self::fileNameAndMimeType($file);
                if ($file['kind'] === 'remote') {
                    $prepared['media'] = self::tl('inputMediaDocument', [
                        'spoiler' => $hasSpoiler, 'id' => self::inputDocument($file['location']),
                        'video_cover' => $inputCover, 'video_timestamp' => $startTimestamp !== 0 ? $startTimestamp : null,
                    ]);
                } elseif ($file['kind'] === 'url') {
                    $prepared['media'] = self::tl('inputMediaDocumentExternal', [
                        'spoiler' => $hasSpoiler, 'url' => $file['url'],
                        'video_cover' => $inputCover, 'video_timestamp' => $startTimestamp !== 0 ? $startTimestamp : null,
                    ]);
                } else {
                    [$width, $height] = self::dimensions($video['width'], $video['height']);
                    $attributes = [self::tl('documentAttributeVideo', [
                        'supports_streaming' => $video['supports_streaming'],
                        'duration' => (float) max($video['duration'], 0), 'w' => $width, 'h' => $height,
                    ])];
                    if ($name !== '') {
                        $attributes[] = ['_' => 'documentAttributeFilename', 'file_name' => $name];
                    }
                    $prepared['media'] = self::tl('inputMediaUploadedDocument', [
                        'nosound_video' => true,
                        'spoiler' => $hasSpoiler,
                        'file' => self::uploadPlaceholder($file),
                        'thumb' => $thumbnailFile === null ? null : self::uploadPlaceholder($thumbnailFile),
                        'mime_type' => str_starts_with($mime, 'video/') ? $mime : 'video/mp4',
                        'attributes' => $attributes,
                        'video_cover' => $inputCover,
                        'video_timestamp' => $startTimestamp !== 0 ? $startTimestamp : null,
                    ]);
                }
                break;

            case 'inputMessageVideoNote':
                $videoNote = $content['video_note'];
                $length = $videoNote['length'];
                if ($length < 0 || $length > 640) {
                    throw new QueryError(400, 'Wrong video note length');
                }
                $prepared['type'] = 'VideoNote';
                $prepared['text'] = new FormattedText();
                if ($file['kind'] === 'remote') {
                    $prepared['media'] = ['_' => 'inputMediaDocument', 'id' => self::inputDocument($file['location'])];
                } elseif ($file['kind'] === 'url') {
                    $prepared['media'] = ['_' => 'inputMediaDocumentExternal', 'url' => $file['url']];
                } else {
                    [$width, $height] = self::dimensions($length, $length);
                    $prepared['media'] = self::tl('inputMediaUploadedDocument', [
                        'nosound_video' => true,
                        'file' => self::uploadPlaceholder($file),
                        'thumb' => $thumbnailFile === null ? null : self::uploadPlaceholder($thumbnailFile),
                        'mime_type' => 'video/mp4',
                        'attributes' => [[
                            '_' => 'documentAttributeVideo', 'round_message' => true,
                            'duration' => (float) max($videoNote['duration'], 0),
                            'w' => $width !== 0 ? $width : 384, 'h' => $height !== 0 ? $height : 384,
                        ]],
                    ]);
                }
                break;

            case 'inputMessageVoiceNote':
                $voiceNote = $content['voice_note'];
                $prepared['type'] = 'VoiceNote';
                [, $mime] = self::fileNameAndMimeType($file);
                if ($file['kind'] === 'remote') {
                    $prepared['media'] = ['_' => 'inputMediaDocument', 'id' => self::inputDocument($file['location'])];
                } elseif ($file['kind'] === 'url') {
                    $prepared['media'] = ['_' => 'inputMediaDocumentExternal', 'url' => $file['url']];
                } else {
                    $prepared['media'] = self::tl('inputMediaUploadedDocument', [
                        'file' => self::uploadPlaceholder($file),
                        'mime_type' => in_array($mime, ['audio/ogg', 'audio/mpeg', 'audio/mp4'], true) ? $mime : 'audio/ogg',
                        'attributes' => [self::tl('documentAttributeAudio', [
                            'voice' => true,
                            'duration' => max($voiceNote['duration'], 0),
                            'waveform' => $voiceNote['waveform'] === '' ? null : $voiceNote['waveform'],
                        ])],
                    ]);
                }
                break;
        }

        return $prepared;
    }

    /**
     * The InputMedia of a document-like file: by remote location, by URL, or
     * uploaded with the attributes $attributes() returns.
     *
     * @param array<string, mixed> $file
     * @param array<string, mixed>|null $thumbnail
     * @param callable(): array{0: string, 1: list<array<string, mixed>>, 2: array<string, bool>} $attributes
     * @return array<string, mixed>
     */
    private function documentInputMedia(array $file, ?array $thumbnail, bool $hasSpoiler, callable $attributes): array
    {
        if ($file['kind'] === 'remote') {
            return self::tl('inputMediaDocument', ['spoiler' => $hasSpoiler, 'id' => self::inputDocument($file['location'])]);
        }
        if ($file['kind'] === 'url') {
            return self::tl('inputMediaDocumentExternal', ['spoiler' => $hasSpoiler, 'url' => $file['url']]);
        }

        [$mimeType, $documentAttributes, $flags] = $attributes();

        return self::tl('inputMediaUploadedDocument', [
            'force_file' => $flags['force_file'] ?? false,
            'spoiler' => $hasSpoiler,
            'file' => self::uploadPlaceholder($file),
            'thumb' => $thumbnail === null ? null : self::uploadPlaceholder($thumbnail),
            'mime_type' => $mimeType,
            'attributes' => $documentAttributes,
        ]);
    }

    /**
     * get_dimensions: both sides valid (1..65535) or none.
     *
     * @return array{0: int, 1: int}
     */
    public static function dimensions(int $width, int $height): array
    {
        $width = $width < 0 || $width > 65535 ? 0 : $width;
        $height = $height < 0 || $height > 65535 ? 0 : $height;

        return $width === 0 || $height === 0 ? [0, 0] : [$width, $height];
    }
}
