import { defineConfig, devices } from '@playwright/test';

/*
| The end-to-end audit of the Grades module at full size (tests/audit):
| 100 teachers, 100 students and every mark entered through the pages,
| run against its own SQLite file (database/audit.sqlite), never a real
| database, and on its own port so it never meets the browser suite.
|
|   npm run build && npx playwright test -c playwright.audit.config.ts
*/
const port = Number(process.env.AUDIT_TEST_PORT ?? 8125);

const serverEnv = {
    APP_ENV: 'browser',
    APP_DEBUG: 'false',
    APP_URL: `http://127.0.0.1:${port}`,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: 'database/audit.sqlite',
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    LOG_CHANNEL: 'null',
    PLATFORM_HSTS: 'false',
    PLATFORM_REQUIRE_SECOND_FACTOR: 'true',
    AUDIT_HMAC_KEY: 'audit-tests-only-key-not-a-secret-0123456789',
};

Object.assign(process.env, serverEnv);

export default defineConfig({
    testDir: 'tests/audit',
    outputDir: 'storage/framework/playwright-audit',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 15 * 60_000,
    reporter: [['list']],
    globalSetup: './tests/audit/setup.ts',
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        trace: 'off',
        screenshot: 'only-on-failure',
        actionTimeout: 30_000,
        navigationTimeout: 60_000,
        contextOptions: { reducedMotion: 'reduce' },
        viewport: { width: 1366, height: 900 },
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${port} --no-reload`,
        url: `http://127.0.0.1:${port}/up`,
        reuseExistingServer: false,
        timeout: 60_000,
        env: serverEnv,
    },
});
