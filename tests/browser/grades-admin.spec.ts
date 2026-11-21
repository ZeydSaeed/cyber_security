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
    // Each settings tab is a page of its own (owner's request, 2026-11-19).
    ...[
        'window',
        'links',
        'teachers',
        'students',
        'permissions',
        'card',
        'teacher-view',
        'subjects',
        // Students' appeals, a tab of their own (2026-11-20).
        'appeals',
    ].map((tab) => `/admin?tab=${tab}`),
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

    // A section's sheet with its change history and approval (ADR-0041),
    // reached from the "Subjects and teachers" tab of the settings, on a
    // phone and a desktop.
    for (const { locale, dir } of [
        { locale: 'ar', dir: 'rtl' },
        { locale: 'en', dir: 'ltr' },
    ]) {
        for (const [size, viewport] of Object.entries(viewports)) {
            test(`a section's sheet and history ${locale} ${size}`, async ({
                page,
            }) => {
                await page.setViewportSize(viewport);
                await open(page, '/admin?tab=subjects', locale);
                await page
                    .locator('a[href*="/admin/subjects/"]')
                    .first()
                    .click();
                await page.waitForURL(
                    /\/admin\/subjects\/[0-9a-f-]{36}\/[AB]$/,
                );
                await pageReady(page);
                await expect(
                    page.locator('[data-test="mark-history"]'),
                ).toBeVisible();
                await check(page, "the owner's sheet", dir);
                await page.screenshot({
                    path: `storage/framework/screenshots/admin-sheet-${locale}-${size}.png`,
                    fullPage: true,
                });
            });
        }
    }

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

    // A search in the toolbar marks matching names in yellow as each letter
    // is typed (owner's request, 2026-11-18). The seeder's second stage has
    // "Student 2A1" to "Student 2A6" in section A.
    for (const { locale, dir, count, next, none } of [
        {
            locale: 'en',
            dir: 'ltr',
            count: '1 of 6',
            next: '2 of 6',
            none: 'No student matches',
        },
        {
            locale: 'ar',
            dir: 'rtl',
            count: '1 من 6',
            next: '2 من 6',
            none: 'لا يوجد طالب بهذا الاسم',
        },
    ]) {
        test(`marks the names a search finds (${locale})`, async ({ page }) => {
            await open(page, '/admin/results?stage=2&section=A', locale);
            const search = page.locator('[data-test="results-search"]');
            const status = page.locator('[data-test="results-search-count"]');
            const marks = page.locator('[data-test="result-row"] mark');

            // Part of a name, any case, words in any order.
            await search.fill('  2a3   STUD ');
            await expect(marks).toHaveText(['Stud', '2A3']);
            await expect(status).toHaveText(
                locale === 'ar' ? '1 من 1' : '1 of 1',
            );

            await search.fill('student');
            await expect(
                page.locator(
                    '[data-test="result-row"] [data-test="name-match"]',
                ),
            ).toHaveCount(6);
            await expect(status).toHaveText(count);
            await search.press('Enter');
            await expect(status).toHaveText(next);
            await search.press('Shift+Enter');
            await expect(status).toHaveText(count);

            // The marked names keep their contrast (axe), in both directions.
            await check(page, `results search (${locale})`, dir);
            await page.screenshot({
                path: `storage/framework/screenshots/grades-results-search-${locale}.png`,
                fullPage: true,
            });

            await search.fill('zzz');
            await expect(marks).toHaveCount(0);
            await expect(status).toHaveText(none);

            await search.press('Escape');
            await expect(search).toHaveValue('');
            await expect(status).toHaveText('');
        });
    }

    // In the teacher's form each ticked section takes a type and an
    // assistant, shown in the teachers' table (owner's request,
    // 2026-11-18). The seeder's "Teacher Test" teaches Network Security
    // (first stage), then Cryptography (second stage, A and B).
    test('gives a section its type and an assistant teacher', async ({
        page,
    }) => {
        await open(page, '/admin/teachers', 'en');

        await page.click('[data-test="add-teacher"]');
        const adding = page.locator('[data-test="teacher-dialog"]');
        await adding.locator('input[name="name"]').fill('Assistant Test');
        await adding.locator('[data-test="save-teacher"]').click();
        await expect(adding).toBeHidden();

        const row = page.locator('[data-test="teacher-row"]', {
            hasText: 'Teacher Test',
        });
        await row.locator('[data-test="edit-teacher"]').click();
        const dialog = page.locator('[data-test="teacher-dialog"]');
        const details = dialog.locator('[data-test="section-details"]');
        await expect(details).toHaveCount(3);
        await check(page, 'the teacher form with section details', 'ltr');

        await details
            .first()
            .locator('[data-test="section-kind-practical"]')
            .check();
        await details
            .first()
            .locator('[data-test="section-assistant"]')
            .selectOption({ label: 'Assistant Test' });
        await page.screenshot({
            path: 'storage/framework/screenshots/grades-teacher-form-details-en.png',
        });
        await dialog.locator('[data-test="save-teacher"]').click();
        await expect(dialog).toBeHidden();

        // One line per section: only the first has a type and an assistant.
        await expect(row.locator('[data-test="teacher-kinds"] bdi')).toHaveText(
            ['Practical', '', ''],
        );
        await expect(
            row.locator('[data-test="teacher-assistants"] bdi'),
        ).toHaveText(['Assistant Test', '', '']);
        await page.screenshot({
            path: 'storage/framework/screenshots/grades-teachers-details-en.png',
            fullPage: true,
        });
    });

    // Students' appeals have a tab of their own (owner's request,
    // 2026-11-20): the picture's summary, who answers and how long.
    test('saves the appeal settings from their own tab', async ({ page }) => {
        await open(page, '/admin', 'ar');
        // The picture's bar: each card's progress, also on the card.
        await expect(
            page.locator('[data-test="progress-overview"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="progress-window"]'),
        ).toContainText('%');
        await expect(
            page.locator('[data-test="card-students"] [role="progressbar"]'),
        ).toBeVisible();
        await page.screenshot({
            path: 'storage/framework/screenshots/grades-control-panel-ar.png',
            fullPage: true,
        });
        await page.locator('[data-test="card-appeals"]').click();
        await expect(page).toHaveURL(/tab=appeals/);
        await pageReady(page);
        await expect(
            page.locator('[data-test="appeal-summary"]'),
        ).toBeVisible();

        const form = page.locator('[data-test="appeal-settings"]');
        await form.locator('[data-test="answered-by-teacher"]').check();
        await form.locator('input[name="appeal_hours"]').fill('6');
        await form.locator('[data-test="save-appeal-settings"]').click();
        await expect(page.getByText('حُفظت إعدادات الاعتراضات.')).toBeVisible();

        await page.reload();
        await pageReady(page);
        await expect(
            form.locator('[data-test="answered-by-teacher"]'),
        ).toBeChecked();
        await expect(form.locator('input[name="appeal_hours"]')).toHaveValue(
            '6',
        );
        await page.screenshot({
            path: 'storage/framework/screenshots/grades-appeals-tab-ar.png',
            fullPage: true,
        });

        // Reached from the students' tab too.
        await open(page, '/admin?tab=students', 'ar');
        await page.locator('[data-test="appeals-link"]').click();
        await expect(page).toHaveURL(/tab=appeals/);
    });

    // "What the student sees" sits in the Students box of the access
    // settings, itself a form; its fields join their own form through the
    // HTML form attribute (ADR-0044). Its button saves them, and only them.
    test('saves what the student sees from the Students box', async ({
        page,
    }) => {
        await open(page, '/admin?tab=students', 'en');
        const box = page.locator('[data-test="student-view"]');
        await expect(box).toBeVisible();
        await check(page, 'the Students box with what the student sees', 'ltr');

        const absences = box.locator('[data-test="option-absences"]');
        const before = await absences.getAttribute('aria-checked');
        await absences.click();
        await box.locator('[data-test="save-student-view"]').click();
        await expect(
            page.getByText('What the student sees was saved.'),
        ).toBeVisible();

        await page.reload();
        await pageReady(page);
        await expect(
            page.locator(
                '[data-test="student-view"] [data-test="option-absences"]',
            ),
        ).toHaveAttribute('aria-checked', before === 'true' ? 'false' : 'true');
        await page.screenshot({
            path: 'storage/framework/screenshots/grades-student-view-en.png',
            fullPage: true,
        });

        // Put it back, for the other tests.
        await page
            .locator('[data-test="student-view"] [data-test="option-absences"]')
            .click();
        await page
            .locator(
                '[data-test="student-view"] [data-test="save-student-view"]',
            )
            .click();
        await expect(
            page.getByText('What the student sees was saved.'),
        ).toBeVisible();
    });

    // Row actions (owner's request, 2026-11-20): icons only, with no
    // background, on one line, the same icons on both pages.
    test('row actions are matching icons on one line', async ({ page }) => {
        await page.setViewportSize(viewports.desktop);
        const icons: Record<string, string[]> = {};

        for (const [path, who] of [
            ['/admin/students', 'student'],
            ['/admin/teachers', 'teacher'],
        ]) {
            await open(page, path, 'ar');
            const edit = page.locator(`[data-test="edit-${who}"]`).first();
            const row = edit.locator('xpath=..');
            const buttons = row.locator('button');
            const tops = await buttons.evaluateAll((all) =>
                all.map((b) => Math.round(b.getBoundingClientRect().top)),
            );
            expect(new Set(tops).size, `${path}: one line`).toBe(1);
            expect(
                await edit.evaluate((b) => getComputedStyle(b).backgroundColor),
                `${path}: no background`,
            ).toBe('rgba(0, 0, 0, 0)');

            icons[who] = await Promise.all(
                ['edit', 'new', 'toggle', 'delete'].map((name) =>
                    page
                        .locator(
                            `[data-test^="${name}-${who}"]:not([data-test*="appeal"]):not([data-test*="devices"])`,
                        )
                        .first()
                        .locator('svg')
                        .getAttribute('class')
                        .then((c) =>
                            (c ?? '')
                                .split(' ')
                                .filter((k) => k.startsWith('lucide-'))
                                .join(' '),
                        ),
                ),
            );
        }

        expect(icons.teacher).toEqual(icons.student);
    });

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
