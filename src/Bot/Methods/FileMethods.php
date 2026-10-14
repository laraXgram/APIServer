<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Foundation\FileDecoder;
use LaraGram\MTProto\Store\FileStore;
use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Files\FileIdException;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Files\PhotoSizeSource;
use LaraGram\Server\Files\RemoteFileLocation;
use LaraGram\Server\Http\JsonRawString;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MediaConverter;
use LaraGram\Server\Objects\ChatId;

/**
 * getFile: the file is downloaded into the bot's files directory like
 * TDLib's FileDownloader does (`<type>/file_<N>.<ext>`), and its path is
 * returned relative to the directory (absolute in local mode).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait FileMethods
{
    /** Client::MAX_DOWNLOAD_FILE_SIZE */
    private const MAX_DOWNLOAD_FILE_SIZE = 20 << 20;

    /** Downloaded files: file unique id → path relative to the files directory. */
    private ?FileStore $downloadedFiles = null;

    /** @var array<string, \Swoole\Coroutine\Channel> unique id → downloads in progress */
    private array $fileDownloads = [];

    public function processGetFileQuery(Query $query): void
    {
        try {
            $this->doGetFile($query);
        } catch (QueryError $e) {
            // Client::fail_query: the description is final
            if (str_starts_with($e->getMessage(), 'Bad Request: ')) {
                $query->fail($e->getCode(), $e->getMessage());

                return;
            }
            throw $e;
        }
    }

    private function doGetFile(Query $query): void
    {
        $fileId = $query->arg('file_id');
        if ($fileId === '') {
            throw new QueryError(400, 'Bad Request: file_id not specified');
        }

        // td_api::getRemoteFile
        try {
            $location = RemoteFileLocation::fromFileId($fileId);
        } catch (FileIdException $e) {
            throw new QueryError(400, $e->getMessage(), 'invalid file_id');
        }
        $uniqueId = $location->toUniqueId();
        [$extension, $expectedSize] = MediaConverter::fileHint($uniqueId) ?? ['', 0];

        $path = $this->getDownloadedFilePath($uniqueId);
        $downloadedSize = $path !== null ? (int) @filesize($this->filesDir.$path) : 0;

        // Client::do_get_file: speculative check
        if (!$this->parameters->localMode && max($expectedSize, $downloadedSize) > self::MAX_DOWNLOAD_FILE_SIZE) {
            throw new QueryError(400, 'Bad Request: file is too big');
        }

        if ($path === null) {
            $path = $this->downloadFile($location, $uniqueId, $extension, $expectedSize);
            $downloadedSize = (int) @filesize($this->filesDir.$path);
        }

        // Client::json_store_file
        $object = ['file_id' => $location->toFileId(), 'file_unique_id' => $uniqueId];
        $size = $expectedSize !== 0 ? $expectedSize : $downloadedSize;
        if ($size !== 0) {
            $object['file_size'] = $size;
        }
        if ($this->parameters->localMode) {
            $fullPath = $this->filesDir.$path;
            $object['file_path'] = mb_check_encoding($fullPath, 'UTF-8') ? $fullPath : new JsonRawString($fullPath);
        } elseif ($downloadedSize <= self::MAX_DOWNLOAD_FILE_SIZE) {
            $object['file_path'] = $path;
        }
        $query->answerQuery($object);
    }

    private function downloadedFiles(): FileStore
    {
        return $this->downloadedFiles ??= new FileStore($this->dir.'files_db');
    }

    private function getDownloadedFilePath(string $uniqueId): ?string
    {
        $path = $this->downloadedFiles()->get($uniqueId);
        if ($path === null || !is_file($this->filesDir.$path)) {
            return null;
        }

        return $path;
    }

    /**
     * FileDownloader + create_from_temp: one download per file at a time.
     */
    private function downloadFile(RemoteFileLocation $location, string $uniqueId, string $extension, int $expectedSize): string
    {
        if (isset($this->fileDownloads[$uniqueId])) {
            $channel = $this->fileDownloads[$uniqueId];
            $channel->pop();
            $path = $this->getDownloadedFilePath($uniqueId);
            if ($path === null) {
                throw new QueryError(400, 'Bad Request: wrong file_id or the file is temporarily unavailable');
            }

            return $path;
        }

        $channel = new \Swoole\Coroutine\Channel(1);
        $this->fileDownloads[$uniqueId] = $channel;
        try {
            return $this->doDownloadFile($location, $uniqueId, $extension, $expectedSize);
        } finally {
            unset($this->fileDownloads[$uniqueId]);
            $channel->close();
        }
    }

    private function doDownloadFile(RemoteFileLocation $location, string $uniqueId, string $extension, int $expectedSize): string
    {
        $files = new Filesystem();
        $tempDir = $this->filesDir.'temp/';
        $files->ensureDirectoryExists($tempDir, 0750);
        $tempPath = $tempDir.bin2hex(random_bytes(8));

        try {
            $size = (new FileDecoder($this->td))->downloadToFile(self::inputFileLocation($location), $location->dcId, $tempPath, $expectedSize);
        } catch (\Throwable $e) {
            @unlink($tempPath);
            if ($this->loggingOut || $this->closing) {
                [$code, $message] = $this->getClosingError();
                throw new QueryError($code, $message);
            }
            throw new QueryError(400, 'Bad Request: wrong file_id or the file is temporarily unavailable');
        }
        if (!$this->parameters->localMode && $size > self::MAX_DOWNLOAD_FILE_SIZE) {
            @unlink($tempPath);
            throw new QueryError(400, 'Bad Request: file is too big');
        }

        // for_suggested_file_name with ignore_file_names: file_<perm_file_id>.<ext>
        $dir = self::getFileTypeName($location->fileType).'/';
        $files->ensureDirectoryExists($this->filesDir.$dir, 0750);
        $suffix = $extension === '' ? '' : '.'.$extension;
        while (true) {
            $permFileId = (int) ($this->options?->get('perm_file_id') ?? 0);
            $this->setOption('perm_file_id', (string) ($permFileId + 1));
            $path = $dir.'file_'.$permFileId.$suffix;
            if (!file_exists($this->filesDir.$path)) {
                break;
            }
        }
        if (!@rename($tempPath, $this->filesDir.$path)) {
            @unlink($tempPath);
            throw new QueryError(400, 'Bad Request: wrong file_id or the file is temporarily unavailable');
        }
        $this->downloadedFiles()->put($uniqueId, $path);

        return $path;
    }

    /**
     * td::get_file_type_name of the main file type.
     */
    private static function getFileTypeName(int $fileType): string
    {
        return match ($fileType) {
            FileType::THUMBNAIL => 'thumbnails',
            FileType::PROFILE_PHOTO => 'profile_photos',
            FileType::PHOTO, FileType::SELF_DESTRUCTING_PHOTO, FileType::LIVE_PHOTO, FileType::SELF_DESTRUCTING_LIVE_PHOTO => 'photos',
            FileType::VOICE_NOTE, FileType::SELF_DESTRUCTING_VOICE_NOTE => 'voice',
            FileType::VIDEO, FileType::SELF_DESTRUCTING_VIDEO => 'videos',
            FileType::ENCRYPTED => 'secret',
            FileType::TEMP => 'temp',
            FileType::STICKER => 'stickers',
            FileType::AUDIO => 'music',
            FileType::ANIMATION => 'animations',
            FileType::ENCRYPTED_THUMBNAIL => 'secret_thumbnails',
            FileType::VIDEO_NOTE, FileType::SELF_DESTRUCTING_VIDEO_NOTE => 'video_notes',
            FileType::SECURE_ENCRYPTED, FileType::SECURE_DECRYPTED => 'passport',
            FileType::BACKGROUND, FileType::WALLPAPER => 'wallpapers',
            FileType::RINGTONE => 'notification_sounds',
            FileType::PHOTO_STORY, FileType::VIDEO_STORY => 'stories',
            default => 'documents',
        };
    }

    /**
     * FullRemoteFileLocation::as_input_file_location.
     *
     * @return array<string, mixed>
     */
    private static function inputFileLocation(RemoteFileLocation $location): array
    {
        if (!$location->isPhoto()) {
            return ['_' => 'inputDocumentFileLocation', 'id' => $location->id, 'access_hash' => $location->accessHash,
                'file_reference' => $location->fileReference, 'thumb_size' => ''];
        }

        $source = $location->source ?? [];
        switch ($source['type'] ?? -1) {
            case PhotoSizeSource::THUMBNAIL:
                $thumbSize = chr($source['thumbnail_type'] & 0xFF);
                if ($source['file_type'] === FileType::THUMBNAIL) {
                    return ['_' => 'inputDocumentFileLocation', 'id' => $location->id, 'access_hash' => $location->accessHash,
                        'file_reference' => $location->fileReference, 'thumb_size' => $thumbSize];
                }

                return ['_' => 'inputPhotoFileLocation', 'id' => $location->id, 'access_hash' => $location->accessHash,
                    'file_reference' => $location->fileReference, 'thumb_size' => $thumbSize];
            case PhotoSizeSource::DIALOG_PHOTO_SMALL:
            case PhotoSizeSource::DIALOG_PHOTO_BIG:
                return self::tl('inputPeerPhotoFileLocation', [
                    'big' => $source['type'] === PhotoSizeSource::DIALOG_PHOTO_BIG,
                    'peer' => self::dialogInputPeer((int) $source['dialog_id'], (int) $source['dialog_access_hash']),
                    'photo_id' => $location->id,
                ]);
            case PhotoSizeSource::FULL_LEGACY:
                return ['_' => 'inputPhotoLegacyFileLocation', 'id' => $location->id, 'access_hash' => $location->accessHash,
                    'file_reference' => $location->fileReference, 'volume_id' => $source['volume_id'],
                    'local_id' => $source['local_id'], 'secret' => $source['secret']];
            case PhotoSizeSource::STICKER_SET_THUMBNAIL_VERSION:
                return ['_' => 'inputStickerSetThumb',
                    'stickerset' => ['_' => 'inputStickerSetID', 'id' => $source['sticker_set_id'], 'access_hash' => $source['sticker_set_access_hash']],
                    'thumb_version' => $source['version']];
        }

        throw new QueryError(400, 'Bad Request: wrong file_id or the file is temporarily unavailable');
    }

    /**
     * DialogPhoto::get_input_peer of a dialog photo source.
     *
     * @return array<string, mixed>
     */
    private static function dialogInputPeer(int $dialogId, int $accessHash): array
    {
        return match (ChatId::type($dialogId)) {
            ChatId::TYPE_PRIVATE => ['_' => 'inputPeerUser', 'user_id' => $dialogId, 'access_hash' => $accessHash],
            ChatId::TYPE_GROUP => ['_' => 'inputPeerChat', 'chat_id' => ChatId::toBasicGroupId($dialogId)],
            default => ['_' => 'inputPeerChannel', 'channel_id' => ChatId::toChannelId($dialogId), 'access_hash' => $accessHash],
        };
    }
}
