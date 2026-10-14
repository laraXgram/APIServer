<?php

declare(strict_types=1);

namespace LaraGram\Server\Entities;

use LaraGram\Server\Support\Unicode;
use LaraGram\Server\Support\Utf8;

/**
 * Entity detection in plain text: a port of the matchers and
 * find_entities() of TDLib's MessageEntity.cpp. Matches are byte ranges
 * [begin, end) in the UTF-8 text.
 */
final class EntityFinder
{
    private const BAD_PATH_END_CHARS = ".:;,('?!`";

    private const VALID_SHORT_USERNAMES = ['gif' => true, 'nft' => true, 'pic' => true, 'ufc' => true, 'vid' => true];

    /**
     * td::find_entities: entities with UTF-16 offsets, sorted, non-intersecting.
     *
     * @return list<array<string, mixed>>
     */
    public static function find(string $text, bool $skipBotCommands, bool $skipMediaTimestamps): array
    {
        $entities = [];
        $add = static function (string $type, array $ranges) use (&$entities): void {
            foreach ($ranges as [$begin, $end]) {
                $entities[] = ['type' => $type, 'offset' => $begin, 'length' => $end - $begin];
            }
        };

        $add('Mention', self::findMentions($text));
        if (!$skipBotCommands) {
            $add('BotCommand', self::matchBotCommands($text));
        }
        $add('Hashtag', self::matchHashtags($text));
        $add('Cashtag', self::matchCashtags($text));
        $add('BankCardNumber', self::findBankCardNumbers($text));
        $add('Url', self::matchTgUrls($text));
        foreach (self::findUrls($text) as [$begin, $end, $isEmail]) {
            $entities[] = ['type' => $isEmail ? 'EmailAddress' : 'Url', 'offset' => $begin, 'length' => $end - $begin];
        }
        if (!$skipMediaTimestamps) {
            foreach (self::findMediaTimestamps($text) as [$begin, $end, $seconds]) {
                $entities[] = ['type' => 'MediaTimestamp', 'offset' => $begin, 'length' => $end - $begin, 'media_timestamp' => $seconds];
            }
        }

        return self::fixEntityOffsets($text, $entities);
    }

    /**
     * find_media_timestamp_entities.
     *
     * @return list<array<string, mixed>>
     */
    public static function findMediaTimestampEntities(string $text): array
    {
        $entities = [];
        foreach (self::findMediaTimestamps($text) as [$begin, $end, $seconds]) {
            $entities[] = ['type' => 'MediaTimestamp', 'offset' => $begin, 'length' => $end - $begin, 'media_timestamp' => $seconds];
        }

        return self::fixEntityOffsets($text, $entities);
    }

