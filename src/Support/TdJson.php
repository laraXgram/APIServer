<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

use LaraGram\Server\Http\HttpReaderException;
use LaraGram\Server\Http\JsonScanner;

/**
 * td::json_decode: JSON with numbers kept as their raw text and objects as
 * ordered field lists (the first of duplicate fields wins), as TDLib parses
 * every JSON-serialized Bot API argument.
 */
final class TdJson
{
    public const NULL = 'Null';
    public const NUMBER = 'Number';
    public const BOOLEAN = 'Boolean';
    public const STRING = 'String';
    public const ARRAY = 'Array';
    public const OBJECT = 'Object';

    private const MAX_DEPTH = 100;

    private int $pos = 0;

    private readonly int $length;

    private function __construct(private readonly string $data)
    {
        $this->length = strlen($data);
    }

    /**
     * @throws JsonError with TDLib's parse error message
     */
    public static function decode(string $json): JsonValue
    {
        $parser = new self($json);
        $value = $parser->value(self::MAX_DEPTH);
        $parser->skipWhitespaces();
        if ($parser->pos < $parser->length) {
            throw new JsonError('Expected string end');
        }

        return $value;
    }

    /**
     * The decoded value, or null when the text is not valid JSON.
     */
    public static function tryDecode(string $json): ?JsonValue
    {
        try {
            return self::decode($json);
        } catch (JsonError) {
            return null;
        }
    }

    private function skipWhitespaces(): void
    {
        $this->pos += strspn($this->data, " \t\r\n", $this->pos);
    }

    private function peek(): string
    {
        return $this->data[$this->pos] ?? "\0";
    }

    private function trySkip(string $token): bool
    {
        if (substr_compare($this->data, $token, $this->pos, strlen($token)) === 0) {
            $this->pos += strlen($token);

            return true;
        }

        return false;
    }

    private function string(): string
    {
        $scanner = new JsonScanner(substr($this->data, $this->pos));
        try {
            $value = $scanner->decodeString('');
        } catch (HttpReaderException $e) {
            throw new JsonError($e->getMessage());
        }
        $this->pos += $this->consumed($scanner);

        return $value;
    }

    private function consumed(JsonScanner $scanner): int
    {
        return (fn () => $this->pos)->call($scanner);
    }

    private function value(int $depth): JsonValue
    {
        if ($depth < 0) {
            throw new JsonError('Too big object depth');
        }

        $this->skipWhitespaces();
        $c = $this->peek();

        switch ($c) {
            case 'f':
                if ($this->trySkip('false')) {
                    return new JsonValue(self::BOOLEAN, false);
                }
                throw new JsonError("Token starts with 'f' -- false expected");
            case 't':
                if ($this->trySkip('true')) {
                    return new JsonValue(self::BOOLEAN, true);
                }
                throw new JsonError("Token starts with 't' -- true expected");
            case 'n':
                if ($this->trySkip('null')) {
                    return new JsonValue(self::NULL, null);
                }
                throw new JsonError("Token starts with 'n' -- null expected");
            case '"':
                return new JsonValue(self::STRING, $this->string());
            case '[':
                $this->pos++;
                $this->skipWhitespaces();
                $items = [];
                if ($this->trySkip(']')) {
                    return new JsonValue(self::ARRAY, $items);
                }
                while (true) {
                    if ($this->pos >= $this->length) {
                        throw new JsonError('Unexpected string end');
                    }
                    $items[] = $this->value($depth - 1);
                    $this->skipWhitespaces();
                    if ($this->trySkip(']')) {
                        return new JsonValue(self::ARRAY, $items);
                    }
                    if ($this->trySkip(',')) {
                        $this->skipWhitespaces();
                        continue;
                    }
                    throw new JsonError($this->pos >= $this->length ? 'Unexpected string end' : 'Unexpected symbol while parsing JSON Array');
                }
            case '{':
                $this->pos++;
                $this->skipWhitespaces();
                $fields = [];
                if ($this->trySkip('}')) {
                    return new JsonValue(self::OBJECT, new JsonObject($fields));
                }
                while (true) {
                    if ($this->pos >= $this->length) {
                        throw new JsonError('Unexpected string end');
                    }
                    $name = $this->string();
                    $this->skipWhitespaces();
                    if (!$this->trySkip(':')) {
                        throw new JsonError("':' expected");
                    }
                    $fields[] = [$name, $this->value($depth - 1)];
                    $this->skipWhitespaces();
                    if ($this->trySkip('}')) {
                        return new JsonValue(self::OBJECT, new JsonObject($fields));
                    }
                    if ($this->trySkip(',')) {
                        $this->skipWhitespaces();
                        continue;
                    }
                    throw new JsonError($this->pos >= $this->length ? 'Unexpected string end' : 'Unexpected symbol while parsing JSON Object');
                }
            case '-': case '+': case '.':
            case '0': case '1': case '2': case '3': case '4':
            case '5': case '6': case '7': case '8': case '9':
                $length = strspn($this->data, '-0123456789eE+.', $this->pos);
                $number = substr($this->data, $this->pos, $length);
                $this->pos += $length;

                return new JsonValue(self::NUMBER, $number);
            case "\0":
                throw new JsonError('Unexpected string end');
            default:
                $ord = ord($c);
                throw new JsonError($ord > 0 && $ord < 127 ? "Unexpected symbol '{$c}'" : 'Unexpected symbol');
        }
    }
}
