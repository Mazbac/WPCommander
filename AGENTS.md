# AGENTS.md

This repository is the durable project memory. Do not rely on chat history for project truth.

## Cold start

1. Read `docs/STATE.md` and `docs/PRODUCT.md`.
2. Read the relevant domain docs: `UI.md`, `ARCHITECTURE.md`, `JOURNEYS.md`, `CAPABILITIES.md`, and `QUALITY.md`.
3. Inspect `git status` and recent commits before editing.
4. Run `npm run doctor` when the environment is unfamiliar or broken.
5. Inspect the closest existing implementation before creating a new pattern.

## Working rules

- Prefer the smallest professional solution appropriate to the product and risk.
- Existing product decisions and shared patterns beat local AI preference.
- Never silently change architecture, terminology, design-system rules, package manager, or core stack.
- Reuse the component library, tokens, layouts, and canonical components before writing custom UI.
- Do not introduce arbitrary colors, spacing, radii, control sizes, shadows, or one-off alignment fixes.
- Preserve user work. Do not use destructive Git operations to make a problem disappear.
- Never commit secrets, credentials, production data, or unsanitized sensitive references.
- Work inside this repository unless a task genuinely requires an external change.

## Unknown-condition protocol

When no rule clearly applies: preserve existing work, determine blast radius, inspect code/tests/docs, find the closest established pattern, choose the smallest reversible solution, verify it, then document a new durable rule only if it is likely to recur.

## UI changes

- Treat `docs/UI.md` as the interaction/design contract and shared code as the pixel-level source of truth.
- Check loading, empty, error, permission, long-content, responsive, keyboard, and destructive states when applicable.
- A shared component or token change is system-wide: inspect its consumers and visual diffs.
- Never update a visual baseline merely to make a failing test pass; first prove the change is intentional.
- Consistency across the product beats making one screen locally prettier.

## Completion

1. Review the diff and remove unrelated work.
2. Run `npm run verify`; for UI-impacting work run `npm run verify:full` when the browser tests are available.
3. Update `STATE.md` if project state changed, `DECISIONS.md` only for durable decisions, and other docs only when their truth changed.
4. Keep commits coherent and use conventional commit messages.
5. Never call work verified when the relevant checks were not actually run.

One active AI writer per working tree. Parallel work must use separate branches/worktrees and be integrated deliberately.
