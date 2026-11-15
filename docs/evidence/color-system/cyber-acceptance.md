# Colour design system: cyber_security acceptance tests (G5)

Directive ENG-DESIGN-COLOR-001, standard
`docs/standards/COLOR-DESIGN-SYSTEM.md` adopted at 1.1.0. Run on 2026-11-15,
Windows 10, PHP 8.5.12 (Herd), Node 22.13.0, Chromium headless shell from
`.tools/ms-playwright`. Commit `bab292a` (G4).

| #   | Requirement                                | Result                                                  |
| --- | ------------------------------------------ | ------------------------------------------------------- |
| 1   | Centralized palette configuration          | PASS                                                    |
| 2   | Semantic token integration                 | PASS                                                    |
| 3   | Global UI coverage                         | PASS                                                    |
| 4   | Light and dark themes                      | PASS                                                    |
| 5   | Contrast requirements                      | PASS                                                    |
| 6   | Hardcoded colour detection                 | PASS                                                    |
| 7   | Component compliance                       | PASS                                                    |
| 8   | Existing application tests                 | PASS (one load-related failure, passed on rerun; below) |
| 9   | Production build                           | PASS                                                    |
| 10  | Independent operation without the template | PASS                                                    |

## 1. Single Token Change Test

Procedure (scripted, then reverted): `colors.primary.hex` in
`resources/design-system/palette.json` from Old Leaf `#023047` to `#2B0F4C`,
then `npm run palette`, `npm run build`, the colour tests and
`tests/browser/colors.spec.ts`, then restore and rebuild.

- Files changed: `palette.json` and the four generated files. Component files
  changed: 0. `app.blade.php` changed: 0.
- `themes.css` changed in one line: `chart-1` moved from `primary-700` to
  `primary-600` by itself, because with the new colour 700 no longer reached
  3:1 on the card. No other role moved.
- Built CSS: identical once token declarations are removed.
- `DesignTokensTest` + `ColorSystemTest`: 14 passed with the new colour.
- Browser, what components paint:

| Element (token)                     | Old Leaf, light         | `#2B0F4C`, light          | Old Leaf, dark           | `#2B0F4C`, dark           |
| ----------------------------------- | ----------------------- | ------------------------- | ------------------------ | ------------------------- |
| Primary button (`button-primary`)   | oklch(0.293 0.062 237)  | oklch(0.252 0.105 299.6)  | oklch(0.808 0.073 228.7) | unchanged (Mendung Parah) |
| Sidebar (`sidebar` = brand-surface) | oklch(0.293 0.062 237)  | oklch(0.252 0.105 299.6)  | oklch(0.293 0.062 237)   | oklch(0.252 0.105 299.6)  |
| Page (`background`)                 | oklch(0.98 0.012 228.7) | unchanged (Mendung Parah) | oklch(0.2 0.037 237)     | oklch(0.2 0.063 299.6)    |
| Toolbar, table head (`highlight`)   | oklch(0.825 0.171 79.9) | unchanged (Maroona)       | oklch(0.825 0.171 79.9)  | unchanged (Maroona)       |

Exactly the roles mapped to the primary family changed; roles mapped to
Mendung Parah and Maroona stayed, as palette.json says.

## 2–3. Semantic tokens and global coverage

- `app.css` holds no colour literal; the ten hand-written blend colours are
  gradient tokens from `palette.json`.
- 44 canonical roles, 4 project roles (`ink`, `highlight-soft*`), 20 L3
  tokens (buttons, fields, links, tables, grid lines, toolbar, headings,
  sidebar), 5 gradients.
- Browser (`colors.spec.ts`): body, sidebar, data-table head, primary button
  and toolbar paint exactly their tokens in both themes.

## 4. Light and dark themes

- Both themes define the same 53 roles; every dark role equal to light is an
  explicit palette.json decision (the Old Leaf sidebar and Maroona highlight
  stay in dark, as the owner asked) or a declared gradient.
- The dark defect found in G0 is fixed: the teachers' subject cards were
  1.05:1 (light text on light Maroona); in dark they are now
  `highlight-soft` accent-950 to accent-900 with `ink` Mendung Parah text,
  checked as declared pairs.
- `colors.spec.ts`: `background`, `card`, `primary`, `foreground` differ
  between themes.

## 5. Contrast

- `DesignTokensTest`: 55 declared pairs × 2 themes, 0 failures.
- Generator report: 116 PASS, 0 FAIL, including every gradient stop.
- Browser: axe WCAG 2.2 A/AA on every page in Arabic and English, light and
  dark, phone, tablet and desktop (`pages.spec.ts`): passed.

## 6–7. Hardcoded colours and component compliance

- `ColorSystemTest`: 0 offenders in every `.tsx` (including `components/ui`)
  and Blade view; layers, duplicates, missing and unused tokens, exceptions
  and generated files all pass.
- Temporary component built from project tokens (`highlight-soft`, `ink`,
  `bg-gradient-heading`, `grid-line`): passed. Temporary component with
  `bg-amber-400`, `text-[#023047]`, `bg-[oklch(...)]`, `from-[orange]`, an
  inline `backgroundColor` and `'#ffb703'`: every form reported. Both removed.

## 8. Existing application tests

- `composer test`: Pint, PHPStan 0 errors, deptrac 0 violations, Pest 420
  passed, 42 skipped, 0 failed.
- `npx playwright test --project=chromium`: 288 of 289 passed (16.6 min).
  The failure, `forms.spec.ts` "invitation (ar)", timed out waiting 15 s for
  the server while a second build ran on the machine (the independence test
  below); the English run of the same test passed in 6.4 s. Rerun alone:
  `forms.spec.ts` 10 of 10 passed, "invitation (ar)" in 5.9 s. Not a colour
  change; recorded, not hidden.
- Not run: Firefox and WebKit (CI runs them).

## 9. Production build

`npm run build`: pass. `npm run budget`: pass (CSS 16.2 kB of 20 kB).
`npm run check` (lint, format, `palette:check`) and `tsc --noEmit`: pass.

## 10. Independence

- No file in `app`, `config`, `resources`, `scripts`, `tests`, `routes`,
  `vite.config.ts` or `package.json` names the template or an absolute
  project path; no symbolic links; no `.gitmodules`.
- Operation without the template: `git clone` of commit `bab292a` into a
  scratch folder where `../Engineering-Template` does not exist, with copies
  of `vendor` and `node_modules`. In that copy: `npm run palette:check` pass;
  regenerating changed 0 files; `npm run build` pass; Pest 420 passed,
  42 skipped, 0 failed; `colors.spec.ts` 3 of 3 passed. The template folder
  was not touched.
