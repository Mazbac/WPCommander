# Roadmap

## MVP

### Epic: Product intake

- [x] Normalize raw idea into a product contract and explicit non-goals.
- [x] Classify the product as a high-consequence WordPress integration.
- [x] Define the install → GPT connection → inspect → execute → verify/revert journey.

### Epic: Control-plane foundation

- [x] Package the repository as an installable WordPress 6.9+ plugin while retaining the React admin application.
- [x] Add the WordPress-native WPCommander admin surface with connection, capabilities, diagnostics, and activity views.
- [x] Expose a generated OpenAPI document and readiness/manifest endpoint.
- [x] Register WPCommander abilities and discover already-registered WordPress Abilities.
- [x] Add copy-ready Custom GPT setup: one-click Application Password/Basic token, pasted Action schema, and recommended GPT instructions.

### Epic: Universal read

- [x] Define stable resource addresses for posts, post meta, options, media, terms, users, comments, menus, plugins, themes, site state, and structured nested values.
- [x] Implement bounded/redacted search and inspect operations with WordPress capability checks.
- [x] Make generic structured data useful for page-builder/theme storage without vendor-specific adapters.
- [x] Add production-safe search → inspect diagnostics for every first-class resource kind.
- [x] Add bounded developer inspection for plugin/theme/core source, REST routes, and WordPress-prefixed database structure/sample rows.

### Epic: Safe change

- [x] Implement direct structured set/remove commands with internal preflight/stale checks for the first safe resource kinds.
- [x] Make repeated mutations idempotent and reject stale target state.
- [x] Record activity automatically with reversible payloads where safe.
- [x] Implement authorized stale-safe revert.
- [ ] Live-verify the end-to-end Custom GPT write -> verify -> activity -> revert flow on production.

### Epic: Universal execution

- [x] Add one vendor-independent privileged execution operation covering internal REST, loaded PHP callables, bounded PHP/SQL, WordPress filesystem mutation, and WP-CLI.
- [x] Keep universal execution behind a separate administrator gate and explicit privileged-command confirmation semantics.
- [x] Add bounded execution activity metadata and hash-only path inspection so existing files can be stale-checked without exposing contents.
- [ ] Live-verify an unknown-plugin or cross-resource task that requires the universal fallback.

## Later

Richer media workflows, multisite fleet workflows, scheduled automation, and optional compatibility expertise remain later. Plugin/theme lifecycle, custom storage, code changes, and other site-local operations do not require dedicated roadmap features when they can already be composed through universal primitives.
