<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Database;

use WalletPlatform\Application\Port\Database;

final class Schema
{
    public const VERSION = 4;
    public const TABLES = ['accounts', 'entries', 'lines', 'lots', 'consumptions', 'holds', 'allocations', 'topups', 'payments', 'payment_refunds', 'provider_events', 'audit', 'idempotency', 'outbox', 'migrations'];

    /** Initial migration is additive, resumable and never deletes financial data. */
    public static function migrate(Database $db): void
    {
        $lock = 'wallet_schema_' . substr(hash('sha256', $db->table('migrations')), 0, 24);
        if ($db->driver() !== 'sqlite' && (int) ($db->row('SELECT GET_LOCK(?, 10) AS acquired', [$lock])['acquired'] ?? 0) !== 1) {
            throw new DatabaseException('Another wallet migration is running.');
        }
        try {
            $suffix = $db->driver() === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
            $db->execute('CREATE TABLE IF NOT EXISTS ' . $db->table('migrations') . ' (' . self::definitions()['migrations'] . ')' . $suffix);
            if ((int) ($db->row('SELECT MAX(version) AS version FROM ' . $db->table('migrations'))['version'] ?? 0) > self::VERSION) {
                throw new DatabaseException('Newer wallet schema cannot be downgraded.');
            }
            foreach (self::definitions() as $name => $columns) {
                $db->execute('CREATE TABLE IF NOT EXISTS ' . $db->table($name) . ' (' . $columns . ')' . $suffix);
            }
            // Migration 2 adds funding reversal counters without rewriting financial history.
            $columns = $db->driver() === 'sqlite'
                ? array_column($db->rows('PRAGMA table_info(' . $db->table('topups') . ')'), 'name')
                : array_column($db->rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$db->table('topups')]), 'COLUMN_NAME');
            if (!in_array('refunded', $columns, true)) {
                $db->execute('ALTER TABLE ' . $db->table('topups') . ' ADD COLUMN refunded BIGINT NOT NULL DEFAULT 0' . ($db->driver() === 'sqlite' ? '' : ', ALGORITHM=INSTANT'));
            }
            $paymentColumns = $db->driver() === 'sqlite'
                ? array_column($db->rows('PRAGMA table_info(' . $db->table('payments') . ')'), 'name')
                : array_column($db->rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$db->table('payments')]), 'COLUMN_NAME');
            if (!in_array('external_settled', $paymentColumns, true)) {
                $db->execute('ALTER TABLE ' . $db->table('payments') . ' ADD COLUMN external_settled INTEGER NOT NULL DEFAULT 0' . ($db->driver() === 'sqlite' ? '' : ', ALGORITHM=INSTANT'));
            }
            $refundColumns = $db->driver() === 'sqlite'
                ? array_column($db->rows('PRAGMA table_info(' . $db->table('payment_refunds') . ')'), 'name')
                : array_column($db->rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$db->table('payment_refunds')]), 'COLUMN_NAME');
            if (!in_array('adapter_context', $refundColumns, true)) {
                $db->execute('ALTER TABLE ' . $db->table('payment_refunds') . ' ADD COLUMN adapter_context TEXT' . ($db->driver() === 'sqlite' ? '' : ', ALGORITHM=INSTANT'));
            }
            foreach (self::indexes() as [$name, $table, $columns]) {
                if ($db->driver() === 'sqlite') {
                    $db->execute('CREATE INDEX IF NOT EXISTS ' . $db->table($table) . '_' . $name . ' ON ' . $db->table($table) . ' (' . $columns . ')');
                } else {
                    $exists = $db->row('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$db->table($table), $name]);
                    if ($exists === null) {
                        $db->execute('CREATE INDEX ' . $name . ' ON ' . $db->table($table) . ' (' . $columns . ')');
                    }
                }
            }
            if ($db->driver() !== 'sqlite') {
                foreach (self::TABLES as $table) {
                    $info = $db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$db->table($table)]);
                    if (strtoupper((string) ($info['ENGINE'] ?? '')) !== 'INNODB') {
                        throw new DatabaseException('Wallet tables must use InnoDB.');
                    }
                }
            }
            foreach (range(1, self::VERSION) as $version) {
                $db->insert('migrations', ['version' => $version, 'completed_at' => time()], true);
            }
        } finally {
            if ($db->driver() !== 'sqlite') {
                $db->row('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    public static function definitions(): array
    {
        return [
            'accounts' => 'id CHAR(32) PRIMARY KEY, user_id BIGINT NOT NULL, currency CHAR(3) NOT NULL, exponent INTEGER NOT NULL, state VARCHAR(12) NOT NULL, available BIGINT NOT NULL DEFAULT 0, reserved BIGINT NOT NULL DEFAULT 0, pending BIGINT NOT NULL DEFAULT 0, version BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, UNIQUE(user_id,currency), CHECK(available>=0 AND reserved>=0 AND pending>=0)',
            'entries' => 'id CHAR(32) PRIMARY KEY, reference VARCHAR(191) NOT NULL UNIQUE, operation VARCHAR(40) NOT NULL, currency CHAR(3) NOT NULL, exponent INTEGER NOT NULL, actor_id BIGINT NOT NULL, reason VARCHAR(500) NOT NULL, created_at BIGINT NOT NULL',
            'lines' => 'id CHAR(32) PRIMARY KEY, entry_id CHAR(32) NOT NULL, account_id CHAR(32), bucket VARCHAR(40) NOT NULL, side VARCHAR(6) NOT NULL, amount BIGINT NOT NULL, CHECK(amount>0)',
            'lots' => 'id CHAR(32) PRIMARY KEY, account_id CHAR(32) NOT NULL, source VARCHAR(32) NOT NULL, original BIGINT NOT NULL, remaining BIGINT NOT NULL, state VARCHAR(12) NOT NULL, available_at BIGINT NOT NULL, expires_at BIGINT, withdrawable INTEGER NOT NULL DEFAULT 0, transferable INTEGER NOT NULL DEFAULT 0, origin VARCHAR(191) NOT NULL, parent_id CHAR(32), created_at BIGINT NOT NULL, CHECK(original>0 AND remaining>=0 AND remaining<=original)',
            'consumptions' => 'id CHAR(32) PRIMARY KEY, account_id CHAR(32) NOT NULL, lot_id CHAR(32) NOT NULL, entry_id CHAR(32) NOT NULL, hold_id CHAR(32), amount BIGINT NOT NULL, refunded BIGINT NOT NULL DEFAULT 0, state VARCHAR(12) NOT NULL, CHECK(amount>0 AND refunded>=0 AND refunded<=amount)',
            'holds' => 'id CHAR(32) PRIMARY KEY, account_id CHAR(32) NOT NULL, amount BIGINT NOT NULL, currency CHAR(3) NOT NULL, reference VARCHAR(191) NOT NULL, state VARCHAR(12) NOT NULL, expires_at BIGINT NOT NULL, captured BIGINT NOT NULL DEFAULT 0, refunded BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, UNIQUE(account_id,reference), CHECK(amount>0 AND captured>=0 AND captured<=amount AND refunded>=0 AND refunded<=captured)',
            'allocations' => 'id CHAR(32) PRIMARY KEY, order_id BIGINT NOT NULL, attempt INTEGER NOT NULL, account_id CHAR(32) NOT NULL, hold_id CHAR(32), gross BIGINT NOT NULL, wallet_amount BIGINT NOT NULL, external_amount BIGINT NOT NULL, currency CHAR(3) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL, UNIQUE(order_id,attempt), CHECK(gross>0 AND wallet_amount>0 AND external_amount>=0 AND wallet_amount+external_amount=gross)',
            'topups' => 'id CHAR(32) PRIMARY KEY, account_id CHAR(32) NOT NULL, order_id BIGINT UNIQUE, amount BIGINT NOT NULL, currency CHAR(3) NOT NULL, state VARCHAR(24) NOT NULL, lot_id CHAR(32), refunded BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, CHECK(amount>0 AND refunded>=0 AND refunded<=amount)',
            'payments' => 'id CHAR(32) PRIMARY KEY, allocation_id CHAR(32) NOT NULL UNIQUE, owner_id BIGINT NOT NULL, provider VARCHAR(24) NOT NULL, provider_order VARCHAR(64) UNIQUE, capture_id VARCHAR(64) UNIQUE, approval_url TEXT, state VARCHAR(32) NOT NULL, external_settled INTEGER NOT NULL DEFAULT 0, cancel_requested INTEGER NOT NULL DEFAULT 0, create_started BIGINT, capture_started BIGINT, lease_until BIGINT NOT NULL DEFAULT 0, lease_token CHAR(32), error_code VARCHAR(64), created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL',
            'payment_refunds' => 'id CHAR(32) PRIMARY KEY, payment_id CHAR(32) NOT NULL, reference VARCHAR(191) NOT NULL UNIQUE, gross BIGINT NOT NULL, wallet_amount BIGINT NOT NULL, external_amount BIGINT NOT NULL, provider_refund VARCHAR(64) UNIQUE, state VARCHAR(32) NOT NULL, adapter_context TEXT, started_at BIGINT, created_at BIGINT NOT NULL, CHECK(gross>0 AND wallet_amount>=0 AND external_amount>=0 AND wallet_amount+external_amount=gross)',
            'provider_events' => 'id VARCHAR(128) PRIMARY KEY, provider VARCHAR(24) NOT NULL, body_hash CHAR(64) NOT NULL, payment_id CHAR(32), state VARCHAR(24) NOT NULL, created_at BIGINT NOT NULL',
            'audit' => 'id CHAR(32) PRIMARY KEY, account_id CHAR(32), actor_id BIGINT NOT NULL, action VARCHAR(40) NOT NULL, reference VARCHAR(191) NOT NULL, reason VARCHAR(500) NOT NULL, context TEXT NOT NULL, created_at BIGINT NOT NULL',
            'idempotency' => 'key_hash CHAR(64) PRIMARY KEY, operation VARCHAR(40) NOT NULL, payload_hash CHAR(64) NOT NULL, result TEXT, created_at BIGINT NOT NULL',
            'outbox' => 'id CHAR(32) PRIMARY KEY, event VARCHAR(64) NOT NULL, payload TEXT NOT NULL, state VARCHAR(12) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_at BIGINT NOT NULL, lease_until BIGINT NOT NULL DEFAULT 0, lease_token CHAR(32), error_code VARCHAR(64), created_at BIGINT NOT NULL',
            'migrations' => 'version INTEGER PRIMARY KEY, completed_at BIGINT NOT NULL',
        ];
    }

    private static function indexes(): array
    {
        return [
            ['owner_state', 'accounts', 'user_id,state'], ['currency_state', 'accounts', 'currency,state'],
            ['created', 'entries', 'created_at,id'], ['entry', 'lines', 'entry_id'], ['account_bucket', 'lines', 'account_id,bucket,entry_id'],
            ['eligible', 'lots', 'account_id,available_at,expires_at'], ['expiry', 'lots', 'expires_at,id'],
            ['hold', 'consumptions', 'hold_id,state'], ['account', 'consumptions', 'account_id,state'],
            ['expiry', 'holds', 'state,expires_at,id'], ['account', 'holds', 'account_id,created_at'],
            ['status', 'topups', 'state,created_at'],
            ['state_updated', 'payments', 'state,updated_at,id'], ['payment', 'payment_refunds', 'payment_id,created_at,id'],
            ['account_created', 'audit', 'account_id,created_at'], ['delivery', 'outbox', 'state,next_at,lease_until'],
        ];
    }
}
