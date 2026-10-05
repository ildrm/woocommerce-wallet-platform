# Release gates

Current artifact class: **development evaluation**. tools/package.php builds a source-only installable ZIP/SHA-256 manifest; it neither certifies nor publishes.

`tools/verify-package.php` rejects unexpected entries/hash mismatches. Repeated builds must have the same archive hash for a fixed source/epoch. `tests/package.php` runs with the extracted ZIP on a fresh isolated WordPress/WooCommerce site and refuses to load the checkout source. These checks are configured in CI and have also run locally; hosted CI has not run here.

Run domain/concurrency/real database/WooCommerce/Store API tests, lint/static/style/build, security/browser/accessibility checks and artifact installation. Locks pin dependencies. CI configuration is not evidence of a hosted passing run.

Production commercial release requires:

- All open [delivery scope](delivery-status.md) implemented, or explicit owner-approved scope revision.
- Real PayPal split/mixed-refund/uncertainty and automatic-funding consent/revocation gates.
- Declared PHP/WP/WC/database matrices, HPOS on/off, classic/Blocks/order-pay and shipping/tax/coupon cases.
- Independent financial/security review, manual assistive-technology/keyboard/RTL/mobile QA, dependency audit and scale/chaos benchmarks.
- Migration/backup/restore/uninstall retention, scheduler/delivery recovery, support and licensing/add-on/updater boundaries.
- Exact artifact/source manifest inspection, fresh installation/activation and smoke/reconciliation, no secrets/test credentials/caches/runtime dependencies.
- Changelog/docs/evidence/compatibility record and authorized release owner approval before publishing.

Do not claim production readiness from activation, compilation or protocol mocks. Existing financial safety continues through entitlement changes/deactivation.
