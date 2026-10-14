<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Http\JsonFloat;
use LaraGram\Server\Messages\MediaConverter;

/**
 * The Bot API `RichMessage` of a received message: TDLib's
 * RichMessage(td, telegram_api::richMessage) and get_page_blocks_object
 * (WebPageBlock.cpp, two passes for anchors) with Client::JsonRichMessage,
 * JsonRichBlock and JsonRichText.
 */
final class JsonRichMessage
{
    /** @var array<int, array<string, mixed>> photo id → td photo */
    private array $photos = [];

    /** @var array<string, array<int, array<string, mixed>>> document kind → id → td document */
    private array $documents = [];

    private bool $isFirstPass = true;

    private bool $hasAnchorUrls = false;

    /** @var array<string, bool> anchor name → whether it has a text (a reference) */
    private array $anchors = [];

    private function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array<string, mixed> $richMessage TL richMessage
     * @return array<string, mixed>
     */
    public static function make(array $richMessage, Client $client): array
    {
        $converter = new self($client);
        foreach ($richMessage['photos'] ?? [] as $photo) {
            $converted = MediaConverter::photo($photo);
            if ($converted !== null && (int) ($photo['id'] ?? 0) !== 0) {
                $converter->photos[(int) $photo['id']] = $converted;
            }
        }
        foreach ($richMessage['documents'] ?? [] as $document) {
            $converted = MediaConverter::document($document);
            if ($converted !== null && (int) ($document['id'] ?? 0) !== 0) {
                $converter->documents[$converted['kind']][(int) $document['id']] = $converted;
            }
        }

        // get_page_blocks_object: a second pass resolves links to anchors
        $blocks = $richMessage['blocks'] ?? [];
        $result = $converter->blocks($blocks);
        if ($converter->hasAnchorUrls) {
            $converter->isFirstPass = false;
            $converter->anchors[''] = false;
            $result = $converter->blocks($blocks);
        }

        $object = ['blocks' => $result];
        if (!empty($richMessage['rtl'])) {
            $object['is_rtl'] = true;
        }

        return $object;
    }

    /**
     * @param list<array<string, mixed>> $blocks TL PageBlock
     * @return list<array<string, mixed>>
     */
    private function blocks(array $blocks): array
    {
        $result = [];
        foreach ($blocks as $block) {
            if (($object = $this->block($block)) !== null) {
                $result[] = $object;
            }
        }

        return $result;
    }

