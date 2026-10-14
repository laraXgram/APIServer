<?php

declare(strict_types=1);

namespace LaraGram\Server\Entities;

use LaraGram\Server\Support\Utf8;

/**
 * Text and entity normalisation: fix_formatted_text() with its helpers from
 * TDLib's MessageEntity.cpp and misc.cpp (clean_input_string,
 * strip_empty_characters, fix_entities, ...).
 */
final class TextFormatting
{
    private const LENGTH_LIMIT = 35000;

    /**
     * td::fix_formatted_text.
     *
     * @param list<array<string, mixed>> $entities
     * @return array{0: string, 1: list<array<string, mixed>>} the fixed text and entities
     *
     * @throws FormattingError
     */
    public static function fixFormattedText(string $text, array $entities, bool $allowEmpty, bool $allowEmptyString,
        bool $skipNewEntities, bool $skipBotCommands, bool $skipMediaTimestamps, bool $skipTrim, ?int &$ltrimCount = null): array
    {
        if ($entities === []) {
            $result = self::cleanInputString($text);
            if ($result === null) {
                throw new FormattingError('Strings must be encoded in UTF-8');
            }
        } else {
            if (!Utf8::check($text)) {
                throw new FormattingError('Strings must be encoded in UTF-8');
            }
            foreach ($entities as $entity) {
                if ($entity['offset'] < 0 || $entity['offset'] > 1000000) {
                    throw new FormattingError("Receive an entity with incorrect offset {$entity['offset']}");
                }
                if ($entity['length'] < 0 || $entity['length'] > 1000000) {
                    throw new FormattingError("Receive an entity with incorrect length {$entity['length']}");
                }
            }
            $entities = self::removeEmptyEntities($entities);
            $entities = self::fixEntities($entities);
            [$result, $entities] = self::cleanInputStringWithEntities($text, $entities);
        }

        [$lastNonWhitespacePos, $lastNonWhitespaceUtf16Offset, $entities] = self::removeInvalidEntities($result, $entities);
        if ($lastNonWhitespaceUtf16Offset === -1) {
            if ($allowEmpty) {
                return ['', []];
            }
            throw new FormattingError('Text must be non-empty');
        }

        $entities = self::fixEntities($entities);

        $ltrimCount = 0;
        if ($skipTrim) {
            $text = $result;
        } else {
            $result = substr($result, 0, $lastNonWhitespacePos + 1);
            while ($entities !== [] && $entities[count($entities) - 1]['offset'] > $lastNonWhitespaceUtf16Offset) {
                array_pop($entities);
            }
            $needSort = false;
            foreach ($entities as &$entity) {
                if ($entity['offset'] + $entity['length'] > $lastNonWhitespaceUtf16Offset + 1) {
                    $entity['length'] = $lastNonWhitespaceUtf16Offset + 1 - $entity['offset'];
                    $needSort = true;
                }
            }
            unset($entity);
            if ($needSort) {
                EntityType::sort($entities);
            }

            $first = 0;
            $firstEntityBegin = $entities === [] ? strlen($result) : $entities[0]['offset'];
            while ($first < $firstEntityBegin && ($result[$first] === ' ' || $result[$first] === "\n")) {
                $first++;
            }
            if ($first > 0) {
                $ltrimCount = $first;
                $text = substr($result, $first);
                foreach ($entities as &$entity) {
                    $entity['offset'] -= $first;
                }
                unset($entity);
            } else {
                $text = $result;
            }
        }

        if (self::isEmptyString($text)) {
            if (!$allowEmpty) {
                throw new FormattingError('Text must be non-empty');
            }
            if (!$allowEmptyString) {
                $text = '';
                $entities = [];
            }
        }

        if (strlen($text) > self::LENGTH_LIMIT) {
            $newSize = self::LENGTH_LIMIT;
            while (Utf8::isUtf8ContinuationByte($text, $newSize)) {
                $newSize--;
            }
            $text = substr($text, 0, $newSize);
            $utf16Length = Utf8::utf16Length($text);
            $entities = array_values(array_filter($entities, static fn (array $e): bool => $e['offset'] + $e['length'] <= $utf16Length));
        }

        if (!$skipNewEntities) {
            $entities = self::mergeNewEntities($entities, EntityFinder::find($text, $skipBotCommands, $skipMediaTimestamps));
        } elseif (!$skipMediaTimestamps) {
            $entities = self::mergeNewEntities($entities, EntityFinder::findMediaTimestampEntities($text));
        }

        return [$text, $entities];
    }

