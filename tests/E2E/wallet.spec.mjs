import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const fixtures = () => JSON.parse(readFileSync('tests/.runtime/browser-env.json', 'utf8'));
const screenshot = (name) => join(tmpdir(), name);

async function login(page, user, password) {
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(user);
    await page.locator('#user_pass').fill(password);
    // WordPress schedules a username-focus callback after loading the login form.
    // Verify actual input state before submitting, including the first cold browser load.
    await page.locator('#user_login').fill(user);
    if (await page.locator('#user_pass').inputValue() !== password) {
        await page.locator('#user_pass').fill(password);
    }
    await expect(page.locator('#user_login')).toHaveValue(user);
    await expect(page.locator('#user_pass')).toHaveValue(password);
    await page.locator('#wp-submit').click();
    await expect(page).not.toHaveURL(/wp-login/);
}

test('customer wallet is readable at desktop and mobile; private API rejects forged credit', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await login(page, 'wallet_test_customer', 'isolated-test-customer');
    await page.goto('/my-account/wallet/');
    await expect(page.locator('#wallet-title')).toHaveCount(1);
    await expect(page.locator('.wallet-cards > div')).toHaveCount(4);
    await expect(page.locator('#wallet-title')).toHaveText('Your wallet');
    await expect(page.locator('.wallet-cards')).toContainText('USD 50.00');
    await expect(page.locator('.wallet-platform')).toContainText('Credit added');
    const unauthorized = await page.evaluate(async () => {
        const response = await fetch('/wp-json/wallet-platform/v1/wallets/ffffffffffffffffffffffffffffffff/credit', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ amount: '100.00', reason: 'Forged browser adjustment' }) });
        return response.status;
    });
    expect(unauthorized).toBe(401);
    const accessibility = await new AxeBuilder({ page }).include('.wallet-platform').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(accessibility.violations).toEqual([]);
    await page.screenshot({ path: screenshot('wallet-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await expect(page.locator('#wallet-title')).toBeVisible();
    await expect(page.locator('#wallet-title')).toHaveCount(1);
    await expect(page.locator('.wallet-cards > div')).toHaveCount(4);
    await expect(page.locator('.wallet-cards')).toContainText('Reserved');
    await expect(page.locator('.wallet-table-scroll')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: screenshot('wallet-mobile.png'), fullPage: true });
    expect(errors).toEqual([]);
});

test('staff adjustment is confirmed, committed and shown in the table', async ({ page }) => {
    await login(page, 'wallet_test_admin', 'isolated-test-admin');
    await page.goto('/wp-admin/admin.php?page=wallet-platform');
    await expect(page.getByRole('heading', { name: 'Wallet', exact: true })).toBeVisible();
    const customerId = process.env.WALLET_QA_CUSTOMER_ID || JSON.parse(readFileSync('tests/.runtime/browser-env.json', 'utf8')).customer_id;
    const csrf = await page.request.post('/wp-admin/admin-post.php', { form: { action: 'wallet_adjust', operation: 'credit', user_id: String(customerId), amount: '100.00', reason: 'Forged CSRF adjustment', confirm: 'yes', request_key: '11111111-1111-1111-1111-111111111111', _wpnonce: 'forged' } });
    expect(csrf.status()).toBe(403);
    await page.locator('#wallet-user').fill(String(customerId));
    await page.locator('#wallet-operation').selectOption('credit');
    await page.locator('#wallet-amount').fill('1.00');
    await page.locator('#wallet-reason').fill('Browser QA confirmed adjustment');
    await page.locator('input[name=confirm]').check();
    await page.getByRole('button', { name: 'Confirm wallet action' }).click();
    await expect(page.getByRole('status')).toContainText('Wallet action committed.');
    await page.locator('#wallet-owner').fill(String(customerId));
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('table')).toContainText('USD 51.00');
    await page.screenshot({ path: screenshot('wallet-admin.png'), fullPage: true });
});

