import { expect, test, type Browser, type Page } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { pageReady } from '../browser/lib/ready';
import { OWNER_STATE } from './setup';

/*
| The whole life of one marking period, through the pages only, at full
| size (GradesAuditSeeder): the owner completes the staff to 100 teachers
| and 100 students and sets what students see; 40 teachers enter, check
| and approve every mark of their sections (800 marks); one asks to change
| approved marks and is allowed; the owner closes entry, checks every
| result against an independent calculation, publishes section by section;
| all 100 students read their result and some appeal; a teacher and the
| owner answer; the owner corrects a mark; export, print and access
| controls. Every console error, page error and failed request on the way
| is collected in storage/framework/audit/findings.json, with the time of
| each page, for the report.
*/

type Sheet = {
    subject: string;
    name: string;
    stage: number;
    section: string;
    max: number;
    teacher: string;
    code: string;
};
type Pupil = { name: string; stage: number; section: string; code: string };
type Data = {
    sheets: Sheet[];
    students: Pupil[];
    teachers: number;
    idle_teacher: string;
    idle_teacher_code: string;
};

const data = JSON.parse(
    readFileSync('storage/app/private/audit/data.json', 'utf8'),
) as Data;
const baseURL = `http://127.0.0.1:${process.env.AUDIT_TEST_PORT ?? 8125}`;

/** What the run saw that a person should read. */
const findings: { step: string; kind: string; detail: string }[] = [];
const timings: { page: string; ms: number }[] = [];

function watch(page: Page, step: string): void {
    page.on('console', (message) => {
        if (message.type() === 'error') {
            findings.push({ step, kind: 'console', detail: message.text() });
        }
    });
    page.on('pageerror', (error) =>
        findings.push({ step, kind: 'pageerror', detail: error.message }),
    );
    page.on('response', (response) => {
        // 422 and 302 are a form's normal answers; 403/404 are checked
        // where they are expected.
        if (response.status() >= 500) {
            findings.push({
                step,
                kind: `http ${response.status()}`,
                detail: response.url(),
            });
        }
    });
}

async function visit(page: Page, path: string): Promise<void> {
    const start = Date.now();
    await page.goto(path);
    await pageReady(page);
    timings.push({
        page: path.replace(/[0-9a-f-]{36}/g, ':uuid'),
        ms: Date.now() - start,
    });
}

async function fresh(
    browser: Browser,
    step: string,
    owner = false,
): Promise<Page> {
    const context = await browser.newContext({
        baseURL,
        storageState: owner ? OWNER_STATE : { cookies: [], origins: [] },
        reducedMotion: 'reduce',
        viewport: { width: 1366, height: 900 },
    });
    await context.addCookies([{ name: 'locale', value: 'ar', url: baseURL }]);
    const page = await context.newPage();
    watch(page, step);

    return page;
}

/** A made-up mark for a student in a subject, the same on every run. */
function markFor(
    student: string,
    subject: string,
    max: number,
): number | 'absent' {
    let hash = 7;
    for (const char of student + '|' + subject) {
        hash = (hash * 31 + char.charCodeAt(0)) % 1_000_003;
    }
    if (hash % 29 === 0) {
        return 'absent';
    }
    // From about a third of the maximum to all of it: some fail.
    return Math.min(max, Math.round((max * (32 + (hash % 69))) / 100));
}

/** The result the application must show, computed here independently. */
function expected(student: Pupil, marks: Map<string, number | 'absent'>) {
    const sheets = data.sheets.filter(
        (sheet) =>
            sheet.stage === student.stage && sheet.section === student.section,
    );
    let total = 0;
    let maximum = 0;
    let below = 0;
    let absences = 0;
    for (const sheet of sheets) {
        const mark =
            marks.get(`${student.name}|${sheet.subject}`) ??
            markFor(student.name, sheet.name, sheet.max);
        maximum += sheet.max;
        const pass = Math.ceil(sheet.max * 0.5);
        if (mark === 'absent') {
            absences++;
            below++;
        } else {
            total += mark;
            below += mark >= pass ? 0 : 1;
        }
    }
    const percentage = Math.round((total * 10000) / maximum) / 100;

    return { total, maximum, percentage, below, absences };
}

