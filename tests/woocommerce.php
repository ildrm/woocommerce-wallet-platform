<?php
declare(strict_types=1);

if (!getenv('WALLET_WP_PATH')) {
    fwrite(STDERR, "Set WALLET_WP_PATH to an isolated WordPress installation.\n");
    exit(1);
}
require rtrim(getenv('WALLET_WP_PATH'), '/') . '/wp-load.php';
add_filter('pre_wp_mail', static fn () => true);
require __DIR__ . '/bootstrap.php';

use WalletPlatform\Bootstrap\Plugin;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\WooCommerce\Blocks\PaymentMethod;
use WalletPlatform\WooCommerce\Orders\TopUps;

update_option('woocommerce_currency', 'USD');
$services = Plugin::services();
$uid = wp_create_user('wallet_customer_' . bin2hex(random_bytes(5)), wp_generate_password(32), 'wallet_' . bin2hex(random_bytes(5)) . '@example.invalid');
if (is_wp_error($uid)) {
    throw new RuntimeException($uid->get_error_message());
}
wp_set_current_user($uid);
$currency = Currency::of('USD');
$account = $services->wallets->account($uid, $currency);
$id = $account['id'];
$services->wallets->credit($id, new Money(10000, $currency), 'wc:test:seed:' . $uid, 0, 'Integration test seed');
$product = new WC_Product_Simple();
$product->set_name('Wallet integration product');
$product->set_regular_price('30.00');
$product->set_virtual(true);
$product->save();
$order = wc_create_order(['customer_id' => $uid]);
$order->add_product($product, 1);
$order->set_payment_method('wallet_platform');
$order->calculate_totals();
$gateway = WC()->payment_gateways()->payment_gateways()['wallet_platform'];
same('success', $gateway->process_payment($order->get_id())['result']);
same('70.00', $services->queries->balance($id)['available']);
$fresh = wc_get_order($order->get_id());
same(true, $fresh->is_paid());
same('30.00', $fresh->get_total('edit'));
same('success', $gateway->process_payment($order->get_id())['result']);
same('70.00', $services->queries->balance($id)['available']);
echo "PASS real WooCommerce order payment and retry, merchandise total preserved\n";
$refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '10.00', 'reason' => 'Partial return', 'refund_payment' => true]);
if (is_wp_error($refund)) {
    throw new RuntimeException('Refund failed: ' . $refund->get_error_message());
}
same('80.00', $services->queries->balance($id)['available']);
do_action('woocommerce_order_refunded', $order->get_id(), $refund->get_id());
same('80.00', $services->queries->balance($id)['available']);
$second = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '20.00', 'reason' => 'Remaining return', 'refund_payment' => true]);
if (is_wp_error($second)) {
    throw new RuntimeException('Second refund failed: ' . $second->get_error_message());
}
same('100.00', $services->queries->balance($id)['available']);
same(true, $services->reconciliation->account($id)['healthy']);
echo "PASS partial and full gateway refunds, duplicate hook and reconciliation\n";
$blocks = new PaymentMethod(dirname(__DIR__) . '/woocommerce-wallet.php');
$blocks->initialize();
same(true, $blocks->is_active());
same(['wallet-platform-blocks'], $blocks->get_payment_method_script_handles());
same(['products'], $blocks->get_payment_method_data()['supports']);
echo "PASS real Blocks server integration and asset registration\n";

update_option('wallet_platform_topup_gateways', ['bacs']);
$funding = $services->topups->create($id, new Money(1000, $currency), 'wc:topup:test:' . $uid, $uid);
$topups = new TopUps($services);
$fundingOrder = $topups->order($funding['topup_id']);
same('wallet_topup', $fundingOrder->get_type());
same(true, wc_get_order_type('wallet_topup')['exclude_from_order_sales_reports']);
same($fundingOrder->get_id(), $topups->order($funding['topup_id'])->get_id());
$fundingOrder->set_payment_method('bacs');
$fundingOrder->save();
same('100.00', $services->queries->balance($id)['available']);
$fundingOrder->payment_complete('bank-settlement-test-' . $uid);
same('100.00', $services->queries->balance($id)['available']);
$administrator = get_user_by('login', 'wallet_test_admin');
if (!$administrator) { throw new RuntimeException('Isolated test admin is required.'); }
wp_set_current_user((int) $administrator->ID);
$topups->settled($fundingOrder->get_id());
same('110.00', $services->queries->balance($id)['available']);
$topups->settled($fundingOrder->get_id());
same('110.00', $services->queries->balance($id)['available']);
$fundingRefund = wc_create_refund(['order_id' => $fundingOrder->get_id(), 'amount' => '5.00', 'reason' => 'Funding return', 'refund_payment' => false]);
if (is_wp_error($fundingRefund)) {
    throw new RuntimeException('Funding refund failed: ' . $fundingRefund->get_error_message());
}
same('105.00', $services->queries->balance($id)['available']);
same(true, $services->reconciliation->account($id)['healthy']);
wp_set_current_user($uid);
echo "PASS bound funding order, verified settlement, replay and funding reversal\n";

