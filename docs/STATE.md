# Current state

## Current

- Mode: active product development
- Epic: universal WordPress control
- Branch: `feat/control-plane-foundation`
- `0.1.11` shipped the universal CRUD control plane. A Custom GPT schema import then exposed one validator incompatibility: the `updateWordPressResourceBatch` action description was 305 characters, above ChatGPT's 300-character limit.
- Patch release `0.1.12` shortens that description, adds a static <=300-character Action-description contract, and is verified/packaged for immediate install.
- Production was last explicitly API-verified on `0.1.10`; subsequent plugin installs must be confirmed from the live manifest before relying on the reported version.
- Production authentication through the dedicated WordPress Application Password is working. The live manifest reported both structured writes and universal execution enabled at the time of verification.
- Production content work remains paused only until the `0.1.12` schema-validator patch is installed and the refreshed Custom GPT Action schema imports cleanly; then controlled acceptance can resume.

## Working foundation

- Generic search/inspect covers 11 first-class resource kinds plus bounded structured-value search and RFC 6901 JSON Pointer addressing.
- Bounded developer inspection covers runtime inventory, REST routes, plugin/theme/core source, database tables/schema/sample rows, and hash-only filesystem metadata with secret-aware redaction.
- 0.1.10 provides the universal Execute fallback: internal REST, loaded PHP callables, bounded PHP, SQL, filesystem operations, and WP-CLI when available.
- Universal capability is invariant per D019: a WordPress/PHP-accessible subsystem must retain a generic control path without a provider adapter.
- Application Password authentication uses WordPress itself; WPCommander never stores a second plaintext API key.

## 0.1.11 work

- The machine-facing model is now generic Discover/Inspect → Create/Read/Update/Delete → Execute (D021).
- Structured Create supports posts/pages/custom post types from scratch or from an inspected source resource. Duplication is therefore Create-from-source, not a clone adapter/action.
- Structured Update keeps the existing exact pointer/field path and adds batch Update for up to 50 non-overlapping changes to one resource.
- Batch Update prepares the full new state in memory, performs the minimum WordPress write(s), verifies once, records one bounded activity entry, and supports stale-safe revert where the before-state fits the reversible envelope.
- Structured Delete currently covers posts/pages/custom post types, requires a fresh fingerprint, defaults to WordPress Trash, and records reversible activity; permanent deletion is irreversible.
- Legacy `/resources/mutate` and `/resources/mutate-batch` routes remain compatibility aliases, while the generated OpenAPI/GPT vocabulary uses Create/Update/Update-batch/Delete.
- Admin UX is being simplified to Connection → Site access → Recent activity. Inspect only / Edit site / Full control are the only user-facing access concepts (D020).
- Full control always includes Edit site; legacy contradictory gate state is normalized so universal execution cannot remain enabled while structured edits are disabled.
- Custom GPT instructions explicitly tell the GPT to inspect unknown software and use generic CRUD/Abilities/Execute rather than searching for adapters.
- A credential-safe local operator helper exists at `scripts/live-api.ps1`; it stores the Basic token outside the repository using Windows CurrentUser protection and never prints the token.

## Verification status

- `npm run verify:full` passed on 2026-09-12: Prettier, oxlint with 0 warnings/errors, TypeScript, UI conformance, PHP parser/static safety contracts, 6 Vitest tests, production build, Playwright accessibility/E2E, and desktop/mobile visual regression. A final `npm run verify` also passed after the last non-UI OpenAPI/documentation cleanup.
- The intentional compact admin redesign was visually reviewed before the desktop/mobile baselines were updated; an actual WCAG contrast issue and mojibake introduced during the refactor were fixed before acceptance.
- Patch verification for `0.1.12` passed via `npm run verify`; the new static contract rejects any literal Custom GPT Action description above 300 characters.
- Release package `release/wpcommander-0.1.12.zip` was built with canonical `wpcommander/` top-level folder, size 180410 bytes, SHA-256 `A5F1ACCE21E502542781C304664E9EC63BE24E096B5F2A463B57C85A4E4D2291`.
- The workstation still has no native WordPress/PHP runtime; backend release verification remains parser/static-contract plus later controlled production acceptance after install.

## Next

1. Commit and push the `0.1.12` schema-validator patch.
2. Install `0.1.12`, copy the freshly generated Action schema into the Custom GPT, and confirm it imports without validation errors; keep the existing credential.
3. Run one controlled end-to-end acceptance: discover source → Create from source → batch Update content/media → update the relevant WordPress relationship/navigation through the narrowest generic primitive → verify frontend/navigation. No provider adapter is allowed.
4. Only after that acceptance succeeds resume normal production content work.

## Known bounds

- Structured CRUD is intentionally smaller than the universal surface; unsupported resource kinds/operations must continue through native Abilities or Full-control Execute, not through vendor adapters.
- Numeric array insertion/removal is not yet a structured JSON Pointer operation.
- Universal execution cannot promise generic automatic rollback and must be verified after execution.
- WP-CLI depends on host process permissions and the `wp` binary; production availability has not yet been proven.
- Direct schema paste remains the default Custom GPT setup path because URL import can be affected by hosting/WAF/encoding behavior.
