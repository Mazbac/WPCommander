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
- Inspect bounded plugin/theme/core source, REST routes, and WordPress-prefix database structure/sample rows when an unknown plugin needs deeper discovery.
- Execute a requested structured set/replace/remove command directly with internal preflight, stale-state checks, result verification, and automatic audit capture.
- Revert an eligible prior change.

## Abuse and failure controls

- Repeated mutation calls must be idempotent or detect already-applied state rather than duplicating a change.
- A structured mutation fails closed when the target changed after the internal preflight snapshot.
- Generic/resource/developer inspection must cap result count/value/file size and redact secret-like keys and values.
- Bounded developer inspection is read-only and restricted to WordPress code roots and WordPress-prefixed tables; arbitrary SQL/PHP/WP-CLI/filesystem mutation is not a generic ability.
- Permission checks happen on every read/write using the authenticated WordPress user; possession of a connection credential does not bypass WordPress capabilities.

## Universal-access layers

- Structured resource plane: preferred for production-safe reads/writes, deterministic validation, audit, stale-state checks, and revert.
- Native ability plane: dynamically exposes core/plugin/theme Abilities without OpenAPI growth or vendor adapters.
- Developer inspection plane: bounded read-only source/runtime/database discovery for understanding unknown plugins without adapters.
- Privileged execution plane: opt-in PHP execution, WP-CLI, arbitrary SQL, and filesystem mutation for tasks no narrower primitive can express; disabled on production by default until explicitly enabled.
- Specialized Elementor/theme/plugin expertise may be added later, but it must compile down to the generic planes rather than becoming a required adapter dependency.
