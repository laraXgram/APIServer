<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot\Concerns;

use LaraGram\Server\Bot\QueryError;
use LaraGram\Server\Entities\FormattedDate;
use LaraGram\Server\Entities\FormattedText;
use LaraGram\Server\Entities\TextFormatting;
use LaraGram\Server\Entities\TgLinks;
use LaraGram\Server\Http\Query;
use LaraGram\Server\Messages\MediaConverter;
use LaraGram\Server\Objects\JsonRichMessage;
use LaraGram\Server\Support\JsonError;
use LaraGram\Server\Support\JsonObject;
use LaraGram\Server\Support\JsonValue;
use LaraGram\Server\Support\TdJson;

/**
 * Rich messages: the Bot API parsers of Client::get_input_rich_message (JSON →
 * td_api shapes), TDLib's RichMessage::get_rich_message with the WebPageBlock
 * and RichText input conversions, and RichMessage::get_input_rich_message.
 *
 * A prepared rich message is ['_' => '@richMessage', ...]: its media are sent
 * as placeholders, and the InputRichMessage is built once they are on the
 * server (uploaded with messages.uploadMedia when needed).
 *
 * @mixin \LaraGram\Server\Bot\Client
 */
trait HandlesRichMessages
{
    // ═════════════════════════════════════════════════════════════════
    //  Bot API side
    // ═════════════════════════════════════════════════════════════════

    /**
     * Client::get_input_rich_message (query argument).
     *
     * @return array<string, mixed> td_api inputRichMessage
     */
    protected function getInputRichMessage(Query $query): array
    {
        $richMessage = $query->arg('rich_message');
        if ($richMessage === '') {
            throw QueryError::badRequest('Rich message must be non-empty');
        }
        $value = TdJson::tryDecode($richMessage);
        if ($value === null) {
            throw QueryError::badRequest('Can\'t parse rich message JSON object');
        }

        return $this->inputRichMessageFromJson($query, $value);
    }

    /**
     * Client::get_input_rich_message (JSON value).
     *
     * @return array<string, mixed>
     */
    protected function inputRichMessageFromJson(Query $query, JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('Object expected as rich message');
        }
        $object = $value->object();

