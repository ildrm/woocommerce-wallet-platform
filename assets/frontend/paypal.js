(function () {
    'use strict';
    const { createElement, useState, useEffect } = window.wp.element;
    const { __, sprintf } = window.wp.i18n;
    const settings = window.wc.wcSettings.getSetting('wallet_platform_paypal_data', {});
    const money = (minor, exponent) => {
        const digits = minor.toString().padStart(exponent + 1, '0');
        return exponent === 0 ? digits : `${digits.slice(0, -exponent)}.${digits.slice(-exponent)}`;
    };
    const Content = ({ eventRegistration, emitResponse }) => {
        const [wallet, setWallet] = useState(null);
        const totals = window.wp.data.useSelect((select) => select(window.wc.wcBlocksData.CART_STORE_KEY).getCartTotals(), []);
        useEffect(() => {
            let active = true;
            window.wp.apiFetch({ path: '/wallet-platform/v1/wallet' })
                .then((data) => { if (active) setWallet(data); })
                .catch(() => { if (active) setWallet(false); });
            return () => { active = false; };
        }, []);
        useEffect(() => {
            if (!eventRegistration || !emitResponse) return undefined;
            return eventRegistration.onPaymentSetup(() => {
                if (!wallet || !totals || wallet.currency !== totals.currency_code) {
                    return { type: emitResponse.responseTypes.ERROR, message: __('Review your wallet balance before paying.', 'wallet-platform') };
                }
                return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: {
                    wallet_paypal_amount: wallet.available,
                    wallet_paypal_gross: money(BigInt(totals.total_price), Number(totals.currency_minor_unit)),
                } } };
            });
        }, [eventRegistration, emitResponse, wallet, totals]);
        let description = __('Checking wallet balance…', 'wallet-platform');
        if (wallet === false) description = __('Sign in and check your wallet balance before paying.', 'wallet-platform');
        if (wallet && totals && wallet.currency === totals.currency_code) {
            const available = BigInt(wallet.available.replace('.', ''));
            const gross = BigInt(totals.total_price);
            if (available > 0n && available < gross) {
                /* translators: 1: formatted wallet amount, 2: formatted PayPal amount. */
                description = sprintf(__('Wallet: %1$s. PayPal: %2$s.', 'wallet-platform'), `${wallet.currency} ${wallet.available}`, `${wallet.currency} ${money(gross - available, Number(totals.currency_minor_unit))}`);
            } else description = __('The server will verify available wallet credit and the final order total.', 'wallet-platform');
        }
        return createElement('div', { role: 'status', 'aria-live': 'polite' }, description, createElement('p', null, settings.description));
    };
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'wallet_platform_paypal',
        label: createElement('span', null, settings.title || __('Wallet + PayPal', 'wallet-platform')),
        ariaLabel: __('Pay with wallet and PayPal', 'wallet-platform'),
        content: createElement(Content),
        edit: createElement('span', null, settings.description),
        canMakePayment: async ({ cartTotals }) => {
            try {
                const wallet = await window.wp.apiFetch({ path: '/wallet-platform/v1/wallet' });
                const available = BigInt(wallet.available.replace('.', ''));
                const external = BigInt(cartTotals.total_price) - available;
                const supported = (settings.currencies || []).includes(wallet.currency) && [0, 2].includes(Number(cartTotals.currency_minor_unit));
                const precision = !(settings.wholeUnitCurrencies || []).includes(wallet.currency) || external % (10n ** BigInt(cartTotals.currency_minor_unit)) === 0n;
                return supported && precision && wallet.state === 'active' && wallet.currency === cartTotals.currency_code && available > 0n && external > 0n;
            } catch (error) { return false; }
        },
        supports: { features: settings.supports || ['products'] },
    });
}());
