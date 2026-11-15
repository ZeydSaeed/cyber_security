import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { pageReady } from './lib/ready';

/*
| A student checks their result on a phone (ADR-0034), in Arabic and
| English: the code page, then their page, which says the results are not
| published yet (the browser seeder leaves entry open and nothing
| published). axe and no sideways scrolling at 360 px.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

function studentCode(): string {
    return (
        JSON.parse(
            readFileSync('storage/app/private/browser/user.json', 'utf8'),
        ) as {
            student_code: string;
        }
    ).student_code;
}

async function check(page: Page, what: string, dir: string): Promise<void> {
    await expect(page.locator('html')).toHaveAttribute('dir', dir);
    const axe = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(
        axe.violations.map((v) => `${v.id}: ${v.help}`),
        `axe on ${what}`,
    ).toEqual([]);
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow, `sideways scrolling on ${what}`).toBeLessThanOrEqual(0);
}

for (const { locale, dir } of [
    { locale: 'ar', dir: 'rtl' },
    { locale: 'en', dir: 'ltr' },
]) {
    test.describe(`student ${locale}`, () => {
        test.use({
            storageState: { cookies: [], origins: [] },
            viewport: { width: 360, height: 780 },
        });

        test('signs in with the code and is told the results are not published yet', async ({
            page,
        }) => {
            await page
                .context()
                .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
            await page.goto('/student');
            await pageReady(page);
            await check(page, 'the code page', dir);

            // Typed in lower case, as a phone may.
            await page.fill(
                '[data-test="student-code"]',
                studentCode().toLowerCase(),
            );
            await page.click('[data-test="student-login"]');
            await page.waitForURL(/\/student\/result$/);
            await pageReady(page);

            await expect(page.locator('[data-test="portal-name"]')).toHaveText(
                'Student 1-1',
            );
            await expect(
                page.locator('[data-test="not-published"]'),
            ).toBeVisible();
            await check(page, 'the result page', dir);

            await page.click('[data-test="portal-logout"]');
            await page.waitForURL(/\/student$/);
        });
    });
}