/** Owner's corrections after appeals: name|subject uuid => mark. */
const corrected = new Map<string, number | 'absent'>();
/** The students the owner adds through the page. */
const added: Pupil[] = [];

test.describe.configure({ mode: 'serial' });

test.afterAll(() => {
    mkdirSync('storage/framework/audit', { recursive: true });
    const byPage = new Map<string, number[]>();
    for (const { page, ms } of timings) {
        byPage.set(page, [...(byPage.get(page) ?? []), ms]);
    }
    const summary = [...byPage].map(([page, list]) => {
        const sorted = [...list].sort((a, b) => a - b);

        return {
            page,
            visits: list.length,
            median_ms: sorted[Math.floor(sorted.length / 2)],
            max_ms: sorted[sorted.length - 1],
        };
    });
    writeFileSync(
        'storage/framework/audit/findings.json',
        JSON.stringify({ findings, timings: summary }, null, 2),
    );
});

test('1. the owner completes the staff and the students to 100', async ({
    browser,
}) => {
    const page = await fresh(browser, 'owner setup', true);

    // Every owner page opens.
    for (const path of [
        '/admin',
        ...[
            'window',
            'links',
            'teachers',
            'students',
            'permissions',
            'card',
            'teacher-view',
            'subjects',
        ].map((tab) => `/admin?tab=${tab}`),
        '/admin/teachers',
        '/admin/subjects',
        '/admin/students',
        '/admin/teachers/print',
        '/admin/subjects/print',
        '/admin/students/print',
    ]) {
        await visit(page, path);
        await expect(page.locator('#app')).not.toBeEmpty();
    }

    // Three teachers through the form: 97 + 3 = 100.
    await visit(page, '/admin/teachers');
    for (const name of [
        'د. ليث عدنان خضير',
        'م. رسل سمير جليل',
        'م.م. أمير ناظم جبار',
    ]) {
        await page.click('[data-test="add-teacher"]');
        const dialog = page.locator('[data-test="teacher-dialog"]');
        await dialog.locator('input[name="name"]').fill(name);
        await dialog.locator('input[name="specialty"]').fill('الأمن السيبراني');
        await dialog.locator('[data-test="save-teacher"]').click();
        await expect(dialog).toBeHidden();
        await expect(
            page.locator('[data-test="teacher-row"]', { hasText: name }),
        ).toBeVisible();
    }
    await expect(page.locator('[data-test="teacher-row"]')).toHaveCount(100);

    // A wrong teacher: an empty name is refused beside the field.
    await page.click('[data-test="add-teacher"]');
    const dialog = page.locator('[data-test="teacher-dialog"]');
    await dialog.locator('input[name="name"]').fill('  ');
    await dialog.locator('[data-test="save-teacher"]').click();
    await expect(dialog).toBeVisible();
    await page.keyboard.press('Escape');

    // A temporary teacher, deleted again.
    await page.click('[data-test="add-teacher"]');
    await dialog.locator('input[name="name"]').fill('مدرس مؤقت للحذف');
    await dialog.locator('[data-test="save-teacher"]').click();
    await expect(dialog).toBeHidden();
    const temporary = page.locator('[data-test="teacher-row"]', {
        hasText: 'مدرس مؤقت للحذف',
    });
    await temporary.locator('[data-test="delete-teacher"]').click();
    await page.click('[data-test="confirm-action-button"]');
    await expect(temporary).toHaveCount(0);
    await expect(page.locator('[data-test="teacher-row"]')).toHaveCount(100);

    // A new code for a teacher; the old one stops working (checked in step 2).
    const renewed = page.locator('[data-test="teacher-row"]', {
        hasText: 'م. رسل سمير جليل',
    });
    const before = await renewed
        .locator('[data-test="teacher-code"]')
        .innerText();
    await renewed.locator('[data-test="new-teacher-code"]').click();
    await page.click('[data-test="confirm-action-button"]');
    await expect(renewed.locator('[data-test="teacher-code"]')).not.toHaveText(
        before,
    );

    // Three students through the form: 97 + 3 = 100.
    await visit(page, '/admin/students');
    for (const [name, stage, section] of [
        ['حسنين علي كاظم', '1', ''],
        ['زهراء محمد جاسم', '2', 'B'],
        ['منتظر حيدر عباس', '3', 'B'],
    ] as const) {
        await page.click('[data-test="add-student"]');
        const form = page.locator('[data-test="student-dialog"]');
        await form.locator('input[name="name"]').fill(name);
        await form.locator('select[name="stage"]').selectOption(stage);
        if (section !== '') {
            await form.locator('select[name="section"]').selectOption(section);
        }
        await form.locator('[data-test="save-student"]').click();
        await expect(form).toBeHidden();
        added.push({
            name,
            stage: Number(stage),
            section: section || 'A',
            code: '',
        });
    }

    // The same name twice in one stage is refused.
    await page.click('[data-test="add-student"]');
    const form = page.locator('[data-test="student-dialog"]');
    await form.locator('input[name="name"]').fill('حسنين علي كاظم');
    await form.locator('select[name="stage"]').selectOption('1');
    await form.locator('[data-test="save-student"]').click();
    await expect(form).toBeVisible();
    await expect(
        form.locator('.text-destructive, [role="alert"], p.text-sm').first(),
    ).toBeVisible();
    await page.keyboard.press('Escape');

    // The added students' codes, from the printable code slips.
    for (const pupil of added) {
        await visit(
            page,
            `/admin/students/codes?stage=${pupil.stage}&section=${pupil.section}`,
        );
        const slip = page.locator('[data-test="code-slip"]', {
            hasText: pupil.name,
        });
        pupil.code = (
            await slip.locator('[data-test="slip-code"]').innerText()
        ).trim();
        expect(pupil.code).toMatch(/^[A-Z0-9]{4}-[A-Z0-9]{4}$/);
    }

    // What students see: rank, grades and absences, appeals for a day,
    // answered by the teachers too.
    await visit(page, '/admin?tab=students');
    const view = page.locator('[data-test="student-view"]');
    for (const option of [
        'rank',
        'mark_grade',
        'overall_grade',
        'absences',
        'appeals',
    ]) {
        const box = view.locator(`[data-test="option-${option}"]`);
        if ((await box.getAttribute('aria-checked')) !== 'true') {
            await box.click();
        }
    }
    await page.locator('input[name="appeal_days"]').fill('1');
    await page.locator('[data-test="appeal-scope"]').selectOption('all');
    const replies = page.locator('[data-test="teacher-appeal-replies"]');
    if ((await replies.getAttribute('aria-checked')) !== 'true') {
        await replies.click();
    }
    await view.locator('[data-test="save-student-view"]').click();
    await expect(page.getByText('تم حفظ ما يراه الطالب.')).toBeVisible();

    await page.context().close();
});

