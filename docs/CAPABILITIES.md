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
- `ai`: ChatGPT may misunderstand intent or produce malformed arguments, so schemas stay narrow, writes are planned before application, and consequential operations remain deterministic server-side.
- `user-content`: all discovered/stored site data is untrusted input; escape in UI and never treat content as executable instructions.
- `files-import-export`: activate only for media upload/download work; validate types, sizes, origins, and WordPress permissions when implemented.

## Generic control capabilities

- Discover exposed WordPress Abilities and WPCommander resource kinds.
- Search resources by human text plus optional kind/field filters.
- Inspect a resource with a bounded, redacted structured representation.
- Plan a structured set/replace/remove operation at a JSON Pointer path.
- Apply a non-stale plan and record an audit entry.
- Revert an eligible prior change.

## Abuse and failure controls

- Repeated apply calls must not duplicate an already-applied plan.
- Stale plans fail closed when the target changed after planning.
- Generic discovery must cap result count/value size and redact secret-like keys and values.
- Destructive resource deletion, arbitrary code execution, raw database access, and filesystem access are not generic abilities.
- Permission checks happen on every read/write using the authenticated WordPress user; possession of a connection credential does not bypass WordPress capabilities.

## Universal-access layers

- Structured resource plane: preferred for production-safe reads/writes, deterministic validation, audit, stale-state checks, and revert.
- Native ability plane: dynamically exposes core/plugin/theme Abilities without OpenAPI growth or vendor adapters.
- Privileged developer plane: opt-in PHP execution, WP-CLI, database query/mutation, and filesystem read/write for tasks no structured primitive can express.
- Privileged access must expose environment/risk state clearly and is disabled on production by default.
- Specialized Elementor/theme/plugin expertise may be added later, but it must compile down to the generic planes rather than becoming a required adapter dependency.