$cancelled = wc_create_order(['customer_id' => $uid]);
$cancelled->add_product($product, 1);
$cancelled->set_payment_method('wallet_platform');
$cancelled->calculate_totals();
same('success', $gateway->process_payment($cancelled->get_id())['result']);
same('75.00', $services->queries->balance($id)['available']);
$cancelled = wc_get_order($cancelled->get_id());
$cancelled->update_status('cancelled');
same('105.00', $services->queries->balance($id)['available']);
$payments = new \WalletPlatform\WooCommerce\Orders\OrderPayments($services);
$payments->onFailure($cancelled->get_id());
same('105.00', $services->queries->balance($id)['available']);
same(true, $services->reconciliation->account($id)['healthy']);
echo "PASS cancellation compensates an actual capture once through WooCommerce refund CRUD\n";

$interrupted = wc_create_order(['customer_id' => $uid]);
$interrupted->add_product($product, 1);
$interrupted->set_payment_method('wallet_platform');
$interrupted->calculate_totals();
$allocationId = \WalletPlatform\Application\CommandContext::id();
$expires = $services->clock->now() + 1800;
$reservation = $services->holds->reserve($id, new Money(3000, $currency), 'wc:allocation:' . $allocationId, $expires, 'wc:reserve:' . $allocationId, $uid);
$services->db->insert('allocations', ['id' => $allocationId, 'order_id' => $interrupted->get_id(), 'attempt' => 1, 'account_id' => $id, 'hold_id' => $reservation['hold_id'], 'gross' => 3000, 'wallet_amount' => 3000, 'external_amount' => 0, 'currency' => 'USD', 'expires_at' => $expires, 'created_at' => $services->clock->now()]);
$services->holds->capture($reservation['hold_id'], 'wc:capture:' . $allocationId, $uid);
same(false, wc_get_order($interrupted->get_id())->is_paid());
// Simulate process death after the committed capture and before saving order metadata.
$payments->recoverCapture($reservation['hold_id']);
same(true, wc_get_order($interrupted->get_id())->is_paid());
$payments->recoverCapture($reservation['hold_id']);
same('75.00', $services->queries->balance($id)['available']);
same(true, $services->reconciliation->account($id)['healthy']);
echo "PASS post-capture crash recovery finishes the order without another debit\n";

$stale = new WC_Order_Refund();
$stale->set_parent_id($interrupted->get_id());
$stale->set_amount('1.00');
$stale->save();
do_action('woocommerce_create_refund', $stale);
$stale->delete(true);
same(true, is_wp_error($gateway->process_refund($interrupted->get_id(), '1.00', 'Stale reference attack')));
same('75.00', $services->queries->balance($id)['available']);
echo "PASS deleted refund context cannot create wallet value\n";

$server = rest_get_server();
$request = new WP_REST_Request('GET', '/wallet-platform/v1/wallet');
same(200, $server->dispatch($request)->get_status());
$foreign = $services->wallets->account($uid + 1000000, $currency);
$request = new WP_REST_Request('GET', '/wallet-platform/v1/wallets/' . $foreign['id']);
same(403, $server->dispatch($request)->get_status());
$request = new WP_REST_Request('POST', '/wallet-platform/v1/wallets/' . $id . '/credit');
$request->set_body_params(['amount' => '100.00', 'reason' => 'Malicious customer credit']);
$request->set_header('Idempotency-Key', 'attack');
same(403, $server->dispatch($request)->get_status());
$privacy = new \WalletPlatform\WordPress\Privacy($services);
$export = $privacy->export(get_userdata($uid)->user_email);
same(true, $export['done']);
same(true, count($export['data']) > 5);
same(true, $privacy->erase(get_userdata($uid)->user_email)['items_retained']);
same([], $privacy->export('no-wallet@example.invalid')['data']);
echo "PASS privacy export includes ledger and provenance; erasure retains financial records\n";
$staff = wp_create_user('wallet_staff_' . bin2hex(random_bytes(5)), wp_generate_password(32));
(new WP_User($staff))->set_role('shop_manager');
wp_set_current_user($staff);
same(true, current_user_can('wallet_view'));
same(false, current_user_can('wallet_credit'));
same(403, $server->dispatch($request)->get_status());
echo "PASS support role can inspect but cannot adjust wallet funds\n";
wp_set_current_user(0);
$request = new WP_REST_Request('GET', '/wallet-platform/v1/wallet');
same(401, $server->dispatch($request)->get_status());
echo "PASS REST ownership and customer mutation denial\n";
echo 'Environment: WordPress ' . get_bloginfo('version') . ', WooCommerce ' . WC_VERSION . ', PHP ' . PHP_VERSION . ', HPOS=' . (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no') . "\n";
