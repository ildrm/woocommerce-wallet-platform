# Adversarial review record

Sequential perspectives: Saboteur (failure/races), New Hire (operational clarity), Security Auditor (boundaries/replay). Scope: implemented financial kernel, WooCommerce full/split tender/funding, UI/API and disabled PayPal adapter. This is an internal review, not an independent security certification.

| Finding | Perspective | Resolution/evidence |
|---|---|---|
| Reusing one maturity/expiry key prevented the second lifecycle action | Saboteur | Distinct stage keys; mature-then-expire regression |
| Expired lots displayed as spendable until cron | Saboteur/UX | Eligibility-based display and capture expiry checks; regressions |
| Refund context could outlive deleted WooCommerce refund | Security | Persisted parent/reference revalidation; actual WC attack test |
| Paid order could be mistaken for recoverable wallet order | Saboteur/Security | Method/transaction/hold binding and order serialization; real integration |
| Crashed workers could exceed retry cap | Saboteur | Exhausted leases dead-letter; crash regression |
| Staff reason leaked into customer history | Security | Remove reason from owner endpoint/export; privileged history remains separate |
| Offline bank settlement needed explicit privileged confirmation | Security | wallet_credit plus receipt nonce/reason/reference/confirmation; customer/staff denial and browser flow |
| Reports needed integer aggregate and CSV formula handling | New Hire/Security | String aggregate formatting, formula neutralization/equation tests |
| Schema index used reserved SQL name and host-specific CLI helper | New Hire | Migration correction, actual InnoDB activation, portable helper |
| Full-page screenshot repeated a stale viewport after resize | UX | Reload and singular/card/overflow assertions; visual inspection |
| Provider mocks could be confused with real PayPal certification | All | Disabled SDK and explicit scope/provider acceptance gates |
| Funding recovery could loosen closed/suspended account restrictions | Security | Freeze only active/pending; restricted-state regressions |
| Full-wallet recovery could reuse a split allocation | Saboteur | Require external=0/full gross in full adapter; real WC rejection test |
| Old PayPal cancellation URL could refund a paid order | Security | Owner cancellation serializes current order and refuses paid/completed state; real WC test |
| Deleted pending WC refund could cause an accidental second remote refund | Saboteur/New Hire | Durable adapter snapshot, block new requests while unresolved, recover CRUD with metadata link; native WC fixture test |
| Checkout balance/total could change after the displayed quote | UX/Security | Submit amount/total snapshot and verify before reservation/remote mutation; adapter regressions |
| Refund recovery could record compensation against a changed payment transaction | Saboteur/Security | Revalidate transaction ID before sales-refund CRUD; fixture regression |
| Process could stop after a refund's first save and leave its parent paid | Saboteur | Reuse linked refund and restore fully refunded parent status; first-save-state regression |
| Generic object serialization could reveal saved-payment/configuration credentials | Security | Redacted JSON/debug output and refused PHP serialization; unit regression |
| Balanced external journals could carry wrong amounts and escape wallet-only reconciliation | Saboteur | Check exact split/capture/refund journal bindings and ceilings; corruption/freeze regressions |
| Provider payment projection was insufficient as an external financial trail | Financial/Saboteur | Idempotent balanced gross capture/refund clearing entries, linked to provider references |

Verdict: **BLOCK commercial production release**. Implemented local checks pass as recorded in testing documentation, but real PayPal/browser acceptance, automatic funding/advanced modules and independent/scale/manual accessibility gates remain incomplete. No claim of complete threat-model coverage or zero vulnerabilities is made.
