<?php

declare(strict_types=1);

namespace LaraGram\Server\Entities;

use LaraGram\Server\Support\LinkChecker;
use LaraGram\Server\Support\TdInteger;
use LaraGram\Server\Support\Utf8;

/**
 * The Bot API `parse_mode` parsers: ports of parse_markdown,
 * parse_markdown_v2 and parse_html from TDLib's MessageEntity.cpp, with
 * td_api::parseTextEntities around them.
 */
final class EntityParser
{
    /**
     * SynchronousRequests::do_request(parseTextEntities).
     *
     * @return FormattedText the parsed text (not yet fixed)
     *
     * @throws FormattingError "Can't parse entities: …" and the other request errors
     */
    public static function parse(string $text, string $mode): FormattedText
    {
        if (!Utf8::check($text)) {
            throw new FormattingError('Text must be encoded in UTF-8');
        }

        try {
            if (Utf8::length($text) > 65536) {
                throw new FormattingError('Text is too long');
            }
            $entities = match ($mode) {
                'markdown' => self::markdown($text),
                'markdownv2' => self::markdownV2($text),
                'html' => self::html($text),
            };
        } catch (FormattingError $e) {
            throw new FormattingError("Can't parse entities: ".$e->getMessage());
        }

        return new FormattedText($text, $entities);
    }

    /**
     * The byte at $i, "\0" past the end (std::string terminator semantics).
     */
    private static function at(string $s, int $i): string
    {
        return $s[$i] ?? "\0";
    }

    /**
     * td::is_space.
     */
    private static function isSpace(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\r" || $c === "\n" || $c === "\0" || $c === "\x0B";
    }

    private static function isSpaceNotNul(string $c): bool
    {
        return $c !== "\0" && self::isSpace($c);
    }

