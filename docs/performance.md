# Performance

Presentation/history use bounded keyset pages; lot consumption caps fragmentation at 1,000; lifecycle, outbox and reconciliation use bounded batches. Owner/currency, journal entry/account/bucket, lot eligibility/expiry, hold state/expiry and delivery indexes serve current queries. Aggregate values stay integer strings and currencies never collapse into one total.

No million-record/latency SLO is certified. Reports and reconciliation aggregate history, privacy pages use offsets, and default reconciliation throughput is 20 accounts/pass. Before production, measure realistic million-entry skew, concurrent checkout/refund, fragmentation, worker overlap and export pressure on each database: p50/p95/p99, examined rows, lock waits/deadlocks and memory. Then justify projections/archival changes.

Do not cache private balances in shared CDN/page caches or remove locking/idempotency for speed. A bounded PHP loop does not prove bounded SQL cost. Size reconciliation/job throughput against store population and business recovery expectations.
