<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Database;

use WalletPlatform\Application\Port\Database;

/** Uses wpdb's connection without wpdb's automatic reconnect, which is unsafe mid-transaction. */
final class WpdbDatabase implements Database
{
    private bool $active = false;
    private readonly \mysqli $connection;
    private readonly DedicatedWpdb $owner;

    private function __construct(DedicatedWpdb $wpdb)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $wpdb->prefix)) {
            throw new DatabaseException('Wallet requires WordPress mysqli persistence.');
        }
        $this->connection = $wpdb->connection();
        $this->owner = $wpdb;
    }

    public static function forSite(\wpdb $site): self
    {
        // A dedicated wpdb connection isolates our BEGIN/COMMIT from other plugins' transactions.
        // WordPress still handles DB_HOST sockets, port parsing and configured SSL client flags.
        $connection = new DedicatedWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $connection->set_prefix($site->prefix);
        return new self($connection);
    }

    public function table(string $name): string
    {
        if (!in_array($name, Schema::TABLES, true)) {
            throw new DatabaseException('Unknown wallet table.');
        }
        return $this->owner->prefix . 'wallet_' . $name;
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function lockSuffix(): string
    {
        return ' FOR UPDATE';
    }

    private function statement(string $sql, array $parameters): \mysqli_stmt
    {
        try {
            $statement = $this->connection->prepare($sql);
            if ($statement === false) {
                throw new \mysqli_sql_exception('Prepare failed.', $this->connection->errno);
            }
            if ($parameters !== []) {
                $types = implode('', array_map(static fn ($value): string => is_int($value) ? 'i' : 's', $parameters));
                $values = array_values($parameters);
                $statement->bind_param($types, ...$values);
            }
            if (!$statement->execute()) {
                throw new \mysqli_sql_exception('Execute failed.', $statement->errno);
            }
            return $statement;
        } catch (\mysqli_sql_exception $error) {
            throw new DatabaseException('Wallet database operation failed.', in_array($error->getCode(), [1205, 1213], true), $error);
        }
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->statement($sql, $parameters);
        try {
            return $statement->affected_rows;
        } finally {
            $statement->close();
        }
    }

    public function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->statement($sql, $parameters);
        try {
            $result = $statement->get_result();
            return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }

    public function row(string $sql, array $parameters = []): ?array
    {
        return $this->rows($sql, $parameters)[0] ?? null;
    }

    public function insert(string $table, array $values, bool $ignoreDuplicate = false): int
    {
        foreach (array_keys($values) as $column) {
            if (!preg_match('/^[a-z_]+$/D', $column)) {
                throw new DatabaseException('Invalid database column.');
            }
        }
        return $this->execute(($ignoreDuplicate ? 'INSERT IGNORE' : 'INSERT') . ' INTO ' . $this->table($table) . ' (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')', array_values($values));
    }

    public function transaction(callable $work): mixed
    {
        if ($this->active) {
            throw new DatabaseException('Nested financial transactions are prohibited.');
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $this->execute('START TRANSACTION');
                $this->active = true;
                $result = $work();
                $this->execute('COMMIT');
                $this->active = false;
                return $result;
            } catch (\Throwable $error) {
                if ($this->active) {
                    try {
                        $this->execute('ROLLBACK');
                    } finally {
                        $this->active = false;
                    }
                }
                if (!$error instanceof DatabaseException || !$error->retryable || $attempt === 2) {
                    throw $error;
                }
                usleep(10000 * ($attempt + 1));
            }
        }
        throw new DatabaseException('Transaction retry exhausted.');
    }
}
