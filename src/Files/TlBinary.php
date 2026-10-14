<?php

declare(strict_types=1);

namespace LaraGram\Server\Files;

/**
 * TDLib's binary helpers for file identifiers: TL little-endian storing,
 * zero run-length encoding and unpadded base64url.
 */
final class TlBinary
{
    public static function int32(int $value): string
    {
        return pack('V', $value & 0xFFFFFFFF);
    }

    public static function int64(int $value): string
    {
        return pack('P', $value);
    }

    /**
     * TL bytes: a 1-byte (or 0xFE + 3-byte) length, the data, zero padding to 4.
     */
    public static function string(string $value): string
    {
        $length = strlen($value);
        $head = $length < 254 ? chr($length) : "\xFE".substr(pack('V', $length), 0, 3);
        $out = $head.$value;
        $pad = (4 - strlen($out) % 4) % 4;

        return $out.str_repeat("\0", $pad);
    }

    /**
     * td::zero_encode: every run of zero bytes becomes "\0" + run length (max 250).
     */
    public static function zeroEncode(string $data): string
    {
        return preg_replace_callback('/\x00{1,250}/', static fn (array $m): string => "\0".chr(strlen($m[0])), $data);
    }

    public static function zeroDecode(string $data): string
    {
        $out = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            if ($data[$i] === "\0" && $i + 1 < $length) {
                $out .= str_repeat("\0", ord($data[++$i]));
            } else {
                $out .= $data[$i];
            }
        }

        return $out;
    }

    public static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * td::base64url_decode.
     *
     * @throws \InvalidArgumentException with TDLib's error message
     */
    public static function base64urlDecodeOrFail(string $data): string
    {
        $length = strlen($data);
        $trimmed = rtrim($data, '=');
        $padding = $length - strlen($trimmed);
        if ($padding >= 3) {
            throw new \InvalidArgumentException('Wrong string padding');
        }
        $size = strlen($trimmed);
        if ($padding > 0 && (($size + $padding) & 3) !== 0) {
            throw new \InvalidArgumentException('Wrong padding length');
        }
        if (($size & 3) === 1) {
            throw new \InvalidArgumentException('Wrong string length');
        }
        if (strspn($trimmed, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_') !== $size) {
            throw new \InvalidArgumentException('Wrong character in the string');
        }
        // A partial last group must have zero unused bits.
        $tail = $size & 3;
        if ($tail !== 0) {
            $value = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_', $trimmed[$size - 1]);
            if (($tail === 2 && ($value & 0x0F) !== 0) || ($tail === 3 && ($value & 0x03) !== 0)) {
                throw new \InvalidArgumentException('Wrong padding in the string');
            }
        }

        return (string) base64_decode(strtr($trimmed, '-_', '+/'), false);
    }

    public static function base64urlDecode(string $data): ?string
    {
        try {
            return self::base64urlDecodeOrFail($data);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
