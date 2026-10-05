# Ledger

Wallet liability is available + reserved + pending. Promotional/withdrawable values overlap these buckets and must not be added again. Each journal has one currency/exponent and equal debit/credit totals, with positive integer lines.

| Operation | Debit | Credit |
|---|---|---|
| Issue | System issuance | Wallet available/pending |
| Mature | Wallet pending | Wallet available |
| Reserve | Wallet available | Wallet reserved |
| Capture/debit | Wallet reserved/available | System spending |
| Release | Wallet reserved | Wallet available or system expiration |
| Expire | Wallet bucket | System expiration |
| Refund | System spending | Wallet available or system expiration |
| Reverse unspent funding | Wallet available | System issuance |
| Verified PayPal capture | System PayPal clearing | System external spending |
| Verified PayPal refund | System external spending | System PayPal clearing |

This is an operational financial ledger, not a statutory chart of accounts. Funding increases merchant liability, not merchandise revenue. Its custom order type is excluded from WooCommerce sales reports; third-party reporting adapters must apply the same distinction.

PayPal clearing records the verified **gross** external tender and its refunds once, linked to their original provider references. It represents an operational receivable; it does not assert bank payout, net merchant cash or fee reconciliation. Provider fees, settlement reports and accounting exports require a separately verified accounting adapter. System clearing lines do not change wallet liability or customer balances.

Account reconciliation checks split owner/allocation/hold binding, completed wallet capture, refund ceilings and the exact two system lines for verified capture/completed-refund journals, including operation/currency/exponent. A balanced journal with the wrong tender amount is still a discrepancy. A confirmed external refund waiting for its journal is a valid intermediate state. Discrepancies can freeze the wallet; reconciliation never rewrites provider records or financial history.

Application commands never update journal history. Corrections use new compensating commands with actor, reason and stable key. Projections, lots/consumptions, holds, audit and outbox commit with the journal. Database administrators remain trusted; this is not a cryptographic ledger.

The report uses ledger lines and UTC inclusive-start/exclusive-end periods, separately per currency: opening + credits − debits − expiration = closing. Internal reserve/maturity movements net to zero. Expired refunds never re-enter liability. Customer spendable display excludes expired lots immediately; accounting expiration is recorded when its journal job runs.
