<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress;

final class Capabilities
{
    public const ALL = ['wallet_view', 'wallet_view_transactions', 'wallet_credit', 'wallet_debit', 'wallet_freeze', 'wallet_reports_view', 'wallet_export', 'wallet_settings_manage'];

    public static function install(): void
    {
        $admin = get_role('administrator');
        foreach (self::ALL as $capability) {
            $admin?->add_cap($capability);
        }
        $manager = get_role('shop_manager');
        foreach (['wallet_view', 'wallet_view_transactions', 'wallet_reports_view'] as $capability) {
            $manager?->add_cap($capability);
        }
    }
}
