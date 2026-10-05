(function () {
    'use strict';
    const { createElement, useState, useEffect } = window.wp.element;
    const { __ } = window.wp.i18n;
    const settings = window.wc.wcSettings.getSetting('wallet_platform_data', {});
    const Content = () => {
        const [balance, setBalance] = useState(null);
        useEffect(() => {
            let active = true;
            window.wp.apiFetch({ path: '/wallet-platform/v1/wallet' })
                .then((data) => { if (active) setBalance(data); })
                .catch(() => { if (active) setBalance(false); });
            return () => { active = false; };
        }, []);
        return createElement('div', { role: 'status', 'aria-live': 'polite' },
            balance ? `${__('Available:', 'wallet-platform')} ${balance.currency} ${balance.available}. ${settings.description}` :
                (balance === false ? __('Sign in and check your wallet balance before paying.', 'wallet-platform') : __('Checking wallet balance…', 'wallet-platform')));
    };
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'wallet_platform',
        label: createElement('span', null, settings.title || __('Wallet', 'wallet-platform')),
        ariaLabel: __('Pay with wallet', 'wallet-platform'),
        content: createElement(Content),
        edit: createElement('span', null, settings.description),
        canMakePayment: async ({ cartTotals }) => {
            try {
                const wallet = await window.wp.apiFetch({ path: '/wallet-platform/v1/wallet' });
                const minor = BigInt(wallet.available.replace('.', ''));
                return wallet.state === 'active' && wallet.currency === cartTotals.currency_code &&
                    minor >= BigInt(cartTotals.total_price) && BigInt(cartTotals.total_price) > 0n;
            } catch (error) {
                return false;
            }
        },
        supports: { features: settings.supports || ['products'] },
    });
}());
