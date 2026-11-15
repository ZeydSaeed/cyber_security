# ADR-0035: Colour design system adopted (ENG-DESIGN-COLOR-001 1.1.0)

- Status: Accepted
- Date: 2026-11-15

## Context

cyber_security inherited ADR-0024's hand-written tokens. The owner's layout
(2026-11-15) then added hand-written tokens and ten raw HEX blend colours in
`app.css`. In the dark theme, the teachers' subject cards were 1.05:1 (light
text on a light Maroona card). The template has since adopted a centralized
colour design system.

## Problem

Keep the owner's palette and layout, make a palette change a single central
edit, fix the dark theme, and stay independent of the template.

## Options

1. Keep the local tokens and fix the dark cards by hand.
2. Adopt the standard as an independent copy, adapted to this project.

## Decision

Option 2. This project's copy of `docs/standards/COLOR-DESIGN-SYSTEM.md`
records "Adopted: 1.1.0" and its local adaptations:

- **Palette:** Old Leaf `#023047`, `#219EBC`, Maroona `#FFB703`, the error
  red `#8E1A11`, Mendung Parah `#8ECAE6` and Mendung `#FB8500`.
- **Roles:** set in `palette.json` to keep the owner's layout.
- **Project roles:** `ink` and `highlight-soft*`.
- **L3 tokens:** sidebar = brand-surface; toolbar, table head and headings =
  highlight; `grid-line`.
- **Gradients:** five declared gradients replace the raw blend colours.

The generator, tests and browser check are local copies. Nothing imports,
links or reads the template.

## Consequences

- Changing a palette colour touches only `palette.json` and generated files
  (Single Token Change Test).
- The dark subject cards now pass.
- A few values moved slightly; the owner approved them on 2026-11-15:
    - light muted 0.872 → 0.857
    - light field borders 0.481 → 0.45
    - light success 0.393 → 0.37
    - dark destructive becomes dark text on 0.62
- Standard updates are applied here by hand after a compatibility review.

## Security implications

None at runtime.

## Performance implications

CSS 16.2 kB gzipped (budget 20 kB).

## Reversal or migration strategy

The generated CSS works without the generator. Evidence:
`docs/evidence/color-system/cyber-acceptance.md`.