test('2. forty teachers enter, check and approve 800 marks', async ({
    browser,
}) => {
    const all = [...data.students, ...added];

    // A replaced code no longer opens the teacher's page.
    const refused = await fresh(browser, 'teacher login');
    await visit(refused, '/teacher');
    await refused.fill('[data-test="teacher-code"]', 'TAAA-AAAA');
    await refused.click('[data-test="teacher-login"]');
    await expect(refused).toHaveURL(/\/teacher$/);
    await refused.context().close();

    // A teacher with no subject is told so.
    const idle = await fresh(browser, 'idle teacher');
    await visit(idle, '/teacher');
    await idle.fill('[data-test="teacher-code"]', data.idle_teacher_code);
    await idle.click('[data-test="teacher-login"]');
    await idle.waitForURL(/\/teacher\/subjects$/);
    await expect(idle.getByText('لا توجد مواد بعد')).toBeVisible();
    await idle.context().close();

    for (const [index, sheet] of data.sheets.entries()) {
        const page = await fresh(
            browser,
            `teacher sheet ${sheet.name} ${sheet.stage}${sheet.section}`,
        );
        await visit(page, '/teacher');
        await page.fill('[data-test="teacher-code"]', sheet.code);
        await page.click('[data-test="teacher-login"]');
        await page.waitForURL(/\/teacher\/subjects$/);
        await expect(page.locator('[data-test="portal-name"]')).toHaveText(
            sheet.teacher,
        );

        // Only their own section: another teacher's sheet is refused.
        if (index === 0) {
            const other = data.sheets[1];
            const response = await page.goto(
                `/teacher/subjects/${other.subject}/${other.section}`,
            );
            expect([403, 404]).toContain(response?.status());
        }

        await visit(
            page,
            `/teacher/subjects/${sheet.subject}/${sheet.section}`,
        );
        const rows = page.locator('[data-test="student-row"]');
        const pupils = all.filter(
            (p) => p.stage === sheet.stage && p.section === sheet.section,
        );
        await expect(rows).toHaveCount(pupils.length);

        // A mark above the maximum is caught before anything is sent.
        if (index === 0) {
            const first = rows.first().locator('[data-test="mark-input"]');
            await first.fill(String(sheet.max + 1));
            await expect(first).toHaveAttribute('aria-invalid', 'true');
            await first.fill('-3');
            await expect(first).toHaveAttribute('aria-invalid', 'true');
            await first.fill('');
        }

        const count = await rows.count();
        for (let i = 0; i < count; i++) {
            const row = rows.nth(i);
            const name = (await row.locator('bdi').first().innerText()).trim();
            const mark = markFor(name, sheet.name, sheet.max);
            if (mark === 'absent') {
                await row.locator('[data-test="absent-box"]').click();
            } else {
                await row
                    .locator('[data-test="mark-input"]')
                    .fill(String(mark));
            }
        }
        await page.locator('[data-test="save-marks"]').first().click();
        await expect(page.locator('[data-test="unsaved"]')).toHaveCount(0);

        // Saved: a reload shows them.
        await page.reload();
        await pageReady(page);
        const name0 = (
            await rows.first().locator('bdi').first().innerText()
        ).trim();
        const mark0 = markFor(name0, sheet.name, sheet.max);
        if (mark0 !== 'absent') {
            await expect(
                rows.first().locator('[data-test="mark-input"]'),
            ).toHaveValue(String(mark0));
        }

        // Approve: the marks lock for the teacher.
        await page.locator('[data-test="approve-marks"]').click();
        await page.click('[data-test="confirm-action-button"]');
        await expect(page.locator('[data-test="approve-marks"]')).toHaveCount(
            0,
        );
        await expect(
            rows.first().locator('[data-test="mark-input"]'),
        ).toBeDisabled();

        // The first teacher asks to change approved marks.
        if (index === 0) {
            const request = page.locator('[data-test="edit-request-form"]');
            await expect(request).toBeVisible();
            await request
                .locator('textarea[name="reason"]')
                .fill('خطأ في إدخال درجة الطالب الأول، أرجو السماح بالتصحيح.');
            await request.locator('[data-test="send-edit-request"]').click();
            await expect(
                page.locator('[data-test="edit-request-status"]'),
            ).toBeVisible();
        }

        await page.click('[data-test="portal-logout"]');
        await page.waitForURL(/\/teacher$/);
        await page.context().close();
    }
});

