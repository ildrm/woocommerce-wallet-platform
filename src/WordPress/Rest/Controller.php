<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Rest;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\Domain\Wallet\WalletState;

final class Controller
{
    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        $customer = static fn (): bool => is_user_logged_in();
        register_rest_route('wallet-platform/v1', '/wallet', ['methods' => 'GET', 'permission_callback' => $customer, 'callback' => fn () => $this->respond(fn () => $this->services->queries->balance($this->ownAccount()['id']))]);
        register_rest_route('wallet-platform/v1', '/wallet/transactions', ['methods' => 'GET', 'permission_callback' => $customer, 'args' => $this->pagination(), 'callback' => fn (\WP_REST_Request $request) => $this->respond(fn () => $this->history($this->ownAccount()['id'], $request))]);
        register_rest_route('wallet-platform/v1', '/wallets', ['methods' => 'GET', 'permission_callback' => static fn (): bool => current_user_can('wallet_view'), 'args' => ['limit' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100], 'after' => ['type' => 'string', 'default' => '', 'pattern' => '^[a-f0-9]{0,32}$']], 'callback' => fn (\WP_REST_Request $request) => $this->respond(fn () => $this->services->queries->accounts((int) $request['limit'], (string) $request['after']))]);
        register_rest_route('wallet-platform/v1', '/wallets/(?P<id>[a-f0-9]{32})', ['methods' => 'GET', 'permission_callback' => static fn (): bool => current_user_can('wallet_view'), 'callback' => fn (\WP_REST_Request $request) => $this->respond(fn () => $this->services->queries->balance($request['id']))]);
        register_rest_route('wallet-platform/v1', '/wallets/(?P<id>[a-f0-9]{32})/transactions', ['methods' => 'GET', 'permission_callback' => static fn (): bool => current_user_can('wallet_view_transactions'), 'args' => $this->pagination(), 'callback' => fn (\WP_REST_Request $request) => $this->respond(fn () => $this->services->queries->transactions($request['id'], (int) $request['limit'], (int) $request['before_time'], (string) $request['before_id']))]);
        foreach (['credit', 'debit'] as $operation) {
            register_rest_route('wallet-platform/v1', '/wallets/(?P<id>[a-f0-9]{32})/' . $operation, [
                'methods' => 'POST', 'permission_callback' => static fn (): bool => current_user_can('wallet_' . $operation),
                'args' => ['amount' => ['type' => 'string', 'required' => true, 'pattern' => '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$'], 'reason' => ['type' => 'string', 'required' => true, 'minLength' => 3, 'maxLength' => 500]],
                'callback' => fn (\WP_REST_Request $request) => $this->respond(function () use ($request, $operation): array {
                    $account = $this->services->queries->account($request['id']);
                    $currency = new Currency($account['currency'], (int) $account['exponent']);
                    $amount = $request->get_param('amount');
                    if (!is_string($amount)) {
                        throw new WalletException('invalid_amount', 'Amount must be a decimal string.');
                    }
                    return $this->services->wallets->$operation($request['id'], Money::fromDecimal($amount, $currency), $this->key($request), get_current_user_id(), sanitize_textarea_field($request['reason']));
                }),
            ]);
        }
        register_rest_route('wallet-platform/v1', '/wallets/(?P<id>[a-f0-9]{32})/state', [
            'methods' => 'POST', 'permission_callback' => static fn (): bool => current_user_can('wallet_freeze'),
            'args' => ['state' => ['type' => 'string', 'required' => true, 'enum' => ['active', 'frozen', 'suspended', 'closed']], 'reason' => ['type' => 'string', 'required' => true, 'minLength' => 3, 'maxLength' => 500]],
            'callback' => fn (\WP_REST_Request $request) => $this->respond(fn () => $this->services->wallets->setState($request['id'], WalletState::from($request['state']), $this->key($request), get_current_user_id(), sanitize_textarea_field($request['reason']))),
        ]);
    }

    private function ownAccount(): array
    {
        return $this->services->wallets->account(get_current_user_id(), Currency::of(get_woocommerce_currency()));
    }

    private function key(\WP_REST_Request $request): string
    {
        $key = $request->get_header('Idempotency-Key');
        if ($key === '' || strlen($key) > 120 || !preg_match('/^[A-Za-z0-9:_-]+$/D', $key)) {
            throw new WalletException('invalid_command', 'Provide a stable Idempotency-Key header.');
        }
        return 'rest:' . get_current_user_id() . ':' . $key;
    }

    private function pagination(): array
    {
        return ['limit' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100], 'before_time' => ['type' => 'integer', 'default' => PHP_INT_MAX, 'minimum' => 0], 'before_id' => ['type' => 'string', 'default' => 'ffffffffffffffffffffffffffffffff', 'pattern' => '^[a-f0-9]{32}$']];
    }

    private function history(string $id, \WP_REST_Request $request): array
    {
        return array_map(static function (array $transaction): array {
            unset($transaction['reason']);
            return $transaction;
        }, $this->services->queries->transactions($id, (int) $request['limit'], (int) $request['before_time'], (string) $request['before_id']));
    }

    private function respond(callable $work): \WP_REST_Response|\WP_Error
    {
        try {
            return new \WP_REST_Response($work(), 200, ['Cache-Control' => 'private, no-store']);
        } catch (WalletException $error) {
            return new \WP_Error($error->errorCode, $error->getMessage(), ['status' => $error->errorCode === 'account_not_found' ? 404 : (str_contains($error->errorCode, 'invalid') ? 400 : 409)]);
        } catch (\Throwable $error) {
            wc_get_logger()->error('Wallet request failed: ' . get_class($error), ['source' => 'wallet-platform']);
            return new \WP_Error('wallet_infrastructure', __('Wallet processing is unavailable. Retry using the same request reference.', 'wallet-platform'), ['status' => 503]);
        }
    }
}
