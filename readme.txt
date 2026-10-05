=== Wallet Platform for WooCommerce ===
Contributors: ildrm
Tags: woocommerce, wallet, store-credit
Requires at least: 6.9
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Ledger-first closed-loop wallet. Development evaluation build; commercial gates remain open.

== Description ==
Integer money, double-entry journals, provenance, holds, full wallet checkout/refunds, private funding, audited staff actions, REST/CLI, reporting and reconciliation.

PayPal split checkout and mixed refund recovery are included and disabled by default. Real provider/browser acceptance, automatic top-up and advanced campaigns/vouchers/transfers/payout/FX/marketplace/licensing remain outstanding. This is not a production-certified platform.

== Installation ==
Use a backed-up isolated single-site WooCommerce store with 64-bit PHP/InnoDB. Activate WooCommerce, activate this plugin, review Wallet settings and test purchase/refund/reconciliation. Read included merchant/operations guides.

== Retention ==
Deactivation/default uninstall preserve history. Full deletion needs explicit stored option and server constant; WooCommerce orders are retained.

== Changelog ==
= 0.1.0 =
Initial development core/PayPal SDK. See CHANGELOG.md and docs/delivery-status.md.
