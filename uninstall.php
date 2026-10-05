<?php
declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
// Financial history is retained unless the merchant explicitly configures both independent guards.
if (get_option('wallet_platform_delete_data', 'no') !== 'yes' || !defined('WALLET_PLATFORM_CONFIRM_DELETE') || WALLET_PLATFORM_CONFIRM_DELETE !== true || is_multisite()) {
    return;
}
require_once __DIR__ . '/autoload.php';
global $wpdb;
$db = \WalletPlatform\Infrastructure\Database\WpdbDatabase::forSite($wpdb);
foreach (\WalletPlatform\Infrastructure\Database\Schema::TABLES as $table) {
    $db->execute('DROP TABLE IF EXISTS ' . $db->table($table));
}
foreach (['wallet_platform_delete_data', 'wallet_platform_checkout', 'wallet_platform_topups', 'wallet_platform_topup_gateways', 'wallet_platform_emails', 'wallet_platform_reconcile_cursor'] as $option) {
    delete_option($option);
}
foreach (['administrator', 'shop_manager'] as $roleName) {
    $role = get_role($roleName);
    foreach (\WalletPlatform\WordPress\Capabilities::ALL as $capability) {
        $role?->remove_cap($capability);
    }
}
