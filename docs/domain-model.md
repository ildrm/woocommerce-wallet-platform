# Domain model

Currency: ISO-style three-letter code and exponent 0–4. Money: minor units, immutable arithmetic, deterministic half-up percentage rounding and allocation with remainders distributed in input order.

Wallet account: owner, currency, lifecycle state, available/reserved/pending projection and version. Promotional/withdrawable totals are derived from eligible lots, not additional overlapping spend buckets.

Journal: immutable reference, operation, currency, lines and timestamp. System clearing accounts represent issuance, spending, expiration and settlement. This is an operational liability ledger rather than statutory bookkeeping.

Credit lot: source, original/remaining value, eligibility/maturity, expiry, withdrawability/transferability, original reference. Default consumption order is earliest expiry (no expiry last), creation time, ID. Refunds restore provenance and original expiry; expired restored value is posted to expiration, never made spendable.

Hold: account, exact amount, reference, deadline, state, capture and refund totals. Created atomically as HELD. Terminal transitions: CAPTURED, RELEASED, EXPIRED. Partial capture is supported as separate allocations only when certified checkout adapters are shipped.

Transaction: public random reference and command result linked to journal, optional hold/order/refund reference. Audit records carry actor, action, reason and correlation reference; financial detail stays in private tables. Outbox records have stable event IDs, leases, retry counters and terminal failure visibility.

States: PENDING permits activation/admin funding; ACTIVE permits normal closed-loop operations; FROZEN and SUSPENDED prohibit new spending/funding but accept refunds and release; CLOSED prohibits new business, preserves history and accepts restorative operations. State changes require a reason and privileged actor.
