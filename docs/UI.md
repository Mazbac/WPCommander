# UI and interaction contract

The UI must feel intentionally designed across time, not merely look good on the first screen. Industry/platform guidance and existing product patterns outrank AI taste.

## Core rules

- KISS: if removing copy, decoration, a container, or a control does not reduce understanding, remove it.
- Friendly means clear labels, sensible defaults, useful errors, and predictable behavior — not verbose explanation.
- Use Mantine components and semantic theme tokens before custom UI or raw values.
- Shared layouts own geometry. Feature screens provide content and product behavior, not bespoke page spacing.
- Consistency across screens beats local visual optimization.
- Do not patch alignment with arbitrary offsets; fix the responsible layout/component.
- One canonical term and action label per product concept.

## Canonical structure

Prefer reusable primitives such as `AppFrame`, `PageHeader`, `Section`, `Toolbar`, `FormActions`, `EmptyState`, `ErrorState`, and table patterns. Create a new pattern only when existing ones cannot express the requirement cleanly.

Before implementing a screen, inspect the closest analogous screen. New list pages should resemble established list pages; settings should use established settings structure; destructive flows should reuse the canonical confirmation pattern.

## Visual tokens

Typography, spacing, control sizes, radii, borders, colors, shadows, breakpoints, focus treatment, and motion belong to the theme or shared components. Semantic color communicates status; color is never the only status signal.

## Applicable states

For each feature, explicitly determine which states apply: default, hover/focus/disabled, loading, empty, error, partial/stale data, offline/reconnecting, permission/read-only, success, destructive/reversible, long/missing content, and supported viewport/input modes. Do not implement impossible states merely to satisfy a checklist.

Complex asynchronous or consequential workflows should define valid state transitions instead of accumulating contradictory booleans.

## Accessibility and platform behavior

WCAG 2.2 AA is the web baseline. Use semantic HTML, visible focus, keyboard operation, meaningful labels, sufficient contrast, non-color status cues, zoom/reflow support, reduced-motion/high-contrast preferences, and accessible dynamic status announcements. Use the component library's dialog/menu/popover focus behavior instead of reimplementing it.

Normal browser behavior is part of UX: meaningful state should survive refresh/deep links when appropriate, back/forward should work, and unsaved work needs an explicit autosave/save/discard policy.

## Anti-drift

- Canonical components and the UI showroom are the visual reference.
- Use stress data: long strings, empty values, Unicode, large numbers, many rows, and realistic filenames.
- Visual snapshots are reviewed evidence. Never regenerate them blindly after a failure.
- Token/shared-component changes require checking representative consumers.
- Preview the actual application code; never maintain a separate mock UI that can diverge.
- Intentional system evolution happens centrally and moves affected screens together.
