<?php

declare(strict_types=1);

namespace LaraGram\Server\Entities;

use LaraGram\Server\Support\TdInteger;

/**
 * tg:// links understood inside formatted text (LinkManager::get_link_user_id,
 * get_link_custom_emoji_id, get_link_formatted_date).
 */
final class TgLinks
{
    /**
     * tg://user?id=<id>; 0 when the link is not a user link.
     */
    public static function userId(string $url): int
    {
        $url = strtolower($url);
        if (!str_starts_with($url, 'tg:')) {
            return 0;
        }
        $url = substr($url, 3);
        if (str_starts_with($url, '//')) {
            $url = substr($url, 2);
        }
        if (!str_starts_with($url, 'user') || (strlen($url) > 4 && !str_contains('/?#', $url[4]))) {
            return 0;
        }
        $url = substr($url, 4);
        if (str_starts_with($url, '/')) {
            $url = substr($url, 1);
        }
        if (!str_starts_with($url, '?')) {
            return 0;
        }
        $url = substr($url, 1);
        if (($hash = strpos($url, '#')) !== false) {
            $url = substr($url, 0, $hash);
        }

        foreach (explode('&', $url) as $parameter) {
            [$key, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            if ($key === 'id') {
                $id = TdInteger::toInt64Safe($value);

                return $id !== null && $id > 0 ? $id : 0;
            }
        }

        return 0;
    }

    /**
     * tg://emoji?id=<id>; null when the link is not a valid emoji link.
     */
    public static function customEmojiId(string $url): ?int
    {
        $query = self::query($url, 'emoji');
        if ($query === null) {
            return null;
        }
        foreach (explode('&', $query) as $parameter) {
            [$key, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            if ($key === 'id') {
                $id = TdInteger::toInt64Safe($value);

                return $id === null || $id === 0 ? null : $id;
            }
        }

        return null;
    }

    /**
     * tg://time?unix=<date>&format=<format>; null when invalid.
     *
     * @return array{unix_time: int, flags: int}|null
     */
    public static function formattedDate(string $url): ?array
    {
        $query = self::query($url, 'time');
        if ($query === null) {
            return null;
        }
        $date = 0;
        $format = '';
        foreach (explode('&', $query) as $parameter) {
            [$key, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            if ($key === 'unix') {
                $parsed = TdInteger::toInt32Safe($value);
                if ($parsed === null || $parsed <= 0) {
                    return null;
                }
                $date = $parsed;
            }
            if ($key === 'format') {
                $format = $value;
            }
        }
        if ($date === 0) {
            return null;
        }

        try {
            return ['unix_time' => $date, 'flags' => FormattedDate::flagsFromFormat($format)];
        } catch (FormattingError) {
            return null;
        }
    }

    /**
     * LinkManager::check_tg_url_host: the query of tg://<host>?…, or null.
     */
    private static function query(string $url, string $host): ?string
    {
        $minSize = 3 + 2 + strlen($host) + 1;
        $lower = strtolower(strlen($url) > $minSize ? substr($url, 0, $minSize) : $url);
        if (!str_starts_with($lower, 'tg:')) {
            return null;
        }
        $lower = substr($lower, 3);
        $url = substr($url, 3);
        if (str_starts_with($url, '//')) {
            $lower = substr($lower, 2);
            $url = substr($url, 2);
        }
        if (!str_starts_with($lower, $host) || (strlen($url) > strlen($host) && !str_contains('/?#', $url[strlen($host)]))) {
            return null;
        }
        $url = substr($url, strlen($host));
        if (str_starts_with($url, '/')) {
            $url = substr($url, 1);
        }
        if (!str_starts_with($url, '?')) {
            return null;
        }
        $url = substr($url, 1);
        if (($hash = strpos($url, '#')) !== false) {
            $url = substr($url, 0, $hash);
        }

        return $url;
    }
}
