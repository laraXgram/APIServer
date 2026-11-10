<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

/**
 * A string that is not valid UTF-8, written like td::JsonRawString: bytes
 * >= 0x80 as they are. It serializes to a marked string that
 * {@see JsonWriter} replaces with the raw form.
 */
final class JsonRawString implements \JsonSerializable
{
    public const MARKER = "\x01\x02\x03R";

    public function __construct(public readonly string $value)
    {
    }

    /**
     * Client::json_store_bytes: the bytes as a string, or as they are when they aren't valid UTF-8.
     */
    public static function bytes(string $value): string|self
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : new self($value);
    }

    public function jsonSerialize(): string
    {
        return self::MARKER.base64_encode($this->value);
    }
}
