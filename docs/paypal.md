# PayPal provider

PayPal is the selected provider. The development split gateway and its server SDK are disabled by default. Local protocol and real WooCommerce tests use test-only provider responses. The automatic top-up workflow remains unimplemented. No sandbox/live charge has run.

PaymentProvider defines create/inspect/capture/refund, saved-payment charge and raw webhook verification. PayPalProvider uses OAuth, Orders v2, Payments v2, Vault v3 lookup and webhook postback verification. Fixed HTTPS hosts, TLS verification, no redirects, JSON size/depth limits and exact reference/amount/currency/merchant checks protect the boundary. Settlement requires completed order **and** completed capture. Pending refunds never claim success.

Keep credentials in server configuration/secret manager, never in tickets, repository or browser JavaScript:

```php
define('WALLET_PAYPAL_ENABLED', false); // Keep disabled until acceptance is complete.
define('WALLET_PAYPAL_ENVIRONMENT', 'sandbox');
define('WALLET_PAYPAL_CLIENT_ID', getenv('PAYPAL_CLIENT_ID'));
define('WALLET_PAYPAL_CLIENT_SECRET', getenv('PAYPAL_CLIENT_SECRET'));
define('WALLET_PAYPAL_MERCHANT_ID', getenv('PAYPAL_MERCHANT_ID'));
define('WALLET_PAYPAL_WEBHOOK_ID', getenv('PAYPAL_WEBHOOK_ID'));
define('WALLET_PAYPAL_VAULT_ELIGIBLE', false);
```

Disabled configuration makes zero HTTP calls. Debug/JSON output redacts credentials/tokens; generic PHP serialization is refused. Access tokens remain in process memory. Other WordPress HTTP-debug plugins must also redact authorization headers.

The caller must persist PaymentIntent/provider mappings before sending. Request IDs are deterministic and distinct per operation. Local durable idempotency remains necessary because PayPal retains IDs for a finite period. A timeout/5xx requires inspection of known resources; unknown create results need recovery/review, not a new blind request. [PayPal idempotency](https://developer.paypal.com/api/rest/reference/idempotency/).

Capture responses omit some order fields, so the SDK inspects full order data before accepting settlement. Reference/custom ID, merchant, gross/currency and capture amount must match. API links are never followed. Approval redirects accept only the environment-specific PayPal checkout host and returned order token. [Orders](https://developer.paypal.com/api/orders/v2), [Payments](https://developer.paypal.com/api/payments/v2).

HUF/JPY/TWD require whole provider units; fractional amounts reject rather than round. An explicit currency allowlist applies; merchant receiving preferences/country eligibility still require verification. [Currency rules](https://developer.paypal.com/reports/reference/supported-currencies).

Saved charges require server-owned consent, matching wallet/PayPal customer, independently retrieved vault token and explicitly configured eligibility. The contract is merchant/subsequent/unscheduled-prepaid. Enrollment, encrypted token persistence, thresholds/frequency caps, customer revocation and scheduler recovery are unimplemented, so no automatic charge runs. [Saved payment contract](https://developer.paypal.com/platforms/checkout/standard/customize/save-payment-methods-for-recurring-payments/).

Webhook postback validates configured webhook ID, trusted certificate URL, timestamp, original raw JSON and successful provider verification. The consumer durably records event ID/body hash, deduplicates, and inspects the mapped provider resource before queuing financial recovery. Verified refund events can bind a lost refund response to its original intent. [Signature verification](https://developer.paypal.com/api/rest/webhooks/rest/).

Release gates: real approval/capture/pending/timeout/refund, duplicates/reordering, late capture after expiry, frozen-wallet compensation, mixed refunds, consent/revocation, country/currency and classic/Blocks UI. Credentials alone do not certify these flows.

The split gateway keeps merchandise gross/tax unchanged, records wallet/external allocations, reserves eligible wallet value, and captures it only after external settlement. Three-minute leases serialize operations; unknown POST outcomes are not automatically replayed. Expired/frozen holds compensate confirmed external funds. Refunds use cumulative exact integer proportions, including cent remainders; outstanding intents consume the ceiling and block new merchant requests until resolved.

WooCommerce removes a refund record after a pending/error gateway response. A bounded adapter snapshot restores that CRUD record after external/wallet settlement, with a stable metadata link. Delayed recovery does not automatically restock inventory. Pre-fulfillment external compensation is recorded in the payment saga/order note and does not create a sales refund for an unpaid merchandise order.

Recovery revalidates the original payment transaction before recording a sales refund. If a crash leaves the refund's first save persisted, it reuses that record and repairs a fully refunded parent's status without another provider refund. Notification, download-permission and third-party hook effects after an interrupted WooCommerce pipeline require review; recovery does not replay arbitrary hooks. Verified captures/refunds also append balanced gross external-clearing journals. Those entries do not reconcile fees or net bank payouts; see [ledger](ledger.md).

Classic/Blocks publish wallet/external amounts before submission; the server validates the submitted amount/total snapshot. Owner, nonce, order key and optional provider token protect return/cancel. An old cancel link cannot cancel/refund a paid order. Staff can inspect records while PayPal is disabled; recovery requires wallet-credit capability, nonce and confirmation. Scheduled jobs inspect known resources one at a time.

PayPal checkout UI and real provider acceptance remain release gates. Configuring the SDK does not certify them.
