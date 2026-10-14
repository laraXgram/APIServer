<?php

declare(strict_types=1);

namespace LaraGram\Server\Files;

/**
 * Reads TDLib's little-endian TL serialization (td::TlParser).
 */
final class TlReader
{
    private int $pos = 0;

    private readonly int $length;

    public function __construct(private readonly string $data)
    {
        $this->length = strlen($data);
    }

    public function int32(): int
    {
        $value = unpack('V', $this->take(4))[1];

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function int64(): int
    {
        return unpack('P', $this->take(8))[1];
    }

    public function string(): string
    {
        $first = ord($this->take(1));
        if ($first < 254) {
            $length = $first;
            $head = 1;
        } elseif ($first === 254) {
            $bytes = $this->take(3);
            $length = ord($bytes[0]) | (ord($bytes[1]) << 8) | (ord($bytes[2]) << 16);
            $head = 4;
        } else {
            throw new \UnexpectedValueException('Too big string found');
        }

        $value = $this->take($length);
        $this->take((4 - ($head + $length) % 4) % 4);

        return $value;
    }

    /**
     * td::TlParser::fetch_end: the whole input must be consumed.
     */
    public function end(): void
    {
        if ($this->pos !== $this->length) {
            throw new \UnexpectedValueException('Too much data to fetch');
        }
    }

    private function take(int $length): string
    {
        if ($this->pos + $length > $this->length) {
            throw new \UnderflowException('Not enough data to read');
        }

        $chunk = substr($this->data, $this->pos, $length);
        $this->pos += $length;

        return $chunk;
    }
}
