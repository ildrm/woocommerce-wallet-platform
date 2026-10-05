# REST and CLI

Namespace `/wp-json/wallet-platform/v1`. Cookies require a valid `X-WP-Nonce` for `wp_rest`; server clients may use WordPress Application Passwords over HTTPS. Nonces are not capabilities. Wallet responses are private/no-store.

| Route | Access |
|---|---|
| GET `/wallet`, `/wallet/transactions` | Authenticated owner; own currency wallet; history excludes private staff reason |
| GET `/wallets`, `/wallets/{id}` | wallet_view |
| GET `/wallets/{id}/transactions` | wallet_view_transactions; includes reason |
| POST `/wallets/{id}/credit` | wallet_credit |
| POST `/wallets/{id}/debit` | wallet_debit |
| POST `/wallets/{id}/state` | wallet_freeze |
| POST `/paypal/webhook` | Only registered when PayPal is configured; public receipt with provider signature verification and bound-resource inspection |

Mutations need `Idempotency-Key` (1–120 ASCII letters/digits/colon/underscore/hyphen), scoped to actor. Same payload/operation/key returns the committed original result; a conflict rejects. Keep the same request/key after timeouts. Credits/debits require positive canonical currency-specific decimal **strings**, plus reason; state requires active/frozen/suspended/closed and reason.

```json
{"amount":"10.00","reason":"Customer service adjustment"}
```

History uses `limit` 1–100 and newest-first `before_time`/`before_id`; continue from the last row's UTC `created_at` and 32-character `id`. Account listing uses ascending `after` ID, up to 100. Opaque IDs never replace access checks. Errors: 400 validation, 401 anonymous, 403 permission, 404 missing, 409 domain/conflict, 503 infrastructure; no SQL or credentials are exposed.

The PayPal webhook preserves the raw JSON body and requires the five PayPal transmission/signature headers. A 200 response acknowledges a verified/deduplicated receipt; it does not declare financial settlement. Invalid input/signatures return 400; transient provider or processing failures return 503. Financial recovery inspects the original mapped provider resource. No raw body or credentials are returned. Configure the sandbox webhook URL only after enabling the server adapter in an isolated HTTPS test store; see [PayPal](paypal.md).

CLI: `wp wallet balance USER_ID`, `transactions USER_ID`, `credit USER_ID AMOUNT`, `debit USER_ID AMOUNT`, `freeze USER_ID`, `unfreeze USER_ID`, `reconcile [--after=ACCOUNT_ID]`, `migrate`, `expire`, `diagnostics`. Mutations need authorized `--user`, `--key` and `--reason`. Read-only CLI is trusted local administration. Reconcile checks at most 100 accounts, fails on discrepancies, and never repairs. Transfers/payouts/vouchers/outbound webhooks have no public routes yet.
