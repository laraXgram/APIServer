<?php

declare(strict_types=1);

namespace LaraGram\Server\Files;

/**
 * A remote file as TDLib identifies it (td::FullRemoteFileLocation), with
 * the encoders of the Bot API `file_id` (persistent id, version 4) and
 * `file_unique_id`.
 *
 * The photo size source is an array: ['type' => PhotoSizeSource::*, ...fields].
 */
final class RemoteFileLocation
{
    private const WEB_LOCATION_FLAG = 1 << 24;
    private const FILE_REFERENCE_FLAG = 1 << 25;

    private const PERSISTENT_ID_VERSION_OLD = 2;
    private const PERSISTENT_ID_VERSION_GENERATED = 3;
    private const PERSISTENT_ID_VERSION = 4;

    /** td::Version::Next - 1: the serialization version written into new identifiers. */
    public const CURRENT_VERSION = 61;

    /** td::Version::RemovePhotoVolumeAndLocalId */
    private const VERSION_REMOVE_PHOTO_VOLUME_AND_LOCAL_ID = 32;

    /** td::Version::AddPhotoSizeSource */
    private const VERSION_ADD_PHOTO_SIZE_SOURCE = 22;

    /** Value TDLib uses for a file reference known to be invalid. */
    private const INVALID_FILE_REFERENCE = "\xFF\xFF";

    /**
     * @param array<string, int>|null $source photo size source for photo-class files
     */
    public function __construct(
        public int $fileType,
        public int $dcId,
        public string $fileReference = '',
        public int $id = 0,
        public int $accessHash = 0,
        public ?array $source = null,
        public ?string $url = null,
    ) {
    }

    public static function common(int $fileType, int $dcId, int $id, int $accessHash, string $fileReference): self
    {
        return new self($fileType, $dcId, $fileReference, $id, $accessHash);
    }

    /**
     * @param array<string, int> $source
     */
    public static function photo(int $fileType, int $dcId, int $id, int $accessHash, string $fileReference, array $source): self
    {
        return new self($fileType, $dcId, $fileReference, $id, $accessHash, $source);
    }

    public function isWeb(): bool
    {
        return $this->url !== null;
    }

