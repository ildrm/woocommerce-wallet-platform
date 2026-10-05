# Migrations

Migration 1 creates transactional tables/indexes; migration 2 adds funding reversal counters; migration 3 creates provider payment/refund/event mappings and settlement proof; migration 4 adds bounded refund adapter recovery context. Completion is recorded after DDL/engine checks. An advisory lock prevents concurrent runs, additive creation/column migration resumes safely, and newer schemas cannot downgrade.

Activation migrates before accepting wallet operations. A version mismatch fails bootstrap closed with an admin notice. `wp wallet migrate` remains available for recovery when normal bootstrap cannot initialize. Use a consistent full backup and staging verification before upgrade.

Migration 2 requests an instant additive MySQL/MariaDB column change; unsupported capabilities fail explicitly rather than silently rewrite a large table. New indexes on existing tables can still be costly; high-volume online migration certification remains a gate. No upgrade drops financial history or expires financial keys automatically.

Correct the failure and retry the same migration; inspect registry/columns/indexes, diagnostics and reconciliation. Never restore wallet tables against different order/user snapshots. Downgrade requires restoring a consistent full backup.