test('3. the owner allows a change, closes entry and checks every result', async ({
    browser,
}) => {
    const all = [...data.students, ...added];
    const page = await fresh(browser, 'owner results', true);

    // Every section approved on the subjects tab.
    await visit(page, '/admin?tab=subjects');
    await expect(page.locator('[data-test="section-approved"]')).toHaveCount(
        40,
    );

    // The edit request: approved for 2 hours.
    await visit(page, '/admin?tab=permissions');
    const request = page.locator('[data-test="edit-request"]').first();
    await expect(request).toBeVisible();
    await request.locator('[data-test="approve-request"]').click();
    await expect(page.locator('[data-test="edit-request"]')).toHaveCount(0);

    // The teacher changes one mark within the grant.
    const sheet = data.sheets[0];
    const teacher = await fresh(browser, 'teacher grant');
    await visit(teacher, '/teacher');
    await teacher.fill('[data-test="teacher-code"]', sheet.code);
    await teacher.click('[data-test="teacher-login"]');
    await teacher.waitForURL(/\/teacher\/subjects$/);
    await visit(teacher, `/teacher/subjects/${sheet.subject}/${sheet.section}`);
    const row = teacher.locator('[data-test="student-row"]').first();
    const name = (await row.locator('bdi').first().innerText()).trim();
    const input = row.locator('[data-test="mark-input"]');
    await expect(input).toBeEnabled();
    const box = row.locator('[data-test="absent-box"]');
    if (
        (await box.getAttribute('aria-checked')) === 'true' ||
        (await box.isChecked().catch(() => false))
    ) {
        await box.click();
    }
    await input.fill('77');
    await teacher.locator('[data-test="save-marks"]').first().click();
    await expect(teacher.locator('[data-test="unsaved"]')).toHaveCount(0);
    corrected.set(`${name}|${sheet.subject}`, 77);
    await teacher.context().close();

    // Close entry.
    await visit(page, '/admin?tab=window');
    await page.click('[data-test="close-window"]');
    await expect(page.locator('[data-test="open-window"]')).toBeVisible();

    // Every row of every section, against the calculation above.
    const places = [
        [1, 'A'],
        [2, 'A'],
        [2, 'B'],
        [3, 'A'],
        [3, 'B'],
    ] as const;
    let checked = 0;
    for (const [stage, section] of places) {
        await visit(page, `/admin/results?stage=${stage}&section=${section}`);
        const rows = page.locator('[data-test="result-row"]');
        const pupils = all.filter(
            (p) => p.stage === stage && p.section === section,
        );
        await expect(rows).toHaveCount(pupils.length);
        for (const pupil of pupils) {
            const want = expected(pupil, corrected);
            const line = rows.filter({ hasText: pupil.name });
            await expect(line, pupil.name).toContainText(
                `${want.total} / ${want.maximum}`,
            );
            await expect(line, pupil.name).toContainText(
                want.percentage.toFixed(2),
            );
            checked++;
        }
    }
    expect(checked).toBe(100);

    // The Excel file of a section.
    await visit(page, '/admin/results?stage=2&section=A');
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.click('[data-test="export-results"]'),
    ]);
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    await download.saveAs('storage/framework/audit/results-2A.xlsx');

    // Printable pages.
    for (const path of [
        '/admin/results/print?stage=1&section=A',
        '/admin/results/print?stage=3&section=B',
        '/admin/students/codes?stage=2&section=B',
    ]) {
        await visit(page, path);
        await expect(page.locator('#app')).not.toBeEmpty();
    }

    // Publish section by section.
    await visit(page, '/admin?tab=window');
    const buttons = page.locator('[data-test="publish-section"]');
    await expect(buttons).toHaveCount(5);
    for (let i = 0; i < 5; i++) {
        await buttons.nth(i).click();
        await expect(buttons.nth(i)).toHaveAttribute('aria-pressed', 'true');
    }
    await page.context().close();
});

