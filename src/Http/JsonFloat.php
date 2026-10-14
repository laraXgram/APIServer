<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

/**
 * A floating-point JSON value printed the way TDLib prints doubles: fixed
 * notation with exactly six decimals (`51.500000`), unlike json_encode's
 * shortest round-trip form.
 *
 * json_encode cannot emit raw numbers from objects, so the value serializes
 * to a marked string that {@see JsonWriter} turns back into a bare number.
 */
final class JsonFloat implements \JsonSerializable
{
    public const MARKER = "\x01\x02\x03F";

    public function __construct(public readonly float $value)
    {
    }

    public function jsonSerialize(): string
    {
        return self::MARKER.sprintf('%.6F', $this->value);
    }
}
