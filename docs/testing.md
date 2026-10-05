# Testing

Recorded local environment: PHP 8.5.8, WordPress 7.1.2, WooCommerce 11.1.2, Twenty Twenty-Five 1.5, MariaDB 11/InnoDB. SQLite tests are supplementary, not production concurrency certification. Hosted CI configuration exists but has not been run here.

- `php tests/run.php`: 39 financial cases, including money/currency/overflow/allocation, journal rejection, replay/conflict/rollback, provenance, holds/refunds, expiry, discrepancy freezing, funding reversal, atomic audit/outbox, worker crash cap, liability/CSV equation, split-payment/refund/webhook recovery and balanced external-journal corruption detection.
- `vendor/bin/phpunit`: money and PayPal protocol contract cases. HTTP test doubles exist only in tests; no remote success is fabricated in production.
- `php tests/concurrency.php`: separate processes race two 80 spends from 100, five duplicate credits and simultaneous over-refunds. Verify the same suite with real InnoDB.
- `WALLET_WP_PATH=/isolated/wordpress php tests/woocommerce.php`: actual CRUD/gateway/refund/funding/cancellation/post-capture recovery/ownership/role/privacy integration.
- `WALLET_WP_PATH=/isolated/wordpress php tests/store-api.php`: actual authenticated Store API checkout/capture/reconciliation.
- `php tools/verify-package.php`: source allowlist, manifest/file hashes and ZIP checksum. `WALLET_WP_PATH=/fresh/zip-installed/wordpress php tests/package.php` verifies the extracted package's activation, migrations, financial smoke/reconciliation, assets and disabled provider, without the checkout autoloader. CI also compares two package builds; the fresh-site gate is configured but hosted execution remains unverified.
- `WALLET_WP_PATH=/isolated/wordpress php tests/woocommerce-paypal.php`: actual split order/Store API/refund CRUD, amount snapshots, owner/nonce/token/stale-cancel checks, delayed refund recovery, first-save interruption and changed-transaction rejection with a test-only provider. This does not exercise real PayPal endpoints, approval, TLS or settlement.
- `npm run test:e2e`: actual customer/staff/funding and classic/Blocks checkout forms, committed balances, desktop/mobile/private request and scoped axe checks.

Use only disposable databases/sites. Standalone DSN/user/password environment variables create randomized-prefix test tables. WordPress fixtures create test users/products/orders and require a `wallet_test_admin` administrator. Never aim them at a live store. WP-CLI helper finds the local PHP/PHAR binary or `WALLET_WP_CLI_BINARY`.

Browser setup: install Chromium with `npx playwright install chromium` (or set WALLET_BROWSER_EXECUTABLE to an installed Chrome binary), run `php tools/wp.php eval-file tests/browser-fixture.php --path=/isolated/wordpress`, start `php -S 127.0.0.1:13317 -t /isolated/wordpress tests/router.php`, then run E2E. The fixture's site URL must match the server. Test-only passwords and data stay out of the distributed ZIP.

HPOS switching requires WooCommerce's official `wc hpos sync --user=wallet_test_admin` before updating woocommerce_custom_orders_table_enabled. Repeat real integration in both modes. Classic/Blocks browser tests change the isolated checkout page option and restore it in finally; workers are serial.

Static gates: PHP syntax, PHPStan level 5/full src with vendor stubs and no baseline, PSR12/limited WooCommerce naming exception, JavaScript syntax and esbuild. No tests were skipped to call a financial flow successful. Scope limits: PayPal sandbox/live and split checkout browser acceptance, automatic funding, advanced modules, large-history/performance, manual assistive tech and wide version/currency/tax/merchant scenarios remain open. The browser suite has six cases, including disabled-provider staff review and recovery denial; PayPal checkout UI browser coverage is not implied by the fixture-provider Store API tests.
