<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Database;

final class DedicatedWpdb extends \wpdb
{
    public function connection(): \mysqli
    {
        if (!$this->dbh instanceof \mysqli) {
            throw new DatabaseException('Wallet requires a live mysqli connection.');
        }
        return $this->dbh;
    }
}
