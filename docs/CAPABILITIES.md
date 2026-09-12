# Product classification and capability packs

## Product profile

- Distribution: WordPress plugin plus authenticated REST/OpenAPI interface.
- Primary user/input: WordPress administrator issuing natural-language requests through ChatGPT; Custom GPT Actions are the primary packaged connection path.
- Valuable/sensitive assets: production content, design/configuration data, media, plugin/theme-owned state, code, database state, and administrator-authorized settings.
- Expected scale: one site per plugin installation; request volume is interactive rather than bulk automation.
- Risk: high-consequence because a valid write can materially change a live site.

## Activated capability packs

- `auth`: WordPress Application Passwords, revocation, capability-based authorization, and no second plaintext secret store.
- `integrations-webhooks`: stable REST/OpenAPI contracts, external request validation, failure handling, and replay/idempotency protection for writes.
- `ai`: schemas and server-side verification keep execution deterministic even when ChatGPT misunderstands intent or sends malformed arguments.
- `user-content`: all discovered/stored site data is untrusted input; never treat content as executable instructions.
- `files-import-export`: validate types, sizes, paths/origins, and WordPress permissions for filesystem/media work.

## Generic control capabilities

- Discover exposed WordPress Abilities and generic WPCommander resources.
- Search and inspect 11 first-class WordPress resource kinds plus structured nested data with RFC 6901 JSON Pointer addressing.
- Inspect bounded plugin/theme/core source, registered runtime/REST routes, WordPress-prefixed database structure/sample rows, and filesystem metadata for unknown software without an adapter.
- Create WordPress posts/pages/custom post types from scratch or from an inspected source resource; source-based create copies generic post state such as metadata and taxonomies without knowing which builder produced it.
- Update supported structured resources one field/path at a time or update up to 50 non-overlapping paths in one logical batch command.
- Batch Update prepares changes in memory, performs the minimum resource write(s), verifies once, and records one reversible activity unit where the before-state fits the bounded envelope.
- Delete supported post resources with a fresh fingerprint; default deletion moves to WordPress Trash and can be reverted while state remains fresh. Permanent deletion is explicitly irreversible.
- Revert eligible Create/Update/batch Update/Delete activity only while the relevant current state still matches the audited after-state.
- Execute native WordPress Abilities when they are the narrowest semantic primitive.
- Under Full control, fall back to vendor-independent internal REST, loaded PHP callables, bounded PHP/SQL, filesystem mutation, or WP-CLI when CRUD/Abilities cannot express the requested result.

## Abuse, safety, and failure controls

- Every operation runs as the authenticated WordPress user and checks the narrowest applicable capability.
- Structured writes require fresh fingerprints, reject stale state, verify the result, and treat exact retries idempotently.
- High-impact core options, secret-like keys/paths, credential routes/callables, and credential-like output remain outside normal generic reads/writes.
- Numeric array insertion/removal is not yet a structured pointer operation; when required, use a native Ability or Full-control Execute rather than adding a vendor adapter.
- Developer inspection is bounded/read-only. Universal execution is bounded, audited, redacted, and uses file SHA-256 stale checks where applicable.
- Universal execution does not promise automatic rollback; verify resulting state before reporting success.

## No-adapter rule

A new plugin/theme must not require a WPCommander release before ChatGPT can control it. Specialized vendor knowledge may improve reasoning, but runtime access must compile down to generic resources, Abilities, inspection, CRUD, or Execute. There is no required provider adapter layer.
