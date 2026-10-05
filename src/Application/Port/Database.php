<?php

declare(strict_types=1);

namespace WalletPlatform\Application\Port;

interface Database
{
    public function table(string $name): string;
    public function execute(string $sql, array $parameters = []): int;
    public function rows(string $sql, array $parameters = []): array;
    public function row(string $sql, array $parameters = []): ?array;
    public function insert(string $table, array $values, bool $ignoreDuplicate = false): int;
    public function transaction(callable $work): mixed;
    public function lockSuffix(): string;
    public function driver(): string;
}
