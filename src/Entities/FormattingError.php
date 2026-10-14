<?php

declare(strict_types=1);

namespace LaraGram\Server\Entities;

/**
 * Invalid text or entities; the message is TDLib's (error code 400).
 */
final class FormattingError extends \RuntimeException
{
}
