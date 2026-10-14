<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

/**
 * JSON encoder producing byte-identical output to TDLib's JsonBuilder.
 *
 * TDLib escapes every non-ASCII code point as a lowercase `\uXXXX` sequence
 * (surrogate pairs above the BMP), escapes `"`, `\` and control characters,
 * and leaves `/` alone. That is exactly json_encode() with only
 * JSON_UNESCAPED_SLASHES set. Doubles are the one difference, see {@see JsonFloat}.
 *
 * Empty JSON objects must be passed as `new \stdClass()` (or `(object) []`);
 * an empty PHP array always encodes as `[]`.
 */
final class JsonWriter
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** json_encode()'s escaped form of {@see JsonFloat::MARKER}. */
    private const ENCODED_MARKER = '\u0001\u0002\u0003F';

    /** json_encode()'s escaped form of {@see JsonRawString::MARKER}. */
    private const ENCODED_RAW_MARKER = '\u0001\u0002\u0003R';

    public static function encode(mixed $value): string
    {
        try {
            $json = json_encode($value, self::FLAGS);
        } catch (\JsonException) {
            // Only reachable with invalid UTF-8, which TDLib never hands out.
            $json = json_encode($value, self::FLAGS | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if (str_contains($json, self::ENCODED_MARKER)) {
            $json = preg_replace('/"\\\\u0001\\\\u0002\\\\u0003F(-?[0-9]+\.[0-9]{6})"/', '$1', $json);
        }
        if (str_contains($json, self::ENCODED_RAW_MARKER)) {
            $json = preg_replace_callback('/"\\\\u0001\\\\u0002\\\\u0003R([A-Za-z0-9+\\\\\/=]*)"/', static fn (array $m): string => self::rawString(base64_decode(str_replace('\\/', '/', $m[1]))), $json);
        }

        return $json;
    }

    /**
     * Encode a string the way td::JsonRawString does for a value that is not
     * valid UTF-8: the same escapes as a normal string, but bytes >= 0x80 are
     * written as they are.
     */
    public static function rawString(string $value): string
    {
        $out = '"';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $c = $value[$i];
            $out .= match ($c) {
                '"' => '\"',
                '\\' => '\\\\',
                "\x08" => '\b',
                "\f" => '\f',
                "\n" => '\n',
                "\r" => '\r',
                "\t" => '\t',
                default => ord($c) <= 31 ? sprintf('\u%04x', ord($c)) : $c,
            };
        }

        return $out.'"';
    }
}
