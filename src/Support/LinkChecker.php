<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

/**
 * LinkManager::check_link: validates and normalises a link (http, https,
 * tg:, ton: and tonsite:).
 */
final class LinkChecker
{
    /**
     * @throws \InvalidArgumentException "URL '…' is invalid: …"
     */
    public static function check(string $link, bool $httpOnly = false, bool $httpsOnly = false): string
    {
        try {
            return self::checkImpl($link, $httpOnly, $httpsOnly);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                Utf8::check($link) ? "URL '{$link}' is invalid: {$e->getMessage()}" : "URL is invalid: {$e->getMessage()}",
            );
        }
    }

    public static function getCheckedLink(string $link, bool $httpOnly = false, bool $httpsOnly = false): string
    {
        try {
            return self::checkImpl($link, $httpOnly, $httpsOnly);
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    private static function checkImpl(string $link, bool $httpOnly, bool $httpsOnly): string
    {
        $lower = strtolower(substr($link, 0, 8));
        $scheme = match (true) {
            str_starts_with($lower, 'tg:') => 'tg',
            str_starts_with($lower, 'ton:') => 'ton',
            str_starts_with($lower, 'tonsite:') => 'tonsite',
            default => null,
        };
        if ($scheme !== null) {
            $link = substr($link, strlen($scheme) + 1);
            if (str_starts_with($link, '//')) {
                $link = substr($link, 2);
            }
        }

        $url = HttpUrl::parse($link);
        if ($httpsOnly && ($url->protocol !== HttpUrl::HTTPS || $scheme !== null)) {
            throw new \InvalidArgumentException('Only HTTPS links are allowed');
        }

        if ($scheme !== null) {
            if ($httpOnly) {
                throw new \InvalidArgumentException('Only HTTP links are allowed');
            }
            if (str_starts_with(strtolower($link), 'http://') || $url->protocol === HttpUrl::HTTPS
                || $url->userinfo !== '' || $url->specifiedPort !== 0 || $url->isIpv6) {
                throw new \InvalidArgumentException($scheme === 'tg' ? 'Wrong tg URL' : 'Wrong ton URL');
            }

            $query = $url->query;
            if (strlen($query) > 1 && $query[1] === '?') {
                $query = substr($query, 1);
            }
            $length = strlen($url->host);
            for ($i = 0; $i < $length; $i++) {
                $c = $url->host[$i];
                if (!ctype_alnum($c) && $c !== '-' && $c !== '_' && !($scheme === 'tonsite' && $c === '.')) {
                    throw new \InvalidArgumentException('Unallowed characters in URL host');
                }
            }

            return $scheme.'://'.$url->host.$query;
        }

        if (!str_contains($url->host, '.') && !$url->isIpv6) {
            throw new \InvalidArgumentException('Wrong HTTP URL');
        }

        return $url->getUrl();
    }
}
