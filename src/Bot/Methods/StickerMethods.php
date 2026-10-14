<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Methods;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Files\FileType;
use LaraGram\Server\Files\PhotoSizeSource;
use LaraGram\Server\Files\RemoteFileLocation;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MediaConverter;
use LaraGram\Server\Objects\JsonMedia;
use LaraGram\Server\Objects\JsonSticker;
use LaraGram\Server\Support\Emoji;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Support\TdJson;

/**
 * Sticker sets: getStickerSet, getCustomEmojiStickers, uploadStickerFile
 * and the sticker set management methods with the StickersManager parts
 * they rely on.
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait StickerMethods
{
    /** Client::GREAT_MINDS_SET_ID / GREAT_MINDS_SET_NAME */
    private const GREAT_MINDS_SET_ID = 1842540969984001;
    private const GREAT_MINDS_SET_NAME = 'TelegramGreatMinds';
    private const GREAT_MINDS_COLOR_SET_ID = 151353307481243663;

    /** Client::MAX_STICKER_EMOJI_COUNT */
    private const MAX_STICKER_EMOJI_COUNT = 20;

    /** StickersManager limits (server-side) */
    private const MAX_STICKER_SET_TITLE_LENGTH = 64;
    private const MAX_STICKER_SET_SHORT_NAME_LENGTH = 64;
    private const MAX_GET_CUSTOM_EMOJI_STICKERS = 200;

    private const STICKER_MASK_POINTS = ['forehead', 'eyes', 'mouth', 'chin'];

    public function processGetStickerSetQuery(Query $query): void
    {
        $name = $query->arg('name');
        if (strtolower(trim($name)) === strtolower(self::GREAT_MINDS_SET_NAME)) {
            $set = $this->loadStickerSet(['_' => 'inputStickerSetID', 'id' => self::GREAT_MINDS_SET_ID, 'access_hash' => 0], '');
        } else {
            // StickersManager::search_sticker_set
            $set = $this->loadStickerSet(['_' => 'inputStickerSetShortName', 'short_name' => self::cleanUsername($name)], $name);
        }
        $query->answerQuery($this->stickerSetObject($set));
    }

    public function processGetCustomEmojiStickersQuery(Query $query): void
    {
        $json = self::getRequiredStringArg($query, 'custom_emoji_ids');
        $value = TdJson::tryDecode($json);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse custom emoji identifiers JSON object');
        }
        if (!$value->isArray()) {
            throw QueryError::badRequest('Expected an Array of custom emoji identifiers');
        }
        $ids = [];
        foreach ($value->array() as $item) {
            if ($item->type !== TdJson::STRING) {
                throw QueryError::badRequest('Custom emoji identifier must be of type String');
            }
            $id = TdInteger::toInt64Safe($item->string());
            if ($id === null) {
                throw QueryError::badRequest('Invalid custom emoji identifier specified');
            }
            $ids[] = $id;
        }

        // StickersManager::get_custom_emoji_stickers
        if (count($ids) > self::MAX_GET_CUSTOM_EMOJI_STICKERS) {
            throw new QueryError(400, 'Too many custom emoji identifiers specified');
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id !== 0)));
        $stickers = [];
        if ($ids !== []) {
            $documents = $this->invokeOrFail('messages.getCustomEmojiDocuments', ['document_id' => $ids]);
            $byId = [];
            foreach (is_array($documents) ? $documents : [] as $document) {
                $sticker = MediaConverter::document($document);
                if ($sticker !== null && $sticker['kind'] === MediaConverter::DOCUMENT_STICKER && $sticker['custom_emoji'] !== null) {
                    $byId[(int) $document['id']] = $sticker;
                }
            }
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $stickers[] = $byId[$id];
                }
            }
        }

        // Client::TdOnGetStickersCallback
        $this->loadStickerSetNames(array_values(array_filter(array_map([JsonSticker::class, 'inputStickerSet'], $stickers))));
        $query->answerQuery(array_map(fn (array $sticker): array => JsonSticker::make($sticker, $this), $stickers));
    }

    public function processUploadStickerFileQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        if ($query->hasArg('sticker') || $query->file('sticker') !== null) {
            $format = self::getStickerFormat($query->arg('sticker_format'));
            $sticker = $this->getInputFile($query, 'sticker', true);
        } else {
            $format = 'webp';
            $sticker = $this->getInputFile($query, 'png_sticker', true);
        }
        $this->checkUser($userId);

        // StickersManager::upload_sticker_file
        $file = $this->prepareStickerInputFile($sticker, $format, 'regular', false);
        $uploaded = $file === null ? null : $this->uploadStickerFile($userId, $file, $format);
        // finish_upload_sticker_file + Client::JsonFile
        $object = [];
        JsonMedia::storeFile($object, $uploaded ?? throw new QueryError(500, 'Failed to upload the file'));
        $query->answerQuery($object);
    }

    public function processCreateNewStickerSetQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $name = $query->arg('name');
        $title = $query->arg('title');
        $needsRepainting = self::boolArg($query, 'needs_repainting');
        $stickers = $this->getNewStickers($query);
        $type = self::getStickerType($query->arg('sticker_type'));
        if (self::boolArg($query, 'contains_masks')) {
            $type = 'mask';
        }
        $this->checkUser($userId);

        // StickersManager::create_new_sticker_set
        $title = TextFormatting::stripEmptyCharacters($title, self::MAX_STICKER_SET_TITLE_LENGTH);
        if ($title === '') {
            throw new QueryError(400, 'Sticker set title must be non-empty');
        }
        $name = TextFormatting::stripEmptyCharacters($name, self::MAX_STICKER_SET_SHORT_NAME_LENGTH);
        if ($name === '') {
            throw new QueryError(400, 'Sticker set name must be non-empty');
        }
        if ($stickers === []) {
            throw new QueryError(400, 'At least 1 sticker must be specified');
        }
        if ($needsRepainting && $type !== 'custom_emoji') {
            throw new QueryError(400, 'Only custom emoji stickers support repainting');
        }
        $files = [];
        foreach ($stickers as &$sticker) {
            $files[] = $this->prepareInputSticker($sticker, $type);
        }
        unset($sticker);

        $items = [];
        foreach ($stickers as $i => $sticker) {
            $items[] = $this->getInputStickerSetItem($sticker, $this->uploadStickerFile($userId, $files[$i], $sticker['format']));
        }
        $result = $this->invokeOrFail('stickers.createStickerSet', self::untl(self::tl('stickers.createStickerSet', [
            'masks' => $type === 'mask',
            'emojis' => $type === 'custom_emoji',
            'text_color' => $needsRepainting,
            'user_id' => $this->inputUser($userId),
            'title' => $title,
            'short_name' => $name,
            'stickers' => $items,
            'software' => 'bot'.$this->getMyId(),
        ])));
        if (($result['_'] ?? '') !== 'messages.stickerSet') {
            throw new QueryError(500, 'Created sticker set not found');
        }
        $this->onGetStickerSetName($result['set']);
        $query->answerQuery(true);
    }

    public function processAddStickerToSetQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $name = $query->arg('name');
        $sticker = $this->getNewSticker($query);
        $this->checkUser($userId);
        $this->addStickerToSet($userId, $name, $sticker, null);
        $query->answerQuery(true);
    }

    public function processReplaceStickerInSetQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $name = $query->arg('name');
        $oldSticker = self::getStickerInputFile($query, 'old_sticker');
        $sticker = $this->getNewSticker($query);
        $this->checkUser($userId);
        $this->addStickerToSet($userId, $name, $sticker, $oldSticker);
        $query->answerQuery(true);
    }

    public function processSetStickerPositionInSetQuery(Query $query): void
    {
        $sticker = self::getStickerInputFile($query);
        $position = self::getIntegerArg($query, 'position', -1);

        // StickersManager::set_sticker_position_in_set
        if ($position < 0) {
            throw new QueryError(400, 'Wrong sticker position specified');
        }
        $document = $this->getStickerInputDocument($sticker);
        $this->onStickerSetResult($this->invokeOrFail('stickers.changeStickerPosition', ['sticker' => $document, 'position' => $position]));
        $query->answerQuery(true);
    }

    public function processDeleteStickerFromSetQuery(Query $query): void
    {
        $document = $this->getStickerInputDocument(self::getStickerInputFile($query));
        $this->onStickerSetResult($this->invokeOrFail('stickers.removeStickerFromSet', ['sticker' => $document]));
        $query->answerQuery(true);
    }

    public function processSetStickerEmojiListQuery(Query $query): void
    {
        $sticker = self::getStickerInputFile($query);
        $emojis = self::getStickerEmojisArg($query->arg('emoji_list'));
        $document = $this->getStickerInputDocument($sticker);
        $this->onStickerSetResult($this->invokeOrFail('stickers.changeSticker', ['sticker' => $document, 'emoji' => $emojis]));
        $query->answerQuery(true);
    }

    public function processSetStickerKeywordsQuery(Query $query): void
    {
        $sticker = self::getStickerInputFile($query);
        $keywords = [];
        if ($query->hasArg('keywords')) {
            $value = TdJson::tryDecode($query->arg('keywords'));
            if ($value === null) {
                throw QueryError::badRequest('Can\'t parse keywords JSON object');
            }
            if (!$value->isArray()) {
                throw QueryError::badRequest('Field "keywords" must be an Array');
            }
            foreach ($value->array() as $keyword) {
                if ($keyword->type !== TdJson::STRING) {
                    throw QueryError::badRequest('keyword must be a string');
                }
                $keywords[] = $keyword->string();
            }
        }

        // StickersManager::set_sticker_keywords
        $document = $this->getStickerInputDocument($sticker);
        foreach ($keywords as $i => $keyword) {
            $keywords[$i] = self::cleanStickerString($keyword, 'Strings must be encoded in UTF-8');
            $keywords[$i] = strtr($keywords[$i], ",\n", '  ');
        }
        $this->onStickerSetResult($this->invokeOrFail('stickers.changeSticker', ['sticker' => $document, 'keywords' => implode(',', $keywords)]));
        $query->answerQuery(true);
    }

    public function processSetStickerMaskPositionQuery(Query $query): void
    {
        $sticker = self::getStickerInputFile($query);
        $maskPosition = $this->getMaskPositionArg($query, 'mask_position');
        $document = $this->getStickerInputDocument($sticker);
        $this->onStickerSetResult($this->invokeOrFail('stickers.changeSticker', self::untl(self::tl('stickers.changeSticker', [
            'sticker' => $document,
            'mask_coords' => self::maskCoords($maskPosition),
        ]))));
        $query->answerQuery(true);
    }

    public function processSetStickerSetTitleQuery(Query $query): void
    {
        // StickersManager::set_sticker_set_title
        $name = self::stickerSetShortName($query->arg('name'));
        $title = TextFormatting::stripEmptyCharacters($query->arg('title'), self::MAX_STICKER_SET_TITLE_LENGTH);
        if ($title === '') {
            throw new QueryError(400, 'Sticker set title must be non-empty');
        }
        $this->onStickerSetResult($this->invokeOrFail('stickers.renameStickerSet', [
            'stickerset' => ['_' => 'inputStickerSetShortName', 'short_name' => $name],
            'title' => $title,
        ]));
        $query->answerQuery(true);
    }

    public function processSetStickerSetThumbnailQuery(Query $query): void
    {
        $userId = self::getUserIdArg($query);
        $name = $query->arg('name');
        $thumbnail = $this->getInputFile($query, 'thumbnail') ?? $this->getInputFile($query, 'thumb');
        $formatStr = $query->arg('format');
        $format = self::getStickerFormat($formatStr === '' ? 'auto' : $formatStr);
        $this->checkUser($userId);

        // StickersManager::set_sticker_set_thumbnail
        $name = self::stickerSetShortName($name);
        $set = $this->loadStickerSet(['_' => 'inputStickerSetShortName', 'short_name' => $name], $name);
        if ($set['type'] === 'custom_emoji') {
            throw new QueryError(400, 'The method can\'t be used to set thumbnail of custom emoji sticker sets');
        }
        $format ??= self::guessStickerSetFormat($set);
        $file = $this->prepareStickerInputFile($thumbnail, $format, $set['type'], true);
        if ($file === null) {
            $document = ['_' => 'inputDocumentEmpty'];
        } else {
            $uploaded = $this->uploadStickerFile($userId, $file, (string) $format, true);
            $document = $uploaded['input_document'];
        }
        $this->onStickerSetResult($this->invokeOrFail('stickers.setStickerSetThumb', [
            'stickerset' => ['_' => 'inputStickerSetShortName', 'short_name' => $name],
            'thumb' => $document,
        ]));
        $query->answerQuery(true);
    }

    public function processSetCustomEmojiStickerSetThumbnailQuery(Query $query): void
    {
        $name = $query->arg('name');
        $customEmojiId = TdInteger::toInt64($query->arg('custom_emoji_id'));

        // StickersManager::set_custom_emoji_sticker_set_thumbnail
        $name = self::stickerSetShortName($name);
        $set = $this->loadStickerSet(['_' => 'inputStickerSetShortName', 'short_name' => $name], $name);
        if ($set['type'] !== 'custom_emoji') {
            throw new QueryError(400, 'The method can be used to set thumbnail only for custom emoji sticker sets');
        }
        $this->onStickerSetResult($this->invokeOrFail('stickers.setStickerSetThumb', [
            'stickerset' => ['_' => 'inputStickerSetShortName', 'short_name' => $name],
            'thumb_document_id' => $customEmojiId,
        ]));
        $query->answerQuery(true);
    }

    public function processDeleteStickerSetQuery(Query $query): void
    {
        // StickersManager::delete_sticker_set
        $name = self::stickerSetShortName($query->arg('name'));
        $result = $this->invokeOrFail('stickers.deleteStickerSet', ['stickerset' => ['_' => 'inputStickerSetShortName', 'short_name' => $name]]);
        if ($result !== true) {
            throw new QueryError(500, 'Failed to delete sticker set');
        }
        $query->answerQuery(true);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Sticker sets
    // ═════════════════════════════════════════════════════════════════

    /**
     * GetStickerSetQuery + StickersManager::on_get_messages_sticker_set.
     *
     * @param array<string, mixed> $inputSet
     * @return array{id: int, name: string, title: string, type: string, thumbnail: array<string, mixed>|null, stickers: list<array<string, mixed>>}
     */
    private function loadStickerSet(array $inputSet, string $requestedName): array
    {
        $result = $this->invokeOrFail('messages.getStickerSet', ['stickerset' => $inputSet, 'hash' => 0]);
        if (($result['_'] ?? '') !== 'messages.stickerSet') {
            throw new QueryError(400, 'Sticker set not found');
        }
        $set = $result['set'];
        if ((int) $set['id'] === self::GREAT_MINDS_COLOR_SET_ID
            && ((int) ($inputSet['id'] ?? 0) === self::GREAT_MINDS_SET_ID || strtolower(trim($requestedName)) === strtolower(self::GREAT_MINDS_SET_NAME))) {
            $set['id'] = self::GREAT_MINDS_SET_ID;
            $set['short_name'] = self::GREAT_MINDS_SET_NAME;
        }
        $this->onGetStickerSetName($set);

        $stickers = [];
        foreach ($result['documents'] ?? [] as $document) {
            $sticker = MediaConverter::document($document);
            if ($sticker !== null && $sticker['kind'] === MediaConverter::DOCUMENT_STICKER) {
                $stickers[] = $sticker;
            }
        }
        $type = !empty($set['emojis']) ? 'custom_emoji' : (!empty($set['masks']) ? 'mask' : 'regular');

        return [
            'id' => (int) $set['id'],
            'name' => (string) $set['short_name'],
            'title' => (string) $set['title'],
            'type' => $type,
            'thumbnail' => $this->stickerSetThumbnail($set, $type, $stickers),
            'stickers' => $stickers,
        ];
    }

    /**
     * Client::on_get_sticker_set_name.
     *
     * @param array<string, mixed> $set TL stickerSet
     */
    private function onGetStickerSetName(array $set): void
    {
        $this->stickerSetNames[(int) $set['id']] = (string) $set['short_name'];
    }

    /**
     * The messages.StickerSet result of a sticker set change.
     */
    private function onStickerSetResult(mixed $result): void
    {
        if (is_array($result) && ($result['_'] ?? '') === 'messages.stickerSet') {
            $this->onGetStickerSetName($result['set']);
        }
    }

    /**
     * StickersManager::get_sticker_set_thumbnail_object.
     *
     * @param array<string, mixed> $set TL stickerSet
     * @param list<array<string, mixed>> $stickers
     * @return array{file: array<string, mixed>, width: int, height: int, format: string}|null
     */
    private function stickerSetThumbnail(array $set, string $type, array $stickers): ?array
    {
        $documentId = (int) ($set['thumb_document_id'] ?? 0);
        if ($documentId !== 0 && $type === 'custom_emoji') {
            foreach ($stickers as $sticker) {
                if ((int) ($sticker['id'] ?? 0) === $documentId) {
                    return ['file' => $sticker['file'], 'width' => $sticker['width'], 'height' => $sticker['height'],
                        'format' => MediaConverter::stickerFormat($sticker)];
                }
            }
        }

        foreach ($set['thumbs'] ?? [] as $thumb) {
            if (!in_array($thumb['_'] ?? '', ['photoSize', 'photoCachedSize', 'photoSizeProgressive'], true)) {
                continue;
            }
            $sizeType = (string) ($thumb['type'] ?? '');
            $format = match ($sizeType) {
                's' => 'webp',
                'v' => 'webm',
                'a' => 'tgs',
                default => 'webp',
            };
            $size = MediaConverter::stickerSetThumbnailSize($thumb, PhotoSizeSource::stickerSetThumbnail((int) $set['id'],
                (int) $set['access_hash'], (int) ($set['thumb_version'] ?? 0)), (int) ($set['thumb_dc_id'] ?? 0), $format);
            if ($size !== null) {
                return $size + ['format' => $format];
            }
        }

        return null;
    }

    /**
     * Client::JsonStickerSet.
     *
     * @param array<string, mixed> $set
     * @return array<string, mixed>
     */
    private function stickerSetObject(array $set): array
    {
        $object = [
            'name' => $set['id'] === self::GREAT_MINDS_SET_ID ? self::GREAT_MINDS_SET_NAME : $set['name'],
            'title' => $set['title'],
        ];
        if ($set['thumbnail'] !== null) {
            JsonMedia::storeThumbnail($object, $set['thumbnail']);
        }
        $object['sticker_type'] = $set['type'];
        $object['contains_masks'] = $set['type'] === 'mask';
        $object['stickers'] = array_map(fn (array $sticker): array => JsonSticker::make($sticker, $this), $set['stickers']);

        return $object;
    }

    /**
     * StickersManager::guess_sticker_set_format.
     *
     * @param array<string, mixed> $set
     */
    private static function guessStickerSetFormat(array $set): ?string
    {
        $format = null;
        foreach ($set['stickers'] as $sticker) {
            $stickerFormat = MediaConverter::stickerFormat($sticker);
            if ($format === null) {
                $format = $stickerFormat;
            } elseif ($format !== $stickerFormat) {
                return null;
            }
        }

        return $format;
    }

    /**
     * StickersManager::add_sticker_to_set / do_add_sticker_to_set.
     *
     * @param array<string, mixed> $sticker
     * @param array<string, mixed>|null $oldSticker
     */
    private function addStickerToSet(int $userId, string $name, array $sticker, ?array $oldSticker): void
    {
        $name = self::stickerSetShortName($name);
        $set = $this->loadStickerSet(['_' => 'inputStickerSetShortName', 'short_name' => $name], $name);
        $oldDocument = $oldSticker === null ? null : $this->getStickerInputDocument($oldSticker);
        if ($sticker['format'] === null) {
            $sticker['format'] = self::guessStickerSetFormat($set);
        }
        $file = $this->prepareInputSticker($sticker, $set['type']);
        $item = $this->getInputStickerSetItem($sticker, $this->uploadStickerFile($userId, $file, (string) $sticker['format']));

        $result = $oldDocument !== null
            ? $this->invokeOrFail('stickers.replaceSticker', ['sticker' => $oldDocument, 'new_sticker' => $item])
            : $this->invokeOrFail('stickers.addStickerToSet', ['stickerset' => ['_' => 'inputStickerSetShortName', 'short_name' => $name], 'sticker' => $item]);
        if (($result['_'] ?? '') !== 'messages.stickerSet') {
            throw new QueryError(500, 'Sticker set not found');
        }
        $this->onGetStickerSetName($result['set']);
    }

    /**
     * clean_username(strip_empty_characters(short_name)) of the sticker set methods.
     */
    private static function stickerSetShortName(string $name): string
    {
        $name = self::cleanUsername(TextFormatting::stripEmptyCharacters($name, self::MAX_STICKER_SET_SHORT_NAME_LENGTH));
        if ($name === '') {
            throw new QueryError(400, 'Sticker set name must be non-empty');
        }

        return $name;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Sticker files
    // ═════════════════════════════════════════════════════════════════

    /**
     * StickersManager::prepare_input_sticker.
     *
     * @param array<string, mixed> $sticker
     * @return array<string, mixed>
     */
    private function prepareInputSticker(array &$sticker, string $type): array
    {
        $sticker['emojis'] = self::cleanStickerString($sticker['emojis'], 'Emojis must be encoded in UTF-8');
        foreach ($sticker['keywords'] as $i => $keyword) {
            $sticker['keywords'][$i] = strtr(self::cleanStickerString($keyword, 'Keywords must be encoded in UTF-8'), ",\n", '  ');
        }
        $file = $this->prepareStickerInputFile($sticker['sticker'], $sticker['format'], $type, false);

        return $file ?? throw new QueryError(400, 'InputFile is not specified');
    }

    private static function cleanStickerString(string $value, string $error): string
    {
        return TextFormatting::cleanInputString($value) ?? throw new QueryError(400, $error);
    }

    /**
     * StickersManager::prepare_input_file: the file and how it gets to the server.
     *
     * @param array<string, mixed>|null $inputFile td_api InputFile
     * @return array<string, mixed>|null
     */
    private function prepareStickerInputFile(?array $inputFile, ?string $format, string $type, bool $forThumbnail): ?array
    {
        if ($format === null) {
            throw new QueryError(400, 'Sticker format must be non-empty');
        }
        $file = $this->getInputFileId($format === 'tgs' ? FileType::STICKER : FileType::DOCUMENT, $inputFile, $forThumbnail);
        if ($file === null) {
            return null;
        }
        if ($file['kind'] === 'remote') {
            if ($file['location']->isWeb()) {
                throw new QueryError(400, 'Can\'t use web file to create a sticker');
            }
        } elseif ($file['kind'] === 'url') {
            if ($format === 'tgs') {
                throw new QueryError(400, 'Animated stickers can\'t be uploaded by URL');
            }
            if ($format === 'webm') {
                throw new QueryError(400, 'Video stickers can\'t be uploaded by URL');
            }
        } elseif ($file['size'] > self::maxStickerFileSize($format, $type, $forThumbnail)) {
            throw new QueryError(400, 'File is too big');
        }

        return $file;
    }

    /**
     * td::get_max_sticker_file_size.
     */
    private static function maxStickerFileSize(string $format, string $type, bool $forThumbnail): int
    {
        $isCustomEmoji = $type === 'custom_emoji';

        return match ($format) {
            'tgs' => $forThumbnail ? (1 << 15) : (1 << 16),
            'webm' => $forThumbnail ? (1 << 15) : ($isCustomEmoji ? (1 << 16) : (1 << 18)),
            default => $forThumbnail ? (1 << 17) : ($isCustomEmoji ? (1 << 17) : (1 << 19)),
        };
    }

    /**
     * StickersManager::upload_sticker_file / do_upload_sticker_file / on_uploaded_sticker_file:
     * the uploaded document's file and InputDocument.
     *
     * @param array<string, mixed> $file
     * @return array{file_id: string, file_unique_id: string, size: int, input_document: array<string, mixed>}
     */
    private function uploadStickerFile(int $userId, array $file, string $format, bool $forThumbnail = false): array
    {
        if ($file['kind'] === 'remote') {
            $location = $file['location'];
            $hint = MediaConverter::fileHint($location->toUniqueId());

            return MediaConverter::file($location, (int) ($hint[1] ?? 0)) + ['input_document' => self::inputDocument($location)];
        }

        if ($file['kind'] === 'url') {
            $media = ['_' => 'inputMediaDocumentExternal', 'url' => $file['url']];
        } elseif ($format === 'tgs') {
            $width = $forThumbnail ? 100 : 512;
            $media = [
                '_' => 'inputMediaUploadedDocument',
                'file' => $this->uploadFile($file),
                'mime_type' => 'application/x-tgsticker',
                'attributes' => [
                    ['_' => 'documentAttributeImageSize', 'w' => $width, 'h' => $width],
                    ['_' => 'documentAttributeSticker', 'alt' => '', 'stickerset' => ['_' => 'inputStickerSetEmpty']],
                ],
            ];
        } else {
            $name = $format === 'webm' ? 'sticker.webm' : 'sticker.png';
            $media = [
                '_' => 'inputMediaUploadedDocument',
                'file' => $this->uploadFile($file),
                'mime_type' => $format === 'webm' ? 'video/webm' : 'image/png',
                'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => $name]],
            ];
        }

        $result = $this->invokeOrFail('messages.uploadMedia', ['peer' => $this->inputPeer($userId), 'media' => $media]);
        if (($result['_'] ?? '') !== 'messageMediaDocument') {
            throw new QueryError(400, 'Can\'t upload sticker file: wrong file type');
        }
        $document = $result['document'] ?? [];
        if (($document['_'] ?? '') !== 'document') {
            throw new QueryError(400, 'Can\'t upload sticker file: empty file');
        }
        $parsed = MediaConverter::document($document);
        $expected = $format === 'tgs' ? MediaConverter::DOCUMENT_STICKER : MediaConverter::DOCUMENT_GENERAL;
        $location = RemoteFileLocation::common($format === 'tgs' ? FileType::STICKER : FileType::DOCUMENT, (int) $document['dc_id'],
            (int) $document['id'], (int) $document['access_hash'], (string) $document['file_reference']);
        if (($parsed['kind'] ?? '') !== $expected) {
            // a WEBP sticker uploaded by URL is used as a document
            if (!($file['kind'] === 'url' && $expected === MediaConverter::DOCUMENT_GENERAL && ($parsed['kind'] ?? '') === MediaConverter::DOCUMENT_STICKER)) {
                throw new QueryError(400, 'Wrong file type');
            }
        }

        return MediaConverter::file($location, (int) ($document['size'] ?? 0)) + ['input_document' => self::inputDocument($location)];
    }

    /**
     * StickersManager::get_input_sticker.
     *
     * @param array<string, mixed> $sticker
     * @param array<string, mixed> $uploaded
     * @return array<string, mixed>
     */
    private function getInputStickerSetItem(array $sticker, array $uploaded): array
    {
        $keywords = implode(',', $sticker['keywords']);

        return self::tl('inputStickerSetItem', [
            'document' => $uploaded['input_document'],
            'emoji' => $sticker['emojis'],
            'mask_coords' => self::maskCoords($sticker['mask_position']),
            'keywords' => $keywords !== '' ? $keywords : null,
        ]);
    }

    /**
     * StickersManager::get_sticker_input_document.
     *
     * @param array<string, mixed> $sticker
     * @return array<string, mixed>
     */
    private function getStickerInputDocument(array $sticker): array
    {
        $file = $this->getInputFileId(FileType::STICKER, $sticker);
        if ($file['kind'] !== 'remote' || $file['location']->isPhoto()) {
            throw new QueryError(400, 'Wrong sticker file specified');
        }

        return self::inputDocument($file['location']);
    }

    /**
     * StickerMaskPosition::get_input_mask_coords.
     *
     * @param array<string, mixed>|null $maskPosition
     * @return array<string, mixed>|null
     */
    private static function maskCoords(?array $maskPosition): ?array
    {
        if ($maskPosition === null) {
            return null;
        }

        return ['_' => 'maskCoords', 'n' => $maskPosition['point'], 'x' => $maskPosition['x_shift'],
            'y' => $maskPosition['y_shift'], 'zoom' => $maskPosition['scale']];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Arguments
    // ═════════════════════════════════════════════════════════════════

    /**
     * Client::get_sticker_format: the format, or null for "auto".
     */
    private static function getStickerFormat(string $format): ?string
    {
        return match ($format) {
            'static' => 'webp',
            'animated' => 'tgs',
            'video' => 'webm',
            'auto' => null,
            default => throw QueryError::badRequest('Invalid sticker format specified'),
        };
    }

    /**
     * Client::get_sticker_type.
     */
    private static function getStickerType(string $type): string
    {
        return match ($type) {
            '', 'regular' => 'regular',
            'mask' => 'mask',
            'custom_emoji' => 'custom_emoji',
            default => throw QueryError::badRequest('Unsupported sticker type specified'),
        };
    }

    /**
     * Client::get_sticker_input_file.
     *
     * @return array<string, mixed>
     */
    private static function getStickerInputFile(Query $query, string $field = 'sticker'): array
    {
        $fileId = trim($query->arg($field));
        if ($fileId === '') {
            throw QueryError::badRequest('Sticker is not specified');
        }

        return ['_' => 'inputFileRemote', 'id' => $fileId];
    }

    /**
     * Client::get_mask_position(query, field_name).
     *
     * @return array{point: int, x_shift: float, y_shift: float, scale: float}|null
     */
    private function getMaskPositionArg(Query $query, string $field): ?array
    {
        $maskPosition = $query->arg($field);
        if ($maskPosition === '') {
            return null;
        }
        $value = TdJson::tryDecode($maskPosition);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse mask position JSON object');
        }
        try {
            return self::getMaskPosition($value);
        } catch (JsonError|QueryError $e) {
            throw QueryError::badRequest('Can\'t parse mask position: '.$e->getMessage());
        }
    }

    /**
     * Client::get_mask_position(JsonValue).
     *
     * @return array{point: int, x_shift: float, y_shift: float, scale: float}|null
     */
    private static function getMaskPosition(JsonValue $value): ?array
    {
        if (!$value->isObject()) {
            if ($value->isNull()) {
                return null;
            }
            throw new JsonError('MaskPosition must be an Object');
        }
        $object = $value->object();
        $point = array_search(strtolower(trim($object->getRequiredStringField('point'))), self::STICKER_MASK_POINTS, true);
        if ($point === false) {
            throw new JsonError('Wrong point specified in MaskPosition');
        }

        return [
            'point' => $point,
            'x_shift' => $object->getRequiredDoubleField('x_shift'),
            'y_shift' => $object->getRequiredDoubleField('y_shift'),
            'scale' => $object->getRequiredDoubleField('scale'),
        ];
    }

    /**
     * Client::get_sticker_emojis(MutableSlice).
     */
    private static function getStickerEmojisArg(string $emojiList): string
    {
        $value = TdJson::tryDecode($emojiList);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse emoji list JSON array');
        }
        try {
            return self::getStickerEmojis($value);
        } catch (JsonError $e) {
            throw QueryError::badRequest('Can\'t parse emoji list: '.$e->getMessage());
        }
    }

    /**
     * Client::get_sticker_emojis(JsonValue).
     */
    private static function getStickerEmojis(JsonValue $value): string
    {
        if (!$value->isArray()) {
            throw new JsonError('expected an Array of string');
        }
        $count = count($value->array());
        if ($count === 0) {
            throw new JsonError('emoji list must be non-empty');
        }
        if ($count > self::MAX_STICKER_EMOJI_COUNT) {
            throw new JsonError('too many emoji specified');
        }
        $result = '';
        foreach ($value->array() as $emoji) {
            if ($emoji->type !== TdJson::STRING) {
                throw new JsonError('emoji must be a string');
            }
            if (!Emoji::isEmoji($emoji->string())) {
                throw new JsonError('expected a Unicode emoji');
            }
            $result .= $emoji->string();
        }

        return $result;
    }

    /**
     * Client::get_new_stickers.
     *
     * @return list<array<string, mixed>>
     */
    private function getNewStickers(Query $query): array
    {
        if ($query->hasArg('stickers')) {
            $format = $query->arg('sticker_format');
            $value = TdJson::tryDecode($query->arg('stickers'));
            if ($value === null) {
                throw QueryError::badRequest('Can\'t parse stickers JSON object');
            }

            return $this->parseArray($value, 'InputSticker', fn (JsonValue $item): array => $this->getNewStickerJson($query, $item, $format));
        }

        return [$this->getLegacyNewSticker($query)];
    }

    /**
     * Client::get_new_sticker(query).
     *
     * @return array<string, mixed>
     */
    private function getNewSticker(Query $query): array
    {
        if ($query->hasArg('sticker') || $query->file('sticker') !== null) {
            $value = TdJson::tryDecode($query->arg('sticker'));
            if ($value === null) {
                throw QueryError::badRequest('Can\'t parse sticker JSON object');
            }
            try {
                return $this->getNewStickerJson($query, $value, 'auto');
            } catch (JsonError|QueryError $e) {
                throw QueryError::badRequest('Can\'t parse sticker: '.$e->getMessage());
            }
        }

        return $this->getLegacyNewSticker($query);
    }

    /**
     * Client::get_new_sticker(query, value, default_sticker_format).
     *
     * @return array<string, mixed>
     */
    private function getNewStickerJson(Query $query, JsonValue $value, string $defaultFormat): array
    {
        if (!$value->isObject()) {
            throw new JsonError('InputSticker must be an Object');
        }
        $object = $value->object();
        $input = $object->getOptionalStringField('sticker');
        $file = $this->getInputFile($query, '', false, $input);
        if ($file === null) {
            throw new JsonError('sticker not found');
        }
        if ($defaultFormat === '') {
            $formatStr = $object->getRequiredStringField('format');
        } else {
            $formatStr = $object->getOptionalStringField('format');
            if ($formatStr === '') {
                $formatStr = $defaultFormat;
            }
        }
        try {
            $format = self::getStickerFormat($formatStr);
        } catch (QueryError $e) {
            throw new JsonError($e->getMessage());
        }
        $emojis = self::getStickerEmojis($object->extractRequiredField('emoji_list', TdJson::ARRAY));
        $maskPosition = self::getMaskPosition($object->extractField('mask_position'));
        $keywords = [];
        if ($object->hasField('keywords')) {
            foreach ($object->extractRequiredField('keywords', TdJson::ARRAY)->array() as $keyword) {
                if ($keyword->type !== TdJson::STRING) {
                    throw new JsonError('keyword must be a string');
                }
                $keywords[] = $keyword->string();
            }
        }

        return ['sticker' => $file, 'format' => $format, 'emojis' => $emojis, 'mask_position' => $maskPosition, 'keywords' => $keywords];
    }

    /**
     * Client::get_legacy_new_sticker.
     *
     * @return array<string, mixed>
     */
    private function getLegacyNewSticker(Query $query): array
    {
        $emojis = $query->arg('emojis');
        $sticker = $this->getInputFile($query, 'png_sticker');
        $maskPosition = null;
        if ($sticker !== null) {
            $format = 'webp';
            $maskPosition = $this->getMaskPositionArg($query, 'mask_position');
        } else {
            $sticker = $this->getInputFile($query, 'tgs_sticker', true);
            if ($sticker !== null) {
                $format = 'tgs';
            } else {
                $sticker = $this->getInputFile($query, 'webm_sticker', true);
                if ($sticker !== null) {
                    $format = 'webm';
                } else {
                    if ($query->arg('tgs_sticker') !== '') {
                        throw new QueryError(400, 'Bad Request: animated sticker must be uploaded as an InputFile');
                    }
                    if ($query->arg('webm_sticker') !== '') {
                        throw new QueryError(400, 'Bad Request: video sticker must be uploaded as an InputFile');
                    }
                    throw new QueryError(400, 'Bad Request: there is no sticker file in the request');
                }
            }
        }

        return ['sticker' => $sticker, 'format' => $format, 'emojis' => $emojis, 'mask_position' => $maskPosition, 'keywords' => []];
    }
}
