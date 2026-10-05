# Security

Mutations require granular capability and CSRF controls; owner queries resolve the signed-in owner server-side. Nonces never authorize a role. SQL uses bound native parameters and table allowlists; UI escapes output and CSV neutralizes formula prefixes.

Replayable commands persist key/payload/result; conflicts reject. Sorted account locks guard lots/holds/projections and journals balance. Audit/outbox commit atomically; transaction retries contain no external effects. Refunds use persisted WooCommerce references and provenance counters. Offline funding requires privileged independent receipt confirmation.

PayPal uses fixed verified-TLS hosts, no redirects, bounded responses and merchant/reference/amount checks. Certificate URLs are validated, not fetched. A verified webhook still requires durable deduplication/resource inspection. Saved tokens require owner consent/eligibility; automatic charging remains disabled.

Trusted plugins, WordPress administrators and DB operators can alter PHP/data and are inside the trust boundary. Services are trusted server APIs, not automatic authorization. Secure installation access, secret storage, logs and consistent backups.

Report vulnerabilities privately to the maintainer through an agreed secure channel. Include version and redacted reproduction, excluding customer details and active tokens. Freeze affected accounts, preserve evidence, stop the affected flow and reconcile before restarting. [Threat model](security-threat-model.md), [review](security-review.md), [runbook](operations.md).
