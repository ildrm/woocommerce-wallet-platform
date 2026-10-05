<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure;

final class Csv
{
    public static function cell(string $value): string
    {
        return preg_match('/^[\s\x00-\x1f]*[=+\-@]/', $value) ? "'" . $value : $value;
    }

    /** @param resource $stream */
    public static function row($stream, array $values): void
    {
        if (fputcsv($stream, array_map(static fn ($value): string => self::cell((string) $value), $values), ',', '"', '') === false) {
            throw new \RuntimeException('CSV export failed.');
        }
    }
}
