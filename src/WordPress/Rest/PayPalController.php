<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Rest;

use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\WooCommerce\Orders\PayPalOrders;

final class PayPalController
{
    public function __construct(private readonly PayPalOrders $orders)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('wallet-platform/v1', '/paypal/webhook', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [$this, 'webhook']]);
        });
        add_action('woocommerce_api_wallet_platform_paypal_return', fn () => $this->returned(false));
        add_action('woocommerce_api_wallet_platform_paypal_cancel', fn () => $this->returned(true));
        add_action('woocommerce_thankyou_wallet_platform_paypal', function (int $orderId): void {
            $order = wc_get_order($orderId);
            $payment = $this->orders->forOrder($orderId);
            if ($order instanceof \WC_Order && $order->get_customer_id() === get_current_user_id() && $payment !== null && !in_array($payment['state'], ['completed', 'cancelled', 'compensated'], true)) {
                echo '<p role="status">' . esc_html__('Payment confirmation is pending. Your order will be paid only after PayPal and the wallet are confirmed. Do not pay again while this order is being reviewed.', 'wallet-platform') . '</p>';
            }
        });
    }

    public function webhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $headers = [];
        foreach (['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'] as $header) {
            $headers[$header] = $request->get_header($header) ?? '';
        }
        try {
            $event = $this->orders->payments->receiveWebhook($request->get_body(), $headers);
            return new \WP_REST_Response(['accepted' => true, 'event_id' => $event['id']], 200);
        } catch (WalletException $error) {
            $retry = in_array($error->errorCode, ['concurrency_conflict', 'provider_outcome_unknown', 'provider_authentication'], true);
            return new \WP_REST_Response(['accepted' => false, 'code' => $retry ? 'provider_retry' : 'invalid_event'], $retry ? 503 : 400);
        } catch (\Throwable $error) {
            wc_get_logger()->error('PayPal webhook recovery failed: ' . get_class($error), ['source' => 'wallet-platform']);
            return new \WP_REST_Response(['accepted' => false, 'code' => 'recovery_unavailable'], 503);
        }
    }

    /** Public for adapter tests; returns only an authorized server-side mapping. */
    public function authorizeReturn(array $input): array
    {
        $id = (string) ($input['payment_id'] ?? '');
        if (!is_user_logged_in() || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new WalletException('payment_owner_mismatch', 'Sign in as the order owner to review payment.');
        }
        $payment = $this->orders->payments->record($id);
        $order = wc_get_order((int) $payment['order_id']);
        if (!$order instanceof \WC_Order || (int) $payment['owner_id'] !== get_current_user_id() || $order->get_customer_id() !== get_current_user_id() || !hash_equals($order->get_order_key(), (string) ($input['order_key'] ?? '')) || !wp_verify_nonce((string) ($input['wallet_nonce'] ?? ''), 'wallet_paypal_' . $id . '_' . $payment['owner_id']) || (isset($input['token']) && !hash_equals((string) $payment['provider_order'], (string) $input['token']))) {
            throw new WalletException('payment_owner_mismatch', 'Payment return does not match its authenticated order.');
        }
        return $payment;
    }

    private function returned(bool $cancel): void
    {
        try {
            $payment = $this->authorizeReturn(wp_unslash($_GET));
            if ($cancel) {
                $payment = $this->orders->cancelByOwner($payment['id'], get_current_user_id());
                $order = wc_get_order((int) $payment['order_id']);
            } else {
                $payment = $this->orders->recover($payment['id']);
                $order = wc_get_order((int) $payment['order_id']);
            }
            wc_add_notice($payment['state'] === 'completed' ? __('Wallet and PayPal payment confirmed.', 'wallet-platform') : __('Review this order’s payment status before making another payment.', 'wallet-platform'), 'notice');
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        } catch (\Throwable $error) {
            wc_get_logger()->error('PayPal return requires review: ' . get_class($error), ['source' => 'wallet-platform']);
            wp_die(esc_html__('Payment could not be confirmed from this return. Sign in as the order owner and review the existing order before paying again.', 'wallet-platform'), '', ['response' => 403, 'back_link' => true]);
        }
    }
}