test('bank receipt requires confirmation and commits the funding request', async ({ page }) => {
    const fixture = fixtures();
    await login(page, 'wallet_test_admin', 'isolated-test-admin');
    await page.goto('/wp-admin/admin.php?page=wallet-platform-funding');
    await page.locator('#wallet-funding-topup_id').fill(fixture.funding.topup_id);
    await page.locator('#wallet-funding-reference').fill('BANK-QA-' + fixture.funding.topup_id);
    await page.locator('#wallet-funding-reason').fill('Confirmed bank receipt in isolated browser test');
    await page.locator('input[name=confirm]').check();
    await page.getByRole('button', { name: 'Confirm receipt and add credit' }).click();
    await expect(page.getByRole('status')).toContainText('Funding receipt confirmed and wallet credit committed.');
    await page.screenshot({ path: screenshot('wallet-funding.png'), fullPage: true });
    await page.context().clearCookies();
    await login(page, fixture.funding.login, 'isolated-checkout-test');
    await page.goto('/my-account/wallet/');
    await expect(page.locator('.wallet-cards')).toContainText('USD 10.00');
});

test('staff can review split records with PayPal disabled and recovery is denied', async ({ page }) => {
    await login(page, 'wallet_test_admin', 'isolated-test-admin');
    await page.goto('/wp-admin/admin.php?page=wallet-platform-payments');
    await expect(page.getByRole('heading', { name: 'Wallet and PayPal payments' })).toBeVisible();
    await expect(page.locator('.wallet-platform')).toContainText('PayPal is disabled.');
    await expect(page.getByRole('columnheader', { name: 'Original total' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Inspect and recover', exact: true })).toHaveCount(0);
    const recovery = await page.request.post('/wp-admin/admin-post.php', { form: { action: 'wallet_payment_recovery', reference: 'ffffffffffffffffffffffffffffffff', kind: 'payment', confirm: 'yes' } });
    expect(recovery.status()).toBe(403);
    const callback = await page.request.post('/wp-json/wallet-platform/v1/paypal/webhook', { data: { id: 'WH-FORGED' } });
    expect(callback.status()).toBe(404);
});

for (const flow of ['blocks', 'classic']) {
    test(`${flow} checkout pays a real order and shows the committed wallet balance`, async ({ page }) => {
        const fixture = fixtures();
        const pageId = fixture[flow + '_page_id'];
        execFileSync('php', ['tools/wp.php', 'option', 'update', 'woocommerce_checkout_page_id', String(pageId), '--path=tests/.runtime/wordpress']);
        try {
            await login(page, fixture[flow].login, 'isolated-checkout-test');
            await page.goto('/?add-to-cart=' + fixture.product_id);
            await page.goto(flow === 'classic' ? '/classic-wallet-checkout/' : '/checkout/');
            await expect(page.getByText('Browser wallet checkout product', { exact: false }).first()).toBeVisible();
            const wallet = page.locator('input[type=radio][value=wallet_platform]');
            if (await wallet.isVisible()) {
                await wallet.check();
            } else {
                // WooCommerce hides the already-selected radio when only one gateway is available.
                await expect(wallet).toBeChecked();
                await expect(page.getByText('Wallet', { exact: true })).toBeVisible();
            }
            const placeOrder = page.getByRole('button', { name: /place order/i });
            await expect(placeOrder).toBeEnabled();
            await placeOrder.click();
            await expect(page).toHaveURL(/order-received/, { timeout: 30000 });
            await expect(page.getByText(/Thank you. Your order has been received/)).toBeVisible();
            await page.goto('/my-account/wallet/');
            await expect(page.locator('.wallet-cards')).toContainText('USD 25.00');
            await expect(page.locator('.wallet-platform')).toContainText('Wallet payment');
            await page.screenshot({ path: screenshot('wallet-' + flow + '-paid.png'), fullPage: true });
        } finally {
            execFileSync('php', ['tools/wp.php', 'option', 'update', 'woocommerce_checkout_page_id', String(fixture.blocks_page_id), '--path=tests/.runtime/wordpress']);
        }
    });
}