    /**
     * get_web_page_block + get_page_block_object + Client::JsonRichBlock; null for dropped blocks.
     *
     * @param array<string, mixed> $block TL PageBlock
     * @return array<string, mixed>|null
     */
    private function block(array $block): ?array
    {
        switch ($block['_'] ?? '') {
            case 'pageBlockHeading1': case 'pageBlockHeading2': case 'pageBlockHeading3':
            case 'pageBlockHeading4': case 'pageBlockHeading5': case 'pageBlockHeading6':
                return ['type' => 'heading', 'text' => $this->text($block['text']), 'size' => (int) substr($block['_'], -1)];
            case 'pageBlockParagraph':
                return ['type' => 'paragraph', 'text' => $this->text($block['text'])];
            case 'pageBlockPreformatted':
                $object = ['type' => 'pre', 'text' => $this->text($block['text'])];
                if ((string) ($block['language'] ?? '') !== '') {
                    $object['language'] = (string) $block['language'];
                }

                return $object;
            case 'pageBlockFooter':
                return ['type' => 'footer', 'text' => $this->text($block['text'])];
            case 'pageBlockDivider':
                return ['type' => 'divider'];
            case 'pageBlockMath':
                return ['type' => 'mathematical_expression', 'expression' => (string) $block['source']];
            case 'pageBlockAnchor':
                if ($this->isFirstPass) {
                    $this->anchors[(string) $block['name']] ??= false;
                }

                return ['type' => 'anchor', 'name' => (string) $block['name']];
            case 'pageBlockList':
                return ['type' => 'list', 'items' => array_map(fn (array $item): array => $this->listItem($item, '', -1, ''), $block['items'] ?? [])];
            case 'pageBlockOrderedList':
                return ['type' => 'list', 'items' => $this->orderedListItems($block)];
            case 'pageBlockBlockquote':
                if (!empty($block['collapsed'])) {
                    return $this->withCredit(['type' => 'expandable_blockquote', 'text' => $this->text($block['text'])], $block['caption'] ?? null);
                }

                return $this->withCredit(['type' => 'blockquote', 'blocks' => [['type' => 'paragraph', 'text' => $this->text($block['text'])]]],
                    $block['caption'] ?? null);
            case 'pageBlockBlockquoteBlocks':
                return $this->withCredit(['type' => 'blockquote', 'blocks' => $this->blocks($block['blocks'] ?? [])], $block['caption'] ?? null);
            case 'pageBlockPullquote':
                return $this->withCredit(['type' => 'pullquote', 'text' => $this->text($block['text'])], $block['caption'] ?? null);
            case 'pageBlockCollage':
            case 'pageBlockSlideshow':
                $object = ['type' => $block['_'] === 'pageBlockCollage' ? 'collage' : 'slideshow', 'blocks' => $this->blocks($block['items'] ?? [])];

                return $this->withCaption($object, $block['caption'] ?? null);
            case 'pageBlockTable':
                return $this->table($block);
            case 'pageBlockDetails':
                $object = ['type' => 'details', 'summary' => $this->text($block['title']), 'blocks' => $this->blocks($block['blocks'] ?? [])];
                if (!empty($block['open'])) {
                    $object['is_open'] = true;
                }

                return $object;
            case 'pageBlockMap':
                return $this->map($block);
            case 'pageBlockButtonRow':
                $object = ['type' => 'buttons', 'buttons' => array_map(fn (array $button): array => $this->button($button), $block['buttons'] ?? [])];
                $align = !empty($block['align_left']) ? 'left' : (!empty($block['align_center']) ? 'center' : (!empty($block['align_right']) ? 'right' : null));
                if ($align !== null) {
                    $object['align'] = $align;
                }

                return $object;
            case 'pageBlockPhoto':
                $photo = $this->photos[(int) $block['photo_id']] ?? null;
                if ($photo === null) {
                    return ['type' => 'unknown'];
                }
                $object = $this->withCaption(['type' => 'photo', 'photo' => JsonMedia::photo($photo)], $block['caption'] ?? null);
                if (!empty($block['spoiler'])) {
                    $object['has_spoiler'] = true;
                }

                return $object;
            case 'pageBlockVideo':
                return $this->video($block);
            case 'pageBlockAudio':
                $voiceNote = $this->documents[MediaConverter::DOCUMENT_VOICE_NOTE][(int) $block['audio_id']] ?? null;
                if ($voiceNote !== null) {
                    return $this->withCaption(['type' => 'voice_note', 'voice_note' => JsonMedia::voiceNote($voiceNote)], $block['caption'] ?? null);
                }
                $audio = $this->documents[MediaConverter::DOCUMENT_AUDIO][(int) $block['audio_id']] ?? null;
                if ($audio === null) {
                    return ['type' => 'unsupported'];
                }

                return $this->withCaption(['type' => 'audio', 'audio' => JsonMedia::audio($audio)], $block['caption'] ?? null);
            case 'pageBlockDocument':
                $document = $this->documents[MediaConverter::DOCUMENT_GENERAL][(int) $block['document_id']] ?? null;
                if ($document === null) {
                    return ['type' => 'unsupported'];
                }

                return $this->withCaption(['type' => 'document', 'document' => JsonMedia::document($document)], $block['caption'] ?? null);
            case 'pageBlockUnsupported':
                return ['type' => 'unsupported'];
            case 'pageBlockCover':
                // the cover is converted, but its type has no Bot API form
                return $this->block($block['cover']) === null ? null : ['type' => 'unknown'];
            case 'inputPageBlockMap':
                return null;
        }

        // title, subtitle, header, kicker, embedded posts and other instant view blocks
        return ['type' => 'unknown'];
    }

