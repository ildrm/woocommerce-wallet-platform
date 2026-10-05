# Financial invariants

1. Money is a signed 64-bit integer of minor units with a bounded magnitude and explicit currency exponent. Arithmetic never overflows or implicitly converts currency.
2. Every journal balances within one currency. Lines have positive amounts and explicit debit/credit direction. Wallet liability increases on credit and decreases on debit.
3. Entries and lines are never updated or deleted by a runtime use case. Corrections are new entries.
4. For each account and bucket, projection equals credits minus debits. Available, reserved and pending are non-negative.
5. Eligible remaining lots equal available value; pending lots equal pending value. Reserved consumptions equal active held value. Lots preserve source, restrictions and expiration.
6. Posting, projection, lots, holds, audit, idempotency and outbox commit together or roll back together.
7. Account locks serialize spending and limit checks. Multi-account locks follow a deterministic order.
8. Same idempotency key and canonical command payload return the original result. Changed payload is a conflict, including actor and reason changes.
9. Holds reserve once, capture at most once and release only uncaptured value. Retry cannot change terminal state. Expired holds cannot capture.
10. Cumulative refund cannot exceed captured amount. Refund creates restricted replacement lots tied to original consumptions. It cannot wash promotional funds into cash.
11. Authoritative amounts come from validated commands or trusted order records. Clients cannot dictate reward, settlement or callback amount.
12. State and capability rules apply on the server. Frozen/suspended accounts permit refund, release and reconciliation but no customer spending or new funding. Closed accounts retain history and permit refund/release only.
13. Jobs have stable keys; failures are observable and retries cannot create duplicate value.
14. Ledger discrepancies cause financial commands to fail closed; repair must be explicit and audited, never silently erase a mismatch.
15. Verified split external captures/refunds have exact gross system journals bound to their original references. External and wallet refund totals each stay within their own original tender ceiling. Completed split orders require both external proof and the matching wallet capture; provider receipt acknowledgement alone cannot fulfill an order.
