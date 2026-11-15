import { expect, test } from '@playwright/test';
import { pageReady } from './lib/ready';

/*
| Tablet layout (#11). The notifications, dashboard and reports pages (and
| their tests) were removed by the owner on 2026-11-15; their addresses
| lead to /admin (DashboardTest).
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

test.describe('tablet layout', () => {
    // No sidebar choice yet: the width decides.
    test('the sidebar starts as icons on a tablet and open on a wide screen', async ({
        browser,
    }) => {
        for (const [width, state] of [
            [820, 'collapsed'],
            [1366, 'expanded'],
        ] as const) {
            const context = await browser.newContext({
                storageState: 'storage/framework/playwright-auth.json',
                viewport: { width, height: 1000 },
            });
            const page = await context.newPage();
            await page.goto(`${baseURL}/admin`);
            await pageReady(page);

            await expect(
                page.locator('[data-slot="sidebar"][data-state]'),
            ).toHaveAttribute('data-state', state);
            // (The dashboard's widget grid went with the dashboard.)

            await context.close();
        }
    });
});
