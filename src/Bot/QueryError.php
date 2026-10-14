<?php

declare(strict_types=1);

namespace LaraGram\Server\Bot;

use LaraGram\Server\Errors\TdError;

/**
 * A failed step while handling a query: the PHP form of an error td::Status.
 * Handlers throw it; the client answers with fail_query_with_error(), using
 * $defaultMessage for 400 errors when it is set.
 */
final class QueryError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $parameters ResponseParameters sent as is (fail_query with a parameters object)
     */
    public function __construct(int $code, string $message, public readonly string $defaultMessage = '', public readonly array $parameters = [])
    {
        parent::__construct($message, $code);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, $message);
    }

    /**
     * A Telegram/TDLib error passed on, with an optional default message.
     */
    public static function fromTd(TdError $error, string $defaultMessage = ''): self
    {
        return new self($error->code, $error->message, $defaultMessage);
    }

    public static function fromThrowable(\Throwable $e, string $defaultMessage = ''): self
    {
        return $e instanceof self ? $e : self::fromTd(TdError::fromThrowable($e), $defaultMessage);
    }
}
