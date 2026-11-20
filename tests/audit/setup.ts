import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium, type FullConfig } from '@playwright/test';
import { totp } from '../browser/lib/totp';

export const OWNER_STATE = 'storage/framework/playwright-audit-owner.json';

/**
 * A fresh audit.sqlite at full size (GradesAuditSeeder), then the owner's
 * sign-in (password and authenticator code), saved for the owner's steps.
 */
export default async function setup(config: FullConfig): Promise<void> {
    writeFileSync('database/audit.sqlite', '');
    execFileSync(
        'php',
        [
            'artisan',
            'migrate:fresh',
            '--force',
            '--seed',
            '--seeder=Database\\Seeders\\GradesAuditSeeder',
        ],
        { stdio: 'inherit', env: process.env },
    );

    const data = JSON.parse(
        readFileSync('storage/app/private/audit/data.json', 'utf8'),
    ) as { email: string; password: string; secret: string };
    const { baseURL } = config.projects[0].use;
    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL });

    await page
        .context()
        .addCookies([{ name: 'locale', value: 'ar', url: String(baseURL) }]);
    await page.goto('/login');
    await page.fill('#email', data.email);
    await page.fill('#password', data.password);
    await page.click('[data-test="login-button"]');
    await page.waitForURL('**/two-factor-challenge');
    if (Date.now() % 30_000 > 25_000) {
        await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 500);
    }
    await page.locator('input[name="code"]').fill(totp(data.secret));
    await page.locator('button[type="submit"]').click();
    await page.waitForURL('**/admin');
    await page.context().storageState({ path: OWNER_STATE });
    await browser.close();
}
