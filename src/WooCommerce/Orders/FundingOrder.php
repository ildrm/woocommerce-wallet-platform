<?php

declare(strict_types=1);

namespace WalletPlatform\WooCommerce\Orders;

final class FundingOrder extends \WC_Order
{
    public function get_type(): string
    {
        return 'wallet_topup';
    }
}
