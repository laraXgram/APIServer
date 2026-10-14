<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

/**
 * td::to_integer / td::to_integer_safe: lenient integer parsing used for
 * every numeric Bot API argument. Parsing stops at the first non-digit and
 * overflows wrap around like the C++ unsigned arithmetic does.
 */
final class TdInteger
{
    public static function toInt32(string $value): int
    {
        [$negative, $digits] = self::split($value);

        $unsigned = 0;
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $unsigned = ($unsigned * 10 + (ord($digits[$i]) - 48)) & 0xFFFFFFFF;
        }

        if ($unsigned > 0x7FFFFFFF) {
            $unsigned = (~$unsigned + 1) & 0xFFFFFFFF;
            $negative = !$negative;
            if ($unsigned > 0x7FFFFFFF) {
                return -0x80000000;
            }
        }

        return $negative ? -$unsigned : $unsigned;
    }

    public static function toInt64(string $value): int
    {
        [$negative, $digits] = self::split($value);

        // Up to 18 digits cannot overflow a signed 64-bit integer.
        if (strlen($digits) <= 18) {
            $result = $digits === '' ? 0 : (int) $digits;

            return $negative ? -$result : $result;
        }

        $modulus = gmp_pow(2, 64);
        $unsigned = gmp_mod(gmp_init($digits, 10), $modulus);
        $max = gmp_init(PHP_INT_MAX);

        if (gmp_cmp($unsigned, $max) > 0) {
            $unsigned = gmp_mod(gmp_sub($modulus, $unsigned), $modulus);
            $negative = !$negative;
            if (gmp_cmp($unsigned, $max) > 0) {
                return PHP_INT_MIN;
            }
        }

        $result = gmp_intval($unsigned);

        return $negative ? -$result : $result;
    }

    /**
     * td::to_integer_safe<int64>: null unless the value prints back unchanged.
     */
    public static function toInt64Safe(string $value): ?int
    {
        $result = self::toInt64($value);

        return (string) $result === $value ? $result : null;
    }

    public static function toInt32Safe(string $value): ?int
    {
        $result = self::toInt32($value);

        return (string) $result === $value ? $result : null;
    }

    /**
     * @return array{0: bool, 1: string} sign and the leading digits
     */
    private static function split(string $value): array
    {
        $negative = $value !== '' && $value[0] === '-';
        $start = $negative ? 1 : 0;

        return [$negative, substr($value, $start, strspn($value, '0123456789', $start))];
    }
}
