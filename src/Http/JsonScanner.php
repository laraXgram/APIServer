<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

/**
 * Cursor over a JSON request body, porting the pieces of td::Parser,
 * json_string_decode() and do_json_skip() the request reader needs.
 */
final class JsonScanner
{
    private const MAX_DEPTH = 100;

    private int $pos = 0;

    private readonly int $length;

    public function __construct(private readonly string $data)
    {
        $this->length = strlen($data);
    }

    public function isEmpty(): bool
    {
        return $this->pos >= $this->length;
    }

    /**
     * The next byte, or "\0" at the end (td::Parser::peek_char).
     */
    public function peek(): string
    {
        return $this->pos < $this->length ? $this->data[$this->pos] : "\0";
    }

    public function trySkip(string $c): bool
    {
        if ($this->pos < $this->length && $this->data[$this->pos] === $c) {
            $this->pos++;

            return true;
        }

        return false;
    }

    public function skipWhitespaces(): void
    {
        $this->pos += strspn($this->data, " \t\r\n", $this->pos);
    }

    /**
     * @throws HttpReaderException with $errorPrefix and the TDLib message
     */
    public function decodeString(string $errorPrefix): string
    {
        if (!$this->trySkip('"')) {
            throw new HttpReaderException(400, $errorPrefix.'Opening \'"\' expected');
        }

        $out = '';
        while (true) {
            $chunk = strcspn($this->data, '"\\', $this->pos);
            $out .= substr($this->data, $this->pos, $chunk);
            $this->pos += $chunk;

            if ($this->pos >= $this->length) {
                throw new HttpReaderException(400, $errorPrefix.'Closing \'"\' not found');
            }

            if ($this->data[$this->pos] === '"') {
                $this->pos++;

                return $out;
            }

            // Backslash escape.
            $this->pos++;
            if ($this->pos >= $this->length) {
                throw new HttpReaderException(400, $errorPrefix.'Closing \'"\' not found');
            }

            $c = $this->data[$this->pos];
            switch ($c) {
                case 'b': $out .= "\x08"; $this->pos++; break;
                case 'f': $out .= "\f"; $this->pos++; break;
                case 'n': $out .= "\n"; $this->pos++; break;
                case 'r': $out .= "\r"; $this->pos++; break;
                case 't': $out .= "\t"; $this->pos++; break;
                case 'u':
                    $this->pos++;
                    $num = $this->readHex4($errorPrefix);
                    if ($num > 0xD7FF && $num < 0xE000
                        && $this->pos + 6 <= $this->length
                        && $this->data[$this->pos] === '\\' && $this->data[$this->pos + 1] === 'u') {
                        $this->pos += 2;
                        $next = $this->readHex4($errorPrefix);
                        if ($next > 0xD7FF && $next < 0xE000) {
                            $num = ((($num & 0x3FF) << 10) | ($next & 0x3FF)) + 0x10000;
                        } else {
                            $this->pos -= 6;
                        }
                    }
                    $out .= self::utf8($num);
                    break;
                default:
                    $out .= $c;
                    $this->pos++;
            }
        }
    }

    /**
     * Skip one non-string JSON value and return its raw text.
     */
    public function rawValue(string $errorPrefix): string
    {
        $start = $this->pos;
        $this->skipValue(self::MAX_DEPTH, $errorPrefix);

        return substr($this->data, $start, $this->pos - $start);
    }

    private function skipValue(int $maxDepth, string $errorPrefix): void
    {
        if ($maxDepth < 0) {
            throw new HttpReaderException(400, $errorPrefix.'Too big object depth');
        }

        $this->skipWhitespaces();
        $c = $this->peek();

        switch ($c) {
            case 'f':
                $this->skipWord('false', $errorPrefix."Starts with 'f' -- false expected");

                return;
            case 't':
                $this->skipWord('true', $errorPrefix."Starts with 't' -- true expected");

                return;
            case 'n':
                $this->skipWord('null', $errorPrefix."Starts with 'n' -- null expected");

                return;
            case '"':
                $this->decodeString($errorPrefix);

                return;
            case '[':
                $this->pos++;
                $this->skipWhitespaces();
                if ($this->trySkip(']')) {
                    return;
                }
                while (true) {
                    if ($this->isEmpty()) {
                        throw new HttpReaderException(400, $errorPrefix.'Unexpected end');
                    }
                    $this->skipValue($maxDepth - 1, $errorPrefix);
                    $this->skipWhitespaces();
                    if ($this->trySkip(']')) {
                        return;
                    }
                    if ($this->trySkip(',')) {
                        $this->skipWhitespaces();
                        continue;
                    }
                    throw new HttpReaderException(400, $errorPrefix.'Unexpected symbol');
                }
            case '{':
                $this->pos++;
                $this->skipWhitespaces();
                if ($this->trySkip('}')) {
                    return;
                }
                while (true) {
                    if ($this->isEmpty()) {
                        throw new HttpReaderException(400, $errorPrefix.'Unexpected end');
                    }
                    $this->decodeString($errorPrefix);
                    $this->skipWhitespaces();
                    if (!$this->trySkip(':')) {
                        throw new HttpReaderException(400, $errorPrefix."':' expected");
                    }
                    $this->skipValue($maxDepth - 1, $errorPrefix);
                    $this->skipWhitespaces();
                    if ($this->trySkip('}')) {
                        return;
                    }
                    if ($this->trySkip(',')) {
                        $this->skipWhitespaces();
                        continue;
                    }
                    throw new HttpReaderException(400, $errorPrefix.'Unexpected symbol');
                }
            case '-': case '+': case '.':
            case '0': case '1': case '2': case '3': case '4':
            case '5': case '6': case '7': case '8': case '9':
                $this->pos += strspn($this->data, '-0123456789eE+.', $this->pos);

                return;
            case "\0":
                throw new HttpReaderException(400, $errorPrefix.'Unexpected end');
            default:
                $ord = ord($c);
                throw new HttpReaderException(400, $errorPrefix.($ord > 0 && $ord < 127 ? "Unexpected symbol '{$c}'" : 'Unexpected symbol'));
        }
    }

    private function skipWord(string $word, string $error): void
    {
        if (substr_compare($this->data, $word, $this->pos, strlen($word)) === 0) {
            $this->pos += strlen($word);

            return;
        }

        throw new HttpReaderException(400, $error);
    }

    private function readHex4(string $errorPrefix): int
    {
        if ($this->pos + 4 > $this->length) {
            throw new HttpReaderException(400, $errorPrefix.'\u has less than 4 symbols');
        }

        $hex = substr($this->data, $this->pos, 4);
        if (strspn($hex, '0123456789abcdefABCDEF') !== 4) {
            throw new HttpReaderException(400, $errorPrefix.'Invalid \u -- not hex digit');
        }
        $this->pos += 4;

        return hexdec($hex);
    }

    /**
     * td::append_utf8_character_unsafe: plain UTF-8, lone surrogates included.
     */
    private static function utf8(int $code): string
    {
        return match (true) {
            $code <= 0x7F => chr($code),
            $code <= 0x7FF => chr(0xC0 | ($code >> 6)).chr(0x80 | ($code & 0x3F)),
            $code <= 0xFFFF => chr(0xE0 | ($code >> 12)).chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F)),
            default => chr(0xF0 | ($code >> 18)).chr(0x80 | (($code >> 12) & 0x3F)).chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F)),
        };
    }
}
