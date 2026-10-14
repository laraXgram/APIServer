<?php

declare(strict_types=1);

namespace LaraGram\Server\Entities;

/**
 * UTF-16 aware text helpers from TDLib (utf8_utf16_substr, get_first_url).
 */
final class TextUtil
{
    /**
     * td::utf8_utf16_substr: a substring by UTF-16 offset and length.
     */
    public static function utf16Substr(string $text, int $offset, int $length): string
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return mb_convert_encoding(substr($utf16, $offset * 2, $length * 2), 'UTF-8', 'UTF-16LE');
    }

    /**
     * td::get_first_url: the URL a link preview would be generated for, or ''.
     */
    public static function getFirstUrl(FormattedText $text): string
    {
        foreach ($text->entities as $entity) {
            if ($entity['type'] === 'Url') {
                if ($entity['length'] <= 4) {
                    continue;
                }
                $url = self::utf16Substr($text->text, $entity['offset'], $entity['length']);
                if (self::isSkippedScheme($url) || self::isPlainDomain($url)) {
                    continue;
                }

                return $url;
            }

            if ($entity['type'] === 'TextUrl') {
                if (self::isSkippedScheme($entity['url'])) {
                    continue;
                }

                return $entity['url'];
            }
        }

        return '';
    }

    /**
     * The scheme test of get_first_url: the first 8 characters, lower-cased,
     * compared as TDLib does (so "ton:" only matches a 4-character URL).
     */
    private static function isSkippedScheme(string $url): bool
    {
        $scheme = strtolower(substr($url, 0, 8));

        return $scheme === 'ton:' || str_starts_with($scheme, 'tg:') || $scheme === 'ftp:' || $scheme === 'tonsite:';
    }

    private static function isPlainDomain(string $url): bool
    {
        return strpbrk($url, '/?#') === false;
    }
}