    /**
     * fix_entity_offsets: keep non-intersecting entities and convert byte
     * offsets to UTF-16 offsets.
     *
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    private static function fixEntityOffsets(string $text, array $entities): array
    {
        if ($entities === []) {
            return [];
        }

        EntityType::sort($entities);
        $entities = TextFormatting::removeIntersectingEntities($entities);

        $result = [];
        $bytePos = 0;
        $utf16Pos = 0;
        foreach ($entities as $entity) {
            $begin = $entity['offset'];
            $end = $begin + $entity['length'];
            $utf16Pos += Utf8::utf16Units($text, $bytePos, $begin);
            $offset = $utf16Pos;
            $utf16Pos += Utf8::utf16Units($text, $begin, $end);
            $bytePos = $end;
            $result[] = ['offset' => $offset, 'length' => $utf16Pos - $offset] + $entity;
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════════
    //  Character classes
    // ═════════════════════════════════════════════════════════════════

    private static function isWordCharacter(int $code): bool
    {
        $category = Unicode::category($code);

        return $category === Unicode::LETTER || $category === Unicode::DECIMAL_NUMBER || $category === Unicode::NUMBER || $code === 0x5F;
    }

    private static function isAlphaDigit(int $code): bool
    {
        return ($code >= 0x30 && $code <= 0x39) || ($code >= 0x61 && $code <= 0x7A) || ($code >= 0x41 && $code <= 0x5A);
    }

    private static function isAlphaDigitOrUnderscore(int $code): bool
    {
        return self::isAlphaDigit($code) || $code === 0x5F;
    }

    private static function isAlphaDigitOrUnderscoreOrMinus(int $code): bool
    {
        return self::isAlphaDigitOrUnderscore($code) || $code === 0x2D;
    }

    private static function isDigit(int $code): bool
    {
        return $code >= 0x30 && $code <= 0x39;
    }

    private static function isHashtagLetter(int $c, ?int &$category): bool
    {
        $category = Unicode::category($c);
        if ($c === 0x5F || $c === 0x200C || $c === 0xB7 || ($c >= 0xD80 && $c <= 0xDFF)) {
            return true;
        }

        return $category === Unicode::DECIMAL_NUMBER || $category === Unicode::LETTER;
    }

    private static function isUrlUnicodeSymbol(int $c): bool
    {
        if ($c >= 0x2000 && $c <= 0x206F) {
            return $c === 0x200C || $c === 0x200D || ($c >= 0x2010 && $c <= 0x2015);
        }

        return Unicode::category($c) !== Unicode::SEPARATOR;
    }

    private static function isUrlPathSymbol(int $c): bool
    {
        return match ($c) {
            0x0A, 0x3C, 0x3E, 0x22, 0xAB, 0xBB => false,
            default => self::isUrlUnicodeSymbol($c),
        };
    }

    private static function byte(string $s, int $pos): int
    {
        return ord($s[$pos]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Matchers (byte ranges)
    // ═════════════════════════════════════════════════════════════════

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function findMentions(string $s): array
    {
        $result = [];
        foreach (self::matchMentions($s) as [$begin, $end]) {
            $name = substr($s, $begin + 1, $end - $begin - 1);
            if (strlen($name) >= 4 || isset(self::VALID_SHORT_USERNAMES[strtolower($name)])) {
                $result[] = [$begin, $end];
            }
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function matchMentions(string $s): array
    {
        $result = [];
        $end = strlen($s);
        $ptr = 0;

        while (($ptr = strpos($s, '@', $ptr)) !== false) {
            if ($ptr !== 0 && self::isWordCharacter(Utf8::before($s, $ptr))) {
                $ptr++;
                continue;
            }

            $mentionBegin = ++$ptr;
            while ($ptr !== $end && self::isAlphaDigitOrUnderscore(self::byte($s, $ptr))) {
                $ptr++;
            }
            $size = $ptr - $mentionBegin;
            if ($size < 2 || $size > 32) {
                continue;
            }
            $next = $ptr !== $end ? Utf8::next($s, $ptr)[0] : 0;
            if (self::isWordCharacter($next)) {
                continue;
            }
            $result[] = [$mentionBegin - 1, $ptr];
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function matchBotCommands(string $s): array
    {
        $result = [];
        $end = strlen($s);
        $ptr = 0;

        while (($ptr = strpos($s, '/', $ptr)) !== false) {
            if ($ptr !== 0) {
                $prev = Utf8::before($s, $ptr);
                if (self::isWordCharacter($prev) || $prev === 0x2F || $prev === 0x3C || $prev === 0x3E) {
                    $ptr++;
                    continue;
                }
            }

            $commandBegin = ++$ptr;
            while ($ptr !== $end && self::isAlphaDigitOrUnderscore(self::byte($s, $ptr))) {
                $ptr++;
            }
            $commandEnd = $ptr;
            $size = $commandEnd - $commandBegin;
            if ($size < 1 || $size > 64) {
                continue;
            }

            if ($ptr !== $end && $s[$ptr] === '@') {
                $mentionBegin = ++$ptr;
                while ($ptr !== $end && self::isAlphaDigitOrUnderscore(self::byte($s, $ptr))) {
                    $ptr++;
                }
                $mentionSize = $ptr - $mentionBegin;
                if ($mentionSize < 3 || $mentionSize > 32) {
                    continue;
                }
                $commandEnd = $ptr;
            }

            $next = $ptr !== $end ? Utf8::next($s, $ptr)[0] : 0;
            if (self::isWordCharacter($next) || $next === 0x2F || $next === 0x3C || $next === 0x3E) {
                continue;
            }
            $result[] = [$commandBegin - 1, $commandEnd];
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function matchHashtags(string $s): array
    {
        $result = [];
        $end = strlen($s);
        $ptr = 0;
        $category = null;

        while (($ptr = strpos($s, '#', $ptr)) !== false) {
            if ($ptr !== 0 && self::isHashtagLetter(Utf8::before($s, $ptr), $category)) {
                $ptr++;
                continue;
            }

            $hashtagBegin = ++$ptr;
            $size = 0;
            $hashtagEnd = null;
            $wasLetter = false;
            while ($ptr !== $end) {
                [$code, $nextPtr] = Utf8::next($s, $ptr);
                if (!self::isHashtagLetter($code, $category)) {
                    break;
                }
                $ptr = $nextPtr;

                if ($size === 255) {
                    $hashtagEnd = $ptr;
                }
                if ($size !== 256) {
                    $wasLetter = $wasLetter || $category === Unicode::LETTER;
                    $size++;
                }
            }
            $hashtagEnd ??= $ptr;
            if ($size < 1) {
                continue;
            }
            if ($hashtagEnd === $ptr && $ptr !== $end && $s[$ptr] === '@') {
                $usernameEnd = $ptr + 1;
                while ($usernameEnd !== $end && $usernameEnd - $ptr < 33 && self::isAlphaDigitOrUnderscore(self::byte($s, $usernameEnd))) {
                    $usernameEnd++;
                }
                if ($usernameEnd - $ptr - 1 >= 3) {
                    $ptr = $usernameEnd;
                    $hashtagEnd = $usernameEnd;
                }
            }
            if ($ptr !== $end && $s[$ptr] === '#') {
                continue;
            }
            if (!$wasLetter) {
                continue;
            }
            $result[] = [$hashtagBegin - 1, $hashtagEnd];
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function matchCashtags(string $s): array
    {
        $result = [];
        $end = strlen($s);
        $ptr = 0;
        $category = null;

        while (($ptr = strpos($s, '$', $ptr)) !== false) {
            if ($ptr !== 0) {
                $prev = Utf8::before($s, $ptr);
                if (self::isHashtagLetter($prev, $category) || $prev === 0x24) {
                    $ptr++;
                    continue;
                }
            }

            $cashtagBegin = ++$ptr;
            if ($end - $ptr >= 5 && substr($s, $ptr, 5) === '1INCH') {
                $ptr += 5;
            } else {
                while ($ptr !== $end && $s[$ptr] >= 'A' && $s[$ptr] <= 'Z') {
                    $ptr++;
                }
            }
            $cashtagEnd = $ptr;
            $size = $cashtagEnd - $cashtagBegin;
            if ($size < 1 || $size > 8) {
                continue;
            }
            if ($ptr !== $end && $s[$ptr] === '@') {
                $usernameEnd = $ptr + 1;
                while ($usernameEnd !== $end && self::isAlphaDigitOrUnderscore(self::byte($s, $usernameEnd))) {
                    $usernameEnd++;
                }
                $length = $usernameEnd - $ptr - 1;
                if ($length >= 3 && $length <= 32) {
                    $cashtagEnd = $usernameEnd;
                    $ptr = $usernameEnd;
                }
            }
            if ($cashtagEnd !== $end) {
                $code = Utf8::next($s, $ptr)[0];
                if (self::isHashtagLetter($code, $category) || $code === 0x24) {
                    continue;
                }
            }

            $result[] = [$cashtagBegin - 1, $cashtagEnd];
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function matchMediaTimestamps(string $s): array
    {
        $result = [];
        $end = strlen($s);
        $ptr = 0;

        while (($ptr = strpos($s, ':', $ptr)) !== false) {
            $begin = $ptr;
            while ($begin !== 0 && ($s[$begin - 1] === ':' || ctype_digit($s[$begin - 1]))) {
                $begin--;
            }
            $tsEnd = $ptr;
            while ($tsEnd + 1 !== $end && ($s[$tsEnd + 1] === ':' || ctype_digit($s[$tsEnd + 1]))) {
                $tsEnd++;
            }
            $tsEnd++;

            if ($begin !== $ptr && $tsEnd !== $ptr + 1 && ctype_digit($s[$ptr + 1])) {
                $ptr = $tsEnd;
                if ($begin !== 0 && self::isWordCharacter(Utf8::before($s, $begin))) {
                    continue;
                }
                if ($tsEnd !== $end && self::isWordCharacter(Utf8::next($s, $tsEnd)[0])) {
                    continue;
                }
                $result[] = [$begin, $tsEnd];
            } else {
                $ptr = $tsEnd;
            }
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}> ranges with the timestamp in seconds
     */
    private static function findMediaTimestamps(string $s): array
    {
        $result = [];
        foreach (self::matchMediaTimestamps($s) as [$begin, $end]) {
            $parts = explode(':', substr($s, $begin, $end - $begin));
            $last = $parts[count($parts) - 1];
            if (count($parts) > 3 || strlen($last) !== 2) {
                continue;
            }
            $seconds = (int) $last;
            if ($seconds >= 60) {
                continue;
            }
            if (count($parts) === 2) {
                if (strlen($parts[0]) > 4 || $parts[0] === '') {
                    continue;
                }
                $result[] = [$begin, $end, (int) $parts[0] * 60 + $seconds];
            } else {
                if (strlen($parts[0]) > 2 || strlen($parts[1]) > 2 || $parts[0] === '' || $parts[1] === '') {
                    continue;
                }
                $minutes = (int) $parts[1];
                if ($minutes >= 60) {
                    continue;
                }
                $result[] = [$begin, $end, (int) $parts[0] * 3600 + $minutes * 60 + $seconds];
            }
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function matchBankCardNumbers(string $s): array
    {
        $result = [];
        $end = strlen($s);
        $ptr = 0;

        while (true) {
            while ($ptr !== $end && !ctype_digit($s[$ptr])) {
                $ptr++;
            }
            if ($ptr === $end) {
                break;
            }
            if ($ptr !== 0) {
                $prev = Utf8::before($s, $ptr);
                if ($prev === 0x2E || $prev === 0x2C || $prev === 0x2B || $prev === 0x2D || $prev === 0x5F
                    || Unicode::category($prev) === Unicode::LETTER) {
                    while ($ptr !== $end && (ctype_digit($s[$ptr]) || $s[$ptr] === ' ' || $s[$ptr] === '-')) {
                        $ptr++;
                    }
                    continue;
                }
            }

            $cardBegin = $ptr;
            $digits = 0;
            while ($ptr !== $end && (ctype_digit($s[$ptr]) || $s[$ptr] === ' ' || $s[$ptr] === '-')) {
                if ($s[$ptr] === ' ' && $digits >= 16 && $digits <= 19 && $digits === $ptr - $cardBegin) {
                    break;
                }
                $digits += ctype_digit($s[$ptr]) ? 1 : 0;
                $ptr++;
            }
            if ($digits < 13 || $digits > 19) {
                continue;
            }

            $cardEnd = $ptr;
            while (!ctype_digit($s[$cardEnd - 1])) {
                $cardEnd--;
            }
            if ($cardEnd - $cardBegin > 2 * $digits - 1) {
                continue;
            }
            if ($cardEnd !== $end) {
                $next = Utf8::next($s, $cardEnd)[0];
                if ($next === 0x2D || $next === 0x5F || Unicode::category($next) === Unicode::LETTER) {
                    continue;
                }
            }

            $result[] = [$cardBegin, $cardEnd];
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function findBankCardNumbers(string $s): array
    {
        return array_values(array_filter(
            self::matchBankCardNumbers($s),
            static fn (array $r): bool => self::isValidBankCard(substr($s, $r[0], $r[1] - $r[0])),
        ));
    }

    private static function isValidBankCard(string $str): bool
    {
        $digits = preg_replace('/\D/', '', $str);
        $count = strlen($digits);

        $sum = 0;
        for ($i = $count; $i > 0; $i--) {
            $digit = ord($digits[$i - 1]) - 48;
            $sum += ($count - $i) % 2 === 0 ? $digit : ($digit < 5 ? 2 * $digit : 2 * $digit - 9);
        }
        if ($sum % 10 !== 0) {
            return false;
        }

        $p1 = (int) $digits[0];
        $p2 = (int) substr($digits, 0, 2);
        $p4 = (int) substr($digits, 0, 4);
        if ($p1 === 4) {
            return in_array($count, [13, 16, 18, 19], true);
        }
        if (($p2 >= 51 && $p2 <= 55) || ($p4 >= 2221 && $p4 <= 2720)) {
            return $count === 16;
        }
        if ($p2 === 34 || $p2 === 37) {
            return $count === 15;
        }
        if ($p2 === 62 || $p2 === 81) {
            return $count >= 16;
        }
        if ($p4 >= 2200 && $p4 <= 2204) {
            return $count === 16;
        }

        return true;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function matchTgUrls(string $s): array
    {
        $result = [];
        $end = strlen($s);
        $ptr = 0;
        $lower = strtolower($s);

        while ($end - $ptr > 5) {
            $ptr = strpos($s, ':', $ptr);
            if ($ptr === false) {
                break;
            }

            $urlBegin = null;
            if ($end - $ptr >= 3 && ($s[$ptr + 1] ?? '') === '/' && ($s[$ptr + 2] ?? '') === '/') {
                if ($ptr >= 2 && substr($lower, $ptr - 2, 2) === 'tg') {
                    $urlBegin = $ptr - 2;
                } elseif ($ptr >= 3 && substr($lower, $ptr - 3, 3) === 'ton') {
                    $urlBegin = $ptr - 3;
                } elseif ($ptr >= 7 && substr($lower, $ptr - 7, 7) === 'tonsite') {
                    // TDLib starts the match three characters back here as well.
                    $urlBegin = $ptr - 3;
                }
            }
            if ($urlBegin === null) {
                $ptr++;
                continue;
            }

            $ptr += 3;
            $domainBegin = $ptr;
            while ($ptr !== $end && $ptr - $domainBegin !== 253 && self::isAlphaDigitOrUnderscoreOrMinus(self::byte($s, $ptr))) {
                $ptr++;
            }
            if ($ptr === $domainBegin) {
                continue;
            }

            if ($ptr !== $end && ($s[$ptr] === '/' || $s[$ptr] === '?' || $s[$ptr] === '#')) {
                $pathEnd = self::pathEnd($s, $ptr);
                if ($s[$ptr] === '/' || $pathEnd > $ptr + 1) {
                    $ptr = $pathEnd;
                }
            }

            $result[] = [$urlBegin, $ptr];
        }

        return $result;
    }

    /**
     * The end of a URL path starting at $start ('/', '?' or '#'), without
     * trailing punctuation.
     */
    private static function pathEnd(string $s, int $start): int
    {
        $end = strlen($s);
        $pathEnd = $start + 1;
        while ($pathEnd !== $end) {
            [$code, $next] = Utf8::next($s, $pathEnd);
            if (!self::isUrlPathSymbol($code)) {
                break;
            }
            $pathEnd = $next;
        }
        while ($pathEnd > $start + 1 && str_contains(self::BAD_PATH_END_CHARS, $s[$pathEnd - 1])) {
            $pathEnd--;
        }

        return $pathEnd;
    }

    private static function isProtocolSymbol(int $c): bool
    {
        if ($c < 0x80) {
            return self::isAlphaDigit($c) || $c === 0x2B || $c === 0x2D;
        }

        return Unicode::category($c) !== Unicode::SEPARATOR;
    }

    private static function isUserDataSymbol(int $c): bool
    {
        return match ($c) {
            0x0A, 0x2F, 0x5B, 0x5D, 0x7B, 0x7D, 0x28, 0x29, 0x27, 0x60, 0x3C, 0x3E, 0x22, 0x40, 0xAB, 0xBB => false,
            default => self::isUrlUnicodeSymbol($c),
        };
    }

    private static function isDomainSymbol(int $c): bool
    {
        if ($c < 0xC0) {
            return $c === 0x2E || self::isAlphaDigitOrUnderscoreOrMinus($c) || $c === 0x7E;
        }

        return self::isUrlUnicodeSymbol($c);
    }

    /**
     * Walk back from $pos over code points accepted by $accept.
     */
    private static function backWhile(string $s, int $pos, int $begin, callable $accept): int
    {
        while ($pos !== $begin) {
            $prev = Utf8::prev($s, $pos);
            [$code] = Utf8::next($s, $prev);
            if (!$accept($code)) {
                break;
            }
            $pos = $prev;
        }

        return $pos;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function matchUrls(string $s): array
    {
        $result = [];
        $begin = 0;
        $end = strlen($s);

        while (true) {
            $dot = strpos($s, '.', $begin);
            if ($dot === false || $dot + 1 === $end) {
                break;
            }
            if ($s[$dot + 1] === ' ') {
                $begin = $dot + 2;
                continue;
            }

            $domainBegin = self::backWhile($s, $dot, $begin, self::isDomainSymbol(...));

            $lastAt = null;
            $domainEnd = $dot;
            while ($domainEnd !== $end) {
                [$code, $next] = Utf8::next($s, $domainEnd);
                if ($code === 0x40) {
                    $lastAt = $domainEnd;
                } elseif (!self::isDomainSymbol($code)) {
                    break;
                }
                $domainEnd = $next;
            }

            if ($lastAt !== null) {
                $domainBegin = self::backWhile($s, $domainBegin, $begin, self::isUserDataSymbol(...));
            }

            $urlEnd = $domainEnd;
            if ($urlEnd !== $end && $s[$urlEnd] === ':') {
                $portEnd = $urlEnd + 1;
                while ($portEnd !== $end && ctype_digit($s[$portEnd])) {
                    $portEnd++;
                }
                $portBegin = $urlEnd + 1;
                while ($portBegin !== $portEnd && $s[$portBegin] === '0') {
                    $portBegin++;
                }
                if ($portBegin !== $portEnd && $portEnd - $portBegin <= 5 && (int) substr($s, $portBegin, $portEnd - $portBegin) <= 65535) {
                    $urlEnd = $portEnd;
                }
            }

            if ($urlEnd !== $end && ($s[$urlEnd] === '/' || $s[$urlEnd] === '?' || $s[$urlEnd] === '#')) {
                $pathEnd = self::pathEnd($s, $urlEnd);
                if ($s[$urlEnd] === '/' || $pathEnd > $urlEnd + 1) {
                    $urlEnd = $pathEnd;
                }
            }
            while ($urlEnd > $dot + 1 && $s[$urlEnd - 1] === '.') {
                $urlEnd--;
            }

            $isBad = false;
            $urlBegin = $domainBegin;
            if ($urlBegin !== $begin && $s[$urlBegin - 1] === '@') {
                if ($lastAt !== null) {
                    $isBad = true;
                }
                $userDataBegin = self::backWhile($s, $urlBegin - 1, $begin, self::isUserDataSymbol(...));
                if ($userDataBegin === $urlBegin - 1) {
                    $isBad = true;
                }
                $urlBegin = $userDataBegin;
            }

            if ($urlBegin !== $begin) {
                $prefix = substr($s, $begin, $urlBegin - $begin);
                if (strlen($prefix) >= 6 && str_ends_with($prefix, '://')) {
                    $protocolBegin = self::backWhile($s, $urlBegin - 3, $begin, self::isProtocolSymbol(...));
                    $protocol = strtolower(substr($s, $protocolBegin, $urlBegin - 3 - $protocolBegin));
                    if (str_ends_with($protocol, 'http') && $protocol !== 'shttp') {
                        $urlBegin -= 7;
                    } elseif (str_ends_with($protocol, 'https')) {
                        $urlBegin -= 8;
                    } elseif (str_ends_with($protocol, 'ftp') && $protocol !== 'tftp' && $protocol !== 'sftp') {
                        $urlBegin -= 6;
                    } elseif (str_ends_with($protocol, 'tonsite')) {
                        $urlBegin -= 10;
                    } else {
                        $isBad = true;
                    }
                } else {
                    $code = Utf8::before($s, $urlBegin);
                    if (self::isWordCharacter($code) || $code === 0x2F || $code === 0x23 || $code === 0x40) {
                        $isBad = true;
                    }
                }
            }

            if (!$isBad) {
                if ($urlEnd > $dot + 1) {
                    $result[] = [$urlBegin, $urlEnd];
                }
                while ($urlEnd !== $end && $s[$urlEnd] === '.') {
                    $urlEnd++;
                }
            } else {
                while ($s[$urlEnd - 1] !== '.') {
                    $urlEnd--;
                }
            }

            if ($urlEnd <= $dot) {
                $urlEnd = $dot + 1;
            }
            $begin = $urlEnd;
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int, 2: bool}> ranges with "is e-mail"
     */
    private static function findUrls(string $s): array
    {
        $result = [];
        foreach (self::matchUrls($s) as [$begin, $end]) {
            $url = substr($s, $begin, $end - $begin);
            if (self::isEmailAddress($url)) {
                $result[] = [$begin, $end, true];
            } elseif (str_starts_with($url, 'mailto:') && self::isEmailAddress(substr($url, 7))) {
                $result[] = [$begin + 7, $end, true];
            } else {
                $length = self::fixUrl($url);
                if ($length > 0) {
                    $result[] = [$begin, $begin + $length, false];
                }
            }
        }

        return $result;
    }

    public static function isEmailAddress(string $str): bool
    {
        $at = strpos($str, '@');
        if ($at === false) {
            return false;
        }
        $userdata = substr($str, 0, $at);
        $domain = substr($str, $at + 1);
        if ($domain === '') {
            return false;
        }

        $prev = 0;
        $parts = 0;
        $length = strlen($userdata);
        for ($i = 0; $i < $length; $i++) {
            $c = $userdata[$i];
            if ($c === '.' || $c === '+') {
                if ($i - $prev >= 27) {
                    return false;
                }
                $parts++;
                $prev = $i + 1;
            } elseif (!self::isAlphaDigitOrUnderscoreOrMinus(ord($c))) {
                return false;
            }
        }
        $parts++;
        if ($parts >= 12) {
            return false;
        }
        $lastPart = $length - $prev;
        if ($lastPart === 0 || $lastPart >= 36) {
            return false;
        }

        $domainParts = explode('.', $domain);
        if (count($domainParts) <= 1 || count($domainParts) > 7) {
            return false;
        }
        $tld = array_pop($domainParts);
        if (strlen($tld) <= 1 || strlen($tld) >= 9 || !ctype_alpha($tld)) {
            return false;
        }
        foreach ($domainParts as $part) {
            if ($part === '' || strlen($part) >= 31) {
                return false;
            }
            for ($i = 0, $n = strlen($part); $i < $n; $i++) {
                if (!self::isAlphaDigitOrUnderscoreOrMinus(ord($part[$i]))) {
                    return false;
                }
            }
            if (!self::isAlphaDigit(ord($part[0])) || !self::isAlphaDigit(ord($part[strlen($part) - 1]))) {
                return false;
            }
        }

        return true;
    }

    private static function isCommonTld(string $str): bool
    {
        if (preg_match('/^[a-z]*$/', $str)) {
            return isset(CommonTlds::LIST[$str]);
        }

        $lower = Unicode::utf8ToLower($str);
        if ($lower !== $str && mb_substr($lower, 1) === mb_substr($str, 1)) {
            return false;
        }

        return isset(CommonTlds::LIST[$lower]);
    }

    /**
     * td::fix_url: the accepted length of a URL candidate, or 0.
     */
    private static function fixUrl(string $str): int
    {
        $fullLength = strlen($str);

        $hasProtocol = false;
        $start = strtolower(substr($str, 0, 10));
        if (str_starts_with($start, 'http://') || str_starts_with($start, 'https://') || str_starts_with($start, 'ftp://')
            || str_starts_with($start, 'tonsite://')) {
            $str = substr($str, strpos($str, ':') + 3);
            $hasProtocol = true;
        }

        $domainEnd = strcspn($str, '/?#');
        $domain = substr($str, 0, $domainEnd);
        $path = substr($str, $domainEnd);

        $at = strpos($domain, '@');
        if ($at !== false) {
            $domain = substr($domain, $at + 1);
        }
        $colon = strrpos($domain, ':');
        if ($colon !== false) {
            $domain = substr($domain, 0, $colon);
        }

        if (strlen($domain) === 12 && ($domain[0] === 't' || $domain[0] === 'T') && strtolower($domain) === 'teiegram.org') {
            return 0;
        }

        $balance = [0, 0, 0];
        $pathLength = strlen($path);
        for ($pathPos = 0; $pathPos < $pathLength; $pathPos++) {
            match ($path[$pathPos]) {
                '(' => $balance[0]++,
                '[' => $balance[1]++,
                '{' => $balance[2]++,
                ')' => $balance[0]--,
                ']' => $balance[1]--,
                '}' => $balance[2]--,
                default => null,
            };
            if ($balance[0] < 0 || $balance[1] < 0 || $balance[2] < 0) {
                break;
            }
        }
        while ($pathPos > 0 && str_contains(self::BAD_PATH_END_CHARS, $path[$pathPos - 1])) {
            $pathPos--;
        }
        $fullLength -= $pathLength - $pathPos;

        $prev = 0;
        $partCount = 0;
        $hasNonDigit = false;
        $isIpv4 = true;
        $domainLength = strlen($domain);
        for ($i = 0; $i <= $domainLength; $i++) {
            if ($i === $domainLength || $domain[$i] === '.') {
                $partSize = $i - $prev;
                if ($partSize === 0 || $partSize >= 64 || $domain[$i - 1] === '-') {
                    return 0;
                }
                if ($isIpv4) {
                    if ($partSize > 3) {
                        $isIpv4 = false;
                    }
                    if ($partSize === 3 && ($domain[$prev] >= '3' || ($domain[$prev] === '2'
                        && ($domain[$prev + 1] >= '6' || ($domain[$prev + 1] === '5' && $domain[$prev + 2] >= '6'))))) {
                        $isIpv4 = false;
                    }
                    if ($domain[$prev] === '0' && $partSize >= 2) {
                        $isIpv4 = false;
                    }
                }
                $partCount++;
                if ($i !== $domainLength) {
                    $prev = $i + 1;
                }
            } elseif (!ctype_digit($domain[$i])) {
                $isIpv4 = false;
                $hasNonDigit = true;
            }
        }
        if ($partCount === 1) {
            return 0;
        }
        if ($isIpv4 && $partCount === 4) {
            return $fullLength;
        }
        if (!$hasNonDigit) {
            return 0;
        }

        $tld = substr($domain, $prev);
        if (Utf8::length($tld) <= 1) {
            return 0;
        }

        if (str_starts_with($tld, 'xn--')) {
            if (strlen($tld) <= 5 || !ctype_alnum(substr($tld, 4))) {
                return 0;
            }
        } else {
            if (str_contains($tld, '_') || str_contains($tld, '-')) {
                return 0;
            }
            if (!$hasProtocol && !self::isCommonTld($tld)) {
                return 0;
            }
        }

        $prev--;
        while ($prev-- > 0) {
            if ($domain[$prev] === '_') {
                return 0;
            }
            if ($domain[$prev] === '.') {
                break;
            }
        }

        return $fullLength;
    }
}
