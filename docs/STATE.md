# Current state

## Current

- Mode: active product development
- Epic: universal WordPress control
- Feature: 0.1.10 universal execution plane on top of generic discovery and structured mutation
- Branch: `feat/control-plane-foundation`
- Release candidate: `0.1.10`
- Live production was last explicitly verified on 0.1.8. Do not assume 0.1.9 or 0.1.10 is installed/live-verified until the user confirms it.
- Live Custom GPT Actions authentication is working after allowing ChatGPT edge traffic and enabling WordPress Application Passwords in Wordfence.
- Live 0.1.8 runtime inspection proved the no-adapter model against Code Snippets 3.10.2: the GPT independently traced plugin source/bootstrap, confirmed 23 live REST routes under `/code-snippets/v1`, and discovered/described `wp_snippets` without reading snippet rows.

## Working

- WordPress-native admin surface, one-click dedicated Application Password creation/rotation, copy-ready Action schema, and generated GPT instructions.
- Generic resource search/inspect covers 11 first-class kinds plus bounded structured-value search and RFC 6901 JSON Pointer addressing.
- Bounded developer inspection covers runtime inventory, REST routes, source search/read, WordPress database table/schema/sample inspection, and hash-only `stat-path` metadata for files that should not expose contents.
- 0.1.9 structured mutations remain the preferred narrow write path for post/media/term/comment fields and exact nested post-meta/options. They require a fresh resource fingerprint, verify results, record reversible activity, and reject stale state.
- Structured writes remain behind their own wp-admin gate; enabling them never enables the privileged universal plane.
- 0.1.10 adds one vendor-independent universal execution operation with `internal-rest`, `call-function`, `php-eval`, `sql`, `write-file`, `make-directory`, `move-path`, `delete-path`, and `wp-cli` primitives.
- Universal execution has its own wp-admin gate and administrator capability check. Every privileged operation requires `confirmed=true`; exposed non-readonly WordPress Abilities use the same gate/confirmation semantics.
- The universal plane is a fallback, not an adapter layer. Unknown/future plugins are discovered through generic source/runtime/storage inspection and then controlled through the narrowest generic primitive available.
- Existing file overwrite/move/delete is stale-protected with a fresh SHA-256 from `stat-path`; text and Base64/binary writes are supported up to the bounded per-operation file limit.
- Universal SQL is single-statement, bounded, restricted away from database/server-account administration and external/system schemas, and returns bounded/redacted output for reads.
- Privileged PHP executes inside WordPress but intentionally returns execution metadata rather than raw stdout/return values; state must be verified through normal inspection after execution.
- WP-CLI execution is time/output bounded, escapes arguments, and redirects overlapping PHP/SQL/credential-reading operations to the dedicated primitives.
- Privileged responses/errors use bounded secret-aware redaction; execution activity stores operation metadata only, not PHP, SQL, file contents, request bodies, or positional arguments.
- Structured and universal execution activity are both visible in the same Recent activity UI.
- Durable rule D019 is authoritative: universal capability is invariant; risk changes gating/confirmation/audit, not whether a WordPress-accessible subsystem has a generic control path.

## Verification

- `npm run verify:full` passes for 0.1.10: Prettier, oxlint (0 warnings/errors), TypeScript, UI conformance, PHP parse/safety contracts across 6 PHP files, 5 Vitest tests, Vite production build, Playwright/axe accessibility, and desktop/mobile visual regression.
- The new desktop/mobile Command access layout was manually reviewed before accepting the intentional visual baseline changes; no clipping or overlap was found.
- `npm run package:plugin` created `release/wpcommander-0.1.10.zip` with the canonical `wpcommander/` top-level directory and the new developer execution class.
- Release ZIP size: 173889 bytes. SHA-256: `78386BC5C62D231BF4F984CD36510C614403F85D23B7A0F1A06652B0B91CCE1F`.
- The workstation still has no native PHP/WordPress runtime; PHP verification is parser/static-contract based. Live WordPress behavior for 0.1.10 remains to be verified after installation.
- Live WordPress previously advertised Application Password authentication and returned HTTP 200 for the public WPCommander OpenAPI route to `ChatGPT-User/1.0`.

## Next

1. Install 0.1.10 on the production site, refresh the Custom GPT Action schema and generated instructions, and keep the existing Basic credential unless it has been revoked.
2. Verify the live manifest/diagnostics first; confirm both Structured writes and Universal execution default to off after upgrade.
3. Enable Structured writes for normal edits and Universal execution separately when the administrator wants whole-site fallback capability.
4. Run a low-risk structured write -> verify -> activity -> revert test.
5. Run a controlled cross-resource acceptance test for the actual workflow: clone an existing page, change its content/metadata, publish or keep it draft as requested, rewire the intended menu item, and verify navigation. Use generic WordPress callables/resources; no page-builder/menu adapter is allowed as a prerequisite.
6. Then live-test an unknown-plugin operation that genuinely requires the universal fallback and verify resulting runtime/storage state.

## Known issues / bounds

- Direct schema paste remains the default because earlier live REST responses included a UTF-8 BOM and URL import was unreliable; targeted WPCommander output cleanup still needs live re-verification.
- Structured mutations intentionally remain narrower than the universal plane. Numeric array insertion/removal, plugin/theme lifecycle, users/roles, menus, arbitrary custom tables, and code changes should use the universal fallback when no narrower Ability/resource mutation exists.
- Privileged universal operations do not promise automatic rollback. They are gated, bounded, audited, and should be followed by explicit state verification.
- `write-file` is bounded to 4 MiB per operation; larger media/artifact workflows should use WordPress APIs, plugin APIs, HTTP-capable callables, or WP-CLI rather than inflating a GPT Action payload.
- WP-CLI execution depends on host process permissions and the `wp` binary being available; this has not been live-verified on production.
- Comments legitimately warn when the site has no readable comment sample.