    private static function utf16Step(int $c): int
    {
        return ($c & 0xC0) !== 0x80 ? 1 + ($c >= 0xF0 ? 1 : 0) : 0;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Markdown (legacy)
    // ═════════════════════════════════════════════════════════════════

    /**
     * @return list<array<string, mixed>>
     */
    public static function markdown(string &$text): array
    {
        // TDLib writes the result over the input buffer; a link without
        // "(url)" reads its URL from that buffer, so this keeps the same layout.
        $entities = [];
        $size = strlen($text);
        $resultSize = 0;
        $utf16 = 0;

        for ($i = 0; $i < $size; $i++) {
            $c = $text[$i];
            $next = self::at($text, $i + 1);
            if ($c === '\\' && ($next === '_' || $next === '*' || $next === '`' || $next === '[')) {
                $i++;
                $text[$resultSize++] = $text[$i];
                $utf16++;
                continue;
            }
            if ($c !== '_' && $c !== '*' && $c !== '`' && $c !== '[') {
                $utf16 += self::utf16Step(ord($c));
                $text[$resultSize++] = $c;
                continue;
            }

            $beginPos = $i;
            $endCharacter = $c === '[' ? ']' : $c;
            $isPre = false;
            $i++;

            $language = '';
            if ($c === '`' && self::at($text, $i) === '`' && self::at($text, $i + 1) === '`') {
                $i += 2;
                $isPre = true;
                $languageEnd = $i;
                while (!self::isSpace(self::at($text, $languageEnd)) && self::at($text, $languageEnd) !== '`') {
                    $languageEnd++;
                }
                if ($i !== $languageEnd && $languageEnd < $size && $text[$languageEnd] !== '`') {
                    $language = substr($text, $i, $languageEnd - $i);
                    $i = $languageEnd;
                }
                $i = self::skipNewLine($text, $i);
            }

            $entityOffset = $utf16;
            while ($i < $size && ($text[$i] !== $endCharacter
                || ($isPre && !(self::at($text, $i + 1) === '`' && self::at($text, $i + 2) === '`')))) {
                $utf16 += self::utf16Step(ord($text[$i]));
                $text[$resultSize++] = $text[$i++];
            }
            if ($i === $size) {
                throw new FormattingError("Can't find end of the entity starting at byte offset {$beginPos}");
            }

            if ($entityOffset !== $utf16) {
                $length = $utf16 - $entityOffset;
                switch ($c) {
                    case '_':
                        $entities[] = ['type' => 'Italic', 'offset' => $entityOffset, 'length' => $length];
                        break;
                    case '*':
                        $entities[] = ['type' => 'Bold', 'offset' => $entityOffset, 'length' => $length];
                        break;
                    case '[':
                        if (self::at($text, $i + 1) !== '(') {
                            $url = substr($text, $beginPos + 1, $i - $beginPos - 1);
                        } else {
                            $i += 2;
                            $url = '';
                            while ($i < $size && $text[$i] !== ')') {
                                $url .= $text[$i++];
                            }
                        }
                        $entity = self::linkEntity($url, $entityOffset, $length);
                        if ($entity !== null) {
                            $entities[] = $entity;
                        }
                        break;
                    case '`':
                        if ($isPre) {
                            $entities[] = $language === ''
                                ? ['type' => 'Pre', 'offset' => $entityOffset, 'length' => $length]
                                : ['type' => 'PreCode', 'offset' => $entityOffset, 'length' => $length, 'language' => $language];
                        } else {
                            $entities[] = ['type' => 'Code', 'offset' => $entityOffset, 'length' => $length];
                        }
                        break;
                }
            }
            if ($isPre) {
                $i += 2;
            }
        }

        $text = substr($text, 0, $resultSize);

        return $entities;
    }

    /**
     * Skip one new line (\n, \r, \r\n or \n\r) at the beginning of a pre block.
     */
    private static function skipNewLine(string $text, int $i): int
    {
        $c = self::at($text, $i);
        if ($c === "\n" || $c === "\r") {
            $next = self::at($text, $i + 1);
            if (($next === "\n" || $next === "\r") && $c !== $next) {
                return $i + 2;
            }

            return $i + 1;
        }

        return $i;
    }

    /**
     * A tg://user link becomes a text mention, anything else a checked URL.
     *
     * @return array<string, mixed>|null
     */
    private static function linkEntity(string $url, int $offset, int $length): ?array
    {
        $userId = TgLinks::userId($url);
        if ($userId > 0) {
            return ['type' => 'MentionName', 'offset' => $offset, 'length' => $length, 'user_id' => $userId];
        }

        $url = LinkChecker::getCheckedLink($url);

        return $url === '' ? null : ['type' => 'TextUrl', 'offset' => $offset, 'length' => $length, 'url' => $url];
    }

    // ═════════════════════════════════════════════════════════════════
    //  MarkdownV2
    // ═════════════════════════════════════════════════════════════════

    /**
     * @return list<array<string, mixed>>
     */
    public static function markdownV2(string &$text): array
    {
        $out = '';
        $entities = [];
        $utf16 = 0;
        $nested = [];
        $haveBlockquote = false;
        $canStartBlockquote = true;
        $size = strlen($text);

        for ($i = 0; $i < $size; $i++) {
            $c = $text[$i];
            $next = self::at($text, $i + 1);
            if ($c === '\\' && ord($next) > 0 && ord($next) <= 126) {
                $i++;
                $utf16++;
                $out .= $text[$i];
                if ($text[$i] !== "\r") {
                    $canStartBlockquote = $text[$i] === "\n";
                }
                continue;
            }

            $reserved = "_*[]()~`>#+-=|{}.!\n";
            if ($nested !== [] && in_array(end($nested)['type'], ['Code', 'Pre', 'PreCode'], true)) {
                $reserved = '`';
            }

            if (!str_contains($reserved, $c)) {
                $code = ord($c);
                if (($code & 0xC0) !== 0x80) {
                    $utf16 += 1 + ($code >= 0xF0 ? 1 : 0);
                    if ($c !== "\r") {
                        $canStartBlockquote = false;
                    }
                }
                $out .= $c;
                continue;
            }

            $isEnd = false;
            if ($nested !== []) {
                if ($haveBlockquote && $c === "\n" && ($i + 1 === $size || $text[$i + 1] !== '>')) {
                    $isEnd = true;
                } else {
                    $isEnd = match (end($nested)['type']) {
                        'Bold' => $c === '*',
                        'Italic' => $c === '_' && $next !== '_',
                        'Code' => $c === '`',
                        'Pre', 'PreCode' => $c === '`' && $next === '`' && self::at($text, $i + 2) === '`',
                        'TextUrl', 'CustomEmoji' => $c === ']',
                        'Underline' => $c === '_' && $next === '_',
                        'Strikethrough' => $c === '~',
                        'Spoiler' => $c === '|' && $next === '|',
                        default => false,
                    };
                }
            }

            if (!$isEnd) {
                $argument = '';
                $byteOffset = $i;
                switch ($c) {
                    case '_':
                        if ($next === '_') {
                            $type = 'Underline';
                            $i++;
                        } else {
                            $type = 'Italic';
                        }
                        break;
                    case '*':
                        $type = 'Bold';
                        break;
                    case '~':
                        $type = 'Strikethrough';
                        break;
                    case '|':
                        if ($next !== '|') {
                            throw self::reserved($c);
                        }
                        $i++;
                        $type = 'Spoiler';
                        break;
                    case '[':
                        $type = 'TextUrl';
                        break;
                    case '`':
                        if ($next === '`' && self::at($text, $i + 2) === '`') {
                            $i += 3;
                            $type = 'Pre';
                            $languageEnd = $i;
                            while (!self::isSpace(self::at($text, $languageEnd)) && self::at($text, $languageEnd) !== '`') {
                                $languageEnd++;
                            }
                            if ($i !== $languageEnd && $languageEnd < $size && $text[$languageEnd] !== '`') {
                                $type = 'PreCode';
                                $argument = substr($text, $i, $languageEnd - $i);
                                $i = $languageEnd;
                            }
                            $i = self::skipNewLine($text, $i);
                            $i--;
                        } else {
                            $type = 'Code';
                        }
                        break;
                    case '!':
                        if ($next !== '[') {
                            throw self::reserved($c);
                        }
                        $i++;
                        $type = 'CustomEmoji';
                        break;
                    case "\n":
                        $utf16++;
                        $out .= "\n";
                        $canStartBlockquote = true;
                        $type = null;
                        break;
                    case '>':
                        if (!$canStartBlockquote) {
                            throw self::reserved($c);
                        }
                        if ($haveBlockquote) {
                            $type = null;
                        } else {
                            $type = 'BlockQuote';
                            $haveBlockquote = true;
                        }
                        break;
                    default:
                        throw self::reserved($c);
                }
                if ($type === null) {
                    continue;
                }
                $nested[] = ['type' => $type, 'argument' => $argument, 'offset' => $utf16, 'byte_offset' => $byteOffset, 'begin_pos' => strlen($out)];

                continue;
            }

            // End of an entity.
            $top = end($nested);
            $type = $top['type'];
            if ($c === "\n" && $type !== 'BlockQuote') {
                $outLength = strlen($out);
                if ($type !== 'Spoiler' || !($top['byte_offset'] === $i - 2
                    || ($top['byte_offset'] === $i - 3 && $outLength !== 0 && $out[$outLength - 1] === "\r"))) {
                    throw new FormattingError("Can't find end of ".self::typeName($type)." entity at byte offset {$top['byte_offset']}");
                }
                array_pop($nested);
                $top = end($nested);
                if ($top['type'] !== 'BlockQuote') {
                    throw new FormattingError("Can't find end of ".self::typeName($top['type'])." entity at byte offset {$top['byte_offset']}");
                }
                $type = 'ExpandableBlockQuote';
            }

            $argument = $top['argument'];
            $userId = 0;
            $customEmojiId = 0;
            $date = null;
            $skip = $utf16 === $top['offset'];

            switch ($type) {
                case 'Underline':
                case 'Spoiler':
                    $i++;
                    break;
                case 'Pre':
                case 'PreCode':
                    $i += 2;
                    break;
                case 'TextUrl':
                    if (self::at($text, $i + 1) !== '(') {
                        $url = substr($out, $top['begin_pos']);
                    } else {
                        [$url, $i] = self::markdownV2Url($text, $i + 2);
                    }
                    $userId = TgLinks::userId($url);
                    if ($userId <= 0) {
                        $userId = 0;
                        $url = LinkChecker::getCheckedLink($url);
                        if ($url === '') {
                            $skip = true;
                        } else {
                            $argument = $url;
                        }
                    }
                    break;
                case 'CustomEmoji':
                    if (self::at($text, $i + 1) !== '(') {
                        throw new FormattingError('The entity must contain a tg://emoji or tg://time URL');
                    }
                    [$url, $i] = self::markdownV2Url($text, $i + 2);
                    $customEmojiId = TgLinks::customEmojiId($url);
                    if ($customEmojiId === null) {
                        $date = TgLinks::formattedDate($url);
                        if ($date === null) {
                            throw new FormattingError('Invalid tg://emoji or tg://time URL specified');
                        }
                        $type = 'FormattedDate';
                        $customEmojiId = 0;
                    }
                    break;
                case 'BlockQuote':
                case 'ExpandableBlockQuote':
                    $haveBlockquote = false;
                    $out .= $text[$i];
                    $canStartBlockquote = true;
                    $utf16++;
                    $skip = false;
                    break;
            }

            if (!$skip) {
                $offset = $top['offset'];
                $length = $utf16 - $offset;
                if ($userId > 0) {
                    $entities[] = ['type' => 'MentionName', 'offset' => $offset, 'length' => $length, 'user_id' => $userId];
                } elseif ($customEmojiId !== 0) {
                    $entities[] = ['type' => 'CustomEmoji', 'offset' => $offset, 'length' => $length, 'custom_emoji_id' => $customEmojiId];
                } elseif ($date !== null) {
                    $entities[] = ['type' => 'FormattedDate', 'offset' => $offset, 'length' => $length] + $date;
                } else {
                    $entities[] = self::withArgument(['type' => $type, 'offset' => $offset, 'length' => $length], $argument);
                }
            }
            array_pop($nested);
        }

        if ($haveBlockquote) {
            $type = 'BlockQuote';
            $top = end($nested);
            if ($top['type'] === 'Spoiler' && $top['byte_offset'] === $size - 2) {
                array_pop($nested);
                $type = 'ExpandableBlockQuote';
            }
            $top = end($nested);
            if ($top['type'] === 'BlockQuote') {
                $length = $utf16 - $top['offset'];
                if ($length !== 0) {
                    $entities[] = ['type' => $type, 'offset' => $top['offset'], 'length' => $length];
                }
                array_pop($nested);
            }
        }
        if ($nested !== []) {
            $top = end($nested);
            throw new FormattingError("Can't find end of ".self::typeName($top['type'])." entity at byte offset {$top['byte_offset']}");
        }

        EntityType::sort($entities);
        $text = $out;

        return $entities;
    }

    /**
     * Read a "(url)" with \-escapes, starting after the '('.
     *
     * @return array{0: string, 1: int} the URL and the position of ')'
     */
    private static function markdownV2Url(string $text, int $i): array
    {
        $size = strlen($text);
        $begin = $i;
        $url = '';
        while ($i < $size && $text[$i] !== ')') {
            $next = self::at($text, $i + 1);
            if ($text[$i] === '\\' && ord($next) > 0 && ord($next) <= 126) {
                $url .= $next;
                $i += 2;
                continue;
            }
            $url .= $text[$i++];
        }
        if (self::at($text, $i) !== ')') {
            throw new FormattingError("Can't find end of a URL at byte offset {$begin}");
        }

        return [$url, $i];
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private static function withArgument(array $entity, string $argument): array
    {
        return match ($entity['type']) {
            'PreCode' => $entity + ['language' => $argument],
            'TextUrl' => $entity + ['url' => $argument],
            default => $entity,
        };
    }

    private static function reserved(string $c): FormattingError
    {
        return new FormattingError("Character '{$c}' is reserved and must be escaped with the preceding '\\'");
    }

    /**
     * operator<<(MessageEntity::Type) as used in parser errors.
     */
    private static function typeName(string $type): string
    {
        return $type === 'FormattedDate' ? 'Date' : $type;
    }

    // ═════════════════════════════════════════════════════════════════
    //  HTML
    // ═════════════════════════════════════════════════════════════════

    /**
     * decode_html_entity: the decoded code point (0 when not an entity) and
     * the position after it.
     *
     * @return array{0: int, 1: int}
     */
    private static function decodeHtmlEntity(string $text, int $pos): array
    {
        $end = $pos + 1;
        $result = 0;
        if (self::at($text, $pos + 1) === '#') {
            $end++;
            if (self::at($text, $pos + 2) === 'x') {
                $end++;
                while (ctype_xdigit(self::at($text, $end))) {
                    $result = ($result * 16 + hexdec($text[$end++])) & 0xFFFFFFFF;
                }
            } else {
                while (ctype_digit(self::at($text, $end))) {
                    $result = ($result * 10 + (ord($text[$end++]) - 48)) & 0xFFFFFFFF;
                }
            }
            if ($result === 0 || $result >= 0x10FFFF || $end - $pos >= 10) {
                return [0, $pos];
            }
        } else {
            while (ctype_alpha(self::at($text, $end))) {
                $end++;
            }
            $result = match (substr($text, $pos + 1, $end - $pos - 1)) {
                'lt' => 0x3C,
                'gt' => 0x3E,
                'amp' => 0x26,
                'quot' => 0x22,
                default => 0,
            };
            if ($result === 0) {
                return [0, $pos];
            }
        }

        return [$result, self::at($text, $end) === ';' ? $end + 1 : $end];
    }

    private const HTML_TAGS = [
        'a', 'b', 'strong', 'i', 'em', 's', 'strike', 'del', 'u', 'ins', 'tg-spoiler', 'tg-emoji', 'tg-time',
        'span', 'pre', 'code', 'blockquote',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function html(string &$str): array
    {
        $size = strlen($str);
        $out = '';
        $entities = [];
        $utf16 = 0;
        $needRecheckUtf8 = false;
        $nested = [];

        for ($i = 0; $i < $size; $i++) {
            $c = $str[$i];
            if ($c === '&') {
                [$code, $after] = self::decodeHtmlEntity($str, $i);
                if ($code !== 0) {
                    $i = $after - 1;
                    $utf16 += 1 + ($code > 0xFFFF ? 1 : 0);
                    if ($code >= 0xD800 && $code <= 0xDFFF) {
                        $needRecheckUtf8 = true;
                    }
                    $out .= Utf8::chr($code);
                    continue;
                }
            }
            if ($c !== '<') {
                $utf16 += self::utf16Step(ord($c));
                $out .= $c;
                continue;
            }

            $beginPos = $i++;
            if (self::at($str, $i) !== '/') {
                while (!self::isSpace(self::at($str, $i)) && self::at($str, $i) !== '>') {
                    $i++;
                }
                if (self::at($str, $i) === "\0") {
                    throw new FormattingError("Unclosed start tag at byte offset {$beginPos}");
                }

                $tagName = strtolower(substr($str, $beginPos + 1, $i - $beginPos - 1));
                if (!in_array($tagName, self::HTML_TAGS, true)) {
                    throw new FormattingError("Unsupported start tag \"{$tagName}\" at byte offset {$beginPos}");
                }

                $argument = '';
                $date = 0;
                while (self::at($str, $i) !== '>') {
                    while (self::isSpaceNotNul(self::at($str, $i))) {
                        $i++;
                    }
                    if (self::at($str, $i) === '>') {
                        break;
                    }
                    $attributeBegin = $i;
                    while (!self::isSpace(self::at($str, $i)) && !in_array(self::at($str, $i), ['=', '>', '/', '"', "'"], true)) {
                        $i++;
                    }
                    $attributeName = substr($str, $attributeBegin, $i - $attributeBegin);
                    if ($attributeName === '') {
                        throw new FormattingError("Empty attribute name in the tag \"{$tagName}\" at byte offset {$beginPos}");
                    }
                    while (self::isSpaceNotNul(self::at($str, $i))) {
                        $i++;
                    }
                    if (self::at($str, $i) !== '=') {
                        if (self::at($str, $i) === "\0") {
                            throw new FormattingError("Unclosed start tag \"{$tagName}\" at byte offset {$beginPos}");
                        }
                        if ($tagName === 'blockquote' && $attributeName === 'expandable') {
                            $argument = '1';
                        }
                        continue;
                    }
                    $i++;
                    while (self::isSpaceNotNul(self::at($str, $i))) {
                        $i++;
                    }
                    if (self::at($str, $i) === "\0") {
                        throw new FormattingError("Unclosed start tag \"{$tagName}\" at byte offset {$beginPos}");
                    }

                    if ($str[$i] !== "'" && $str[$i] !== '"') {
                        $tokenBegin = $i;
                        while (ctype_alnum(self::at($str, $i)) || self::at($str, $i) === '.' || self::at($str, $i) === '-') {
                            $i++;
                        }
                        $value = strtolower(substr($str, $tokenBegin, $i - $tokenBegin));
                        if (!self::isSpace(self::at($str, $i)) && self::at($str, $i) !== '>') {
                            throw new FormattingError("Unexpected end of name token at byte offset {$tokenBegin}");
                        }
                    } else {
                        $endCharacter = $str[$i++];
                        $value = '';
                        while (self::at($str, $i) !== $endCharacter && self::at($str, $i) !== "\0") {
                            if ($str[$i] === '&') {
                                [$code, $after] = self::decodeHtmlEntity($str, $i);
                                if ($code !== 0) {
                                    $value .= Utf8::chr($code);
                                    $i = $after;
                                    continue;
                                }
                            }
                            $value .= $str[$i++];
                        }
                        if (self::at($str, $i) === $endCharacter) {
                            $i++;
                        }
                    }
                    if (self::at($str, $i) === "\0") {
                        throw new FormattingError("Unclosed start tag at byte offset {$beginPos}");
                    }

                    if ($tagName === 'a' && $attributeName === 'href') {
                        $argument = $value;
                    } elseif ($tagName === 'code' && $attributeName === 'class' && str_starts_with($value, 'language-')) {
                        $argument = substr($value, 9);
                    } elseif ($tagName === 'span' && $attributeName === 'class' && str_starts_with($value, 'tg-')) {
                        $argument = substr($value, 3);
                    } elseif ($tagName === 'tg-emoji' && $attributeName === 'emoji-id') {
                        $argument = $value;
                    } elseif ($tagName === 'blockquote' && $attributeName === 'expandable') {
                        $argument = '1';
                    } elseif ($tagName === 'tg-time' && $attributeName === 'unix') {
                        $date = TdInteger::toInt32($value);
                    } elseif ($tagName === 'tg-time' && $attributeName === 'format') {
                        $argument = $value;
                    }
                }

                if ($tagName === 'span' && $argument !== 'spoiler') {
                    throw new FormattingError("Tag \"span\" must have class \"tg-spoiler\" at byte offset {$beginPos}");
                }

                $nested[] = ['tag' => $tagName, 'argument' => $argument, 'date' => $date, 'offset' => $utf16, 'begin_pos' => strlen($out)];

                continue;
            }

            // End tag.
            if ($nested === []) {
                throw new FormattingError("Unexpected end tag at byte offset {$beginPos}");
            }
            while (!self::isSpace(self::at($str, $i)) && self::at($str, $i) !== '>') {
                $i++;
            }
            $endTag = strtolower(substr($str, $beginPos + 2, $i - $beginPos - 2));
            while (self::isSpaceNotNul(self::at($str, $i))) {
                $i++;
            }
            if (self::at($str, $i) !== '>') {
                throw new FormattingError("Unclosed end tag at byte offset {$beginPos}");
            }

            $top = array_pop($nested);
            $tagName = $top['tag'];
            if ($endTag !== '' && $endTag !== $tagName) {
                throw new FormattingError("Unmatched end tag at byte offset {$beginPos}, expected \"</{$tagName}>\", found \"</{$endTag}>\"");
            }

            if ($utf16 <= $top['offset']) {
                continue;
            }

            $offset = $top['offset'];
            $length = $utf16 - $offset;
            $last = count($entities) - 1;
            switch ($tagName) {
                case 'i':
                case 'em':
                    $entities[] = ['type' => 'Italic', 'offset' => $offset, 'length' => $length];
                    break;
                case 'b':
                case 'strong':
                    $entities[] = ['type' => 'Bold', 'offset' => $offset, 'length' => $length];
                    break;
                case 's':
                case 'strike':
                case 'del':
                    $entities[] = ['type' => 'Strikethrough', 'offset' => $offset, 'length' => $length];
                    break;
                case 'u':
                case 'ins':
                    $entities[] = ['type' => 'Underline', 'offset' => $offset, 'length' => $length];
                    break;
                case 'tg-spoiler':
                case 'span':
                    $entities[] = ['type' => 'Spoiler', 'offset' => $offset, 'length' => $length];
                    break;
                case 'tg-emoji':
                    $id = TdInteger::toInt64Safe($top['argument']);
                    if ($id === null || $id === 0) {
                        throw new FormattingError('Invalid custom emoji identifier specified');
                    }
                    $entities[] = ['type' => 'CustomEmoji', 'offset' => $offset, 'length' => $length, 'custom_emoji_id' => $id];
                    break;
                case 'tg-time':
                    $flags = FormattedDate::flagsFromFormat($top['argument']);
                    if ($top['date'] > 0) {
                        $entities[] = ['type' => 'FormattedDate', 'offset' => $offset, 'length' => $length, 'unix_time' => $top['date'], 'flags' => $flags];
                    }
                    break;
                case 'a':
                    $url = $top['argument'] !== '' ? $top['argument'] : substr($out, $top['begin_pos']);
                    $entity = self::linkEntity($url, $offset, $length);
                    if ($entity !== null) {
                        $entities[] = $entity;
                    }
                    break;
                case 'pre':
                    if ($last >= 0 && $entities[$last]['type'] === 'Code' && $entities[$last]['offset'] === $offset
                        && $entities[$last]['length'] === $length && ($entities[$last]['language'] ?? '') !== '') {
                        $entities[$last]['type'] = 'PreCode';
                    } else {
                        $entities[] = ['type' => 'Pre', 'offset' => $offset, 'length' => $length];
                    }
                    break;
                case 'code':
                    if ($last >= 0 && $entities[$last]['type'] === 'Pre' && $entities[$last]['offset'] === $offset
                        && $entities[$last]['length'] === $length && $top['argument'] !== '') {
                        $entities[$last]['type'] = 'PreCode';
                        $entities[$last]['language'] = $top['argument'];
                    } else {
                        $entities[] = ['type' => 'Code', 'offset' => $offset, 'length' => $length, 'language' => $top['argument']];
                    }
                    break;
                case 'blockquote':
                    $entities[] = ['type' => $top['argument'] !== '' ? 'ExpandableBlockQuote' : 'BlockQuote', 'offset' => $offset, 'length' => $length];
                    break;
            }
        }

        if ($nested !== []) {
            throw new FormattingError("Can't find end tag corresponding to start tag \"".end($nested)['tag'].'"');
        }

        foreach ($entities as &$entity) {
            if ($entity['type'] === 'Code') {
                unset($entity['language']);
            }
        }
        unset($entity);

        EntityType::sort($entities);

        $str = $out;
        if ($needRecheckUtf8 && !Utf8::check($str)) {
            throw new FormattingError('Text contains invalid Unicode characters after decoding HTML entities, check for unmatched surrogate code units');
        }

        return $entities;
    }
}
