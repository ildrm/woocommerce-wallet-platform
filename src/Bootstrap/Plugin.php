<?php

declare(strict_types=1);

namespace WalletPlatform\Bootstrap;

use WalletPlatform\Application\Policies\Limits;
use WalletPlatform\Application\OutboxProcessor;
use WalletPlatform\Infrastructure\Notifications\WordPressEvents;
use WalletPlatform\Infrastructure\Database\Schema;
use WalletPlatform\Infrastructure\Database\WpdbDatabase;
use WalletPlatform\Infrastructure\SystemClock;
use WalletPlatform\WooCommerce\Blocks\PaymentMethod;
use WalletPlatform\WooCommerce\Gateway\WalletGateway;
use WalletPlatform\WooCommerce\Orders\OrderPayments;
use WalletPlatform\WooCommerce\Orders\TopUps;
use WalletPlatform\WordPress\Admin\AdminPage;
use WalletPlatform\WordPress\Admin\SettingsPage;
use WalletPlatform\WordPress\Admin\ReportsPage;
use WalletPlatform\WordPress\Admin\ToolsPage;
use WalletPlatform\WordPress\Admin\FundingPage;
use WalletPlatform\WordPress\Admin\PaymentsPage;
use WalletPlatform\WordPress\Capabilities;
use WalletPlatform\WordPress\Cli\Commands;
use WalletPlatform\WordPress\Customer\WalletPage;
use WalletPlatform\WordPress\Privacy;
use WalletPlatform\WordPress\Rest\Controller;
use WalletPlatform\Application\SplitPaymentService;
use WalletPlatform\Infrastructure\Integrations\PayPal\Factory as PayPalFactory;
use WalletPlatform\WooCommerce\Orders\PayPalOrders;
use WalletPlatform\WooCommerce\Gateway\PayPalGateway;
use WalletPlatform\WooCommerce\Blocks\PayPalMethod;
use WalletPlatform\WordPress\Rest\PayPalController;

final class Plugin
{
    private static ?Services $services = null;

