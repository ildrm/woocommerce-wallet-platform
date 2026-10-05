<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Database;

final class DatabaseException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