    /**
     * td::clean_input_string: null when the string is not valid UTF-8.
     */
    public static function cleanInputString(string $str): ?string
    {
        if (!Utf8::check($str)) {
            return null;
        }

        // Fast path: nothing to replace or remove.
        if (strlen($str) < self::LENGTH_LIMIT - 3
            && !preg_match('/[\x00-\x09\x0B-\x1F\r]|\xE2\x80[\xA8-\xAE\x8E\x8F]|\xCC[\xB3\xBF\x8A]/', $str)) {
            return $str;
        }

        $out = '';
        $size = strlen($str);
        for ($pos = 0; $pos < $size; $pos++) {
            $c = ord($str[$pos]);
            if ($c <= 32 && $c !== 10 && $c !== 13) {
                $out .= ' ';
            } elseif ($c === 13) {
                // skip
            } elseif ($c === 0xE2 && $pos + 2 < $size && ord($str[$pos + 1]) === 0x80 && ord($str[$pos + 2]) >= 0xA8 && ord($str[$pos + 2]) <= 0xAE) {
                $pos += 2;
            } elseif ($c === 0xCC && $pos + 1 < $size && in_array(ord($str[$pos + 1]), [0xB3, 0xBF, 0x8A], true)) {
                $pos++;
            } else {
                $out .= $str[$pos];
            }

            $newSize = strlen($out);
            if ($newSize >= self::LENGTH_LIMIT - 3 && !Utf8::isUtf8ContinuationByte($out, $newSize - 1)) {
                $out = substr($out, 0, $newSize - 1);
                break;
            }
        }

        return self::replaceOffendingCharacters($out);
    }

    /**
     * td::replace_offending_characters: a run of LRM/RLM marks keeps only its
     * last mark; the others become zero-width non-joiners.
     */
    public static function replaceOffendingCharacters(string $str): string
    {
        if (!str_contains($str, "\xE2\x80\x8E") && !str_contains($str, "\xE2\x80\x8F")) {
            return $str;
        }

        return preg_replace_callback('/(?:\xE2\x80[\x8E\x8F]){2,}/', static function (array $m): string {
            $count = intdiv(strlen($m[0]), 3);

            return str_repeat("\xE2\x80\x8C", $count - 1).substr($m[0], -3);
        }, $str);
    }

    /**
     * td::strip_empty_characters.
     */
    public static function stripEmptyCharacters(string $str, int $maxLength, bool $stripRtlo = false): string
    {
        static $spaces = [
            "\u{1680}", "\u{180E}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}", "\u{2005}", "\u{2006}",
            "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}", "\u{202E}", "\u{202F}", "\u{205F}", "\u{2800}", "\u{3000}",
            "\u{FFFC}",
        ];

        $out = '';
        $size = strlen($str);
        for ($i = 0; $i < $size;) {
            $c = ord($str[$i]);
            if ($i + 3 <= $size && ($c === 0xE1 || $c === 0xE2 || $c === 0xE3 || $c === 0xEF || $c === 0xF3)) {
                if ($c === 0xF3) {
                    if (ord($str[$i + 1]) === 0xA0 && (ord($str[$i + 2]) & 0xFE) === 0x80 && $i + 4 <= $size) {
                        $out .= ' ';
                        $i += 4;
                        continue;
                    }
                } else {
                    $three = substr($str, $i, 3);
                    if (in_array($three, $spaces, true) && ($three !== "\u{202E}" || $stripRtlo)) {
                        $out .= ' ';
                        $i += 3;
                        continue;
                    }
                }
            }
            $out .= $str[$i++];
        }

        $trimmed = self::trim(mb_substr(self::trim($out), 0, $maxLength));

        $i = 0;
        $length = strlen($trimmed);
        while (true) {
            if ($i === $length) {
                return '';
            }
            if ($trimmed[$i] === ' ' || $trimmed[$i] === "\n") {
                $i++;
                continue;
            }
            if ($trimmed[$i] === "\xE2" && ($trimmed[$i + 1] ?? '') === "\x80") {
                $next = ord($trimmed[$i + 2] ?? "\0");
                if (($next >= 0x8B && $next <= 0x8F) || $next === 0xAE) {
                    $i += 3;
                    continue;
                }
            }
            if (substr($trimmed, $i, 3) === "\xEF\xBB\xBF") {
                $i += 3;
                continue;
            }
            if (substr($trimmed, $i, 2) === "\xC2\xA0") {
                $i += 2;
                continue;
            }
            break;
        }

        return $trimmed;
    }