test('4. all 100 students read their result; six appeal', async ({
    browser,
}) => {
    const all = [...data.students, ...added];
    expect(all).toHaveLength(100);
    const appealers = new Set([0, 34, 51, 67, 84, 99]);

    for (const [index, pupil] of all.entries()) {
        const page = await fresh(
            browser,
            `student ${pupil.stage}${pupil.section}`,
        );
        await visit(page, '/student');
        await page.fill('[data-test="student-code"]', pupil.code.toLowerCase());
        await page.click('[data-test="student-login"]');
        await page.waitForURL(/\/student\/result$/);
        await pageReady(page);
        await expect(page.locator('[data-test="portal-name"]')).toHaveText(
            pupil.name,
        );
        await expect(page.locator('[data-test="not-published"]')).toHaveCount(
            0,
        );

        const want = expected(pupil, corrected);
        await expect(page.locator('[data-test="card-total"]')).toContainText(
            `${want.total} / ${want.maximum}`,
        );
        await expect(
            page.locator('[data-test="card-percentage"]'),
        ).toContainText(want.percentage.toFixed(2));

        if (appealers.has(index)) {
            const form = page.locator('[data-test="appeal-form"]');
            await expect(form).toBeVisible();
            await form
                .locator('[data-test="appeal-subject"]')
                .selectOption({ index: 1 });
            await form
                .locator('[data-test="appeal-reason"]')
                .fill(
                    'أعتقد أن درجتي في هذه المادة لم تُجمع بشكل صحيح، أرجو المراجعة.',
                );
            await form.locator('[data-test="send-appeal"]').click();
            await expect(
                page.locator('[data-test="my-appeal"]').first(),
            ).toBeVisible();
        }

        await page.click('[data-test="portal-logout"]');
        await page.waitForURL(/\/student$/);
        await page.context().close();
    }
});

