# Wallet Platform specification

Specification date: 2026-10-04. The repository begins empty. Safety order: security, financial integrity, clarity, recovery, compatibility, accessibility, performance, maintainability.

## Assumptions and delivery gates

The default is a closed-loop merchant liability. No external payout, currency conversion, regulated escrow, automatic card charge, or subscription fallback is implied. Unknown providers fail closed. Monetary API amounts are canonical decimal strings, never localized values or floats. PHP requires 64-bit integers. All timestamps are UTC seconds.

The release must not be advertised as production ready until the acceptance matrix below has passed against real WordPress/WooCommerce and InnoDB, including parallel workers. Compatibility declarations require that evidence. An installable development package is distinguishable from a commercial release.

| Phase | Scope | Acceptance |
|---|---|---|
| 0 | Specification, research, ADRs, threat model | Decisions documented before code |
| 1 | Bootstrap, currency/money, migrations, persistence | Strict parsing, overflow, atomic rollback |
| 2 | Ledger, lots, holds, refund, idempotency, audit | Balanced entries, no double spend, reconciliation, fault injection |
| 3 | Admin/customer UI, funding | Capability and ownership tests, trusted paid-order funding |
| 4–5 | Checkout, Blocks, order-pay, refunds | Real WC checkout/refund tests; split only with a certified adapter |
| 6 | Rules, cashback, rewards, expiration, vouchers, gifts | Provenance, maturity, budget and redemption races |
| 7 | REST, CLI, events, reports, diagnostics | Auth, pagination, CSV safety, retry/outbox recovery |
| 8–9 | Transfers, requests, payout, FX, marketplace | Provider settlement and chargeback scenarios |
| 10 | Editions, updater, onboarding, release | Packaging, compatibility, security and accessibility gates |

Core includes security and integrity regardless of edition. Paid distributions consume the core API and never suppress refunds or reconciliation due to expired entitlement. Every advanced feature needs executable acceptance evidence; provider contracts are not equivalent to shipped integrations.

## Personas and information architecture

Customers: balance with available, reserved, pending and promotional breakdown; understandable history; funding confirmation with amount and currency. Merchants: liability dashboard, searchable wallets, reasoned credit/debit confirmation, transactions, reconciliation, diagnostics and settings. Support: read-only capabilities. Finance: opening/closing liability by currency and CSV. Advanced modules are absent from menus until installed and enabled.

All actions use server-confirmed balances, labelled inputs, visible focus, text statuses, keyboard operation, responsive tables, logical CSS properties for RTL, and translated strings. Target WCAG 2.2 AA; manual assistive-technology review is a release gate.
