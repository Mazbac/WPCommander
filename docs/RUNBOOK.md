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

## Optional live operator bridge

`scripts/live-api.ps1` is a development/operator helper for authenticated live verification from an authorized Windows workstation. It is not part of the installable plugin archive and contains no credential.

- `-Action capture -SiteUrl <site>` reads a Basic token from the clipboard only after the user has explicitly chosen to provide it, protects it with Windows CurrentUser secure-string protection in `%LOCALAPPDATA%\WPCommander\live-connection.json`, clears the clipboard, and tests the authenticated manifest without printing the token.
- `-Action status` returns connection metadata only.
- `-Action request -Method <verb> -Path <route> [-BodyJson <json>]` sends an authenticated request through the stored credential.
- `-Action clear` deletes the workstation-local credential store.

Never paste the credential into chat, commit the local store, print the protected blob, or perform a production write merely to prove connectivity. Live mutations require a concrete user-requested outcome or a deliberate acceptance test.
