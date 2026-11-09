<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

/**
 * Sizes and durations written for humans in command-line options.
 */
final class Units
{
    private const SIZE_UNITS = ['' => 1, 'b' => 1, 'k' => 1 << 10, 'm' => 1 << 20, 'g' => 1 << 30, 't' => 1 << 40];

    private const DURATION_UNITS = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

    /**
     * "1048576", "500M", "1.5GB", "2GiB" → bytes (1K = 1024).
     *
     * @throws \InvalidArgumentException
     */
    public static function parseSize(string $value): int
    {
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?)(?:i?b)?$/i', trim($value), $matches)
            && !preg_match('/^(\d+(?:\.\d+)?)\s*(b)$/i', trim($value), $matches)) {
            throw new \InvalidArgumentException("Wrong size \"{$value}\": expected a number with an optional K, M, G or T suffix");
        }

        return (int) ((float) $matches[1] * self::SIZE_UNITS[strtolower($matches[2])]);
    }

    /**
     * "3600", "90m", "23h", "7d", "1d12h" → seconds.
     *
     * @throws \InvalidArgumentException
     */
    public static function parseDuration(string $value): int
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^(?:\d+[smhdw]?)+$/', $value)) {
            throw new \InvalidArgumentException("Wrong duration \"{$value}\": expected a number of seconds or values like 90m, 23h, 7d");
        }

        preg_match_all('/(\d+)([smhdw]?)/', $value, $parts, PREG_SET_ORDER);
        $seconds = 0;
        foreach ($parts as [, $amount, $unit]) {
            $seconds += (int) $amount * self::DURATION_UNITS[$unit];
        }

        return $seconds;
    }

    public static function formatSize(int $bytes): string
    {
        foreach (['TB' => 1 << 40, 'GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return round($bytes / $size, 2).' '.$unit;
            }
        }

        return $bytes.' B';
    }
}
