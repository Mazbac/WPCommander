# Quality gate

Quality is progressive: apply only relevant checks, but never skip a relevant check merely for speed.

## Definition of done

- Acceptance criteria and the intended user outcome are satisfied.
- Applicable loading, empty, error, permission, destructive, stale/offline, and recovery states are handled.
- No duplicate UI pattern or unexplained local visual exception was introduced.
- Short/long/missing/hostile data and supported viewport/input conditions were considered where relevant.
- Authorization, privacy, secrets, concurrency/idempotency, and abuse risks were considered where relevant.
- The diff contains no unrelated refactor or speculative infrastructure.
- `npm run verify` passes; UI-impacting changes also run relevant browser/visual checks when available.
- Any durable project truth changed by the work is updated in the correct document.

## Review dimensions

For substantial work check applicability across: product scope, user journey, information architecture, visual system, interaction, state, data, responsive/platform behavior, accessibility, localization/time, security/privacy, recovery, performance, testing, anti-drift, lifecycle, and operations.

`Not applicable` is a valid result and should not create work.

## Verification language

Use `implemented` when code exists but checks were not completed. Use `verified` only when the relevant checks actually ran successfully. Pre-existing failures must be identified rather than hidden or attributed to new work.
