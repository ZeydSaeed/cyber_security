import { expect, test, type Page } from '@playwright/test';
import { pageReady } from './lib/ready';

/*
| Uploads (#14, #15) and the multi-step invitation (#6), ADR-0027, in
| Arabic and English: drop or choose files, progress, cancel, retry, the
| server's refusal; one step at a time with the server's rules, focus on
| each step's heading.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

// The same minimal PDF as tests/Support/Files::pdf().
const pdf = Buffer.from(
    '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\nstream\nBT /F1 12 Tf (Hello) Tj ET\nendstream\ntrailer << /Root 1 0 R >>\n%%EOF\n',
);

async function open(page: Page, locale: string, path: string) {
    await page
        .context()
        .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(path);
    await pageReady(page);
}

const queueItem = (page: Page, name: string) =>
    page.locator('[data-slot="upload-queue"] li', { hasText: name });

for (const locale of ['ar', 'en']) {
    test.describe(`uploads (${locale})`, () => {
        test('chosen and dropped files upload and appear as documents', async ({
            page,
        }) => {
            await open(page, locale, '/settings/documents');

            await page.locator('input[type="file"]').setInputFiles({
                name: `chosen-${locale}.pdf`,
                mimeType: 'application/pdf',
                buffer: pdf,
            });
            await expect(
                queueItem(page, `chosen-${locale}.pdf`),
            ).toHaveAttribute('data-status', 'done');

            // Dropping: a DataTransfer with a file, as a browser builds it.
            const transfer = await page.evaluateHandle(
                ({ name, content }) => {
                    const data = new DataTransfer();
                    data.items.add(
                        new File([content], name, { type: 'application/pdf' }),
                    );

                    return data;
                },
                {
                    name: `dropped-${locale}.pdf`,
                    content: pdf.toString('latin1'),
                },
            );
            await page.dispatchEvent('[data-test="drop-zone"]', 'drop', {
                dataTransfer: transfer,
            });
            await expect(
                queueItem(page, `dropped-${locale}.pdf`),
            ).toHaveAttribute('data-status', 'done');

            const documents = page.locator('[data-slot="documents"]');
            await expect(documents).toContainText(`chosen-${locale}.pdf`);
            await expect(documents).toContainText(`dropped-${locale}.pdf`);
            await expect(
                documents
                    .getByRole('link', {
                        name: new RegExp(`chosen-${locale}.pdf`),
                    })
                    // The newest, if a repeated run uploaded it before.
                    .first(),
            ).toHaveAttribute('href', /\/settings\/documents\/[0-9a-f-]{36}$/);
        });

        test('shows the server refusal on the file and offers to try again', async ({
            page,
        }) => {
            await open(page, locale, '/settings/documents');

            // Named .pdf, but the bytes are a program: the server refuses it.
            await page.locator('input[type="file"]').setInputFiles({
                name: `fake-${locale}.pdf`,
                mimeType: 'application/pdf',
                buffer: Buffer.from('MZ\x90\x00 a program'),
            });

            const item = queueItem(page, `fake-${locale}.pdf`);
            await expect(item).toHaveAttribute('data-status', 'failed');
            await expect(item).toContainText(
                locale === 'ar'
                    ? 'هذا النوع من الملفات غير مقبول.'
                    : 'This type of file is not accepted.',
            );
            await expect(item.getByRole('button')).toBeVisible();
        });

        test('refuses a disallowed type before sending it', async ({
            page,
        }) => {
            await open(page, locale, '/settings/documents');
            let sent = false;
            page.on('request', (request) => {
                if (
                    request.method() === 'POST' &&
                    request.url().endsWith('/settings/documents')
                ) {
                    sent = true;
                }
            });

            await page.locator('input[type="file"]').setInputFiles({
                name: `script-${locale}.exe`,
                mimeType: 'application/octet-stream',
                buffer: Buffer.from('MZ'),
            });

            await expect(
                queueItem(page, `script-${locale}.exe`),
            ).toHaveAttribute('data-status', 'failed');
            expect(sent).toBe(false);
        });

        test('shows progress, can be cancelled and tried again', async ({
            page,
        }) => {
            await open(page, locale, '/settings/documents');
            const name = `slow-${locale}.pdf`;

            // Hold the first upload so there is time to cancel it.
            let held = true;
            await page.route('**/settings/documents', async (route) => {
                if (route.request().method() === 'POST' && held) {
                    held = false;
                    await new Promise((resolve) => setTimeout(resolve, 4000));
                }
                await route.continue().catch(() => undefined);
            });

            await page.locator('input[type="file"]').setInputFiles({
                name,
                mimeType: 'application/pdf',
                buffer: pdf,
            });

            const item = queueItem(page, name);
            await expect(item).toHaveAttribute('data-status', 'uploading');
            await expect(item.locator('progress')).toHaveAccessibleName(
                new RegExp(name),
            );

            await item.getByRole('button').click();
            await expect(item).toHaveAttribute('data-status', 'cancelled');

            await item.getByRole('button').click();
            await expect(item).toHaveAttribute('data-status', 'done');
        });
    });

    test.describe(`managing users (${locale})`, () => {
        test('adds, shows the password, edits and deletes a person', async ({
            page,
        }) => {
            // Unique per run: a repeated run must not meet its own person.
            const email = `added-${locale}-${Date.now()}@example.test`;
            const secret = 'Str0ng!Passw0rd-x';
            await open(page, locale, '/admin/users');

            await page.locator('[data-test="add-user"]').click();
            const dialog = page.locator('[data-test="user-dialog"]');
            await expect(dialog).toBeVisible();

            await dialog.locator('input[name="name"]').fill('Added Person');
            await dialog
                .locator('input[name="email"]')
                .fill('person-01@example.test');

            // The eye shows and hides the password while typing it.
            const password = dialog.locator('input[name="password"]');
            await password.fill(secret);
            await expect(password).toHaveAttribute('type', 'password');
            await dialog
                .getByRole('button', {
                    name:
                        locale === 'ar' ? 'إظهار كلمة المرور' : 'Show password',
                })
                .click();
            await expect(password).toHaveAttribute('type', 'text');
            await dialog
                .getByRole('button', {
                    name:
                        locale === 'ar' ? 'إخفاء كلمة المرور' : 'Hide password',
                })
                .click();
            await expect(password).toHaveAttribute('type', 'password');

            // Roles and permissions are ticked from the full list.
            await dialog.locator('[data-test="role-auditor"]').check();
            await dialog
                .locator('[data-test="permission-grades.view_results"]')
                .check();

            // An address already in use: the server refuses, the dialog stays.
            await dialog.locator('[data-test="save-user"]').click();
            await expect(dialog).toContainText(
                locale === 'ar' ? 'مستخدمة من قبل' : 'already been taken',
            );

            await dialog.locator('input[name="email"]').fill(email);
            await password.fill(secret);
            await dialog.locator('[data-test="save-user"]').click();
            await expect(dialog).toBeHidden();

            await page.locator('[data-test="table-search"]').fill(email);
            const row = page.locator('[data-slot="data-table"] tbody tr');
            await expect(row).toHaveCount(1);

            // The password in the table: hidden, shown, hidden again.
            const value = row.locator('[data-test="password-value"]');
            await expect(value).not.toContainText(secret);
            await row.locator('[data-test="toggle-password"]').click();
            await expect(value).toHaveText(secret);
            await row.locator('[data-test="toggle-password"]').click();
            await expect(value).not.toContainText(secret);

            // Editing keeps the ticks and changes the password.
            await row.locator('[data-test="edit-user"]').click();
            await expect(dialog).toBeVisible();
            await expect(
                dialog.locator('[data-test="role-auditor"]'),
            ).toBeChecked();
            await expect(
                dialog.locator('[data-test="permission-grades.view_results"]'),
            ).toBeChecked();
            await dialog
                .locator('input[name="password"]')
                .fill('N3w!Passw0rd-zz');
            await dialog.locator('[data-test="save-user"]').click();
            await expect(dialog).toBeHidden();
            await row.locator('[data-test="toggle-password"]').click();
            await expect(value).toHaveText('N3w!Passw0rd-zz');

            // Deleting asks first.
            await row.locator('[data-test="delete-user"]').click();
            const confirm = page.locator('[data-slot="confirm-action"]');
            await expect(confirm).toBeVisible();
            await confirm
                .locator('[data-test="confirm-action-button"]')
                .click();
            await expect(confirm).toBeHidden();
            await expect(
                page.locator('[data-slot="data-table"]'),
            ).not.toContainText(email);
        });
    });
}
