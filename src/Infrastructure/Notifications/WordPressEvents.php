<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Notifications;

use WalletPlatform\Bootstrap\Services;

final class WordPressEvents
{
    public function __construct(private readonly Services $services)
    {
    }

    public function deliver(array $event): void
    {
        // Subscribers must deduplicate this stable event ID; delivery is at least once.
        do_action('wallet_platform_event', $event);
        if (get_option('wallet_platform_emails', 'yes') !== 'yes' || !in_array($event['event'], ['wallet.credited', 'wallet.debited', 'refund.processed'], true)) {
            return;
        }
        $id = $event['data']['account_id'] ?? null;
        if (!is_string($id)) {
            return;
        }
        $account = $this->services->queries->account($id);
        $user = get_userdata((int) $account['user_id']);
        if (!$user) {
            return;
        }
        $body = __('A wallet transaction has been recorded. Sign in to your account to see the committed balance and transaction history.', 'wallet-platform') . "\n" . wc_get_account_endpoint_url('wallet');
        if (!wp_mail($user->user_email, __('Your wallet has been updated', 'wallet-platform'), $body, ['X-Wallet-Event-ID: ' . $event['id']])) {
            throw new \RuntimeException('Wallet notification delivery failed.');
        }
    }
}
