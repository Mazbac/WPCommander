# Architecture

WPCommander is a WordPress plugin with a small React admin application and a stable machine-facing control API.

## Platform baseline

- WordPress: 6.9+ so the native Abilities API is always available.
- PHP: follow the minimum supported by the selected WordPress baseline; do not introduce a separate server runtime.
- Admin UI: React 19 + TypeScript + Mantine 9, built with Vite 8 and npm.
- Tests: Vitest for UI/unit behavior; Playwright + axe-core for browser/accessibility/visual behavior. The workstation has no native WordPress runtime, so backend release checks are parser/static-contract plus controlled live acceptance after installation.

## Universal control model

The invariant is: if the authenticated WordPress/PHP process can legitimately inspect or perform something, WPCommander must retain a vendor-independent route to it.

1. **Structured resource plane** — discover/read and deterministic Create/Update/Delete for supported WordPress resources plus RFC 6901 JSON Pointer updates inside structured post-meta/options.
2. **Native ability plane** — discover and execute permission-aware WordPress Abilities registered by core, plugins, themes, and WPCommander.
3. **Developer inspection plane** — bounded read-only inspection of plugin/theme/core source, registered runtime, REST routes, database structure/sample rows, and filesystem metadata so unknown software can be understood without an adapter.
4. **Universal execution plane** — Full-control fallback through internal REST, loaded PHP callables, bounded PHP/SQL, filesystem mutation, and WP-CLI when a narrower primitive cannot express the result.

There is no provider adapter layer in the required architecture. Elementor, WooCommerce, ACF, a custom plugin, or software installed tomorrow must remain reachable through the same generic planes.

## Machine control language

The stable mental model is **Discover/Inspect → Create/Read/Update/Delete → Execute**.

- Duplication is Create from an inspected source state, not a `cloneVendorThing` operation.
- Multi-field edits to one resource use one batch Update: prepare all changes in memory, perform the minimum WordPress write(s), verify once, and record one reversible activity entry where safe.
- Unknown storage is first discovered through generic resource/runtime/database/source inspection; the GPT then uses the narrowest generic write primitive available.
- Execute is the capability-complete fallback, not a reason to add task-specific endpoints.

## External API

The ChatGPT/OpenAPI surface stays compact: manifest/diagnostics; resource search, inspect, and search-inside; generic Create, Update, batch Update, Delete; conversation-image media ingress and WordPress image-link lookup; activity/revert; developer inspection; Ability discovery/execution; and one universal Execute operation. Legacy mutation route aliases may remain for compatibility but are not the product vocabulary.

## Authentication, access, and authorization

- External clients authenticate as real WordPress users through dedicated, revocable WordPress Application Passwords over HTTP Basic. WPCommander does not create a second plaintext API-key system.
- Every operation checks the authenticated WordPress user's applicable capability; credentials do not bypass WordPress authorization.
- The admin presents three nested levels: **Inspect only**, **Edit site**, **Full control**. Full control always includes Edit site. Internally the structured-write and universal-execution options may remain separate gates, but contradictory states are normalized away.
- Sensitive options/meta and credential-like values remain denied/redacted. Universal control is not credential extraction.

## Command execution protocol

- Reads execute directly after authorization.
- Structured Update/Delete requires a fresh resource fingerprint from inspection and fails stale rather than overwriting newer work.
- Create from an existing resource requires the source's fresh fingerprint. Exact retries are idempotent.
- Batch Update is one logical command and one activity/revert unit; it must not degrade into a network or storage roundtrip per pointer when one resource write can express the same result.
- Structured operations verify post-write state and capture bounded before-state for later revert where safe.
- Universal execution cannot honestly promise the same rollback guarantee; it remains bounded, audited, and followed by explicit state verification.
- A clear user request can supply operation intent. Additional confirmation is reserved for consequential steps not reasonably implied by that request.

## Boundary rule

Prefer structured CRUD and native Abilities, then bounded inspection to understand unknown code/storage, then universal Execute. Never add a provider-specific adapter merely to gain access to underlying WordPress state. Safety may change method, bounds, confirmation, audit, or revert behavior, but it must not make a WordPress/PHP-accessible subsystem permanently unreachable.
