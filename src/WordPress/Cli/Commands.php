<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Cli;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Wallet\WalletState;
use WalletPlatform\Infrastructure\Database\Schema;

final class Commands
{
    public function __construct(private readonly Services $services)
    {
    }

    public function balance(array $args): void
    {
        $id = $this->owner($args)['id'];
        \WP_CLI::line(wp_json_encode($this->services->queries->balance($id)));
    }

    public function transactions(array $args): void
    {
        \WP_CLI::line(wp_json_encode($this->services->queries->transactions($this->owner($args)['id'])));
    }

    public function credit(array $args, array $options): void
    {
        $this->adjust('credit', $args, $options);
    }

    public function debit(array $args, array $options): void
    {
        $this->adjust('debit', $args, $options);
    }

    public function freeze(array $args, array $options): void
    {
        $this->state(WalletState::Frozen, $args, $options);
    }

    public function unfreeze(array $args, array $options): void
    {
        $this->state(WalletState::Active, $args, $options);
    }

    public function reconcile(array $args, array $options): void
    {
        $after = (string) ($options['after'] ?? '');
        $healthy = true;
        foreach ($this->services->queries->accounts(100, $after) as $account) {
            $report = $this->services->reconciliation->account($account['id']);
            \WP_CLI::line(wp_json_encode($report));
            $healthy = $healthy && $report['healthy'];
        }
        if (!$healthy) {
            \WP_CLI::error('Reconciliation discrepancies found. No automatic repair performed.');
        }
    }

    public function migrate(): void
    {
        Schema::migrate($this->services->db);
        \WP_CLI::success('Wallet schema migration complete.');
    }

    public function expire(): void
    {
        \WP_CLI::line(wp_json_encode($this->services->lifecycle->run()));
    }

    public function diagnostics(): void
    {
        $db = $this->services->db;
        $failed = $db->row('SELECT COUNT(*) AS n FROM ' . $db->table('outbox') . " WHERE state='failed'");
        \WP_CLI::line(wp_json_encode(['plugin' => '0.1.0', 'php' => PHP_VERSION, 'wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION, 'schema' => Schema::VERSION, 'database' => $db->driver(), 'failed_events' => (int) ($failed['n'] ?? 0), 'scheduler' => function_exists('as_schedule_recurring_action') ? 'action-scheduler' : 'wp-cron', 'modules' => ['closed-loop-core'], 'production_certified' => false]));
    }

    private function owner(array $args): array
    {
        $user = get_user_by('id', (int) ($args[0] ?? 0));
        if (!$user) {
            \WP_CLI::error('Specify an existing numeric user ID.');
        }
        return $this->services->wallets->account((int) $user->ID, Currency::of(get_woocommerce_currency()));
    }

    private function authorize(string $capability, array $options): void
    {
        if (!current_user_can($capability)) {
            \WP_CLI::error('Use --user with an account possessing ' . $capability . '.');
        }
        if (empty($options['key']) || empty($options['reason'])) {
            \WP_CLI::error('Provide --key and --reason for an audited idempotent command.');
        }
    }

    private function adjust(string $operation, array $args, array $options): void
    {
        $this->authorize('wallet_' . $operation, $options);
        $account = $this->owner($args);
        $money = Money::fromDecimal((string) ($args[1] ?? ''), new Currency($account['currency'], (int) $account['exponent']));
        $result = $this->services->wallets->$operation($account['id'], $money, 'cli:' . get_current_user_id() . ':' . $options['key'], get_current_user_id(), (string) $options['reason']);
        \WP_CLI::line(wp_json_encode($result));
    }

    private function state(WalletState $state, array $args, array $options): void
    {
        $this->authorize('wallet_freeze', $options);
        \WP_CLI::line(wp_json_encode($this->services->wallets->setState($this->owner($args)['id'], $state, 'cli:' . get_current_user_id() . ':' . $options['key'], get_current_user_id(), (string) $options['reason'])));
    }
}
