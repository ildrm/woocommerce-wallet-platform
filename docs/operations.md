# Operations

Back up users/options, WooCommerce orders/refunds, every wallet table and server secrets consistently. Test restore in isolation. Ledger/projections/lots/holds/idempotency/mappings/outbox must recover together.

`wp wallet diagnostics` and **Wallet tools** report versions/schema/scheduler/queue without credentials/contact details. Monitor WooCommerce wallet-platform logs, Action Scheduler failures and pending funding. Logs expose safe classes/references, not SQL/provider bodies.

Action Scheduler runs every five minutes (hourly WP-Cron fallback): up to 100 lifecycle records, 20 reconciliation accounts and 25 events per pass. A cursor advances reconciliation; discrepancies freeze active/pending accounts. Spendable display excludes expiry immediately, but jobs must post expiry journals/release holds.

| Failure | Recovery |
|---|---|
| Adjustment timeout | Inspect result/history; retry original key/payload, never a new adjustment |
| Interrupted order CRUD | Capture event or checkout retry completes same capture; changed mapping/gross requires review |
| Cancellation/failure | Recheck current status; release hold or actual WooCommerce refund |
| Funding mismatch | No credit; inspect bound owner/currency/gross/gateway receipt |
| Spent funding refunded | Freeze/review; no unrelated-lot clawback or fabricated negative value |
| Discrepancy | Preserve evidence; compare ledger/projections/provenance/order; compensating command only after root-cause review |
| Failed event | Commit stays valid; fix consumer and review stable ID; manual replay UI is not shipped |
| PayPal uncertainty | Keep reference; Wallet payments shows state/allocation/provider IDs. Enabled adapter jobs inspect known resources. Staff recovery requires wallet_credit/nonce/confirmation; unknown mutations require original-resource/provider assistance, not a replacement request. Auto-top-up remains absent. |

Support bundles contain version/schema/scheduler, safe error code, opaque references and redacted reproduction. Exclude contacts, private notes, unnecessary balances, DB/provider secrets, authorization headers and tokens.
