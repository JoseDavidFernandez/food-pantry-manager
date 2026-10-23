import { defineConfig, devices } from '@playwright/test';

/**
 * E2E session against the LOCAL DEVELOPMENT app and database (food_pantry_manager), on purpose:
 * the data created by the tests stays there for manual inspection. The Laravel suite keeps using
 * food_pantry_manager_test (phpunit.xml) and never runs these tests.
 */
export default defineConfig({
    testDir: './e2e',
    outputDir: './e2e/results',
    globalSetup: './e2e/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 300_000,
    expect: { timeout: 10_000 },
    reporter: [
        ['list'],
        ['html', { outputFolder: 'e2e/report', open: 'never' }],
    ],
    use: {
        baseURL: 'http://127.0.0.1:8000',
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        ...devices['Desktop Chrome'],
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000/up',
        reuseExistingServer: true,
        timeout: 60_000,
    },
});
