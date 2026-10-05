# Merchant evaluation

Use an isolated backed-up staging store; this is a development build. Activate WooCommerce then Wallet Platform. Single-site/64-bit PHP/InnoDB are required. Default model is closed-loop store credit; transfers, withdrawals and marketplace settlements are unavailable.

1. Review **WooCommerce → Wallet settings** and store currency. Start with top-ups disabled.
2. In **Wallet**, choose an existing customer, amount, action and reason, then confirm. Staff reasons remain private; the committed result appears in the account table.
3. Sign in as the customer. **My Account → Wallet** shows available, reserved, pending and promotional value. Promotional is included in available.
4. Purchase a test product using Wallet. The full merchandise gross is paid from eligible credit. Insufficient, expired or frozen value cannot pay.
5. Refund through WooCommerce's order UI/gateway refund action. Stable refund IDs prevent duplication; original expiry/provenance survives.
6. Check **Wallet tools**. Scheduled reconciliation freezes discrepant accounts. Investigate before unfreezing; no tool rewrites financial history.

Funding needs an explicitly verified gateway allowlist. Customers request an amount and pay its private funding order; wallet cannot fund itself. Online callbacks must be trustworthy for that gateway. Bank/cheque receipt confirmation in **Wallet funding** requires wallet_credit, independent receipt verification, funding/bank reference, reason and explicit confirmation. Shop managers/support cannot adjust funds or confirm receipts by default.

If consumed funding is refunded externally, the wallet freezes for recovery review rather than debiting unrelated lots. Preserve the external dispute/order references and use a documented authorized recovery process.

PayPal split checkout is disabled by default and still needs real sandbox/browser acceptance. Its original merchandise total remains unchanged. **Wallet payments** shows the wallet/PayPal allocation, provider references and pending refunds; records stay readable when the adapter is disabled. Customers see their pending payment status in My Account. Do not collect another payment or issue a replacement refund while the original outcome is unknown.

When configured for staging evaluation, **Inspect and recover** requires wallet_credit permission, a valid form nonce, the original reference and confirmation. It can complete an approved payment or restore a confirmed refund. A pending provider refund can cause WooCommerce to remove its initial refund record; recovery restores that record using its durable snapshot. Delayed recovery does not restock inventory. After a process interruption, review download permissions, notifications and third-party refund hooks separately; those external effects cannot be proven once-only from the financial ledger.

Reports use UTC, exclusive end dates and separate currencies. Wallet funding is liability, not merchandise revenue. Keep Action Scheduler/WP-Cron running; monitor failed events. Deactivation/default uninstall preserve history. [Operations](operations.md) and [retention](privacy.md).
