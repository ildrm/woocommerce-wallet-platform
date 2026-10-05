# Privacy and retention

Records contain WordPress owner/actor IDs, wallet IDs, currencies/amounts, credit provenance/eligibility, financial/payment references, command results, timestamps and privileged reason/audit context. Emails/names are resolved from WordPress for transactional notifications, not duplicated in the ledger. No PAN/CVV or telemetry is collected.

The WordPress privacy helper/exporter/eraser are registered. Export includes owner balances, ledger activity, credit lots and owner-bound split payment/refund amounts, states and provider references, in bounded 100-row pages per group. Private staff reasons, adapter snapshots, raw webhook bodies and credentials are excluded. These are paginated live reads, not a forensic snapshot. Use a consistent backup for point-in-time evidence, and avoid account changes/deletion during export.

The eraser reports retained financial records. Retention obligations depend on the merchant's jurisdiction/model; this is not a legal determination. Closing a wallet prevents new business and retains restorative operations/history; it does not erase the WordPress user.

Deactivation/default uninstall preserve records. Destructive uninstall needs both wallet_platform_delete_data=yes and WALLET_PLATFORM_CONFIRM_DELETE === true, and is refused on multisite. Only named plugin tables/options/capabilities are dropped; WooCommerce orders remain. Obtain merchant authorization and a consistent backup before enabling the guards. No ordinary settings screen exposes destructive deletion.

Protect backups/private exports, restrict capabilities and keep customer/audit information/tokens out of support tickets. Diagnostics omit secrets/contact details. Saved-token enrollment/storage is not enabled; future PayPal integration needs additional processing/consent documentation.
