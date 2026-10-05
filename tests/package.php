<?php
declare(strict_types=1);

// Run on a fresh isolated site using the extracted ZIP, without tests/bootstrap.php's source autoloader.
if (!getenv('WALLET_WP_PATH')) { throw new RuntimeException('Set an isolated WALLET_WP_PATH.'); }
require rtrim(getenv('WALLET_WP_PATH'), '/') . '/wp-load.php';
add_filter('pre_wp_mail', static fn () => true);
$source = (new ReflectionClass(\WalletPlatform\Bootstrap\Plugin::class))->getFileName();
if (!$source || str_starts_with(realpath($source), realpath(dirname(__DIR__) . '/src') . '/')) { throw new RuntimeException('Package test loaded the source checkout instead of the ZIP.'); }
$services = \WalletPlatform\Bootstrap\Plugin::services();
$currency = \WalletPlatform\Domain\Shared\Currency::of('USD');
$account = $services->wallets->account(1234567, $currency)['id'];
$services->wallets->credit($account, new \WalletPlatform\Domain\Shared\Money(10000, $currency), 'package:credit', 1, 'Isolated ZIP acceptance');
$hold = $services->holds->reserve($account, new \WalletPlatform\Domain\Shared\Money(3000, $currency), 'package:order', time() + 300, 'package:reserve', 1234567);
$services->holds->capture($hold['hold_id'], 'package:capture', 1234567);
$services->refunds->refund($hold['hold_id'], new \WalletPlatform\Domain\Shared\Money(3000, $currency), 'package:refund', 1, 'Isolated ZIP refund');
if ($services->queries->balance($account)['available'] !== '100.00' || !$services->reconciliation->account($account)['healthy']) { throw new RuntimeException('ZIP financial acceptance failed.'); }
$db = $services->db;
$count = (int) $db->row('SELECT COUNT(*) AS count FROM ' . $db->table('migrations'))['count'];
if ($count !== \WalletPlatform\Infrastructure\Database\Schema::VERSION) { throw new RuntimeException('ZIP migration registry differs.'); }
$pluginRoot = dirname($source, 3);
foreach (['assets/frontend/blocks.min.js', 'assets/frontend/paypal.min.js', 'languages/wallet-platform.pot', 'source-manifest.json'] as $file) {
    if (!is_file($pluginRoot . '/' . $file)) { throw new RuntimeException('ZIP asset missing: ' . $file); }
}
if (\WalletPlatform\Infrastructure\Integrations\PayPal\Factory::configuration()->enabled) { throw new RuntimeException('Fresh ZIP unexpectedly enables PayPal.'); }
echo "PASS fresh extracted ZIP activation, migrations, credit/reserve/capture/refund/reconciliation and bundled assets; PayPal disabled\n";
