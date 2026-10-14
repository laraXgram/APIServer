<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

/**
 * Byte-level UTF-8 helpers mirroring td/utils/utf8.h, for code that walks
 * text by byte offsets the way TDLib does.
 */
final class Utf8
{
    /**
     * td::next_utf8_unsafe: decode the code point at byte $pos.
     *
     * @return array{0: int, 1: int} code point and the position after it
     */
    public static function next(string $s, int $pos): array
    {
        $a = ord($s[$pos]);
        if ($a < 0x80) {
            return [$a, $pos + 1];
        }
        if ($a < 0xE0) {
            return [(($a & 0x1F) << 6) | (ord($s[$pos + 1] ?? "\0") & 0x3F), $pos + 2];
        }
        if ($a < 0xF0) {
            return [(($a & 0x0F) << 12) | ((ord($s[$pos + 1] ?? "\0") & 0x3F) << 6) | (ord($s[$pos + 2] ?? "\0") & 0x3F), $pos + 3];
        }

        return [(($a & 0x07) << 18) | ((ord($s[$pos + 1] ?? "\0") & 0x3F) << 12)
            | ((ord($s[$pos + 2] ?? "\0") & 0x3F) << 6) | (ord($s[$pos + 3] ?? "\0") & 0x3F), $pos + 4];
    }

    /**
     * td::prev_utf8_unsafe: the start of the code point ending before $pos.
     */
    public static function prev(string $s, int $pos): int
    {
        do {
            $pos--;
        } while ($pos > 0 && (ord($s[$pos]) & 0xC0) === 0x80);

        return $pos;
    }

    /**
     * The code point that ends right before byte $pos.
     */
    public static function before(string $s, int $pos): int
    {
        return self::next($s, self::prev($s, $pos))[0];
    }

    public static function chr(int $code): string
    {
        return match (true) {
            $code <= 0x7F => chr($code),
            $code <= 0x7FF => chr(0xC0 | ($code >> 6)).chr(0x80 | ($code & 0x3F)),
            $code <= 0xFFFF => chr(0xE0 | ($code >> 12)).chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F)),
            default => chr(0xF0 | ($code >> 18)).chr(0x80 | (($code >> 12) & 0x3F)).chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F)),
        };
    }

    /**
     * @return \Generator<int, int>
     */
    public static function codepoints(string $s): \Generator
    {
        $length = strlen($s);
        for ($pos = 0; $pos < $length;) {
            [$code, $pos] = self::next($s, $pos);
            yield $code;
        }
    }

    /**
     * td::utf8_length: the number of code points.
     */
    public static function length(string $s): int
    {
        $count = 0;
        $length = strlen($s);
        for ($i = 0; $i < $length; $i++) {
            if ((ord($s[$i]) & 0xC0) !== 0x80) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * td::utf8_utf16_length: the number of UTF-16 code units.
     */
    public static function utf16Length(string $s): int
    {
        $count = 0;
        $length = strlen($s);
        for ($i = 0; $i < $length; $i++) {
            $c = ord($s[$i]);
            if (($c & 0xC0) !== 0x80) {
                $count += $c >= 0xF0 ? 2 : 1;
            }
        }

        return $count;
    }

    /**
     * Number of UTF-16 code units of the byte range [$from, $to).
     */
    public static function utf16Units(string $s, int $from, int $to): int
    {
        return self::utf16Length(substr($s, $from, $to - $from));
    }

    /**
     * td::check_utf8.
     */
    public static function check(string $s): bool
    {
        return mb_check_encoding($s, 'UTF-8');
    }

    public static function isUtf8ContinuationByte(string $s, int $pos): bool
    {
        return (ord($s[$pos]) & 0xC0) === 0x80;
    }
}
