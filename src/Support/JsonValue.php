<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

/**
 * td::JsonValue: a typed JSON value; numbers keep their raw text.
 */
final class JsonValue
{
    /**
     * @param string $type one of the TdJson type names
     * @param bool|string|list<JsonValue>|JsonObject|null $value
     */
    public function __construct(
        public readonly string $type,
        public readonly bool|string|array|JsonObject|null $value,
    ) {
    }

    public function isNull(): bool
    {
        return $this->type === TdJson::NULL;
    }

    public function isObject(): bool
    {
        return $this->type === TdJson::OBJECT;
    }

    public function isArray(): bool
    {
        return $this->type === TdJson::ARRAY;
    }

    public function object(): JsonObject
    {
        return $this->value;
    }

    /**
     * @return list<JsonValue>
     */
    public function array(): array
    {
        return $this->value;
    }

    public function string(): string
    {
        return (string) $this->value;
    }

    public function boolean(): bool
    {
        return (bool) $this->value;
    }
}
