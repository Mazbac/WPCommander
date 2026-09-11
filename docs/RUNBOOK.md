# Runbook

## Local start

1. `npm ci` after a fresh clone.
2. `npm run doctor` to confirm the supported runtime and required files.
3. `npm run dev` for the live preview.

## Verification

- `npm run verify` — fast default gate: formatting, lint, types, UI conformance, unit tests, build.
- `npm run verify:full` — adds E2E/accessibility and visual regression.
- `npm run test:visual:update` — only after confirming a visual change is intentional.

## Failure protocol

First reproduce and identify blast radius. Preserve local/user work, inspect current state and recent changes, then choose the smallest reversible fix. Do not change global machine configuration or unrelated code merely to make a check pass.

If an environment/check cannot run, record the work as unverified and state why. Never regenerate visual baselines, delete tests, loosen validation, or reset Git simply to turn red checks green.

## Release/operations

A real project should add deployment, rollback, monitoring, backup/recovery, supported-platform, and production-data rules only when those concerns become applicable. Production changes require explicit safeguards; local test/debug workflows must not casually target production data or credentials.

## Parallel work

Default: one AI writer per working tree. If parallel work is useful, create separate branches/worktrees, verify independently, then integrate deliberately.
