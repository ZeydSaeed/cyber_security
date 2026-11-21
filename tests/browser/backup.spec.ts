import { expect, test } from '@playwright/test';
import { pageReady } from './lib/ready';

/*
| The database copy asks for the person's own password every time
| (owner's request, 2026-11-21). The browser database is SQLite, so no
| copy is taken here: only the password step is checked.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

test('a copy needs the password, and a wrong one is refused in place', async ({
    page,
}) => {
    await page
        .context()
        .addCookies([{ name: 'locale', value: 'en', url: baseURL }]);
    await page.goto('/admin/backup');
    await pageReady(page);

    await page.locator('[data-test="backup-take"]').click();
    const dialog = page.locator('[data-test="backup-password"]');
    await expect(dialog).toBeVisible();
    await expect(page.locator('#backup-password')).toBeFocused();
    // Nothing to send until a password is typed.
    await expect(page.locator('[data-test="backup-confirm"]')).toBeDisabled();

    await page.locator('#backup-password').fill('not-the-password');
    await page.locator('[data-test="backup-confirm"]').click();

    await expect(page.locator('#backup-password-error')).toHaveText(
        'The password is incorrect.',
    );
    await expect(page.locator('#backup-password')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    // No copy was made: the "ready" window never opens.
    await expect(page.locator('[data-test="backup-ready"]')).toHaveCount(0);

    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toHaveCount(0);
});
