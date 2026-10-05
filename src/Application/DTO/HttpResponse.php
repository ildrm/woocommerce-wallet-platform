<?php

declare(strict_types=1);

namespace WalletPlatform\Application\DTO;

final readonly class HttpResponse
{
    public function __construct(public int $status, public string $body)
    {
    }
}
