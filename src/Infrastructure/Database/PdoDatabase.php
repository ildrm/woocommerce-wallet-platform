<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Database;

use WalletPlatform\Application\Port\Database;

final class PdoDatabase implements Database
{
    private bool $active = false;

    public function __construct(private readonly \PDO $pdo, private readonly string $prefix = 'wp_')
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) {
            throw new DatabaseException('Invalid database prefix.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if ($this->driver() === 'sqlite') {
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        }
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function table(string $name): string
    {
        if (!in_array($name, Schema::TABLES, true)) {
            throw new DatabaseException('Unknown wallet table.');
        }
        return $this->prefix . 'wallet_' . $name;
    }

    private function statement(string $sql, array $parameters): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            foreach (array_values($parameters) as $index => $value) {
                $statement->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : ($value === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR));
            }
            $statement->execute();
            return $statement;
        } catch (\PDOException $error) {
            $code = (int) ($error->errorInfo[1] ?? 0);
            throw new DatabaseException('Wallet database operation failed.', in_array($code, [5, 6, 1205, 1213], true) || $error->getCode() === '40001', $error);
        }
    }

    public function execute(string $sql, array $parameters = []): int
    {
        return $this->statement($sql, $parameters)->rowCount();
    }

    public function rows(string $sql, array $parameters = []): array
    {
        return $this->statement($sql, $parameters)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function row(string $sql, array $parameters = []): ?array
    {
        $row = $this->statement($sql, $parameters)->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function insert(string $table, array $values, bool $ignoreDuplicate = false): int
    {
        $name = $this->table($table);
        foreach (array_keys($values) as $column) {
            if (!preg_match('/^[a-z_]+$/D', $column)) {
                throw new DatabaseException('Invalid database column.');
            }
        }
        $prefix = $ignoreDuplicate ? ($this->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE') : 'INSERT';
        return $this->execute($prefix . ' INTO ' . $name . ' (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')', array_values($values));
    }

    public function lockSuffix(): string
    {
        return $this->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }

    public function transaction(callable $work): mixed
    {
        if ($this->active) {
            throw new DatabaseException('Nested wallet transactions are prohibited.');
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $this->execute($this->driver() === 'sqlite' ? 'BEGIN IMMEDIATE' : 'START TRANSACTION');
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
