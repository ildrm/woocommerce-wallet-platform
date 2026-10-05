# Compatibility targets

Research date 2026-10-04. Target PHP 8.2–8.5 (64-bit), WordPress 6.9+, WooCommerce 10.6+, MySQL 8.0+/MariaDB 10.6+, InnoDB and HTTPS. Target means intended, not tested certification. Runtime and CI evidence must record exact installed versions.

Use WooCommerce CRUD exclusively for orders; public Blocks payment integration and Store API checkout; WordPress capabilities/nonces, REST routes and privacy exporter/eraser. No direct order-table writes or internal WC namespaces.

Official sources: [server recommendations](https://woocommerce.com/document/server-requirements/), [HPOS recipe](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/), [Blocks integration](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/), [WordPress plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/), [security handbook](https://developer.wordpress.org/plugins/security/), [WCAG 2.2](https://www.w3.org/TR/WCAG22/).

Compatibility declarations and commercial release stay gated on real HPOS/legacy, classic/Blocks, order-pay, mixed-refund and browser accessibility tests. Subscriptions/marketplace integrations stay dormant unless their separately tested adapter is installed.

Local evidence: PHP 8.5.8, WordPress 7.1.2, WooCommerce 11.1.2, Twenty Twenty-Five 1.5 and MariaDB 11/InnoDB. Full-tender CRUD/refund/funding, Store API and test-provider split checkout/mixed-refund recovery ran in HPOS and legacy storage. Actual browser checkout checks cover full-wallet classic and Checkout Blocks. Customer wallet axe checks are scoped to the plugin surface and do not certify whole-theme or manual WCAG conformance. PayPal UI and real provider acceptance remain unverified. SQLite is development-only. Wider target versions/MySQL/merchant/provider combinations and the remaining product scope are unverified.
