<?php

declare(strict_types=1);

namespace LaraGram\Server\Errors;

use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * An error as TDLib reports it to the Bot API layer (td_api::error): the
 * Telegram RPC error code and message, with FLOOD_WAIT_X already turned
 * into 429 "Too Many Requests: retry after X".
 */
final class TdError
{
    public function __construct(
        public readonly int $code,
        public readonly string $message,
    ) {
    }

    public static function fromThrowable(\Throwable $e): self
    {
        if ($e instanceof MTProtoException && preg_match('/^RPC Error (-?\d+): (.*)$/s', $e->getMessage(), $m)) {
            return self::fromRpc((int) $m[1], $m[2]);
        }

        return new self(500, 'Request aborted');
    }

    public static function fromRpc(int $code, string $message): self
    {
        if (preg_match('/^(?:FLOOD_WAIT|FLOOD_PREMIUM_WAIT|SLOWMODE_WAIT)_(\d+)$/', $message, $m)) {
            return new self(429, 'Too Many Requests: retry after '.$m[1]);
        }
        if ($code === 420) {
            $code = 429;
        }

        return new self($code, $message);
    }
}
