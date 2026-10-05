# Extension API

`WalletPlatform\Bootstrap\Plugin::services()` is available after `plugins_loaded` priority 20. It exposes wallet, hold, refund, query, top-up, lifecycle and reconciliation services. Domain/application code has no WordPress/WooCommerce dependencies. Ports: Database, Clock, HttpTransport and PaymentProvider.

```php
use WalletPlatform\Bootstrap\Plugin;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;

// Authorize actor and business operation before invoking this trusted server API.
$services = Plugin::services();
$currency = Currency::of('USD');
$account = $services->wallets->account($customerId, $currency);
$result = $services->wallets->credit(
    $account['id'], Money::fromDecimal('10.00', $currency),
    'campaign:immutable-business-event-id', $actorId,
    'Authorized promotion grant', 'promotion', null, $expiresAt
);
```

An actor ID does not authenticate a caller. Adapters must verify ownership/capabilities/consent, then normalize inputs. Never expose services through an unauthenticated proxy. Business keys must identify the immutable event, not callback attempt.

Use holds->reserve/capture/release and refunds->refund for provenance. queries->transactions uses timestamp/ID pagination. reconciliation->account($id) is read-only; passing true as second argument atomically freezes discrepant active/pending accounts and is reserved for authorized automation. It never edits journals/balances.

`wallet_platform_event` is post-commit and at least once. Exceptions retry the delivery chain; subscribers must deduplicate event ID/use stable keys. Keep network calls outside kernel/SQL transaction closures. Direct table writes are unsupported. PayPal uses a separate typed provider port and disabled factory; see [its settlement contract](paypal.md).

License state may never prevent existing balances, releases/refunds/reconciliation or statements from functioning. Add-ons need explicit dependency/schema compatibility and their own tests.
