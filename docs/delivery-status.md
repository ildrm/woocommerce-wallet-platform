# Delivery status

Updated 2026-10-05. This is a development implementation, not the completed commercial platform.

| Area | Implemented/exercised | Open release work |
|---|---|---|
| Foundation | Domain boundaries, threats/ADRs, service wiring, immutable integer money, currency metadata, clock/database ports, migrations | Wider compatibility matrix and module registry |
| Financial kernel | Double-entry journals, currency-isolated accounts, provenance, holds/refunds, durable idempotency, sorted account locks, audit/outbox, wallet/split gross-tender reconciliation and discrepancy freeze | Independent financial review, net provider/fee accounting and high-volume benchmarks |
| Staff/customer | My Account balances/history, private reasons, granular adjustments, settings, funding receipt confirmation | Rich explorer, receipts, bulk tools and manual screen-reader/RTL review |
| Funding | Private non-taxable orders, allowlisted gateway callback, privileged offline receipt, replay protection and spent-funding review | PayPal live/sandbox certification, automated chargeback/dispute recovery |
| Checkout | Full-wallet tender, CRUD/refunds, Store API, classic/Blocks assets, crash/cancellation recovery; disabled PayPal split gateway, mixed refund and delayed CRUD recovery with local fixtures | Real PayPal approval/settlement and checkout browser acceptance; order-pay/shipping/tax/coupon/currency matrix |
| PayPal | OAuth/order/inspect/capture/refund, durable provider mapping/leases, verified event deduplication, staff/customer pending status, saved-token/customer checks | Real credentials/provider certification; consent/enrollment/revocation, auto-top-up scheduler/customer controls |
| Operations | Bounded lifecycle/outbox, CSV liability reports, REST/CLI, diagnostics, privacy export, scheduled reconciliation | HTTP webhooks/signing/replay UI, accounting integrations, export automation, complete observability |
| Commercial features | Trusted API source-labelled credits with maturity/expiry | Rule engine, automatic cashback/clawback, rewards, vouchers/gifts, budgets, fees and advanced limits |
| Advanced modules | Separate currency accounts; transfer/withdraw provenance flags default false | Transfers/requests/withdrawals, FX snapshots, risk providers, vendor commissions/settlements/escrow/disputes |
| Delivery | Reproducible development ZIP, manifest verification, fresh extracted-package activation/financial smoke, CI configuration and operational documents | Hosted CI, separate paid add-ons, licensing/updater, signed releases, full acceptance/independent release approval |

A source label such as `cashback` is not an automatic cashback engine. Test-only HTTP/provider responses are not real provider settlement evidence. No real PayPal charge has run. Unimplemented financial actions are absent from the UI.

Next: complete PayPal real provider/UI acceptance, then automatic funding and commercial modules. Phases 6–10 remain open; do not claim the requested definition of done is met.
