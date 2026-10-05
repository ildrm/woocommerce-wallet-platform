import { defineConfig } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
export default defineConfig({
    testDir: './tests/E2E',
    timeout: 90000,
    workers: 1,
    outputDir: join(tmpdir(), 'wallet-playwright-results'),
    use: { baseURL: process.env.WALLET_TEST_URL || 'http://127.0.0.1:13317', headless: true, trace: 'retain-on-failure', launchOptions: process.env.WALLET_BROWSER_EXECUTABLE ? { executablePath: process.env.WALLET_BROWSER_EXECUTABLE } : {} },
    reporter: 'line',
});
