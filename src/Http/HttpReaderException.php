<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

/**
 * A request that cannot be read. The server answers it with the bare status
 * line and an empty body, then closes the connection, as TDLib does.
 */
final class HttpReaderException extends \RuntimeException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
