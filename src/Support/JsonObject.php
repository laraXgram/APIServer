<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

/**
 * td::JsonObject with the field accessors (and error texts) of TDLib.
 */
final class JsonObject
{
    /**
     * @param list<array{0: string, 1: JsonValue}> $fields
     */
    public function __construct(private array $fields)
    {
    }

    public function fieldCount(): int
    {
        return count($this->fields);
    }

    /**
     * @return list<array{0: string, 1: JsonValue}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function getField(string $name): ?JsonValue
    {
        foreach ($this->fields as [$key, $value]) {
            if ($key === $name) {
                return $value;
            }
        }

        return null;
    }

    public function hasField(string $name): bool
    {
        return $this->getField($name) !== null;
    }

    /**
     * extract_field: the value, or a JSON null when absent.
     */
    public function extractField(string $name): JsonValue
    {
        return $this->getField($name) ?? new JsonValue(TdJson::NULL, null);
    }

    public function extractOptionalField(string $name, string $type): JsonValue
    {
        $value = $this->getField($name);
        if ($value === null) {
            return new JsonValue(TdJson::NULL, null);
        }
        if ($type !== TdJson::NULL && $value->type !== $type) {
            throw new JsonError("Field \"{$name}\" must be of type {$type}");
        }

        return $value;
    }

    public function extractRequiredField(string $name, string $type): JsonValue
    {
        $value = $this->getField($name);
        if ($value === null) {
            throw new JsonError("Can't find field \"{$name}\"");
        }
        if ($type !== TdJson::NULL && $value->type !== $type) {
            throw new JsonError("Field \"{$name}\" must be of type {$type}");
        }

        return $value;
    }

    public function getOptionalBoolField(string $name, bool $default = false): bool
    {
        $value = $this->getField($name);
        if ($value === null) {
            return $default;
        }
        if ($value->type !== TdJson::BOOLEAN) {
            throw new JsonError("Field \"{$name}\" must be of type Boolean");
        }

        return $value->boolean();
    }

    public function getRequiredBoolField(string $name): bool
    {
        if (!$this->hasField($name)) {
            throw new JsonError("Can't find field \"{$name}\"");
        }

        return $this->getOptionalBoolField($name);
    }

    public function getOptionalIntField(string $name, int $default = 0): int
    {
        $value = $this->getField($name);

        return $value === null ? $default : self::integer($name, $value, false);
    }

    public function getRequiredIntField(string $name): int
    {
        $value = $this->getField($name) ?? throw new JsonError("Can't find field \"{$name}\"");

        return self::integer($name, $value, false);
    }

    public function getOptionalLongField(string $name, int $default = 0): int
    {
        $value = $this->getField($name);

        return $value === null ? $default : self::integer($name, $value, true);
    }

    public function getRequiredLongField(string $name): int
    {
        $value = $this->getField($name) ?? throw new JsonError("Can't find field \"{$name}\"");

        return self::integer($name, $value, true);
    }

    public function getOptionalDoubleField(string $name, float $default = 0.0): float
    {
        $value = $this->getField($name);
        if ($value === null) {
            return $default;
        }
        if ($value->type !== TdJson::NUMBER) {
            throw new JsonError("Field \"{$name}\" must be of type Number");
        }

        return self::toDouble($value->string());
    }

    public function getRequiredDoubleField(string $name): float
    {
        if (!$this->hasField($name)) {
            throw new JsonError("Can't find field \"{$name}\"");
        }

        return $this->getOptionalDoubleField($name);
    }

    public function getOptionalStringField(string $name, string $default = ''): string
    {
        $value = $this->getField($name);
        if ($value === null) {
            return $default;
        }
        if ($value->type !== TdJson::STRING && $value->type !== TdJson::NUMBER) {
            throw new JsonError("Field \"{$name}\" must be of type String");
        }

        return $value->string();
    }

    public function getRequiredStringField(string $name): string
    {
        if (!$this->hasField($name)) {
            throw new JsonError("Can't find field \"{$name}\"");
        }

        return $this->getOptionalStringField($name);
    }

    private static function integer(string $name, JsonValue $value, bool $long): int
    {
        if ($value->type !== TdJson::STRING && $value->type !== TdJson::NUMBER) {
            throw new JsonError("Field \"{$name}\" must be a Number");
        }
        $parsed = $long ? TdInteger::toInt64Safe($value->string()) : TdInteger::toInt32Safe($value->string());
        if ($parsed === null) {
            throw new JsonError("Field \"{$name}\" must be a valid Number");
        }

        return $parsed;
    }

    /**
     * td::to_double: the longest numeric prefix (std::istringstream), 0 when none.
     */
    public static function toDouble(string $value): float
    {
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $value, $m)) {
            return (float) $m[0];
        }

        return 0.0;
    }
}
