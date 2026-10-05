<?php
declare(strict_types=1);

add_filter('pre_wp_mail', static fn () => true);
WC_Install::create_pages();
update_option('woocommerce_currency', 'USD');
update_option('woocommerce_coming_soon', 'no');
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();
$user = get_user_by('login', 'wallet_test_customer');
if (!$user) {
    $id = wp_create_user('wallet_test_customer', 'isolated-test-customer', 'wallet-browser@example.invalid');
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $user = get_user_by('id', $id);
}
$user->set_role('customer');
$services = \WalletPlatform\Bootstrap\Plugin::services();
$currency = \WalletPlatform\Domain\Shared\Currency::of('USD');
$account = $services->wallets->account((int) $user->ID, $currency);
$available = (int) $account['available'];
if ($available !== 5000) {
    $method = $available < 5000 ? 'credit' : 'debit';
    $services->wallets->$method($account['id'], new \WalletPlatform\Domain\Shared\Money(abs(5000 - $available), $currency), 'browser:reset:' . bin2hex(random_bytes(8)), 0, 'Browser QA fixture');
}
$fixtures = ['customer_id' => (int) $user->ID];
$product = new WC_Product_Simple();
$product->set_name('Browser wallet checkout product');
$product->set_regular_price('25.00');
$product->set_virtual(true);
$product->save();
$fixtures['product_id'] = $product->get_id();
foreach (['blocks', 'classic', 'funding'] as $flow) {
    $login = 'wallet_qa_' . $flow . '_' . bin2hex(random_bytes(4));
    $id = wp_create_user($login, 'isolated-checkout-test', $login . '@example.invalid');
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    (new WP_User($id))->set_role('customer');
    $customer = new WC_Customer($id);
    $customer->set_billing_first_name('Browser');
    $customer->set_billing_last_name('Buyer');
    $customer->set_billing_email($login . '@example.invalid');
    $customer->set_billing_address_1('1 QA Street');
    $customer->set_billing_city('Los Angeles');
    $customer->set_billing_state('CA');
    $customer->set_billing_postcode('90210');
    $customer->set_billing_country('US');
    $customer->set_billing_phone('5551234567');
    $customer->save();
    $qaAccount = $services->wallets->account($id, $currency);
    $fixtures[$flow] = ['login' => $login, 'account_id' => $qaAccount['id']];
    if ($flow === 'funding') {
        wp_set_current_user($id);
        $funding = $services->topups->create($qaAccount['id'], new \WalletPlatform\Domain\Shared\Money(1000, $currency), 'browser:funding:' . $login, $id);
        $fundingOrder = (new \WalletPlatform\WooCommerce\Orders\TopUps($services))->order($funding['topup_id']);
        $fundingOrder->set_payment_method('bacs');
        $fundingOrder->set_status('on-hold');
        $fundingOrder->save();
        $fixtures['funding']['topup_id'] = $funding['topup_id'];
        wp_set_current_user(0);
    } else {
        $services->wallets->credit($qaAccount['id'], new \WalletPlatform\Domain\Shared\Money(5000, $currency), 'browser:checkout:' . $login, 0, 'Browser checkout QA fixture');
    }
}
$fixtures['blocks_page_id'] = (int) get_option('woocommerce_checkout_page_id');
$classic = get_page_by_path('classic-wallet-checkout');
if (!$classic) {
    $classicId = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Classic wallet checkout', 'post_name' => 'classic-wallet-checkout', 'post_content' => '[woocommerce_checkout]']);
} else { $classicId = $classic->ID; }
$fixtures['classic_page_id'] = (int) $classicId;
file_put_contents(__DIR__ . '/.runtime/browser-env.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
echo 'Browser fixture customer ID: ' . $user->ID . "\n";