    public static function register(string $file): void
    {
        register_activation_hook($file, static function (bool $networkWide): void {
            if ($networkWide || is_multisite()) {
                wp_die(esc_html__('This development build requires a single-site installation.', 'wallet-platform'));
            }
            if (PHP_INT_SIZE < 8 || !class_exists('WooCommerce')) {
                wp_die(esc_html__('Wallet Platform requires 64-bit PHP and active WooCommerce.', 'wallet-platform'));
            }
            global $wpdb;
            Schema::migrate(WpdbDatabase::forSite($wpdb));
            Capabilities::install();
            add_rewrite_endpoint('wallet', EP_ROOT | EP_PAGES);
            flush_rewrite_rules();
        });
        register_deactivation_hook($file, static function (): void {
            wp_clear_scheduled_hook('wallet_platform_maintenance');
            wp_unschedule_hook('wallet_platform_paypal_payment');
            wp_unschedule_hook('wallet_platform_paypal_refund');
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('wallet_platform_maintenance', [], 'wallet-platform');
                as_unschedule_all_actions('wallet_platform_paypal_payment', [], 'wallet-platform');
                as_unschedule_all_actions('wallet_platform_paypal_refund', [], 'wallet-platform');
            }
            flush_rewrite_rules();
        });
        add_action('plugins_loaded', static function () use ($file): void {
            if (!class_exists('WooCommerce') || PHP_INT_SIZE < 8) {
                return;
            }
            try {
                global $wpdb;
                $db = WpdbDatabase::forSite($wpdb);
                $version = $db->row('SELECT MAX(version) AS version FROM ' . $db->table('migrations'));
                if ((int) ($version['version'] ?? 0) !== Schema::VERSION) {
                    throw new \RuntimeException('Wallet schema requires migration.');
                }
                $services = new Services($db, new SystemClock(), new Limits());
                self::$services = $services;
                $payments = new OrderPayments($services);
                $payments->register();
                add_action('wallet_platform_event', static function (array $event) use ($payments): void {
                    if ($event['event'] === 'funds.captured' && isset($event['data']['hold_id'])) {
                        $payments->recoverCapture((string) $event['data']['hold_id']);
                    }
                });
                (new TopUps($services))->register();
                add_filter('woocommerce_payment_gateways', static function (array $gateways) use ($services, $payments): array {
                    $gateways[] = new WalletGateway($services, $payments);
                    return $gateways;
                });
                add_action('woocommerce_blocks_payment_method_type_registration', static fn ($registry) => $registry->register(new PaymentMethod($file)));
                $paypalOrders = self::registerPayPal($services, $file);
                (new PaymentsPage($services, $paypalOrders))->register();
                (new WalletPage($services))->register();
                (new AdminPage($services))->register();
                (new SettingsPage($services))->register();
                (new ReportsPage($services))->register();
                (new ToolsPage($services))->register();
                (new FundingPage($services))->register();
                (new Privacy($services))->register();
                add_action('rest_api_init', [(new Controller($services)), 'register']);
                add_action('wp_enqueue_scripts', static function () use ($file): void {
                    if (is_account_page()) {
                        wp_enqueue_style('wallet-platform', plugins_url('assets/frontend/wallet.css', $file), [], '0.1.0');
                    }
                });
                add_action('admin_enqueue_scripts', static function (string $hook) use ($file): void {
                    if (in_array($hook, ['woocommerce_page_wallet-platform', 'woocommerce_page_wallet-platform-settings', 'woocommerce_page_wallet-platform-reports', 'woocommerce_page_wallet-platform-tools', 'woocommerce_page_wallet-platform-funding', 'woocommerce_page_wallet-platform-payments'], true)) {
                        wp_enqueue_style('wallet-platform', plugins_url('assets/frontend/wallet.css', $file), [], '0.1.0');
                    }
                });
                add_action('init', static function (): void {
                    if (function_exists('as_schedule_recurring_action')) {
                        if (!as_has_scheduled_action('wallet_platform_maintenance', [], 'wallet-platform')) {
                            as_schedule_recurring_action(time() + 60, 300, 'wallet_platform_maintenance', [], 'wallet-platform', true);
                        }
                    } elseif (!wp_next_scheduled('wallet_platform_maintenance')) {
                        wp_schedule_event(time() + 60, 'hourly', 'wallet_platform_maintenance');
                    }
                });
                add_action('wallet_platform_maintenance', static function () use ($services): void {
                    try {
                        $services->lifecycle->run();
                        $after = (string) get_option('wallet_platform_reconcile_cursor', '');
                        $batch = $services->queries->accounts(20, $after);
                        foreach ($batch as $account) {
                            $services->reconciliation->account($account['id'], true);
                        }
                        update_option('wallet_platform_reconcile_cursor', count($batch) === 20 ? end($batch)['id'] : '', false);
                        (new OutboxProcessor($services->db, $services->clock, [new WordPressEvents($services), 'deliver']))->run();
                    } catch (\Throwable $error) {
                        wc_get_logger()->error('Wallet maintenance failed: ' . get_class($error), ['source' => 'wallet-platform']);
                        throw $error;
                    }
                });
                if (defined('WP_CLI') && WP_CLI) {
                    \WP_CLI::add_command('wallet', new Commands($services));
                }
            } catch (\Throwable $error) {
                if (defined('WP_CLI') && WP_CLI) {
                    \WP_CLI::add_command('wallet migrate', static function (): void {
                        global $wpdb;
                        Schema::migrate(WpdbDatabase::forSite($wpdb));
                        \WP_CLI::success('Wallet schema migration complete.');
                    });
                }
                add_action('admin_notices', static function (): void {
                    if (current_user_can('activate_plugins')) {
                        echo '<div class="notice notice-error"><p>' . esc_html__('Wallet Platform is unavailable. Review the database schema and environment before accepting wallet payments.', 'wallet-platform') . '</p></div>';
                    }
                });
                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->error('Wallet bootstrap failed: ' . get_class($error), ['source' => 'wallet-platform']);
                }
            }
        }, 20);
    }

    /** Trusted server-side extension API. Callers must enforce their own actor authorization. */
    public static function services(): Services
    {
        return self::$services ?? throw new \RuntimeException('Wallet Platform is not initialized.');
    }

    private static function registerPayPal(Services $services, string $file): ?PayPalOrders
    {
        try {
            $config = PayPalFactory::configuration();
            if (!$config->enabled) {
                return null;
            }
            $saga = new SplitPaymentService($services->wallets, $services->holds, $services->refunds, PayPalFactory::create($services->clock));
            $orders = new PayPalOrders($services, $saga);
            $orders->register();
            (new PayPalController($orders))->register();
            add_filter('woocommerce_payment_gateways', static function (array $gateways) use ($services, $orders): array {
                $gateways[] = new PayPalGateway($services, $orders, true);
                return $gateways;
            });
            add_action('woocommerce_blocks_payment_method_type_registration', static fn ($registry) => $registry->register(new PayPalMethod($file, true)));
            add_action('wallet_platform_event', static function (array $event) use ($orders, $services): void {
                $data = $event['data'];
                if (in_array($event['event'], ['split.completed', 'paypal.resource_verified'], true)) {
                    $orders->recover((string) $data['payment_id']);
                    if (!empty($data['refund_id'])) {
                        $orders->recoverRefund((string) $data['refund_id']);
                    }
                } elseif ($event['event'] === 'split.refunded') {
                    $orders->recoverRefund((string) $data['refund_id']);
                } elseif ($event['event'] === 'split.compensated') {
                    $records = $services->db->rows('SELECT id FROM ' . $services->db->table('payment_refunds') . " WHERE payment_id=? AND state='completed' ORDER BY id LIMIT 100", [$data['payment_id']]);
                    foreach ($records as $refund) {
                        $orders->recoverRefund($refund['id']);
                    }
                }
            });
            add_action('wallet_platform_paypal_payment', static fn (string $id) => $orders->recover($id));
            add_action('wallet_platform_paypal_refund', static fn (string $id) => $orders->recoverRefund($id));
            add_action('wallet_platform_maintenance', static function () use ($services): void {
                $db = $services->db;
                $payments = $db->rows('SELECT id FROM ' . $db->table('payments') . " WHERE provider_order IS NOT NULL AND state NOT IN ('completed','cancelled','compensated') ORDER BY updated_at,id LIMIT 20");
                $refunds = $db->rows('SELECT id FROM ' . $db->table('payment_refunds') . " WHERE state IN ('prepared','pending','external_settled','requesting') AND (provider_refund IS NOT NULL OR (started_at IS NULL AND adapter_context IS NOT NULL)) ORDER BY created_at,id LIMIT 20");
                foreach (['wallet_platform_paypal_payment' => $payments, 'wallet_platform_paypal_refund' => $refunds] as $hook => $records) {
                    foreach ($records as $record) {
                        $args = [$record['id']];
                        if (function_exists('as_enqueue_async_action')) {
                            as_enqueue_async_action($hook, $args, 'wallet-platform', true);
                        } elseif (!wp_next_scheduled($hook, $args)) {
                            wp_schedule_single_event(time() + 1, $hook, $args);
                        }
                    }
                }
            });
            return $orders;
        } catch (\Throwable $error) {
            wc_get_logger()->error('PayPal wallet adapter is unavailable: ' . get_class($error), ['source' => 'wallet-platform']);
            add_action('admin_notices', static function (): void {
                if (current_user_can('wallet_settings_manage')) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('PayPal wallet payments are unavailable. Review the server configuration before accepting split payments.', 'wallet-platform') . '</p></div>';
                }
            });
            return null;
        }
    }
}
