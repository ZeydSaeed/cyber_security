// Client-side navigation and scrolling, measured in a real browser
// (Chromium through Playwright). Compares builds served side by side, each
// with its own database, e.g. the commit before a change and the one after:
//
//   node scripts/perf/navigation.mjs \
//       before=http://127.0.0.1:8131@<worktree>/storage/app/private/browser/user.json \
//       after=http://127.0.0.1:8132@<worktree>/storage/app/private/browser/user.json \
//       --repeats 3 --rounds 4 --out docs/evidence/<dir>
//
// Each target is a browser-test build (BrowserTestSeeder: its user.json
// holds the e-mail, password and authenticator secret). Only local hosts
// are accepted.
//
// Navigation: from the Grades overview, the sidebar's links are visited in
// turn, as a person would: the pointer rests on the link for `dwell` ms,
// then clicks. Two times per visit, from the click:
//   - shown:   the new page is in the DOM and two frames have painted;
//   - settled: also any page transition has finished.
// Scrolling: the longest of those pages is scrolled with the wheel for a
// few seconds, at full speed and with the CPU slowed 4x (a modest laptop),
// and the frame intervals are recorded.
//
// Targets alternate (A B A B ...) so drift on the machine hits both alike.
import { chromium } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';

const args = process.argv.slice(2);
const option = (name, fallback) => {
    const i = args.indexOf(`--${name}`);

    return i === -1 ? fallback : args[i + 1];
};
const repeats = Number(option('repeats', 3));
const rounds = Number(option('rounds', 4));
const dwells = option('dwells', '0,300').split(',').map(Number);
const out = option('out', null);
const targets = args
    .filter((a) => /^[a-z0-9-]+=/i.test(a))
    .map((a) => {
        const [label, rest] = a.split(/=(.*)/s);
        const [base, userFile] = rest.split('@');
        const host = new URL(base).hostname;

        if (!['127.0.0.1', 'localhost'].includes(host)) {
            throw new Error(`Refusing a non-local host: ${base}`);
        }

        return {
            label,
            base,
            user: JSON.parse(readFileSync(userFile, 'utf8')),
        };
    });

if (targets.length === 0) {
    throw new Error('No targets. See the header of this file.');
}

// RFC 6238, SHA-1, 30 s, 6 digits.
function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';

    for (const c of secret.replace(/=+$/, '').toUpperCase()) {
        bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    }

    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return String(code).padStart(6, '0');
}

async function signIn(browser, target) {
    const context = await browser.newContext({ baseURL: target.base });
    const page = await context.newPage();

    await context.addCookies([
        { name: 'locale', value: 'en', url: target.base },
    ]);
    await page.goto('/login');
    await page.fill('#email', target.user.email);
    await page.fill('#password', target.user.password);
    await page.click('[data-test="login-button"]');
    await page.waitForURL('**/two-factor-challenge');

    if (Date.now() % 30_000 > 25_000) {
        await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 500);
    }

    await page.locator('input[name="code"]').fill(totp(target.user.secret));
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.waitForURL('**/admin');
    target.state = await context.storageState();
    await context.close();
}

// Runs in the page before its own scripts.
function probe() {
    window.__navs = [];
    let t0 = null;
    let transition = null;

    document.addEventListener(
        'click',
        (event) => {
            if (event.target.closest?.('a[href]')) {
                t0 = performance.now();
                transition = null;
            }
        },
        true,
    );

    if (document.startViewTransition) {
        const original = document.startViewTransition.bind(document);

        document.startViewTransition = (...a) => {
            const vt = original(...a);
            transition = vt.finished.catch(() => null);

            return vt;
        };
    }

    document.addEventListener('inertia:navigate', () => {
        if (t0 === null) {
            return;
        }

        const start = t0;
        t0 = null;
        requestAnimationFrame(() =>
            requestAnimationFrame(async () => {
                const shown = performance.now() - start;
                const usedTransition = transition !== null;
                await (transition ?? Promise.resolve());
                window.__navs.push({
                    path: location.pathname,
                    shown,
                    settled: performance.now() - start,
                    transition: usedTransition,
                });
            }),
        );
    });
}

async function navigation(browser, target, dwell) {
    const context = await browser.newContext({
        baseURL: target.base,
        storageState: target.state,
        viewport: { width: 1280, height: 800 },
    });
    await context.addInitScript(probe);
    const page = await context.newPage();
    await page.goto('/admin');
    await page.waitForLoadState('networkidle');

    const paths = await page.$$eval(
        '[data-slot="sidebar"] a[href]',
        (links) => [
            ...new Set(
                links
                    .map((a) => new URL(a.href))
                    .filter((u) => u.origin === location.origin)
                    .map((u) => u.pathname),
            ),
        ],
    );
    const visits = [];

    for (let round = 0; round < rounds; round++) {
        for (const path of paths) {
            const current = await page.evaluate(() => location.pathname);

            if (current === path) {
                continue;
            }

            const link = page
                .locator(`[data-slot="sidebar"] a[href$="${path}"]`)
                .first();
            const before = await page.evaluate(() => window.__navs.length);
            await link.hover();
            await page.waitForTimeout(dwell);
            await link.click();
            await page.waitForFunction(
                (n) => window.__navs.length > n,
                before,
                { timeout: 30_000 },
            );
            const visit = await page.evaluate(() => window.__navs.at(-1));
            visits.push({ ...visit, round });
            // A person reads the page before moving on.
            await page.waitForTimeout(400);
        }
    }

    await context.close();

    return { paths, visits };
}