    public function isPhoto(): bool
    {
        return !$this->isWeb() && FileType::typeClass($this->fileType) === FileType::CLASS_PHOTO;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Encoding
    // ═════════════════════════════════════════════════════════════════

    /**
     * FileNode::get_persistent_id: the Bot API `file_id`.
     */
    public function toFileId(): string
    {
        $binary = TlBinary::zeroEncode($this->serialize());

        return TlBinary::base64urlEncode($binary.chr(self::CURRENT_VERSION).chr(self::PERSISTENT_ID_VERSION));
    }

    /**
     * FileNode::get_unique_id: the Bot API `file_unique_id`.
     */
    public function toUniqueId(): string
    {
        $type = $this->isWeb() ? 0 : FileType::typeClass($this->fileType) + 1;
        $binary = TlBinary::int32($type);

        if ($this->isWeb()) {
            $binary .= TlBinary::string($this->url);
        } elseif ($this->isPhoto()) {
            $binary .= self::photoKey($this->id, $this->source, true);
        } else {
            $binary .= TlBinary::int64($this->id);
        }

        return TlBinary::base64urlEncode(TlBinary::zeroEncode($binary));
    }

    private function serialize(): string
    {
        $type = $this->fileType;
        if ($this->isWeb()) {
            $type |= self::WEB_LOCATION_FLAG;
        }
        if ($this->fileReference !== '') {
            $type |= self::FILE_REFERENCE_FLAG;
        }

        $out = TlBinary::int32($type).TlBinary::int32($this->dcId);
        if ($this->fileReference !== '') {
            $out .= TlBinary::string($this->fileReference);
        }

        if ($this->isWeb()) {
            return $out.TlBinary::string($this->url).TlBinary::int64($this->accessHash);
        }

        $out .= TlBinary::int64($this->id).TlBinary::int64($this->accessHash);
        if ($this->isPhoto()) {
            $out .= PhotoSizeSource::store($this->source);
        }

        return $out;
    }

    /**
     * PhotoRemoteFileLocation::AsKey::store.
     *
     * @param array<string, int> $source
     */
    private static function photoKey(int $id, array $source, bool $isUnique): string
    {
        $unique = PhotoSizeSource::unique($source);

        return match ($source['type']) {
            PhotoSizeSource::FULL_LEGACY, PhotoSizeSource::DIALOG_PHOTO_SMALL_LEGACY,
            PhotoSizeSource::DIALOG_PHOTO_BIG_LEGACY, PhotoSizeSource::STICKER_SET_THUMBNAIL_LEGACY => ($isUnique ? '' : TlBinary::int64($id)).$unique,
            PhotoSizeSource::DIALOG_PHOTO_SMALL, PhotoSizeSource::DIALOG_PHOTO_BIG, PhotoSizeSource::THUMBNAIL => TlBinary::int64($id).$unique,
            PhotoSizeSource::STICKER_SET_THUMBNAIL_VERSION => $unique,
            default => throw new \LogicException('Invalid photo size source for a file key'),
        };
    }

    // ═════════════════════════════════════════════════════════════════
    //  Decoding
    // ═════════════════════════════════════════════════════════════════

    /**
     * FileManager::from_persistent_id for remote locations.
     *
     * @throws FileIdException with the TDLib error message
     */
    public static function fromFileId(string $fileId): self
    {
        try {
            $binary = TlBinary::base64urlDecodeOrFail($fileId);
        } catch (\InvalidArgumentException $e) {
            throw new FileIdException('Wrong remote file identifier specified: '.$e->getMessage());
        }
        if ($binary === '') {
            throw new FileIdException('Remote file identifier must be non-empty');
        }

        $last = ord($binary[strlen($binary) - 1]);
        $binary = substr($binary, 0, -1);

        if ($last === self::PERSISTENT_ID_VERSION_OLD) {
            return self::parse(TlBinary::zeroDecode($binary), 0);
        }
        if ($last === self::PERSISTENT_ID_VERSION) {
            if ($binary === '') {
                throw new FileIdException('Invalid remote file identifier');
            }
            $version = ord($binary[strlen($binary) - 1]);

            return self::parse(TlBinary::zeroDecode(substr($binary, 0, -1)), $version);
        }
        if ($last === self::PERSISTENT_ID_VERSION_GENERATED) {
            throw new FileIdException('Wrong remote file identifier specified: can\'t unserialize it');
        }

        throw new FileIdException('Wrong remote file identifier specified: can\'t unserialize it. Wrong last symbol');
    }

    private static function parse(string $data, int $version): self
    {
        if ($version < 0 || $version > self::CURRENT_VERSION) {
            throw new FileIdException('Invalid remote file identifier');
        }

        try {
            $reader = new TlReader($data);
            $location = self::parseLocation($reader, $version);
            $reader->end();

            return $location;
        } catch (\UnderflowException|\UnexpectedValueException) {
            throw new FileIdException('Wrong remote file identifier specified: can\'t unserialize it');
        }
    }

    private static function parseLocation(TlReader $reader, int $version): self
    {
        $rawType = $reader->int32();
        $isWeb = ($rawType & self::WEB_LOCATION_FLAG) !== 0;
        $rawType &= ~self::WEB_LOCATION_FLAG;
        $hasFileReference = ($rawType & self::FILE_REFERENCE_FLAG) !== 0;
        $rawType &= ~self::FILE_REFERENCE_FLAG;
        if ($rawType < 0 || $rawType >= FileType::SIZE) {
            throw new \UnexpectedValueException('Invalid FileType in FullRemoteFileLocation');
        }

        $dcId = $reader->int32();
        $fileReference = '';
        if ($hasFileReference) {
            $fileReference = $reader->string();
            if ($fileReference === self::INVALID_FILE_REFERENCE) {
                $fileReference = '';
            }
        }

        if ($isWeb) {
            $url = $reader->string();

            return new self($rawType, $dcId, $fileReference, 0, $reader->int64(), null, $url);
        }

        $class = FileType::typeClass($rawType);
        if ($class === FileType::CLASS_TEMP) {
            throw new \UnexpectedValueException('Invalid FileType in FullRemoteFileLocation');
        }

        $id = $reader->int64();
        $accessHash = $reader->int64();

        if ($class !== FileType::CLASS_PHOTO) {
            return new self($rawType, $dcId, $fileReference, $id, $accessHash);
        }

        if ($version >= self::VERSION_REMOVE_PHOTO_VOLUME_AND_LOCAL_ID) {
            $source = PhotoSizeSource::parse($reader, $version);
        } else {
            $source = self::parseLegacyPhotoSource($reader, $version);
        }

        self::checkPhotoSource($rawType, $source);

        return new self($rawType, $dcId, $fileReference, $id, $accessHash, $source);
    }

    /**
     * @return array<string, int>
     */
    private static function parseLegacyPhotoSource(TlReader $reader, int $version): array
    {
        $volumeId = $reader->int64();
        if ($version >= self::VERSION_ADD_PHOTO_SIZE_SOURCE) {
            $source = PhotoSizeSource::parse($reader, $version);
            $localId = $reader->int32();
        } else {
            $secret = $reader->int64();
            $localId = $reader->int32();
            $source = ['type' => PhotoSizeSource::FULL_LEGACY, 'volume_id' => $volumeId, 'local_id' => $localId, 'secret' => $secret];
        }

        return match ($source['type']) {
            PhotoSizeSource::LEGACY => ['type' => PhotoSizeSource::FULL_LEGACY, 'volume_id' => $volumeId, 'local_id' => $localId, 'secret' => $source['secret']],
            PhotoSizeSource::FULL_LEGACY, PhotoSizeSource::THUMBNAIL => $source,
            PhotoSizeSource::DIALOG_PHOTO_SMALL, PhotoSizeSource::DIALOG_PHOTO_BIG => [
                'type' => $source['type'] === PhotoSizeSource::DIALOG_PHOTO_BIG ? PhotoSizeSource::DIALOG_PHOTO_BIG_LEGACY : PhotoSizeSource::DIALOG_PHOTO_SMALL_LEGACY,
                'dialog_id' => $source['dialog_id'],
                'dialog_access_hash' => $source['dialog_access_hash'],
                'volume_id' => $volumeId,
                'local_id' => $localId,
            ],
            PhotoSizeSource::STICKER_SET_THUMBNAIL => [
                'type' => PhotoSizeSource::STICKER_SET_THUMBNAIL_LEGACY,
                'sticker_set_id' => $source['sticker_set_id'],
                'sticker_set_access_hash' => $source['sticker_set_access_hash'],
                'volume_id' => $volumeId,
                'local_id' => $localId,
            ],
            default => throw new \UnexpectedValueException('Invalid PhotoSizeSource in legacy PhotoRemoteFileLocation'),
        };
    }

    /**
     * @param array<string, int> $source
     */
    private static function checkPhotoSource(int $fileType, array $source): void
    {
        $valid = match ($source['type']) {
            PhotoSizeSource::LEGACY, PhotoSizeSource::FULL_LEGACY => true,
            PhotoSizeSource::THUMBNAIL => $source['file_type'] === $fileType
                && in_array($fileType, [FileType::PHOTO, FileType::PHOTO_STORY, FileType::SELF_DESTRUCTING_PHOTO, FileType::THUMBNAIL, FileType::ENCRYPTED_THUMBNAIL], true),
            PhotoSizeSource::DIALOG_PHOTO_SMALL, PhotoSizeSource::DIALOG_PHOTO_BIG,
            PhotoSizeSource::DIALOG_PHOTO_SMALL_LEGACY, PhotoSizeSource::DIALOG_PHOTO_BIG_LEGACY => $fileType === FileType::PROFILE_PHOTO,
            default => $fileType === FileType::THUMBNAIL,
        };

        if (!$valid) {
            throw new \UnexpectedValueException('Invalid FileType in PhotoRemoteFileLocation');
        }
    }

    /**
     * The type check of FileManager::from_persistent_id_v23 for a file id
     * used where a file of type $expected is required.
     *
     * @throws FileIdException
     */
    public function checkType(int $expected): void
    {
        if (FileType::isDocument($this->fileType) && FileType::isDocument($expected)) {
            $this->fileType = $expected;

            return;
        }
        if (FileType::isBackground($this->fileType) && FileType::isBackground($expected)) {
            return;
        }
        if ($this->fileType !== $expected && $expected !== FileType::TEMP) {
            throw new FileIdException('Can\'t use file of type '.FileTypeName::of($this->fileType).' as '.FileTypeName::of($expected));
        }
    }
}
