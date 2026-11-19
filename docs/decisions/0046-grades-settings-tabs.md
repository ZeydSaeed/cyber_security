# ADR-0046: The settings page in tabs

- Status: Accepted (owner's request and choices, 2026-11-19; phase G13, fourth round)
- Amends: ADR-0043, ADR-0044 (the layout of `/admin`)

## Context

`/admin` ("Settings") had grown into one long page. The owner asked for
it to be split into tabs in the top toolbar, in the style of a segmented
"nav-tab" bar, with each tab a page of its own and a Close button. The
tabs they named:

1. mark entry and publication;
2. the teachers' and students' links;
3. teachers' sign-in and mark editing;
4. students' sign-in (and what they see);
5. teachers' permissions;
6. the result card;
7. what teachers see of their students' results;
8. subjects and teachers.

On colours, the owner chose to keep the current palette (navy, steel
blue, amber on linen), which already reads as classic, rather than
change `palette.json`. Asked what Close should lead to, they chose a
landing page of tabs.

## Decision

- **Addresses.** `/admin?tab=<key>` opens a tab. The keys are `window`,
  `links`, `teachers`, `students`, `permissions`, `card`,
  `teacher-view` and `subjects` (`GradesAdminController::TABS`).
- **Landing page.** `/admin` alone, or an unknown key, shows one card per
  tab, each with a line of its state (entry open or closed, appeals or
  requests waiting, sign-ins and so on).
- **Toolbar.** The tab bar sits in the top toolbar: one link per tab,
  the open one filled in the primary colour and marked `aria-current`,
  with a count where something waits. It scrolls sideways on a phone.
  An open tab also shows Close, which leads back to the landing page.
- **Who sees which tabs.** Those who only read results see the first and
  the last tab.
- **Teachers' and students' rules.** These now have separate forms.
  `PUT /admin/access` takes one side (detected by `teacher_mode` or
  `student_access`) or both, and keeps the saved values of the side it
  does not receive. "Remember a device" is on the teachers' tab.
- **Students' tab.** It holds the students' rules, "What the student
  sees" with the appeals (still a form of its own, ADR-0044), the appeals
  waiting, and the students' sign-ins.
- **Result card tab and what teachers see.** The result settings panel
  takes a `part`, so each has its own tab.
- **Colours.** Token utilities only (`primary`, `card`, `border-strong`,
  `accent`, `toolbar`), so contrast stays within the pairs the
  generator checks (COLOR-DESIGN-SYSTEM). No palette change.

## Consequences

- All the tabs' data is still sent with each tab, as before. The page
  stays one Inertia page.
- Links to `/admin` from elsewhere now reach the landing page.

## Reversal

Render every part with no tab, as before ADR-0046. The forms and
endpoints do not depend on the tabs.

## Amendment (owner's request, 2026-11-19): cards only, each in its colour

- **No tab bar.** The bar in the toolbar is removed. The landing cards are
  the only way between tabs, and a card shows the count where something
  waits.
- **Close** sits beside the previous, next and home buttons, through a
  second toolbar slot (`PageToolbarNav` in `page-toolbar.tsx`) that any
  page may use.
- **A colour per card.** The owner asked for each card to have its own
  colour. This supersedes "No palette change" above: eight project roles,
  `tile-1` … `tile-8` with their foregrounds, were added to
  `palette.json` (green, blue, navy, amber, red, plum, olive, teal; the
  400 steps in dark). Each card has a stripe on top and its icon's square
  in its colour. Declared pairs: foreground on tile at 4.5:1 and tile on
  card at 3:1. All pass (palette report).
