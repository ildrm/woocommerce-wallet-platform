<?php
/**
 * Plugin Name: Wallet Platform for WooCommerce
 * Description: Ledger-first closed-loop wallet and store credit. Development release; see release gates.
 * Version: 0.1.0
 * Author: Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * WC requires at least: 10.6
 * Text Domain: wallet-platform
 * Domain Path: /languages
 * License: GPL-3.0-or-later
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/autoload.php';
\WalletPlatform\Bootstrap\Plugin::register(__FILE__);
