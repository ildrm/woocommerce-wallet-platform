# Wallet Platform for WooCommerce

Ledger-first closed-loop wallet and store credit. **0.1.0 development build: the complete requested commercial platform is not finished or production certified.** See [delivery status](docs/delivery-status.md) and [release gates](docs/release-process.md).

Implemented: integer money, balanced journals, provenance lots, holds/capture/release, refunds, limits, idempotency, locking, reconciliation, audited staff actions, customer wallet/history, private funding orders, full wallet checkout, Blocks/Store API, REST/CLI, reports, privacy export and post-commit event delivery. PayPal split checkout, durable recovery and proportional mixed refunds are implemented with local protocol/WooCommerce tests and remain disabled by default. Real provider/browser acceptance and automatic top-up remain outstanding.

Install only on an isolated backed-up single-site WordPress/WooCommerce evaluation store with 64-bit PHP 8.2+, mysqli/mysqlnd and InnoDB. Copy to `wp-content/plugins/woocommerce-wallet` or install the development ZIP from `php tools/package.php`. Activate WooCommerce then this plugin; review **WooCommerce → Wallet settings** and perform a purchase/refund/reconciliation. [Merchant guide](docs/merchant-guide.md).

```sh
composer install
npm ci
composer test
composer test:phpunit
php tests/concurrency.php
composer lint
vendor/bin/phpstan analyse --debug --memory-limit=1G --no-progress
vendor/bin/phpcs
npm run check
npm run build
```

No runtime Composer dependencies are required. The standalone suite uses SQLite by default; production concurrency also needs real MySQL/MariaDB evidence. Set `WALLET_TEST_DSN`, `WALLET_TEST_USER` and `WALLET_TEST_PASSWORD` for a disposable database only. Real WordPress tests need a separate installed site and `WALLET_WP_PATH`. [Testing guide](docs/testing.md).

[Architecture](docs/architecture.md) · [invariants](docs/financial-invariants.md) · [schema](docs/database-schema.md) · [ledger](docs/ledger.md) · [checkout](docs/checkout-design.md) · [API](docs/api.md) · [extension API](docs/extension-points.md) · [PayPal](docs/paypal.md) · [security](docs/security.md) · [privacy](docs/privacy.md) · [compatibility](docs/compatibility.md) · [operations](docs/operations.md) · [editions](docs/commercial-editions.md).

Deactivation and default uninstall preserve financial records. GPL-3.0-or-later; see [LICENSE](LICENSE).
