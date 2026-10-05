# Checkout design

The shipped gateway pays the full merchandise gross from eligible wallet credit. It never represents tender as a negative fee/coupon or modifies merchandise taxes/gross.

The server checks authenticated owner, order state/method, currency/gross and active account, stores an immutable allocation, reserves on a stable reference, captures once, then saves metadata and calls `payment_complete`. Advisory order locks serialize payment/cancellation/recovery; account row locks protect value. The capture journal commits before WooCommerce CRUD. The durable capture event and checkout retry recover an interrupted save using the original capture. Paid orders need the matching wallet transaction reference.

Expired holds/credit cannot capture. Cancellation/failure releases uncaptured funds or creates a real WooCommerce gateway refund for remaining captured value. Late callbacks recheck current state. Refunds need a persisted `WC_Order_Refund` with matching parent/amount, and both processing/hooks use that stable ID. Refund limits/provenance counters prevent replay/over-refund; original promotion expiry/flags persist.

Funding uses a private excluded order type and positive non-taxable funding line. Wallet cannot pay funding. Credit requires allowlisted paid gateway, external reference and matching top-up/order/owner/currency/gross. Offline bank/cheque/COD requires `wallet_credit`; the bank/cheque confirmation UI requires nonce, audited reason/reference and receipt confirmation. Consumed funding refunds freeze for review rather than debit unrelated lots.

Blocks reads private balance to control availability; the Store API uses the same authoritative gateway. JavaScript never decides financial state.

PayPal split tender persists gross/wallet/external snapshots, provider request/order/capture mappings and a processing lease. The gateway validates the submitted wallet/total quote before reserving. Only verified completed external capture can capture the wallet hold; expired/frozen reservations compensate confirmed external funds. Unknown mutations are not replayed. Known resources are inspected by callback/outbox/scheduler recovery. Customer return requires owner, nonce, order key and matching optional provider token. Old cancel links cannot refund paid orders.

Mixed refunds use cumulative integer proportions and original provenance. Pending intents consume the ceiling and block a new merchant request until resolved. A bounded adapter snapshot recovers WooCommerce refund CRUD after a pending/error response removes the original record. Delayed refunds do not automatically restock inventory. Pre-fulfillment cash compensation stays separate from merchandise sales refunds.

PayPal remains disabled by default. Local tests use protocol/provider fixtures and real WooCommerce CRUD/Store API; sandbox/live settlement and classic/Blocks PayPal browser acceptance remain open gates.
