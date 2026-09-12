# Product classification and capability packs

## Product profile

- Distribution: WordPress plugin plus authenticated REST/OpenAPI interface.
- Primary user/input: WordPress administrator issuing natural-language requests through a Custom GPT; secondary UI is the WordPress admin screen.
- External system: ChatGPT Custom GPT Actions.
- Valuable/sensitive assets: production site content, design/configuration data, media, and administrator-authorized settings.
- Expected scale: one site per plugin installation in MVP; request volume is interactive rather than bulk automation.
- Risk: high-consequence because a valid write can materially change a live site.

## Activated capability packs

- `auth`: WordPress Application Passwords, revocation, capability-based authorization, and no custom plaintext secret store.
- `integrations-webhooks`: stable REST/OpenAPI contracts, external request validation, provider failure handling, and replay/idempotency protection where writes are involved.
- `ai`: ChatGPT may misunderstand intent or produce malformed arguments, so schemas stay narrow, structured writes use internal server-side preflight/stale checks/verification, and consequential execution remains deterministic.
- `user-content`: all discovered/stored site data is untrusted input; escape in UI and never treat content as executable instructions.
- `files-import-export`: activate only for media upload/download work; validate types, sizes, origins, and WordPress permissions when implemented.

## Generic control capabilities

- Discover exposed WordPress Abilities and WPCommander resource kinds.
- Search resources by human text plus optional kind/field filters.
- Inspect a resource with a bounded, redacted structured representation.
- Inspect bounded plugin/theme/core source, REST routes, WordPress-prefix database structure/sample rows, and hash-only metadata for any existing WordPress path when an unknown plugin needs deeper discovery.
- Execute direct field-set commands for posts, media, terms, and comments plus exact nested set/remove commands for post-meta/options after wp-admin enables command access. JSON Pointer addressing includes builder data such as Elementor settings.
- Require the fresh `resourceFingerprint` from inspection, verify the result, treat exact retries idempotently, and record bounded reversible activity automatically.
- Revert an eligible prior structured change only while its resource still matches the audited after-state.
- When narrower primitives cannot express the command, execute one vendor-independent privileged operation through internal REST, a loaded PHP callable, bounded PHP, SQL, filesystem mutation, or WP-CLI.

## Abuse and failure controls

- Repeated mutation calls detect the already-applied after-state rather than duplicating a change. High-impact core options and secret-like keys/paths are excluded from the normal mutation surface.
- A structured mutation fails closed when the target changed after inspection. Numeric array elements can be updated in place, but adding/removing array elements remains outside the structured surface; universal execution is the generic fallback when that limitation matters.
- Generic/resource/developer inspection must cap result count/value/file size and redact secret-like keys and values.
- Bounded developer inspection remains read-only. Universal execution is a separate administrator-gated capability with bounded output/audit; existing file overwrite/move/delete requires a fresh SHA-256 where supported.
- Permission checks happen on every read/write using the authenticated WordPress user; possession of a connection credential does not bypass WordPress capabilities.

## Universal-access layers

- Structured resource plane: preferred for production-safe reads/writes, deterministic validation, audit, stale-state checks, and revert.
- Native ability plane: dynamically exposes core/plugin/theme Abilities without OpenAPI growth or vendor adapters.
- Developer inspection plane: bounded read-only source/runtime/database discovery for understanding unknown plugins without adapters.
- Universal execution plane: opt-in internal REST, loaded PHP callables, bounded PHP/SQL, WordPress filesystem mutation, and WP-CLI for tasks no narrower primitive can express; disabled by default until explicitly enabled.
- Specialized Elementor/theme/plugin expertise may be added later, but it must compile down to the generic planes rather than becoming a required adapter dependency.
