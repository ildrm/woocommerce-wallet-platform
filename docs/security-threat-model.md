# Threat model

Trust boundaries: authenticated customer → REST/UI; staff → privileged commands; WooCommerce settled order → wallet funding; application → database; outbox → external webhook; extension/provider → core API.

| Threat | Control and required evidence |
|---|---|
| Double spend/limit race | InnoDB row locks, consistent lock order, atomic lots/ledger; parallel-process tests |
| Callback/refund replay | Payload-bound idempotency plus unique business references; duplicate/conflict tests |
| IDOR | Server-resolved owner, no customer-supplied target account; cross-user tests |
| CSRF/escalation | WP REST authentication/nonces and granular capabilities; capability+nonce tests |
| Injection/XSS | Bound SQL values, whitelisted table identifiers, contextual escaping; malicious inputs |
| Floating-point/overflow | String parser, checked bounded integer arithmetic; exponent/large-value tests |
| Voucher guessing | Cryptographic random code, hashed storage, uniform error, atomic rate/redemption counters |
| Promotional laundering | Source restrictions carried through refunds/transfers; provenance tests |
| Lost commit/worker crash | Atomic outbox, leases, stable event IDs, reconciliation after uncertain outcome |
| SSRF/webhook leakage | HTTPS safe HTTP API, allowlist, signatures, secret redaction, bounded retries |
| Unauthorized data deletion | Retention default; explicit destructive opt-in; uninstall guard |
| Malicious admin/database | Reasoned audit, least privilege, diagnostics; DB administrator is trusted |
| Supply chain | Locked development dependencies, runtime dependency-free loader, CI checks |

No card/CVV/OTP/payout secret is stored by core. No telemetry by default. Detailed internal exceptions never reach customer HTTP responses. Provider success cannot be simulated. Release review includes security, financial replay/races, and manual accessibility evidence.
