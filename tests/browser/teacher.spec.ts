import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { pageReady } from './lib/ready';

/*
| A teacher enters marks on a phone (ADR-0034), in Arabic and English:
| the code page, their subjects, and a section's sheet. Enter moves to the
| next student, "absent" empties and locks the field, a wrong value is
| named beside its student before anything is sent, and saved marks are
| there after a reload. axe on every page, no sideways scrolling at 360 px.
| The browser seeder provides the teacher, two subjects and six students in
| each section of the first stage, with mark entry open.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;
const phone = { width: 360, height: 780 };

function teacherCode(): string {
    return (
        JSON.parse(
            readFileSync('storage/app/private/browser/user.json', 'utf8'),
        ) as { teacher_code: string }
    ).teacher_code;
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

// Each language fills its own section: the runs share one database.
for (const { locale, dir, section, first, absentWord } of [
    { locale: 'ar', dir: 'rtl', section: 'A', first: '91', absentWord: 'غائب' },
    {
        locale: 'en',
        dir: 'ltr',
        section: 'B',
        first: '88',
        absentWord: 'Absent',
    },
]) {
    test.describe(`teacher ${locale}`, () => {
        // A teacher is not a user: no signed-in user here.
        test.use({
            storageState: { cookies: [], origins: [] },
            viewport: phone,
        });

        test.beforeEach(async ({ page }) => {
            await page
                .context()
                .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
        });

        test('a wrong code is refused without saying why', async ({ page }) => {
            await page.goto('/teacher');
            await pageReady(page);
            await check(page, 'the code page', dir);

            await page.fill('[data-test="teacher-code"]', 'not-the-code');
            await page.click('[data-test="teacher-login"]');

            await expect(
                page.locator('[data-test="teacher-code"]'),
            ).toHaveValue('');
            await expect(page).toHaveURL(/\/teacher$/);
        });

        test('enters, checks and saves a section', async ({ page }) => {
            await page.goto('/teacher');
            await pageReady(page);
            await page.fill('[data-test="teacher-code"]', teacherCode());
            await page.click('[data-test="teacher-login"]');

            await page.waitForURL(/\/teacher\/subjects$/);
            await pageReady(page);
            await expect(page.locator('[data-test="teacher-name"]')).toHaveText(
                'Teacher Test',
            );
            await check(page, 'the subjects', dir);

            await page
                .locator(`[data-test="section-${section}"]`)
                .first()
                .click();
            await page.waitForURL(
                new RegExp(`/teacher/subjects/[0-9a-f-]{36}/${section}$`),
            );
            await pageReady(page);
            await check(page, 'the sheet', dir);

            const rows = page.locator('[data-test="student-row"]');
            await expect(rows).toHaveCount(6);
            const inputs = page.locator('[data-test="mark-input"]');

            // Enter moves to the next student.
            await inputs.nth(0).fill(first);
            await inputs.nth(0).press('Enter');
            await expect(inputs.nth(1)).toBeFocused();

            // Absent empties and locks the field.
            await inputs.nth(1).fill('40');
            await rows.nth(1).locator('[data-test="absent-box"]').click();
            await expect(inputs.nth(1)).toBeDisabled();
            await expect(inputs.nth(1)).toHaveValue('');

            // A wrong value is named before anything is sent.
            await inputs.nth(2).fill('120');
            await expect(inputs.nth(2)).toHaveAttribute('aria-invalid', 'true');
            await check(page, 'the sheet with an error', dir);
            await inputs.nth(2).fill('64');
            await expect(inputs.nth(2)).not.toHaveAttribute('aria-invalid');

            await expect(page.locator('[data-test="unsaved"]')).toBeVisible();
            await page.click('[data-test="save-marks"]');
            await expect(page.locator('[data-test="unsaved"]')).toHaveCount(0);

            await page.reload();
            await pageReady(page);
            await expect(inputs.nth(0)).toHaveValue(first);
            await expect(inputs.nth(1)).toBeDisabled();
            await expect(inputs.nth(1)).toHaveAttribute(
                'placeholder',
                absentWord,
            );
            await expect(inputs.nth(2)).toHaveValue('64');

            await page.screenshot({
                path: `storage/framework/screenshots/teacher-sheet-${locale}-phone.png`,
                fullPage: true,
            });

            await page.click('[data-test="teacher-logout"]');
            await page.waitForURL(/\/teacher$/);
        });
    });
}
