# Client navigation and scrolling, before and after the UI batch (2026-11-20)

Status: **Tested** (local, headless Chromium). Not a production figure.

## What was compared

| Target                | Build                                                                                   | Port |
| --------------------- | --------------------------------------------------------------------------------------- | ---- |
| `before`              | `79e68a2` (before the UI batch)                                                         | 8131 |
| `after-transition`    | `6a04fdd` + the `visitOptions` guard fix (view transitions really on)                   | 8132 |
| `after-no-transition` | `6a04fdd` with the `defaults.visitOptions` block removed (prefetch, chunk warm-up, CSS) | 8133 |

Note: `6a04fdd` as committed never turned the transition on (Inertia's
`<Link>` passes `viewTransition: false`, which the first guard respected), so
in practice it behaves like `after-no-transition`.

Each target ran from its own git worktree, own `npm run build`, own SQLite
database seeded with `BrowserTestSeeder`, served by
`php artisan serve --no-reload` (one request at a time on Windows).

## Environment

- Windows 10 Pro 10.0.19045, Intel Core i5-7300U (4 logical cores), 16 GB.
- Node v22.13.0, Playwright Chromium 158.0.8127.18, headless, 1280×800.
- Started 2026-11-19T21:37:59Z, finished 21:48:11Z (UTC).

## Scenario

`node scripts/perf/navigation.mjs` (header of that file):

- Signed in as the browser-test administrator (password + TOTP), English.
- From `/admin`, visit each sidebar link in turn (6 pages), 3 rounds, 3
  repeats per target, targets alternating; one unrecorded warm-up per target.
- Pointer rests on the link for 0 ms (direct click) or 300 ms before
  clicking; 400 ms pause after each page.
- **Shown**: click → Inertia `navigate` event + two painted frames.
  **Settled**: also until any view transition has finished.
- Scrolling: `/admin/students` (longest page), wheel for 4 s, 1280×600, at
  full speed and with CDP CPU throttling ×4; frame intervals from
  `requestAnimationFrame`.

## Results (51 visits per row)

| Target              | Pointer rests | Shown median | Shown p90 | Settled median | Settled p90 |
| ------------------- | ------------- | ------------ | --------- | -------------- | ----------- |
| before              | 0 ms          | 137.4 ms     | 207.3 ms  | 137.4 ms       | 207.3 ms    |
| before              | 300 ms        | 44.5 ms      | 125.5 ms  | 44.5 ms        | 125.5 ms    |
| after-transition    | 0 ms          | 230.1 ms     | 325.5 ms  | 496.0 ms       | 598.6 ms    |
| after-transition    | 300 ms        | 110.9 ms     | 187.9 ms  | 374.2 ms       | 465.4 ms    |
| after-no-transition | 0 ms          | 116.2 ms     | 228.3 ms  | 116.2 ms       | 228.4 ms    |
| after-no-transition | 300 ms        | 43.5 ms      | 123.0 ms  | 43.5 ms        | 123.1 ms    |

Scrolling: every target 57.8–60.0 fps mean, frame p95 16.7 ms, at most
0.6 % of frames over 33 ms, with and without CPU throttling. Raw:
`summary.md`, `results.json`, `run.txt`.

## Reading

- View transitions cost time: the new page is shown ~90 ms later (median)
  and settles ~260–360 ms later than without them.
- Resting on a link (hover prefetch) cuts the median from ~137 ms to
  ~44 ms. The sidebar already prefetched before this batch, so this shows
  what the 23 newly prefetched links gain; those links were not measured
  directly.
- `after-no-transition` vs `before`: direct-click median 116 vs 137 ms; the
  p90 is a little higher (228 vs 207 ms). Within the noise of 51 samples on
  a single-request server; no claim of improvement.
- The scrolling change (page gradient off `background-attachment: fixed`)
  shows no measurable difference here. Headless Chromium on this machine
  scrolls at 60 fps either way; real GPUs and displays were not measured.

## Limits

- One machine, headless, local server answering one request at a time; the
  browser and server share the CPU. Absolute numbers are not those of the
  target host.
- Only the sidebar route; tables, forms and the teacher/student portals were
  not measured.

## Decision

Owner's approval, 2026-11-20: the page-to-page view transition is removed
(`resources/js/app.tsx`, `resources/js/lib/navigation.ts`, `app.css`).
Hover prefetch, page-chunk warm-up, the composited page gradient and the
touch and button changes stay. The shipped build is the
`after-no-transition` target above.
