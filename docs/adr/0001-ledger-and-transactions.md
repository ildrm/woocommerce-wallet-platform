# ADR 0001: Integer ledger and atomic account locks

Accepted. Integer minor units bound to currency, balanced insert-only journals, separate projections and credit provenance. All financial tables use InnoDB. Account locks serialize correctness and limits. Stable idempotency keys are retained indefinitely with financial history. The SQLite adapter is for local tests only and uses BEGIN IMMEDIATE; it does not prove InnoDB behavior.

Foreign references are explicit indexed IDs validated by application/reconciliation. No cascaded deletion of ledger history. Schema migration DDL is resumable because MySQL DDL commits implicitly; version advances only after full validation under an advisory lock. Deadlock retries are bounded and only encompass rolled-back local transactions.