    public static function isEmptyString(string $str): bool
    {
        return self::stripEmptyCharacters($str, strlen($str)) === '';
    }

    /**
     * td::trim: ' ', \t, \r, \n, \0 and \v.
     */
    private static function trim(string $str): string
    {
        return trim($str, " \t\r\n\0\x0B");
    }

    // ═════════════════════════════════════════════════════════════════
    //  Entities
    // ═════════════════════════════════════════════════════════════════

    /**
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    public static function removeEmptyEntities(array $entities): array
    {
        return array_values(array_filter($entities, static function (array $e): bool {
            if ($e['length'] <= 0) {
                return false;
            }

            return match ($e['type']) {
                'TextUrl' => $e['url'] !== '',
                'MentionName' => $e['user_id'] > 0,
                'CustomEmoji' => $e['custom_emoji_id'] !== 0,
                'FormattedDate' => $e['unix_time'] > 0,
                default => true,
            };
        }));
    }

    /**
     * Removes every entity intersecting (or nested in) a previous one.
     *
     * @param list<array<string, mixed>> $entities sorted
     * @return list<array<string, mixed>>
     */
    public static function removeIntersectingEntities(array $entities): array
    {
        $result = [];
        $lastEnd = 0;
        foreach ($entities as $entity) {
            if ($entity['offset'] >= $lastEnd) {
                $lastEnd = $entity['offset'] + $entity['length'];
                $result[] = $entity;
            }
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @param list<array<string, mixed>> $blockquotes
     * @return list<array<string, mixed>>
     */
    private static function removeEntitiesIntersectingBlockquote(array $entities, array $blockquotes): array
    {
        if ($blockquotes === []) {
            return $entities;
        }

        $result = [];
        $b = 0;
        $count = count($blockquotes);
        foreach ($entities as $entity) {
            while ($b < $count && (!isset(EntityType::BLOCKQUOTE[$blockquotes[$b]['type']])
                || $blockquotes[$b]['offset'] + $blockquotes[$b]['length'] <= $entity['offset'])) {
                $b++;
            }
            if ($b < $count) {
                $quoteEnd = $blockquotes[$b]['offset'] + $blockquotes[$b]['length'];
                $entityEnd = $entity['offset'] + $entity['length'];
                if ($quoteEnd < $entityEnd || ($entity['offset'] < $blockquotes[$b]['offset'] && $blockquotes[$b]['offset'] < $entityEnd)) {
                    continue;
                }
            }
            $result[] = $entity;
        }

        return $result;
    }

    /**
     * merge_entities: old entities win over intersecting new ones.
     *
     * @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     * @return list<array<string, mixed>>
     */
    private static function mergeEntities(array $old, array $new): array
    {
        if ($new === []) {
            return $old;
        }
        if ($old === []) {
            return $new;
        }

        $result = [];
        $n = 0;
        $count = count($new);
        foreach ($old as $entity) {
            while ($n < $count && $new[$n]['offset'] + $new[$n]['length'] <= $entity['offset']) {
                $result[] = $new[$n++];
            }
            $end = $entity['offset'] + $entity['length'];
            $result[] = $entity;
            while ($n < $count && $new[$n]['offset'] < $end) {
                $n++;
            }
        }
        while ($n < $count) {
            $result[] = $new[$n++];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $entities sorted
     */
    private static function areEntitiesValid(array $entities): bool
    {
        if ($entities === []) {
            return true;
        }

        $splittableMask = EntityType::maskOf(EntityType::SPLITTABLE);
        $blockquoteMask = EntityType::maskOf(EntityType::BLOCKQUOTE);
        $continuousMask = EntityType::maskOf(EntityType::CONTINUOUS);

        $endPos = [-1, -1, -1, -1, -1];
        $stack = [];
        $mask = 0;
        foreach ($entities as $entity) {
            while ($stack !== [] && $entity['offset'] >= end($stack)['offset'] + end($stack)['length']) {
                $mask -= EntityType::mask(array_pop($stack)['type']);
            }

            $type = $entity['type'];
            if ($stack !== []) {
                $parent = end($stack);
                if ($entity['offset'] + $entity['length'] > $parent['offset'] + $parent['length']) {
                    return false;
                }
                if (($mask & EntityType::mask($type)) !== 0) {
                    return false;
                }
                if (isset(EntityType::PRE[$parent['type']])) {
                    return false;
                }
                if (isset(EntityType::PRE[$type]) && ($mask & ~$blockquoteMask) !== 0) {
                    return false;
                }
                if ((isset(EntityType::CONTINUOUS[$type]) || isset(EntityType::BLOCKQUOTE[$type])) && ($mask & $continuousMask) !== 0) {
                    return false;
                }
                if (isset(EntityType::BLOCKQUOTE[$type]) && ($mask & $blockquoteMask) !== 0) {
                    return false;
                }
                if (($mask & $splittableMask) !== 0) {
                    return false;
                }
            }

            if (isset(EntityType::SPLITTABLE[$type])) {
                $index = EntityType::SPLITTABLE[$type];
                if ($endPos[$index] >= $entity['offset']) {
                    return false;
                }
                $endPos[$index] = $entity['offset'] + $entity['length'];
            }
            $stack[] = $entity;
            $mask += EntityType::mask($type);
        }

        return true;
    }

    /**
     * split_entities: splittable entities split around the others (and dropped inside pre).
     *
     * @param list<array<string, mixed>> $entities splittable only, sorted
     * @param list<array<string, mixed>> $other sorted
     * @return list<array<string, mixed>>
     */
    public static function splitEntities(array $entities, array $other): array
    {
        $types = ['Bold', 'Italic', 'Underline', 'Strikethrough', 'Spoiler'];
        $beginPos = [0, 0, 0, 0, 0];
        $endPos = [0, 0, 0, 0, 0];
        $it = 0;
        $count = count($entities);
        $result = [];

        $flush = static function (int $offset) use (&$beginPos, &$endPos, &$result, $types): void {
            foreach ($types as $index => $type) {
                if ($endPos[$index] !== 0 && $beginPos[$index] < $offset) {
                    if ($endPos[$index] <= $offset) {
                        $result[] = ['type' => $type, 'offset' => $beginPos[$index], 'length' => $endPos[$index] - $beginPos[$index]];
                        $beginPos[$index] = 0;
                        $endPos[$index] = 0;
                    } else {
                        $result[] = ['type' => $type, 'offset' => $beginPos[$index], 'length' => $offset - $beginPos[$index]];
                        $beginPos[$index] = $offset;
                    }
                }
            }
        };

        $addEntities = static function (int $endOffset) use (&$it, $count, $entities, &$beginPos, &$endPos, $flush): void {
            while ($it < $count) {
                $entity = $entities[$it];
                if ($entity['offset'] >= $endOffset) {
                    break;
                }
                $index = EntityType::SPLITTABLE[$entity['type']];
                if ($entity['offset'] <= $endPos[$index] && $endPos[$index] !== 0) {
                    if ($entity['offset'] + $entity['length'] > $endPos[$index]) {
                        $endPos[$index] = $entity['offset'] + $entity['length'];
                    }
                } else {
                    $flush($entity['offset']);
                    $beginPos[$index] = $entity['offset'];
                    $endPos[$index] = $entity['offset'] + $entity['length'];
                }
                $it++;
            }
            $flush($endOffset);
        };

        $stack = [];
        $addOffset = static function (int $offset) use (&$stack, &$result, $addEntities): void {
            while ($stack !== [] && $offset >= end($stack)['offset'] + end($stack)['length']) {
                $top = end($stack);
                $oldSize = count($result);
                $addEntities($top['offset'] + $top['length']);
                if (isset(EntityType::PRE[$top['type']])) {
                    $result = array_slice($result, 0, $oldSize);
                }
                array_pop($stack);
            }
            $addEntities($offset);
        };

        foreach ($other as $entity) {
            $addOffset($entity['offset']);
            $stack[] = $entity;
        }
        $addOffset(PHP_INT_MAX);

        EntityType::sort($result);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $splittable
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    private static function resplitEntities(array $splittable, array $entities): array
    {
        if ($splittable !== []) {
            $splittable = self::splitEntities($splittable, $entities);
            if ($entities === []) {
                return $splittable;
            }
            $entities = array_merge($entities, $splittable);
            EntityType::sort($entities);
        }

        return $entities;
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private static function partition(array $entities): array
    {
        $continuous = $blockquotes = $splittable = [];
        foreach ($entities as $entity) {
            if (isset(EntityType::SPLITTABLE[$entity['type']])) {
                $splittable[] = $entity;
            } elseif (isset(EntityType::BLOCKQUOTE[$entity['type']])) {
                $blockquotes[] = $entity;
            } else {
                $continuous[] = $entity;
            }
        }

        return [$continuous, $blockquotes, $splittable];
    }

    /**
     * td::fix_entities.
     *
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    public static function fixEntities(array $entities): array
    {
        EntityType::sort($entities);
        if (self::areEntitiesValid($entities)) {
            return $entities;
        }

        [$continuous, $blockquotes, $splittable] = self::partition($entities);
        $continuous = self::removeIntersectingEntities($continuous);

        if ($blockquotes !== []) {
            $blockquotes = self::removeIntersectingEntities($blockquotes);
            $continuous = self::removeEntitiesIntersectingBlockquote($continuous, $blockquotes);
            $continuous = array_merge($continuous, $blockquotes);
            EntityType::sort($continuous);
        }

        return self::resplitEntities($splittable, $continuous);
    }

    /**
     * @param list<array<string, mixed>> $entities sorted
     * @param list<array<string, mixed>> $new sorted, non-intersecting
     * @return list<array<string, mixed>>
     */
    private static function mergeNewEntities(array $entities, array $new): array
    {
        if ($new === []) {
            return $entities;
        }

        [$continuous, $blockquotes, $splittable] = self::partition($entities);
        $new = self::removeEntitiesIntersectingBlockquote($new, $blockquotes);
        $continuous = self::mergeEntities($continuous, $new);

        if ($blockquotes !== []) {
            $continuous = array_merge($continuous, $blockquotes);
            EntityType::sort($continuous);
        }

        return self::resplitEntities($splittable, $continuous);
    }

    /**
     * clean_input_string_with_entities: clean the text and move the entities
     * over removed characters.
     *
     * @param list<array<string, mixed>> $entities sorted
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private static function cleanInputStringWithEntities(string $text, array $entities): array
    {
        $stack = [];
        $current = 0;
        $count = count($entities);
        $utf16Offset = 0;
        $utf16Skipped = 0;
        $size = strlen($text);
        $result = '';

        for ($pos = 0; $pos <= $size; $pos++) {
            $c = $pos < $size ? ord($text[$pos]) : 0;
            $isCharBegin = ($c & 0xC0) !== 0x80;
            if ($isCharBegin) {
                while ($stack !== []) {
                    [$index, $skippedBefore] = end($stack);
                    $entityEnd = $entities[$index]['offset'] + $entities[$index]['length'];
                    if ($utf16Offset < $entityEnd) {
                        break;
                    }
                    if ($utf16Offset !== $entityEnd) {
                        throw new FormattingError("Entity beginning at UTF-16 offset {$entities[$index]['offset']} ends in a middle of a UTF-16 symbol at byte offset {$pos}");
                    }
                    $entities[$index]['offset'] -= $skippedBefore;
                    $entities[$index]['length'] -= $utf16Skipped - $skippedBefore;
                    array_pop($stack);
                }
                while ($current < $count && $utf16Offset >= $entities[$current]['offset']) {
                    if ($utf16Offset !== $entities[$current]['offset']) {
                        throw new FormattingError("Entity begins in a middle of a UTF-16 symbol at byte offset {$pos}");
                    }
                    $stack[] = [$current++, $utf16Skipped];
                }
            }
            if ($pos === $size) {
                break;
            }

            if ($c <= 32 && $c !== 10 && $c !== 13) {
                $result .= ' ';
                $utf16Offset++;
            } elseif ($c === 13) {
                $utf16Offset++;
                $utf16Skipped++;
            } else {
                if ($isCharBegin) {
                    $utf16Offset += 1 + ($c >= 0xF0 ? 1 : 0);
                }
                if ($c === 0xE2 && $pos + 2 < $size && ord($text[$pos + 1]) === 0x80 && ord($text[$pos + 2]) >= 0xA8 && ord($text[$pos + 2]) <= 0xAE) {
                    $pos += 2;
                    $utf16Skipped++;
                    continue;
                }
                if ($c === 0xCC && $pos + 1 < $size && in_array(ord($text[$pos + 1]), [0xB3, 0xBF, 0x8A], true)) {
                    $pos++;
                    $utf16Skipped++;
                    continue;
                }
                $result .= $text[$pos];
            }
        }

        if ($current !== $count) {
            throw new FormattingError("Entity begins after the end of the text at UTF-16 offset {$entities[$current]['offset']}");
        }
        if ($stack !== []) {
            $entity = $entities[end($stack)[0]];
            throw new FormattingError("Entity beginning at UTF-16 offset {$entity['offset']} ends after the end of the text at UTF-16 offset ".($entity['offset'] + $entity['length']));
        }

        return [self::replaceOffendingCharacters($result), $entities];
    }

    /**
     * remove_invalid_entities: drops empty entities and finds the last
     * non-whitespace position (byte and UTF-16; UTF-16 is -1 for an all-blank text).
     *
     * @param list<array<string, mixed>> $entities
     * @return array{0: int, 1: int, 2: list<array<string, mixed>>}
     */
    private static function removeInvalidEntities(string $text, array $entities): array
    {
        $size = strlen($text);
        if ($entities === []) {
            for ($pos = $size - 1; $pos >= 0; $pos--) {
                if ($text[$pos] !== "\n" && $text[$pos] !== ' ') {
                    return [$pos, 0, []];
                }
            }

            return [$size, -1, []];
        }

        $entities = self::removeEmptyEntities($entities);

        $lastPos = $size;
        $utf16Offset = 0;
        $lastUtf16 = -1;
        for ($pos = 0; $pos < $size; $pos++) {
            $c = ord($text[$pos]);
            if ($c !== 10 && $c !== 32) {
                while ($pos + 1 < $size && Utf8::isUtf8ContinuationByte($text, $pos + 1)) {
                    $pos++;
                }
                $utf16Offset += $c >= 0xF0 ? 1 : 0;
                $lastPos = $pos;
                $lastUtf16 = $utf16Offset;
            }
            $utf16Offset++;
        }

        return [$lastPos, $lastUtf16, $entities];
    }
}
