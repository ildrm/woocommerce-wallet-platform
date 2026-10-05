# Events and webhooks

Financial commands append an outbox event atomically. Delivery occurs outside the transaction. WordPress action `wallet_platform_event` receives `{id,event,created_at,data}` at least once. Every consumer must durably deduplicate event ID and use stable financial keys.

Events: wallet.created/credited/debited/state_changed, funds.reserved/captured/released, refund.processed, credit.matured/expired, wallet.reconciliation_failed. Payloads use opaque references and monetary snapshots. Transaction emails are post-commit effects; delivery failure never reverses value.

Workers claim bounded batches with a five-minute random lease, compare-and-set acknowledgement, exponential failure delay and ten-attempt terminal failure, including exhausted crashed leases. Diagnostics/logs show failures; do not delete financial history to repair delivery.

Outbound HTTP webhook registration/signing/delivery/replay is **not shipped**. Extensions still need SSRF defenses, explicit endpoint configuration, rotating secrets, timestamped HMAC, safe payload filtering, stable IDs and audited replay. A local WordPress action is not equivalent.

PayPal's SDK verifies raw incoming signatures, but no public listener is registered. The future listener must persist event IDs, inspect mapped resources, validate reference/merchant/amount/currency and issue a stable command. Redirect success and verified webhook data alone are insufficient settlement proof. See [PayPal](paypal.md).
