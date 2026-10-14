<?php

declare(strict_types=1);

namespace LaraGram\Server\Entities;

/**
 * td::FormattedDate flags and their conversions.
 */
final class FormattedDate
{
    public const RELATIVE = 1;
    public const SHORT_TIME = 2;
    public const LONG_TIME = 4;
    public const SHORT_DATE = 8;
    public const LONG_DATE = 16;
    public const DAY_OF_WEEK = 32;

    /**
     * The FormattedDate constructor used for server entities.
     *
     * @param array<string, mixed> $entity TL messageEntityFormattedDate
     */
    public static function flagsFromServer(array $entity): int
    {
        if (!empty($entity['relative'])) {
            return self::RELATIVE;
        }

        $flags = 0;
        if (!empty($entity['short_time'])) {
            $flags |= self::SHORT_TIME;
        } elseif (!empty($entity['long_time'])) {
            $flags |= self::LONG_TIME;
        }
        if (!empty($entity['short_date'])) {
            $flags |= self::SHORT_DATE;
        } elseif (!empty($entity['long_date'])) {
            $flags |= self::LONG_DATE;
        }
        if (!empty($entity['day_of_week'])) {
            $flags |= self::DAY_OF_WEEK;
        }

        return $flags;
    }

    /**
     * FormattedDate::get_date_flags for a `format` written by a user.
     *
     * @throws FormattingError
     */
    public static function flagsFromFormat(string $format): int
    {
        if ($format === 'r' || $format === 'R') {
            return self::RELATIVE;
        }

        $flags = 0;
        $length = strlen($format);
        for ($i = 0; $i < $length; $i++) {
            $flags |= match ($format[$i]) {
                't' => self::SHORT_TIME,
                'T' => self::LONG_TIME,
                'd' => self::SHORT_DATE,
                'D' => self::LONG_DATE,
                'w', 'W' => self::DAY_OF_WEEK,
                default => throw new FormattingError('Invalid date format used'),
            };
        }

        return $flags;
    }

    /**
     * get_date_time_formatting_type_object + Client::get_date_time_format.
     */
    public static function botApiFormat(int $flags): string
    {
        if ($flags === 0) {
            return '';
        }
        if (($flags & self::RELATIVE) !== 0) {
            return 'r';
        }

        $format = ($flags & self::DAY_OF_WEEK) !== 0 ? 'w' : '';
        if (($flags & self::SHORT_DATE) !== 0) {
            $format .= 'd';
        } elseif (($flags & self::LONG_DATE) !== 0) {
            $format .= 'D';
        }
        if (($flags & self::SHORT_TIME) !== 0) {
            $format .= 't';
        } elseif (($flags & self::LONG_TIME) !== 0) {
            $format .= 'T';
        }

        return $format;
    }

    /**
     * The TL messageEntityFormattedDate flags of a date entity.
     *
     * @return array<string, bool>
     */
    public static function toServer(int $flags): array
    {
        return array_filter([
            'relative' => ($flags & self::RELATIVE) !== 0,
            'short_time' => ($flags & self::SHORT_TIME) !== 0,
            'long_time' => ($flags & self::LONG_TIME) !== 0,
            'short_date' => ($flags & self::SHORT_DATE) !== 0,
            'long_date' => ($flags & self::LONG_DATE) !== 0,
            'day_of_week' => ($flags & self::DAY_OF_WEEK) !== 0,
        ]);
    }
}
