# Schema

Version 4; `{site_prefix}wallet_{name}`, InnoDB, utf8mb4/binary collation, UTC seconds, random 128-bit hex IDs, integer minor-unit BIGINT amounts. Aggregate monetary sums remain strings where they exceed an account bound.

| Table | Purpose/constraints |
|---|---|
| accounts | Unique owner/currency, exponent/state, nonnegative available/reserved/pending and version |
| entries/lines | Immutable unique command reference, operation/actor/reason/currency/time and balanced positive journal lines |
| lots | Source, original/remaining, maturity/expiry, provenance parent/reference, transfer/withdraw flags |
| consumptions | Lot/account/entry/hold, state and bounded refund counters |
| holds | Unique account/business reference, deadline, state, amount/captured/refunded |
| allocations | Unique order/attempt, immutable gross = wallet + external, account/currency/hold/deadline; full wallet or PayPal split |
| topups | Bound owner-account/amount/currency/order, originating lot, state and refunded counter |
| payments | Durable owner/allocation/provider-order/capture mapping, settlement proof, cancellation, mutation markers and lease |
| payment_refunds | Unique business reference, immutable gross/wallet/external allocation, provider refund, state and bounded adapter recovery snapshot |
| provider_events | Verified event ID/body hash, mapping and receipt state; no raw provider payload/token persistence |
| audit | Append-only action/actor/reason/reference and safe context |
| idempotency | Unique key hash, payload digest and original committed result; no automatic financial key expiry |
| outbox | Atomic event ID/payload, state, attempts, schedule, lease/token and safe error code |
| migrations | Completion registry; newer versions cannot downgrade |

Indexes serve owner/currency, journal entry/account/bucket, lot eligibility/expiry, hold state/expiry and outbox delivery paths. Foreign references are enforced through locked application transactions and reconciliation, not cross-WordPress foreign keys. Never write WooCommerce order tables directly; use order/refund CRUD.

Commands lock idempotency then accounts in sorted ID order. Lot/hold writers inherit that account lock. Native prepared mysqli statements use a dedicated connection to prevent unrelated WordPress commits/reconnects from breaking atomicity. Bounded deadlock retries repeat SQL transactions only; network effects never run inside them.

Future transfer/request/payout/rule/voucher/vendor tables are unimplemented. Their migrations belong to remaining scope. Mutable payment projections never replace the append-only financial journal/audit.