async function scrolling(browser, target, paths, slowdown) {
    const context = await browser.newContext({
        baseURL: target.base,
        storageState: target.state,
        viewport: { width: 1280, height: 600 },
        colorScheme: 'light',
    });
    const page = await context.newPage();
    let longest = { path: '/admin', height: 0 };

    for (const path of paths) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        const height = await page.evaluate(
            () => document.documentElement.scrollHeight,
        );

        if (height > longest.height) {
            longest = { path, height };
        }
    }

    await page.goto(longest.path);
    await page.waitForLoadState('networkidle');
    const cdp = await context.newCDPSession(page);
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: slowdown });

    await page.evaluate(() => {
        window.__frames = [];
        let last = performance.now();
        const tick = (now) => {
            window.__frames.push(now - last);
            last = now;

            if (!window.__stopFrames) {
                requestAnimationFrame(tick);
            }
        };
        requestAnimationFrame(tick);
    });
    await page.mouse.move(800, 300);
    const end = Date.now() + 4000;
    let direction = 1;

    while (Date.now() < end) {
        const atEdge = await page.evaluate(
            (d) =>
                d > 0
                    ? window.scrollY + innerHeight >=
                      document.documentElement.scrollHeight - 2
                    : window.scrollY <= 0,
            direction,
        );

        if (atEdge) {
            direction = -direction;
        }

        await page.mouse.wheel(0, 60 * direction);
        await page.waitForTimeout(16);
    }

    const frames = await page.evaluate(() => {
        window.__stopFrames = true;

        return window.__frames.slice(1);
    });
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 1 });
    await context.close();

    return { path: longest.path, height: longest.height, slowdown, frames };
}

const quantile = (values, q) => {
    if (values.length === 0) {
        return NaN;
    }

    const sorted = [...values].sort((a, b) => a - b);

    return sorted[Math.min(sorted.length - 1, Math.floor(q * sorted.length))];
};
const fixed = (n) => (Number.isFinite(n) ? n.toFixed(1) : '-');

const browser = await chromium.launch();
const results = {
    started: new Date().toISOString(),
    environment: {
        os: `${os.type()} ${os.release()}`,
        cpu: os.cpus()[0]?.model,
        cores: os.cpus().length,
        memoryGb: Math.round(os.totalmem() / 2 ** 30),
        node: process.version,
        chromium: browser.version(),
        headless: true,
    },
    settings: { repeats, rounds, dwells },
    targets: Object.fromEntries(
        targets.map((t) => [t.label, { base: t.base, runs: [] }]),
    ),
};

for (const target of targets) {
    await signIn(browser, target);
}

// Warm-up, not recorded: PHP caches, compiled views, the browser's disk.
for (const target of targets) {
    await navigation(browser, target, 0);
}

for (let r = 0; r < repeats; r++) {
    for (const target of targets) {
        for (const dwell of dwells) {
            const { paths, visits } = await navigation(browser, target, dwell);
            results.targets[target.label].paths = paths;
            results.targets[target.label].runs.push({
                kind: 'navigation',
                repeat: r,
                dwell,
                visits,
            });
            console.log(
                `${target.label} repeat ${r} dwell ${dwell}: ${visits.length} visits`,
            );
        }

        for (const slowdown of [1, 4]) {
            const scroll = await scrolling(
                browser,
                target,
                results.targets[target.label].paths,
                slowdown,
            );
            results.targets[target.label].runs.push({
                kind: 'scroll',
                repeat: r,
                ...scroll,
            });
            console.log(
                `${target.label} repeat ${r} scroll x${slowdown}: ${scroll.frames.length} frames on ${scroll.path}`,
            );
        }
    }
}

await browser.close();
results.finished = new Date().toISOString();

// Summary.
const lines = [];
lines.push(
    '| Target | Pointer rests | Visits | Shown median | Shown p90 | Settled median | Settled p90 | With transition |',
    '| --- | --- | --- | --- | --- | --- | --- | --- |',
);

for (const [label, data] of Object.entries(results.targets)) {
    for (const dwell of dwells) {
        const visits = data.runs
            .filter((run) => run.kind === 'navigation' && run.dwell === dwell)
            .flatMap((run) => run.visits);
        const shown = visits.map((v) => v.shown);
        const settled = visits.map((v) => v.settled);
        lines.push(
            `| ${label} | ${dwell} ms | ${visits.length} | ${fixed(quantile(shown, 0.5))} ms | ${fixed(quantile(shown, 0.9))} ms | ${fixed(quantile(settled, 0.5))} ms | ${fixed(quantile(settled, 0.9))} ms | ${visits.filter((v) => v.transition).length} |`,
        );
    }
}

lines.push(
    '',
    '| Target | CPU | Page | Frames | Mean fps | Frame p95 | Frames > 33 ms |',
    '| --- | --- | --- | --- | --- | --- | --- |',
);

for (const [label, data] of Object.entries(results.targets)) {
    for (const slowdown of [1, 4]) {
        const runs = data.runs.filter(
            (run) => run.kind === 'scroll' && run.slowdown === slowdown,
        );
        const frames = runs.flatMap((run) => run.frames);
        const mean = frames.reduce((a, b) => a + b, 0) / frames.length;
        const long = frames.filter((f) => f > 33.4).length;
        lines.push(
            `| ${label} | ${slowdown === 1 ? 'full speed' : `${slowdown}x slower`} | ${runs[0]?.path} | ${frames.length} | ${fixed(1000 / mean)} | ${fixed(quantile(frames, 0.95))} ms | ${long} (${fixed((100 * long) / frames.length)} %) |`,
        );
    }
}

const summary = lines.join('\n');
console.log('\n' + summary);

if (out) {
    mkdirSync(out, { recursive: true });
    writeFileSync(`${out}/results.json`, JSON.stringify(results, null, 2));
    writeFileSync(`${out}/summary.md`, summary + '\n');
}
