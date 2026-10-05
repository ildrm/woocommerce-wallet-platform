<?php

declare(strict_types=1);

namespace WalletPlatform\Application\Port;

use WalletPlatform\Application\DTO\HttpResponse;

interface HttpTransport
{
    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body): HttpResponse;
}
