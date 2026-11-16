import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { pageReady } from './lib/ready';

/*
| The owner's Grades pages (ADR-0034), signed in as the browser seeder's
| administrator, in Arabic and English, on a phone and a desktop: axe and
| no sideways scrolling on every page, then the printable pages. The seeder provides a teacher, two
| first-stage subjects and six students per section; teacher.spec.ts has
| entered some marks by the time these run, or not: nothing here depends
| on it.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;
const viewports = {
    phone: { width: 360, height: 780 },
    desktop: { width: 1366, height: 900 },
};

async function open(page: Page, path: string, locale: string): Promise<void> {
    await page
        .context()
        .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
    await page.goto(path);
    await pageReady(page);
}

async function check(page: Page, what: string, dir: string): Promise<void> {
    await expect(page.locator('html')).toHaveAttribute('dir', dir);

    const axe = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(
        axe.violations.map(
            (v) =>
                `${v.id}: ${v.help} — ${v.nodes
                    .slice(0, 2)
                    .map((n) => n.target.join(' '))
                    .join(' | ')}`,
        ),
        `axe on ${what}`,
    ).toEqual([]);

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow, `sideways scrolling on ${what}`).toBeLessThanOrEqual(0);
}

const pages = [
    '/admin',
    '/admin/teachers',
    '/admin/subjects',
    '/admin/students',
    '/admin/results?stage=1&section=A',
    '/admin/results/print?stage=1&section=A',
    '/admin/teachers/print',
    '/admin/students/print',
    '/admin/subjects/print',
];

for (const { locale, dir } of [
    { locale: 'ar', dir: 'rtl' },
    { locale: 'en', dir: 'ltr' },
]) {
    for (const [size, viewport] of Object.entries(viewports)) {
        test.describe(`grades owner ${locale} ${size}`, () => {
            test.use({ viewport });

            for (const path of pages) {
                test(path, async ({ page }) => {
                    await open(page, path, locale);
                    await check(page, path, dir);

                    if (size === 'desktop') {
                        const name = path
                            .replace(/[/?=&]+/g, '-')
                            .replace(/^-|-$/g, '');
                        await page.screenshot({
                            path: `storage/framework/screenshots/${name}-${locale}-desktop.png`,
                            fullPage: true,
                        });
                    }
                });
            }
        });
    }
}

test.describe('grades owner flow', () => {
    test.use({ viewport: viewports.desktop });

    // The code slips print on white paper with black ink in every theme
    // (owner's request, 2026-11-17).
    for (const path of [
        '/admin/teachers/print',
        '/admin/students/codes?stage=1&section=A',
    ]) {
        test(`${path} is white in the dark theme`, async ({ page }) => {
            await page.emulateMedia({ colorScheme: 'dark' });
            await page
                .context()
                .addCookies([
                    { name: 'appearance', value: 'dark', url: baseURL },
                ]);
            await open(page, path, 'ar');
            await expect(page.locator('html')).toHaveClass(/\bdark\b/);

            const paper = page.locator('[data-test="print-paper"]');
            await expect(paper).toHaveCSS('background-color', 'oklch(1 0 0)');
            await expect(paper).toHaveCSS('color', 'oklch(0 0 0)');
            await check(page, `${path} (dark)`, 'rtl');
            const name = path.replace(/[/?=&]+/g, '-').replace(/^-/, '');
            await page.screenshot({
                path: `storage/framework/screenshots/${name}-ar-dark.png`,
                fullPage: true,
            });
        });
    }

    // The import card left the students page on 2026-11-16 (the owner adds
    // students one by one); the import itself keeps its feature tests.
    test('prints a result card', async ({ page }) => {
        await open(page, '/admin/results?stage=1&section=A', 'ar');
        const card = page.locator('[data-test="result-row"] a').first();
        const href = await card.getAttribute('href');
        expect(href).toMatch(/\/admin\/results\/students\/[0-9a-f-]{36}$/);

        await open(page, href ?? '', 'ar');
        await check(page, 'a result card', 'rtl');
        await expect(page.locator('[data-test="card-name"]')).toHaveText(
            /Student 1-/,
        );
        await page.screenshot({
            path: 'storage/framework/screenshots/grades-result-card-ar.png',
            fullPage: true,
        });
        // The card saves as a PNG image (owner's request, 2026-11-16).
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.click('[data-test="download"]'),
        ]);
        expect(download.suggestedFilename()).toMatch(/^result-.+\.png$/);
        await download.saveAs(
            'storage/framework/screenshots/grades-result-card-ar-download.png',
        );
        await page.emulateMedia({ media: 'print' });
        await expect(page.locator('[data-test="print"]')).toBeHidden();
        await page.screenshot({
            path: 'storage/framework/screenshots/grades-result-card-ar-print.png',
            fullPage: true,
        });
    });
});