    /**
     * @param array<string, mixed> $block TL pageBlockVideo
     * @return array<string, mixed>
     */
    private function video(array $block): array
    {
        $animation = $this->documents[MediaConverter::DOCUMENT_ANIMATION][(int) $block['video_id']] ?? null;
        if ($animation !== null) {
            $object = $this->withCaption(['type' => 'animation', 'animation' => JsonMedia::animation($animation, false)], $block['caption'] ?? null);
            if (!empty($block['autoplay'])) {
                $object['need_autoplay'] = true;
            }
            if (!empty($block['spoiler'])) {
                $object['has_spoiler'] = true;
            }

            return $object;
        }
        $video = $this->documents[MediaConverter::DOCUMENT_VIDEO][(int) $block['video_id']] ?? null;
        if ($video === null) {
            return ['type' => 'unknown'];
        }
        $object = $this->withCaption(['type' => 'video', 'video' => JsonMedia::video($video)], $block['caption'] ?? null);
        if (!empty($block['autoplay'])) {
            $object['need_autoplay'] = true;
        }
        if (!empty($block['loop'])) {
            $object['is_looped'] = true;
        }
        if (!empty($block['spoiler'])) {
            $object['has_spoiler'] = true;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $block TL pageBlockMap
     * @return array<string, mixed>|null
     */
    private function map(array $block): ?array
    {
        $geo = $block['geo'] ?? [];
        $zoom = (int) ($block['zoom'] ?? 0);
        [$width, $height] = MediaConverter::dimensions((int) ($block['w'] ?? 0), (int) ($block['h'] ?? 0));
        if (($geo['_'] ?? '') !== 'geoPoint' || $zoom < 0 || $zoom > 24 || $width === 0) {
            return null;
        }
        $location = ['latitude' => new JsonFloat((float) $geo['lat']), 'longitude' => new JsonFloat((float) $geo['long'])];
        if ((int) ($geo['accuracy_radius'] ?? 0) > 0) {
            $location['horizontal_accuracy'] = new JsonFloat((float) $geo['accuracy_radius']);
        }
        $object = ['type' => 'map', 'location' => $location, 'zoom' => $zoom, 'width' => $width, 'height' => $height];

        return $this->withCaption($object, $block['caption'] ?? null);
    }

    /**
     * @param array<string, mixed> $block TL pageBlockTable
     * @return array<string, mixed>
     */
    private function table(array $block): array
    {
        $rows = [];
        foreach ($block['rows'] ?? [] as $row) {
            $cells = [];
            foreach ($row['cells'] ?? [] as $cell) {
                $object = [];
                if (isset($cell['text']) && !self::isEmptyText($cell['text'])) {
                    $object['text'] = $this->text($cell['text']);
                }
                if (!empty($cell['header'])) {
                    $object['is_header'] = true;
                }
                $colspan = max((int) ($cell['colspan'] ?? 1), 1);
                $rowspan = max((int) ($cell['rowspan'] ?? 1), 1);
                if ($colspan > 1) {
                    $object['colspan'] = $colspan;
                }
                if ($rowspan > 1) {
                    $object['rowspan'] = $rowspan;
                }
                $object['align'] = !empty($cell['align_center']) ? 'center' : (!empty($cell['align_right']) ? 'right' : 'left');
                $object['valign'] = !empty($cell['valign_middle']) ? 'middle' : (!empty($cell['valign_bottom']) ? 'bottom' : 'top');
                $cells[] = $object;
            }
            $rows[] = $cells;
        }
        $object = ['type' => 'table', 'cells' => $rows];
        if (!self::isEmptyText($block['title'] ?? null)) {
            $object['caption'] = $this->text($block['title']);
        }
        if (!empty($block['bordered'])) {
            $object['is_bordered'] = true;
        }
        if (!empty($block['striped'])) {
            $object['is_striped'] = true;
        }
        if (!empty($block['compact'])) {
            $object['is_compact'] = true;
        }

        return $object;
    }

    /**
     * WebPageBlockList::Item::get_page_block_list_item_object + Client::JsonRichBlockListItem.
     *
     * @param array<string, mixed> $item TL PageListItem or PageListOrderedItem
     * @return array<string, mixed>
     */
    private function listItem(array $item, string $label, int $value, string $type): array
    {
        $blocks = in_array($item['_'] ?? '', ['pageListItemText', 'pageListOrderedItemText'], true)
            ? [['type' => 'paragraph', 'text' => $this->text($item['text'])]]
            : $this->blocks($item['blocks'] ?? []);
        if ($blocks === []) {
            $blocks = [['type' => 'paragraph', 'text' => '']];
        }
        $object = ['label' => $label === '' ? "\u{2022}" : $label, 'blocks' => $blocks];
        if (!empty($item['checkbox'])) {
            $object['has_checkbox'] = true;
            if (!empty($item['checked'])) {
                $object['is_checked'] = true;
            }
        }
        if ($label !== '' && $type !== '') {
            $object['type'] = $type;
            $object['value'] = $value;
        }

        return $object;
    }

    /**
     * pageBlockOrderedList items with their labels.
     *
     * @param array<string, mixed> $block
     * @return list<array<string, mixed>>
     */
    private function orderedListItems(array $block): array
    {
        $nextValue = isset($block['start']) ? (int) $block['start'] : 1;
        $baseType = self::orderedListType((string) ($block['type'] ?? ''));
        $items = [];
        foreach ($block['items'] ?? [] as $item) {
            $value = isset($item['value']) ? (int) $item['value'] : $nextValue;
            $type = (string) ($item['type'] ?? '') === '' ? $baseType : self::orderedListType((string) $item['type']);
            $label = (string) ($item['num'] ?? '');
            $label = $label === '' ? self::orderedListLabel($value, $type) : $label.'.';
            $items[] = $this->listItem($item, $label, $value, $type);
            $nextValue = $value + (!empty($block['reversed']) ? -1 : 1);
        }

        return $items;
    }

    /**
     * get_ordered_list_type.
     */
    private static function orderedListType(string $type): string
    {
        return in_array($type, ['a', 'A', 'i', 'I', '1'], true) ? $type : '1';
    }

    /**
     * get_ordered_list_label.
     */
    public static function orderedListLabel(int $num, string $type): string
    {
        if (($type === 'a' || $type === 'A') && $num > 0) {
            $result = '';
            while ($num > 0) {
                $num--;
                $result = chr(ord('A') + $num % 26).$result;
                $num = intdiv($num, 26);
            }

            return ($type === 'a' ? strtolower($result) : $result).'.';
        }
        if (($type === 'i' || $type === 'I') && $num > 0 && $num < 4000) {
            $result = '';
            foreach ([1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL',
                10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'] as $unit => $roman) {
                while ($num >= $unit) {
                    $result .= $roman;
                    $num -= $unit;
                }
            }

            return ($type === 'i' ? strtolower($result) : $result).'.';
        }

        return $num.'.';
    }

    /**
     * @param array<string, mixed> $object
     * @param array<string, mixed>|null $caption TL pageCaption
     * @return array<string, mixed>
     */
    private function withCaption(array $object, ?array $caption): array
    {
        // WebPageBlockCaption::get_page_block_caption_object
        if ($caption === null || (self::isEmptyText($caption['text'] ?? null) && self::isEmptyText($caption['credit'] ?? null))) {
            return $object;
        }
        $value = ['text' => $this->text($caption['text'])];
        if (!self::isEmptyText($caption['credit'] ?? null)) {
            $value['credit'] = $this->text($caption['credit']);
        }
        $object['caption'] = $value;

        return $object;
    }

    /**
     * @param array<string, mixed> $object
     * @param array<string, mixed>|null $credit TL RichText
     * @return array<string, mixed>
     */
    private function withCredit(array $object, ?array $credit): array
    {
        if (!self::isEmptyText($credit)) {
            $object['credit'] = $this->text($credit);
        }

        return $object;
    }

    /**
     * RichText::empty of a server text.
     *
     * @param array<string, mixed>|null $text
     */
    private static function isEmptyText(?array $text): bool
    {
        return $text === null || ($text['_'] ?? '') === 'textEmpty' || (($text['_'] ?? '') === 'textPlain' && (string) $text['text'] === '');
    }

    /**
     * @param array<string, mixed> $button TL pageButton or textButton
     * @return array<string, mixed>
     */
    private function button(array $button): array
    {
        // Client::JsonRichMessageButton
        $object = ['text' => $this->text($button['text'])];
        $style = $button['style'] ?? null;
        if (!empty($style['bg_primary'])) {
            $object['style'] = 'primary';
        } elseif (!empty($style['bg_danger'])) {
            $object['style'] = 'danger';
        } elseif (!empty($style['bg_success'])) {
            $object['style'] = 'success';
        } elseif (!empty($style['link'])) {
            $object['style'] = 'link';
        }
        JsonReplyMarkup::storeButtonType($object, $button['type'] ?? []);

        return $object;
    }

    /**
     * get_rich_text (server) + RichText::get_rich_text_object + Client::JsonRichText.
     *
     * @param array<string, mixed> $text TL RichText
     */
    private function text(array $text): mixed
    {
        switch ($text['_'] ?? '') {
            case 'textEmpty':
                return '';
            case 'textPlain':
                return (string) $text['text'];
            case 'textConcat':
                return array_map(fn (array $item): mixed => $this->text($item), $text['texts'] ?? []);
            case 'textBold':
                return ['type' => 'bold', 'text' => $this->text($text['text'])];
            case 'textItalic':
                return ['type' => 'italic', 'text' => $this->text($text['text'])];
            case 'textUnderline':
                return ['type' => 'underline', 'text' => $this->text($text['text'])];
            case 'textStrike':
                return ['type' => 'strikethrough', 'text' => $this->text($text['text'])];
            case 'textSpoiler':
                return ['type' => 'spoiler', 'text' => $this->text($text['text'])];
            case 'textFixed':
                return ['type' => 'code', 'text' => $this->text($text['text'])];
            case 'textSubscript':
                return ['type' => 'subscript', 'text' => $this->text($text['text'])];
            case 'textSuperscript':
                return ['type' => 'superscript', 'text' => $this->text($text['text'])];
            case 'textMarked':
                return ['type' => 'marked', 'text' => $this->text($text['text'])];
            case 'textUrl':
                return $this->url($text);
            case 'textEmail':
                return ['type' => 'email_address', 'text' => $this->text($text['text']), 'email_address' => (string) $text['email']];
            case 'textPhone':
                return ['type' => 'phone_number', 'text' => $this->text($text['text']), 'phone_number' => (string) $text['phone']];
            case 'textAnchor':
                $name = (string) $text['name'];
                $hasText = !self::isEmptyText($text['text'] ?? null);
                if ($this->isFirstPass) {
                    $this->anchors[$name] ??= $hasText;
                }
                if (!$hasText) {
                    return ['type' => 'anchor', 'name' => $name];
                }

                return ['type' => 'reference', 'text' => $this->text($text['text']), 'name' => $name];
            case 'textMath':
                return ['type' => 'mathematical_expression', 'expression' => (string) $text['source']];
            case 'textCustomEmoji':
                return ['type' => 'custom_emoji', 'custom_emoji_id' => (string) $text['document_id'], 'alternative_text' => (string) $text['alt']];
            case 'textMention':
                return ['type' => 'mention', 'text' => $this->text($text['text']), 'username' => self::trimFirst(self::fullText($text['text']), '@')];
            case 'textHashtag':
                return ['type' => 'hashtag', 'text' => $this->text($text['text']), 'hashtag' => self::trimFirst(self::fullText($text['text']), '#')];
            case 'textCashtag':
                return ['type' => 'cashtag', 'text' => $this->text($text['text']), 'cashtag' => self::trimFirst(self::fullText($text['text']), '$')];
            case 'textBotCommand':
                return ['type' => 'bot_command', 'text' => $this->text($text['text']), 'bot_command' => self::trimFirst(self::fullText($text['text']), '/')];
            case 'textAutoUrl':
                return ['type' => 'url', 'text' => $this->text($text['text']), 'url' => self::fullText($text['text'])];
            case 'textAutoEmail':
                return ['type' => 'email_address', 'text' => $this->text($text['text']), 'email_address' => self::fullText($text['text'])];
            case 'textAutoPhone':
                return ['type' => 'phone_number', 'text' => $this->text($text['text']), 'phone_number' => self::fullText($text['text'])];
            case 'textBankCard':
                return ['type' => 'bank_card_number', 'text' => $this->text($text['text']), 'bank_card_number' => self::fullText($text['text'])];
            case 'textMentionName':
                $userId = (int) $text['user_id'];

                return ['type' => 'text_mention', 'text' => $this->text($text['text']), 'user' => JsonUser::make(max($userId, 0), $this->client)];
            case 'textDate':
                if ((int) $text['date'] <= 0) {
                    return '';
                }

                return ['type' => 'date_time', 'text' => $this->text($text['text']), 'unix_time' => (int) $text['date'],
                    'date_time_format' => self::dateTimeFormat($text)];
            case 'textButton':
                return ['type' => 'button', 'button' => $this->button($text)];
            case 'textImage':
            case 'textDiff':
                // richTextIcon and richTextDiff have no Bot API form
                return '';
        }

        return '';
    }

    /**
     * RichText::get_rich_text_object of a URL: links to anchors become anchor or reference links.
     *
     * @param array<string, mixed> $text TL textUrl
     * @return array<string, mixed>
     */
    private function url(array $text): array
    {
        $url = (string) $text['url'];
        if (str_starts_with($url, '#')) {
            if ($this->isFirstPass) {
                $this->hasAnchorUrls = true;
            } else {
                $anchor = substr($url, 1);
                foreach ([$anchor, rawurldecode($anchor)] as $name) {
                    if (array_key_exists($name, $this->anchors)) {
                        return $this->anchors[$name]
                            ? ['type' => 'reference_link', 'text' => $this->text($text['text']), 'reference_name' => $name]
                            : ['type' => 'anchor_link', 'text' => $this->text($text['text']), 'anchor_name' => $name];
                    }
                }
            }
        }

        return ['type' => 'url', 'text' => $this->text($text['text']), 'url' => $url];
    }

    /**
     * FormattedDate + Client::get_date_time_format.
     *
     * @param array<string, mixed> $date TL textDate
     */
    private static function dateTimeFormat(array $date): string
    {
        if (!empty($date['relative'])) {
            return 'r';
        }
        $format = '';
        if (!empty($date['day_of_week'])) {
            $format .= 'w';
        }
        if (!empty($date['short_date'])) {
            $format .= 'd';
        } elseif (!empty($date['long_date'])) {
            $format .= 'D';
        }
        if (!empty($date['short_time'])) {
            $format .= 't';
        } elseif (!empty($date['long_time'])) {
            $format .= 'T';
        }

        return $format;
    }

    /**
     * RichText::get_full_text.
     *
     * @param array<string, mixed>|null $text TL RichText
     */
    private static function fullText(?array $text): string
    {
        if ($text === null) {
            return '';
        }

        return match ($text['_'] ?? '') {
            'textPlain' => (string) $text['text'],
            'textMath' => (string) $text['source'],
            'textCustomEmoji' => (string) $text['alt'],
            'textConcat' => implode('', array_map(static fn (array $item): string => self::fullText($item), $text['texts'] ?? [])),
            'textDiff' => self::fullText($text['text'] ?? null).self::fullText($text['old_text'] ?? null),
            default => self::fullText($text['text'] ?? null),
        };
    }

    private static function trimFirst(string $value, string $char): string
    {
        return str_starts_with($value, $char) ? substr($value, 1) : $value;
    }
}
