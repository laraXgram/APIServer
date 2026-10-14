<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

/**
 * IP address helpers mirroring td::IPAddress where the Bot API server relies on them.
 */
final class IpAddress
{
    /** IPv4 blocks td::IPAddress::is_reserved() treats as non-public. */
    private const RESERVED_V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
        ['203.0.113.0', 24], ['224.0.0.0', 3],
    ];

    public static function isValid(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Every IPv6 address counts as reserved, exactly as in TDLib.
     */
    public static function isReserved(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return true;
        }

        $value = ip2long($ip);
        foreach (self::RESERVED_V4 as [$block, $bits]) {
            $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            if (($value & $mask) === (ip2long($block) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise an address the way ClientManager does before keying flood
     * control on it (IPv6 scope identifiers are dropped).
     */
    public static function normalize(string $ip): string
    {
        $ip = trim($ip, '[]');
        if (($percent = strpos($ip, '%')) !== false) {
            $ip = substr($ip, 0, $percent);
        }

        $packed = @inet_pton($ip);

        return $packed === false ? $ip : (string) inet_ntop($packed);
    }
}
