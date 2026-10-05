<?php
declare(strict_types=1);

if (!getenv('WALLET_WP_PATH')) {
    throw new RuntimeException('Set WALLET_WP_PATH to an isolated WordPress installation.');
}
require rtrim(getenv('WALLET_WP_PATH'), '/') . '/wp-load.php';
require __DIR__ . '/bootstrap.php';
add_filter('pre_wp_mail', static fn () => true);

use WalletPlatform\Bootstrap\Plugin;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;

update_option('woocommerce_currency', 'USD');
$uid = wp_create_user('wallet_blocks_' . bin2hex(random_bytes(5)), wp_generate_password(32), 'blocks_' . bin2hex(random_bytes(5)) . '@example.invalid');
if (is_wp_error($uid)) {
    throw new RuntimeException($uid->get_error_message());
}
wp_set_current_user($uid);
wc_load_cart();
WC()->customer = new WC_Customer($uid, true);
$services = Plugin::services();
$account = $services->wallets->account($uid, Currency::of('USD'));
$services->wallets->credit($account['id'], new Money(10000, Currency::of('USD')), 'blocks:seed:' . $uid, 0, 'Store API test seed');
$product = new WC_Product_Simple();
$product->set_name('Blocks wallet test');
$product->set_regular_price('25.00');
$product->set_virtual(true);
$product->save();
WC()->cart->add_to_cart($product->get_id(), 1);
WC()->cart->calculate_totals();
$request = new WP_REST_Request('POST', '/wc/store/v1/checkout');
$request->set_header('Nonce', wp_create_nonce('wc_store_api'));
$request->set_body_params(['payment_method' => 'wallet_platform', 'billing_address' => ['first_name' => 'Wallet', 'last_name' => 'Test', 'address_1' => '1 Test Street', 'city' => 'Los Angeles', 'state' => 'CA', 'postcode' => '90210', 'country' => 'US', 'email' => 'blocks_' . $uid . '@example.invalid', 'phone' => '5551234567'], 'payment_data' => []]);
$response = rest_get_server()->dispatch($request);
if ($response->get_status() !== 200) {
    throw new RuntimeException('Store API checkout failed: ' . wp_json_encode($response->get_data()));
}
$data = $response->get_data();
same('success', $data['payment_result']['payment_status']);
same('75.00', $services->queries->balance($account['id'])['available']);
same(true, wc_get_order($data['order_id'])->is_paid());
same(true, $services->reconciliation->account($account['id'])['healthy']);
echo "PASS real Store API / Checkout Block payment with owner, nonce and committed capture\n";
