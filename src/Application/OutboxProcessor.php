<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Application\Port\Clock;
use WalletPlatform\Application\Port\Database;

final class OutboxProcessor
{
    private readonly \Closure $deliver;

    public function __construct(private readonly Database $db, private readonly Clock $clock, callable $deliver)
    {
        $this->deliver = \Closure::fromCallable($deliver);
    }

    public function run(int $limit = 25): array
    {
        $count = ['delivered' => 0, 'retried' => 0, 'failed' => 0];
        for ($i = 0; $i < max(1, min(100, $limit)); ++$i) {
            $count['failed'] += $this->db->execute('UPDATE ' . $this->db->table('outbox') . " SET state='failed',lease_token=NULL,lease_until=0,error_code='attempts_exhausted' WHERE attempts>=10 AND ((state='processing' AND lease_until<=?) OR (state='pending' AND next_at<=?))", [$this->clock->now(), $this->clock->now()]);
            $event = $this->claim();
            if ($event === null) {
                break;
            }
            try {
                ($this->deliver)(['id' => $event['id'], 'event' => $event['event'], 'created_at' => (int) $event['created_at'], 'data' => json_decode($event['payload'], true, 32, JSON_THROW_ON_ERROR)]);
                $changed = $this->db->execute('UPDATE ' . $this->db->table('outbox') . " SET state='delivered',lease_token=NULL,lease_until=0,error_code=NULL WHERE id=? AND lease_token=?", [$event['id'], $event['lease_token']]);
                $count['delivered'] += $changed;
            } catch (\Throwable $error) {
                $failed = (int) $event['attempts'] >= 10;
                $delay = 60 * (2 ** min(10, (int) $event['attempts']));
                $this->db->execute('UPDATE ' . $this->db->table('outbox') . ' SET state=?,next_at=?,lease_until=0,lease_token=NULL,error_code=? WHERE id=? AND lease_token=?', [$failed ? 'failed' : 'pending', $this->clock->now() + $delay, 'delivery_failed', $event['id'], $event['lease_token']]);
                ++$count[$failed ? 'failed' : 'retried'];
            }
        }
        return $count;
    }

    private function claim(): ?array
    {
        return $this->db->transaction(function (): ?array {
            $now = $this->clock->now();
            $event = $this->db->row('SELECT * FROM ' . $this->db->table('outbox') . " WHERE (state='pending' AND next_at<=?) OR (state='processing' AND lease_until<=?) ORDER BY created_at,id LIMIT 1" . $this->db->lockSuffix(), [$now, $now]);
            if ($event === null) {
                return null;
            }
            $event['lease_token'] = CommandContext::id();
            $event['attempts'] = (int) $event['attempts'] + 1;
            $this->db->execute('UPDATE ' . $this->db->table('outbox') . " SET state='processing',attempts=?,lease_until=?,lease_token=? WHERE id=?", [$event['attempts'], $now + 300, $event['lease_token'], $event['id']]);
            return $event;
        });
    }
}