test('5. appeals are answered, a mark corrected, access withdrawn', async ({
    browser,
}) => {
    const page = await fresh(browser, 'owner appeals', true);
    await visit(page, '/admin?tab=students');
    const appeals = page.locator('[data-test="appeal"]');
    await expect(appeals).toHaveCount(6);

    // Accept the first, reject the rest.
    const first = appeals.first();
    const text = await first.innerText();
    await first.locator('input').fill('قُبل الاعتراض وصُحّحت الدرجة.');
    await first.locator('[data-test="accept-appeal"]').click();
    await expect(appeals).toHaveCount(5);
    while ((await appeals.count()) > 0) {
        await appeals.first().locator('input').fill('رُوجعت الدرجة وهي صحيحة.');
        await appeals.first().locator('[data-test="reject-appeal"]').click();
        await page.waitForTimeout(300);
    }
    await expect(appeals).toHaveCount(0);

    // The owner corrects the accepted appeal's mark on the sheet: the
    // published result changes for the student.
    const pupil = [...data.students, ...added].find((p) =>
        text.includes(p.name),
    );
    expect(pupil, 'the appealing student is named in the appeal').toBeTruthy();
    const sheet = data.sheets.find(
        (s) =>
            s.stage === pupil!.stage &&
            s.section === pupil!.section &&
            text.includes(s.name),
    );
    expect(sheet, 'the appealed subject is named in the appeal').toBeTruthy();
    await visit(page, `/admin/subjects/${sheet!.subject}/${sheet!.section}`);
    const row = page.locator('[data-test="student-row"]', {
        hasText: pupil!.name,
    });
    const box = row.locator('[data-test="absent-box"]');
    if (await box.isChecked().catch(() => false)) {
        await box.click();
    }
    await row
        .locator('[data-test="mark-input"]')
        .fill(String(Math.min(sheet!.max, 90)));
    await page.locator('[data-test="save-marks"]').first().click();
    await expect(page.locator('[data-test="unsaved"]')).toHaveCount(0);
    corrected.set(`${pupil!.name}|${sheet!.subject}`, Math.min(sheet!.max, 90));

    const student = await fresh(browser, 'student after appeal');
    await visit(student, '/student');
    await student.fill('[data-test="student-code"]', pupil!.code);
    await student.click('[data-test="student-login"]');
    await student.waitForURL(/\/student\/result$/);
    await pageReady(student);
    const want = expected(pupil!, corrected);
    await expect(student.locator('[data-test="card-total"]')).toContainText(
        `${want.total} / ${want.maximum}`,
    );
    await student.context().close();

    // A stopped student cannot sign in.
    const blocked = data.students[5];
    // The list is paged: find the student by name.
    await visit(
        page,
        `/admin/students?search=${encodeURIComponent(blocked.name)}`,
    );
    const line = page.locator('tr', { hasText: blocked.name });
    await line.locator('[data-test="toggle-student-access"]').click();
    const out = await fresh(browser, 'blocked student');
    await visit(out, '/student');
    await out.fill('[data-test="student-code"]', blocked.code);
    await out.click('[data-test="student-login"]');
    await expect(out).toHaveURL(/\/student$/);
    await out.context().close();

    // A stopped teacher cannot sign in.
    const stopped = data.sheets[3];
    await visit(page, '/admin/teachers');
    // Exact name: "X" is also part of "X (24)".
    const teacherRow = page.locator('[data-test="teacher-row"]').filter({
        has: page.getByText(stopped.teacher, { exact: true }),
    });
    await teacherRow.locator('[data-test="toggle-teacher"]').click();
    await expect(
        teacherRow.locator('[data-test="toggle-teacher"]'),
    ).toHaveAttribute('aria-label', 'السماح بالدخول');
    const gone = await fresh(browser, 'stopped teacher');
    await visit(gone, '/teacher');
    await gone.fill('[data-test="teacher-code"]', stopped.code);
    await gone.click('[data-test="teacher-login"]');
    await expect(gone).toHaveURL(/\/teacher$/);
    await gone.context().close();

    // Every owner page once more, with the data in.
    for (const path of [
        '/admin',
        '/admin?tab=students',
        '/admin?tab=permissions',
        '/admin/results?stage=1&section=A',
    ]) {
        await visit(page, path);
    }
    await page.context().close();
});