        return self::json(function () use ($query, $object): array {
            $result = [
                '_' => 'inputRichMessage',
                'is_rtl' => $object->getOptionalBoolField('is_rtl'),
                'detect_automatic_blocks' => !$object->getOptionalBoolField('skip_entity_detection'),
            ];
            if ($object->hasField('blocks')) {
                $result['source'] = ['_' => 'blocks', 'blocks' => $this->inputPageBlocksFromJson($query, $object->extractField('blocks'))];
            } elseif ($object->hasField('markdown')) {
                $text = $object->getRequiredStringField('markdown');
                $result['source'] = ['_' => 'markdown', 'text' => $text, 'media' => $this->inputRichMessageMediasFromJson($query, $object->extractField('media'))];
            } elseif ($object->hasField('html')) {
                $text = $object->getRequiredStringField('html');
                $result['source'] = ['_' => 'html', 'text' => $text, 'media' => $this->inputRichMessageMediasFromJson($query, $object->extractField('media'))];
            } else {
                throw QueryError::badRequest('Rich message must be non-empty');
            }

            return $result;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inputPageBlocksFromJson(Query $query, JsonValue $value): array
    {
        return $this->parseArray($value, 'InputRichBlock', fn (JsonValue $block): array => $this->inputPageBlockFromJson($query, $block));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inputRichMessageMediasFromJson(Query $query, JsonValue $value): array
    {
        return $this->parseArray($value, 'InputRichMessageMedia', function (JsonValue $media) use ($query): array {
            if (!$media->isObject()) {
                throw new JsonError('expected an Object');
            }
            $object = $media->object();
            $id = $object->getRequiredStringField('id');
            $mediaObject = $object->extractRequiredField('media', TdJson::OBJECT)->object();
            $type = $mediaObject->getRequiredStringField('type');

            return ['id' => $id, 'media' => $this->getInputMediaOfType($query, $mediaObject, $type, null, false, false, false, true)];
        });
    }

    /**
     * Client::get_input_page_block.
     *
     * @return array<string, mixed> td_api InputPageBlock
     */
    private function inputPageBlockFromJson(Query $query, JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('Object expected as InputRichMessageBlock');
        }
        $object = $value->object();
        $type = $object->getRequiredStringField('type');
        switch ($type) {
            case 'heading':
                $text = $this->richTextFromJson($object->extractField('text'));

                return ['_' => 'heading', 'text' => $text, 'size' => $object->getRequiredIntField('size')];
            case 'paragraph':
            case 'footer':
            case 'thinking':
                return ['_' => $type, 'text' => $this->richTextFromJson($object->extractField('text'))];
            case 'pre':
                $text = $this->richTextFromJson($object->extractField('text'));

                return ['_' => 'pre', 'text' => $text, 'language' => $object->getOptionalStringField('language')];
            case 'divider':
                return ['_' => 'divider'];
            case 'mathematical_expression':
                return ['_' => 'mathematical_expression', 'expression' => $object->getRequiredStringField('expression')];
            case 'anchor':
                return ['_' => 'anchor', 'name' => $object->getRequiredStringField('name')];
            case 'list':
                $items = $this->parseArray($object->extractField('items'), 'InputRichBlockListItem', function (JsonValue $item) use ($query): array {
                    if (!$item->isObject()) {
                        throw QueryError::badRequest('Object expected as InputRichBlockListItem');
                    }
                    $item = $item->object();
                    $blocks = $this->inputPageBlocksFromJson($query, $item->extractField('blocks'));

                    return [
                        'blocks' => $blocks,
                        'has_checkbox' => $item->getOptionalBoolField('has_checkbox'),
                        'is_checked' => $item->getOptionalBoolField('is_checked'),
                        'value' => $item->getOptionalIntField('value'),
                        'type' => $item->getOptionalStringField('type'),
                    ];
                });

                return ['_' => 'list', 'items' => $items];
            case 'blockquote':
                $blocks = $this->inputPageBlocksFromJson($query, $object->extractField('blocks'));

                return ['_' => 'blockquote', 'blocks' => $blocks, 'credit' => $this->richTextFromJson($object->extractField('credit'))];
            case 'expandable_blockquote':
            case 'pullquote':
                $text = $this->richTextFromJson($object->extractField('text'));

                return ['_' => $type, 'text' => $text, 'credit' => $this->richTextFromJson($object->extractField('credit'))];
            case 'collage':
            case 'slideshow':
                $blocks = $this->inputPageBlocksFromJson($query, $object->extractField('blocks'));

                return ['_' => $type, 'blocks' => $blocks, 'caption' => $this->pageBlockCaptionFromJson($object->extractField('caption'))];
            case 'table':
                $cells = [];
                foreach ($object->extractRequiredField('cells', TdJson::ARRAY)->array() as $row) {
                    $cells[] = $this->parseArray($row, 'PageBlockTableCell', fn (JsonValue $cell): array => $this->pageBlockTableCellFromJson($cell));
                }
                $caption = $this->richTextFromJson($object->extractField('caption'));

                return [
                    '_' => 'table', 'caption' => $caption, 'cells' => $cells,
                    'is_bordered' => $object->getOptionalBoolField('is_bordered'),
                    'is_striped' => $object->getOptionalBoolField('is_striped'),
                    'is_compact' => $object->getOptionalBoolField('is_compact'),
                ];
            case 'details':
                $summary = $this->richTextFromJson($object->extractField('summary'));
                $blocks = $this->inputPageBlocksFromJson($query, $object->extractField('blocks'));

                return ['_' => 'details', 'summary' => $summary, 'blocks' => $blocks, 'is_open' => $object->getOptionalBoolField('is_open')];
            case 'map':
                $location = self::getLocationFromJson($object->extractRequiredField('location', TdJson::OBJECT)->object());

                return [
                    '_' => 'map', 'location' => $location,
                    'zoom' => $object->getOptionalIntField('zoom'),
                    'width' => $object->getOptionalIntField('width'),
                    'height' => $object->getOptionalIntField('height'),
                    'caption' => $this->pageBlockCaptionFromJson($object->extractField('caption')),
                ];
            case 'buttons':
                $buttons = $this->parseArray($object->extractRequiredField('buttons', TdJson::ARRAY), 'RichMessageButton',
                    fn (JsonValue $button): array => $this->inlineButtonFromJson($button));
                $align = $object->getOptionalStringField('align');
                if (!in_array($align, ['', 'left', 'center', 'right'], true)) {
                    throw QueryError::badRequest('Invalid horizontal alignment specified');
                }

                return ['_' => 'buttons', 'buttons' => $buttons, 'align' => $align];
        }

        if (!in_array($type, ['animation', 'audio', 'document', 'photo', 'video', 'voice_note'], true)) {
            throw QueryError::badRequest("type \"{$type}\" is unsupported");
        }
        $mediaObject = $object->extractRequiredField($type, TdJson::OBJECT)->object();
        $mediaType = $mediaObject->getRequiredStringField('type');
        if ($mediaType !== $type) {
            throw QueryError::badRequest("Unexpected media type \"{$mediaType}\" for block \"{$type}\"");
        }
        $content = $this->getInputMediaOfType($query, $mediaObject, $type, null, false, false, false, true);
        $caption = $this->pageBlockCaptionFromJson($object->extractField('caption'));
        $hasSpoiler = $mediaObject->getOptionalBoolField('has_spoiler');

        return ['_' => $type, 'content' => $content, 'caption' => $caption, 'has_spoiler' => $hasSpoiler];
    }

    /**
     * Client::get_page_block_caption.
     *
     * @return array{text: array<string, mixed>|null, credit: array<string, mixed>|null}|null
     */
    private function pageBlockCaptionFromJson(JsonValue $value): ?array
    {
        if ($value->isNull()) {
            return null;
        }
        if (!$value->isObject()) {
            throw QueryError::badRequest('RichBlockCaption must be an object');
        }
        $object = $value->object();
        $text = $this->richTextFromJson($object->extractField('text'));

        return ['text' => $text, 'credit' => $this->richTextFromJson($object->extractField('credit'))];
    }

    /**
     * Client::get_page_block_table_cell.
     *
     * @return array<string, mixed>
     */
    private function pageBlockTableCellFromJson(JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('RichBlockTableCell must be an object');
        }
        $object = $value->object();
        $text = $this->richTextFromJson($object->extractField('text'));
        $isHeader = $object->getOptionalBoolField('is_header');
        $colspan = $object->getOptionalIntField('colspan');
        $rowspan = $object->getOptionalIntField('rowspan');

        $align = $object->getOptionalStringField('align');
        if ($align === '') {
            $align = $isHeader ? 'center' : 'left';
        } elseif (!in_array($align, ['left', 'center', 'right'], true)) {
            throw QueryError::badRequest('Invalid horizontal alignment specified');
        }
        $valign = $object->getOptionalStringField('valign');
        if ($valign === '') {
            $valign = 'middle';
        } elseif (!in_array($valign, ['top', 'middle', 'bottom'], true)) {
            throw QueryError::badRequest('Invalid vertical alignment specified');
        }

        return ['text' => $text, 'is_header' => $isHeader, 'colspan' => $colspan, 'rowspan' => $rowspan, 'align' => $align, 'valign' => $valign];
    }

    /**
     * Client::get_inline_button.
     *
     * @return array{text: array<string, mixed>|null, style: string, type: array<string, mixed>}
     */
    private function inlineButtonFromJson(JsonValue $value): array
    {
        if (!$value->isObject()) {
            throw QueryError::badRequest('InlineButton must be an Object');
        }
        $object = $value->object();
        $text = $this->richTextFromJson($object->extractField('text'));
        $style = self::getButtonStyle($object->getOptionalStringField('style'), true);

        return ['text' => $text, 'style' => $style, 'type' => $this->getInlineKeyboardButtonType($object, false)];
    }

    /**
     * Client::get_rich_text.
     *
     * @return array<string, mixed>|null td_api RichText
     */
    private function richTextFromJson(JsonValue $value): ?array
    {
        if ($value->isNull()) {
            return null;
        }
        if ($value->type === TdJson::STRING) {
            return ['_' => 'plain', 'text' => $value->string()];
        }
        if ($value->isArray()) {
            $texts = [];
            foreach ($value->array() as $text) {
                $texts[] = $this->richTextFromJson($text);
            }

            return ['_' => 'texts', 'texts' => $texts];
        }
        if (!$value->isObject()) {
            throw QueryError::badRequest('Invalid rich text specified');
        }

        $object = $value->object();
        $type = $object->getRequiredStringField('type');
        $text = fn (): ?array => $this->richTextFromJson($object->extractField('text'));
        switch ($type) {
            case 'bold': case 'italic': case 'underline': case 'strikethrough': case 'spoiler': case 'mention':
            case 'hashtag': case 'cashtag': case 'bot_command': case 'code': case 'bank_card_number':
            case 'subscript': case 'superscript': case 'marked':
                return ['_' => $type, 'text' => $text()];
            case 'date_time':
                $result = ['_' => 'date_time', 'text' => $text(), 'unix_time' => $object->getRequiredIntField('unix_time')];
                $result['flags'] = self::dateTimeFormatFlags($object->getOptionalStringField('date_time_format'));

                return $result;
            case 'text_mention':
                $result = ['_' => 'text_mention', 'text' => $text()];
                $user = $object->extractRequiredField('user', TdJson::OBJECT)->object();
                $result['user_id'] = $user->getRequiredLongField('id');

                return $result;
            case 'url':
                return ['_' => 'url', 'text' => $text(), 'url' => $object->getRequiredStringField('url')];
            case 'email_address':
                return ['_' => 'email_address', 'text' => $text(), 'email_address' => $object->getRequiredStringField('email_address')];
            case 'phone_number':
                return ['_' => 'phone_number', 'text' => $text(), 'phone_number' => $object->getRequiredStringField('phone_number')];
            case 'custom_emoji':
                $id = $object->getRequiredLongField('custom_emoji_id');

                return ['_' => 'custom_emoji', 'custom_emoji_id' => $id, 'alternative_text' => $object->getRequiredStringField('alternative_text')];
            case 'mathematical_expression':
                return ['_' => 'mathematical_expression', 'expression' => $object->getRequiredStringField('expression')];
            case 'reference':
                return ['_' => 'reference', 'text' => $text(), 'name' => $object->getRequiredStringField('name')];
            case 'reference_link':
                return ['_' => 'reference_link', 'text' => $text(), 'name' => $object->getRequiredStringField('reference_name')];
            case 'anchor':
                return ['_' => 'anchor', 'name' => $object->getRequiredStringField('name')];
            case 'anchor_link':
                return ['_' => 'anchor_link', 'text' => $text(), 'name' => $object->getRequiredStringField('anchor_name')];
            case 'button':
                return ['_' => 'button', 'button' => $this->inlineButtonFromJson($object->extractField('button'))];
        }

        throw QueryError::badRequest('Unsupported rich text type');
    }

    // ═════════════════════════════════════════════════════════════════
    //  TDLib side
    // ═════════════════════════════════════════════════════════════════

    /**
     * RichMessage::get_rich_message: the prepared content of inputMessageRichMessage.
     *
     * @param array<string, mixed> $content td_api inputMessageRichMessage
     * @return array<string, mixed>
     */
    protected function getInputRichContent(int $chatId, array $content): array
    {
        $message = $content['message'];
        $source = $message['source'];
        $rich = ['_' => '@richMessage', 'kind' => $source['_'], 'is_rtl' => $message['is_rtl'],
            'noautolink' => !$message['detect_automatic_blocks'], 'blocks' => [], 'text' => '', 'media' => [], 'rights' => [], 'users' => []];

        if ($source['_'] === 'blocks') {
            $rich['blocks'] = $this->getInputPageBlocks($chatId, $source['blocks'], $rich);
        } else {
            if ($source['_'] === 'markdown') {
                $text = TextFormatting::cleanInputString($source['text']);
                if ($text === null) {
                    throw new QueryError(400, 'Strings must be encoded in UTF-8');
                }
            }
            foreach ($source['media'] as $media) {
                $id = $media['id'];
                if ($id === '' || strlen($id) > 64 || strspn($id, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_') !== strlen($id)) {
                    throw new QueryError(400, 'Invalid rich message file identifier specified');
                }
                $prepared = $this->getInputMessageContent($chatId, $media['media']);
                if (!in_array($prepared['type'], ['Animation', 'Audio', 'Document', 'Photo', 'Video', 'VoiceNote'], true)) {
                    throw new QueryError(400, 'Unallowed media specified');
                }
                $rich['media'][] = ['id' => $id, 'type' => $prepared['type'], 'media' => $prepared['media']];
            }
            // the HTML source is checked last
            $text = TextFormatting::cleanInputString($source['text']);
            if ($text === null) {
                throw new QueryError(400, 'Strings must be encoded in UTF-8');
            }
            $rich['text'] = $text;
        }

        return ['type' => 'RichText', 'text' => new FormattedText(), 'media' => null, 'no_webpage' => false, 'invert_media' => false,
            'rich_message' => $rich];
    }

    /**
     * get_web_page_blocks (td_api input blocks) + get_input_page_blocks.
     *
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $rich
     * @return list<array<string, mixed>> TL PageBlock
     */
    private function getInputPageBlocks(int $chatId, array $blocks, array &$rich): array
    {
        $result = [];
        foreach ($blocks as $block) {
            $result[] = $this->getInputPageBlock($chatId, $block, $rich);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $block td_api InputPageBlock
     * @param array<string, mixed> $rich
     * @return array<string, mixed> TL PageBlock
     */
    private function getInputPageBlock(int $chatId, array $block, array &$rich): array
    {
        switch ($block['_']) {
            case 'heading':
                $text = $this->getInputRichText($block['text'], $rich);
                $size = $block['size'];
                if ($size < 1 || $size > 6) {
                    throw new QueryError(400, 'Invalid section heading size specified');
                }

                return ['_' => 'pageBlockHeading'.$size, 'text' => $text];
            case 'paragraph':
                return ['_' => 'pageBlockParagraph', 'text' => $this->getInputRichText($block['text'], $rich)];
            case 'pre':
                $text = $this->getInputRichText($block['text'], $rich);
                $language = TextFormatting::cleanInputString($block['language']);
                if ($language === null) {
                    throw new QueryError(400, 'Language must be encoded in UTF-8');
                }

                return ['_' => 'pageBlockPreformatted', 'text' => $text, 'language' => $language];
            case 'footer':
                return ['_' => 'pageBlockFooter', 'text' => $this->getInputRichText($block['text'], $rich)];
            case 'thinking':
                return ['_' => 'pageBlockThinking', 'text' => $this->getInputRichText($block['text'], $rich)];
            case 'divider':
                return ['_' => 'pageBlockDivider'];
            case 'mathematical_expression':
                $expression = TextFormatting::cleanInputString($block['expression']);
                if ($expression === null) {
                    throw new QueryError(400, 'Mathematical expression must be encoded in UTF-8');
                }

                return ['_' => 'pageBlockMath', 'source' => $expression];
            case 'anchor':
                $name = TextFormatting::cleanInputString($block['name']);
                if ($name === null) {
                    throw new QueryError(400, 'Anchor name must be encoded in UTF-8');
                }

                return ['_' => 'pageBlockAnchor', 'name' => $name];
            case 'list':
                return $this->getInputPageBlockList($chatId, $block['items'], $rich);
            case 'blockquote':
                $blocks = $this->getInputPageBlocks($chatId, $block['blocks'], $rich);

                return ['_' => 'pageBlockBlockquoteBlocks', 'blocks' => $blocks, 'caption' => $this->getInputRichText($block['credit'], $rich)];
            case 'expandable_blockquote':
            case 'pullquote':
                $text = $this->getInputRichText($block['text'], $rich);
                $credit = $this->getInputRichText($block['credit'], $rich);

                return $block['_'] === 'pullquote'
                    ? ['_' => 'pageBlockPullquote', 'text' => $text, 'caption' => $credit]
                    : ['_' => 'pageBlockBlockquote', 'collapsed' => true, 'text' => $text, 'caption' => $credit];
            case 'collage':
            case 'slideshow':
                $blocks = $this->getInputPageBlocks($chatId, $block['blocks'], $rich);
                $caption = $this->getInputPageCaption($block['caption'], $rich);

                return ['_' => $block['_'] === 'collage' ? 'pageBlockCollage' : 'pageBlockSlideshow', 'items' => $blocks, 'caption' => $caption];
            case 'table':
                $title = $this->getInputRichText($block['caption'], $rich);
                $rows = [];
                foreach ($block['cells'] as $row) {
                    $cells = [];
                    foreach ($row as $cell) {
                        $cells[] = $this->getInputPageTableCell($cell, $rich);
                    }
                    $rows[] = ['_' => 'pageTableRow', 'cells' => $cells];
                }

                return self::tl('pageBlockTable', ['bordered' => $block['is_bordered'], 'striped' => $block['is_striped'],
                    'compact' => $block['is_compact'], 'title' => $title, 'rows' => $rows]);
            case 'details':
                $title = $this->getInputRichText($block['summary'], $rich);
                $blocks = $this->getInputPageBlocks($chatId, $block['blocks'], $rich);

                return self::tl('pageBlockDetails', ['open' => $block['is_open'], 'blocks' => $blocks, 'title' => $title]);
            case 'map':
                $location = $block['location'];
                if (!self::isValidLocation($location)) {
                    throw new QueryError(400, 'Invalid location specified');
                }
                ['zoom' => $zoom, 'width' => $width, 'height' => $height] = $block;
                if ($zoom < 0 || $zoom >= 25 || $width < 0 || $width > 10000 || $height < 0 || $height > 10000
                    || $width + $height > 10000 || $width > $height * 20 || $height > $width * 20) {
                    throw new QueryError(400, 'Invalid map properties specified');
                }
                $caption = $this->getInputPageCaption($block['caption'], $rich);
                [$width, $height] = MediaConverter::dimensions($width, $height);

                return ['_' => 'inputPageBlockMap', 'geo' => self::inputGeoPoint($location), 'zoom' => $zoom, 'w' => $width, 'h' => $height,
                    'caption' => $caption];
            case 'buttons':
                $buttons = [];
                foreach ($block['buttons'] as $button) {
                    $text = $this->getInputRichText($button['text'], $rich);
                    [$type, $style] = $this->getInputRichButton($button, $rich);
                    $buttons[] = self::tl('pageButton', ['text' => $text, 'type' => $type, 'style' => $style]);
                }
                if ($buttons === []) {
                    throw new QueryError(400, 'Button row must be non-empty');
                }

                return self::tl('pageBlockButtonRow', ['align_left' => $block['align'] === 'left', 'align_center' => $block['align'] === 'center',
                    'align_right' => $block['align'] === 'right', 'buttons' => $buttons]);
        }

        return $this->getInputPageBlockMedia($chatId, $block, $rich);
    }

    /**
     * A media block: get_input_message_content of the file, then its caption.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $rich
     * @return array<string, mixed>
     */
    private function getInputPageBlockMedia(int $chatId, array $block, array &$rich): array
    {
        $content = $block['content'];
        $content['caption'] = null;
        $content['show_caption_above_media'] = false;
        $content['has_spoiler'] = false;
        $prepared = $this->getInputMessageContent($chatId, $content);
        $caption = $this->getInputPageCaption($block['caption'], $rich);

        $index = count($rich['media']);
        $rich['media'][] = ['id' => '', 'type' => $prepared['type'], 'media' => $prepared['media']];
        $rich['rights'][] = $prepared['type'];
        $id = ['_' => '@richMediaId', 'index' => $index];
        $hasSpoiler = $block['has_spoiler'];

        return match ($block['_']) {
            'animation' => self::tl('pageBlockVideo', ['autoplay' => true, 'loop' => true, 'spoiler' => $hasSpoiler, 'video_id' => $id, 'caption' => $caption]),
            'video' => self::tl('pageBlockVideo', ['spoiler' => $hasSpoiler, 'video_id' => $id, 'caption' => $caption]),
            'photo' => self::tl('pageBlockPhoto', ['spoiler' => $hasSpoiler, 'photo_id' => $id, 'caption' => $caption]),
            'audio', 'voice_note' => ['_' => 'pageBlockAudio', 'audio_id' => $id, 'caption' => $caption],
            default => ['_' => 'pageBlockDocument', 'document_id' => $id, 'caption' => $caption],
        };
    }

    /**
     * WebPageBlockList::Item::get_item + WebPageBlockList::get_input_page_block.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $rich
     * @return array<string, mixed>
     */
    private function getInputPageBlockList(int $chatId, array $items, array &$rich): array
    {
        $result = [];
        foreach ($items as $item) {
            $blocks = $this->getInputPageBlocks($chatId, $item['blocks'], $rich);
            $label = '';
            $value = -1;
            $type = $item['type'];
            if ($type !== '') {
                if (strlen($type) !== 1 || !str_contains('aAiI1', $type)) {
                    throw new QueryError(400, 'Invalid list item type specified');
                }
                $value = $item['value'];
                $label = JsonRichMessage::orderedListLabel($value, $type);
            }
            $result[] = ['blocks' => $blocks, 'checkbox' => $item['has_checkbox'], 'checked' => $item['has_checkbox'] && $item['is_checked'],
                'label' => $label, 'value' => $value, 'type' => $type];
        }
        if ($result === []) {
            throw new QueryError(400, 'List must be non-empty');
        }
        $isUnordered = $result[0]['label'] === '';
        foreach ($result as $item) {
            if (($item['label'] === '') !== $isUnordered) {
                throw new QueryError(400, 'List must be either ordered or unordered');
            }
        }

        if ($isUnordered) {
            return ['_' => 'pageBlockList', 'items' => array_map(static fn (array $item): array => self::tl('pageListItemBlocks',
                ['checkbox' => $item['checkbox'], 'checked' => $item['checked'], 'blocks' => $item['blocks']]), $result)];
        }

        return ['_' => 'pageBlockOrderedList', 'items' => array_map(static fn (array $item): array => self::tl('pageListOrderedItemBlocks', [
            'checkbox' => $item['checkbox'], 'checked' => $item['checked'], 'num' => $item['label'], 'blocks' => $item['blocks'],
            'value' => $item['value'], 'type' => $item['type'],
        ]), $result), 'start' => 0, 'type' => ''];
    }

    /**
     * WebPageBlockTableCell::get_web_page_block_table_cell + get_input_page_table_cell.
     *
     * @param array<string, mixed> $cell
     * @param array<string, mixed> $rich
     * @return array<string, mixed>
     */
    private function getInputPageTableCell(array $cell, array &$rich): array
    {
        if ($cell['colspan'] < 0) {
            throw new QueryError(400, 'Invalid table cell colspan specified');
        }
        if ($cell['rowspan'] < 0) {
            throw new QueryError(400, 'Invalid table cell rowspan specified');
        }
        $text = $this->getInputRichText($cell['text'], $rich);
        $colspan = max($cell['colspan'], 1);
        $rowspan = max($cell['rowspan'], 1);

        return self::tl('pageTableCell', [
            'header' => $cell['is_header'],
            'align_center' => $cell['align'] === 'center',
            'align_right' => $cell['align'] === 'right',
            'valign_middle' => $cell['valign'] === 'middle',
            'valign_bottom' => $cell['valign'] === 'bottom',
            'text' => $text['_'] === 'textEmpty' ? null : $text,
            'colspan' => $colspan !== 1 ? $colspan : null,
            'rowspan' => $rowspan !== 1 ? $rowspan : null,
        ]);
    }

    /**
     * WebPageBlockCaption::get_web_page_block_caption + get_input_page_caption.
     *
     * @param array<string, mixed>|null $caption
     * @param array<string, mixed> $rich
     * @return array<string, mixed>
     */
    private function getInputPageCaption(?array $caption, array &$rich): array
    {
        $text = $this->getInputRichText($caption['text'] ?? null, $rich);

        return ['_' => 'pageCaption', 'text' => $text, 'credit' => $this->getInputRichText($caption['credit'] ?? null, $rich)];
    }

    /**
     * A rich message button: get_inline_keyboard_button + get_input_keyboard_inline_button
     * and RichButtonStyle::get_input_rich_button_style.
     *
     * @param array<string, mixed> $button
     * @param array<string, mixed> $rich
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function getInputRichButton(array $button, array &$rich): array
    {
        $type = $this->resolveLoginUrlBot($button['type']);
        $input = $this->getInputInlineKeyboardButton(['text' => '1', 'icon_custom_emoji_id' => 0, 'style' => 'default', 'type' => $type], true);
        if ($type['_'] === 'inlineKeyboardButtonTypeUrl' && ($userId = TgLinks::userId($type['url'])) > 0) {
            $rich['users'][] = $userId;
        } elseif ($type['_'] === 'inlineKeyboardButtonTypeLoginUrl' && $type['id'] !== 0 && $type['id'] !== -1) {
            $rich['users'][] = $type['id'] > 0 ? $type['id'] : -$type['id'] - 1;
        }
        $style = $button['style'] === 'default' ? null : self::tl('richButtonStyle', [
            'bg_primary' => $button['style'] === 'primary', 'bg_danger' => $button['style'] === 'danger',
            'bg_success' => $button['style'] === 'success', 'link' => $button['style'] === 'link',
        ]);

        return [$input['type'], $style];
    }

    /**
     * RichText::get_rich_text (td_api) + RichText::get_input_rich_text.
     *
     * @param array<string, mixed>|null $text
     * @param array<string, mixed> $rich
     * @return array<string, mixed> TL RichText
     */
    private function getInputRichText(?array $text, array &$rich): array
    {
        if ($text === null) {
            return ['_' => 'textEmpty'];
        }
        $clean = static function (string $value, string $error): string {
            $value = TextFormatting::cleanInputString($value);
            if ($value === null) {
                throw new QueryError(400, $error);
            }

            return $value;
        };
        $inner = fn (): array => $this->getInputRichText($text['text'] ?? null, $rich);

        switch ($text['_']) {
            case 'plain':
                $value = $clean($text['text'], 'Rich text must be encoded in UTF-8');

                return $value === '' ? ['_' => 'textEmpty'] : ['_' => 'textPlain', 'text' => $value];
            case 'bold':
                return ['_' => 'textBold', 'text' => $inner()];
            case 'italic':
                return ['_' => 'textItalic', 'text' => $inner()];
            case 'underline':
                return ['_' => 'textUnderline', 'text' => $inner()];
            case 'strikethrough':
                return ['_' => 'textStrike', 'text' => $inner()];
            case 'spoiler':
                return ['_' => 'textSpoiler', 'text' => $inner()];
            case 'subscript':
                return ['_' => 'textSubscript', 'text' => $inner()];
            case 'superscript':
                return ['_' => 'textSuperscript', 'text' => $inner()];
            case 'marked':
                return ['_' => 'textMarked', 'text' => $inner()];
            case 'code':
                return ['_' => 'textFixed', 'text' => $inner()];
            case 'mention':
            case 'hashtag':
            case 'cashtag':
            case 'bank_card_number':
            case 'bot_command':
                return $inner();
            case 'date_time':
                $inputText = $inner();
                if ($text['unix_time'] <= 0) {
                    throw new QueryError(400, 'Invalid date specified');
                }
                $flags = $text['flags'];

                return self::tl('textDate', [
                    'relative' => ($flags & FormattedDate::RELATIVE) !== 0,
                    'short_time' => ($flags & FormattedDate::SHORT_TIME) !== 0,
                    'long_time' => ($flags & FormattedDate::LONG_TIME) !== 0,
                    'short_date' => ($flags & FormattedDate::SHORT_DATE) !== 0,
                    'long_date' => ($flags & FormattedDate::LONG_DATE) !== 0,
                    'day_of_week' => ($flags & FormattedDate::DAY_OF_WEEK) !== 0,
                    'text' => $inputText,
                    'date' => $text['unix_time'],
                ]);
            case 'text_mention':
                $inputText = $inner();
                $userId = (int) $text['user_id'];
                if ($userId !== $this->getMyId() && ($userId <= 0 || $userId >= (1 << 40))) {
                    throw new QueryError(400, 'User not found');
                }
                $rich['users'][] = $userId;

                return ['_' => 'textMentionName', 'text' => $inputText, 'user_id' => $userId];
            case 'url':
                $inputText = $inner();

                return ['_' => 'textUrl', 'text' => $inputText, 'url' => $clean($text['url'], 'Rich text URL must be encoded in UTF-8'), 'webpage_id' => 0];
            case 'email_address':
                $inputText = $inner();

                return ['_' => 'textEmail', 'text' => $inputText, 'email' => $clean($text['email_address'], 'Rich text email address must be encoded in UTF-8')];
            case 'phone_number':
                $inputText = $inner();

                return ['_' => 'textPhone', 'text' => $inputText, 'phone' => $clean($text['phone_number'], 'Rich text phone number must be encoded in UTF-8')];
            case 'custom_emoji':
                $alt = $clean($text['alternative_text'], 'Custom emoji alternative text must be encoded in UTF-8');
                if ($text['custom_emoji_id'] === 0) {
                    throw new QueryError(400, 'Invalid custom emoji identifier specified');
                }

                return ['_' => 'textCustomEmoji', 'document_id' => $text['custom_emoji_id'], 'alt' => $alt];
            case 'mathematical_expression':
                return ['_' => 'textMath', 'source' => $clean($text['expression'], 'Mathematical expression must be encoded in UTF-8')];
            case 'button':
                $button = $text['button'];
                $inputText = $this->getInputRichText($button['text'], $rich);
                [$type, $style] = $this->getInputRichButton($button, $rich);

                return self::tl('textButton', ['text' => $inputText, 'type' => $type, 'style' => $style]);
            case 'reference':
                $name = $clean($text['name'], 'Reference name must be encoded in UTF-8');

                return ['_' => 'textAnchor', 'text' => $inner(), 'name' => $name];
            case 'reference_link':
                $name = $clean($text['name'], 'Reference name must be encoded in UTF-8');

                return ['_' => 'textUrl', 'text' => $inner(), 'url' => '#'.self::urlEncode($name), 'webpage_id' => 0];
            case 'anchor':
                return ['_' => 'textAnchor', 'text' => ['_' => 'textEmpty'], 'name' => $clean($text['name'], 'Anchor name must be encoded in UTF-8')];
            case 'anchor_link':
                $name = $clean($text['name'], 'Anchor name must be encoded in UTF-8');

                return ['_' => 'textUrl', 'text' => $inner(), 'url' => '#'.self::urlEncode($name), 'webpage_id' => 0];
            case 'texts':
                if ($text['texts'] === []) {
                    return ['_' => 'textEmpty'];
                }
                $texts = [];
                foreach ($text['texts'] as $item) {
                    $texts[] = $this->getInputRichText($item, $rich);
                }

                return ['_' => 'textConcat', 'texts' => $texts];
        }

        throw new QueryError(400, 'Unsupported rich text type');
    }

    /**
     * td::url_encode: unreserved characters are kept, other bytes become %XX.
     */
    private static function urlEncode(string $value): string
    {
        return preg_replace_callback('/[^A-Za-z0-9\-._~]/', static fn (array $m): string => sprintf('%%%02X', ord($m[0])), $value) ?? $value;
    }

    /**
     * RichMessage::can_send: the rights each media block needs.
     *
     * @param array<string, mixed> $rich
     */
    protected function richMessageError(int $chatId, array $rich): ?string
    {
        $permissions = $this->getChatPermissions($chatId);
        $canSend = $permissions->canSendMessages();
        foreach ($rich['rights'] as $type) {
            $canSend = $canSend && match ($type) {
                'Animation' => $permissions->canSendAnimations(),
                'Audio' => $permissions->canSendAudios(),
                'Document' => $permissions->canSendDocuments(),
                'Photo' => $permissions->canSendPhotos(),
                'Video' => $permissions->canSendVideos(),
                'VoiceNote' => $permissions->canSendVoiceNotes(),
                default => true,
            };
        }

        return $canSend ? null : 'Not enough rights to send the rich message to the chat';
    }

    /**
     * RichMessage::get_input_rich_message: the TL InputRichMessage once its
     * media are on the server. Media to upload are uploaded with
     * messages.uploadMedia, or fail with $noUploadError where TDLib can't upload.
     *
     * @param array<string, mixed> $rich
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $upload
     * @return array<string, mixed>
     */
    public function getInputRichMessageTl(array $rich, int $chatId, ?string $noUploadError = null, ?callable $upload = null): array
    {
        $inputs = [];
        foreach ($rich['media'] as $media) {
            $input = $media['media'];
            if (!in_array($input['_'], ['inputMediaPhoto', 'inputMediaDocument'], true)) {
                if ($noUploadError !== null) {
                    throw new QueryError(400, $noUploadError);
                }
                $input = $upload !== null ? $upload($input) : $this->getUploadedInputMedia($input, $chatId, false);
            }
            $inputs[] = $input;
        }

        if ($rich['kind'] !== 'blocks') {
            $files = [];
            foreach ($inputs as $i => $input) {
                $files[] = $input['_'] === 'inputMediaPhoto'
                    ? ['_' => 'inputRichFilePhoto', 'id' => $rich['media'][$i]['id'], 'photo' => $input['id']]
                    : ['_' => 'inputRichFileDocument', 'id' => $rich['media'][$i]['id'], 'document' => $input['id']];
            }

            return self::tl($rich['kind'] === 'markdown' ? 'inputRichMessageMarkdown' : 'inputRichMessageHTML', [
                'rtl' => $rich['is_rtl'],
                'noautolink' => $rich['noautolink'],
                $rich['kind'] => $rich['text'],
                'files' => $files === [] ? null : $files,
            ]);
        }

        $photos = $documents = [];
        foreach ($inputs as $input) {
            if ($input['_'] === 'inputMediaPhoto') {
                $photos[] = $input['id'];
            } else {
                $documents[] = $input['id'];
            }
        }
        $blocks = self::resolveRichMediaIds($rich['blocks'], $inputs);
        $users = [];
        foreach ($rich['users'] as $userId) {
            $users[] = $this->inputUser($userId);
        }

        return self::tl('inputRichMessage', [
            'rtl' => $rich['is_rtl'],
            'noautolink' => $rich['noautolink'],
            'blocks' => $blocks,
            'photos' => $photos === [] ? null : $photos,
            'documents' => $documents === [] ? null : $documents,
            'users' => $users === [] ? null : $users,
        ]);
    }

    /**
     * @param array<mixed> $object
     * @param list<array<string, mixed>> $inputs
     * @return array<mixed>
     */
    private static function resolveRichMediaIds(array $object, array $inputs): array
    {
        foreach ($object as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $object[$key] = ($value['_'] ?? '') === '@richMediaId'
                ? (int) $inputs[$value['index']]['id']['id']
                : self::resolveRichMediaIds($value, $inputs);
        }

        return $object;
    }
}
