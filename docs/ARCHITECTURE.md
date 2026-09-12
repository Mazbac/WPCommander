# Architecture

WPCommander is a WordPress plugin with a small React admin application and a stable machine-facing control API.

## Platform baseline

- WordPress: 6.9+ so the native Abilities API is always available.
- PHP: follow the minimum supported by the selected WordPress baseline; do not introduce a separate server runtime.
- Admin UI: React 19 + TypeScript + Mantine 9, built with Vite 8 and npm.
- Tests: Vitest for UI/unit behavior; Playwright + axe-core for browser/accessibility/visual behavior. Add WordPress/PHP integration coverage as the plugin backend lands.

## Universal control model

WPCommander has complementary control layers. The goal is broad WordPress control without an endless catalog of vendor adapters.

1. **Structured resource plane** — safe, introspectable access to posts, post meta, options, terms, media, users where permitted, and nested structured values through stable addresses and JSON Pointer paths.
2. **Native ability plane** — discover and execute permission-aware WordPress Abilities registered by core, plugins, themes, and WPCommander itself.
3. **Developer inspection plane** — bounded read-only inspection of plugin/theme/core source, registered runtime surface, and WordPress-prefixed database structure/sample rows so an unknown plugin can be understood without a prebuilt adapter. Secret-like files, fields, and values are denied or redacted.
4. **Universal execution plane** — opt-in escape-hatch abilities for arbitrary PHP, WP-CLI, SQL, and filesystem mutation when the narrower planes cannot express the requested operation.

Provider-specific integrations are optional expertise, not required access. Elementor, Bricks, WooCommerce, ACF, or a future plugin should remain reachable through the generic planes even when WPCommander has no dedicated adapter.

## External API

The stable ChatGPT Action surface stays compact: manifest/diagnostics, three generic resource reads (search, inspect, search-inside), direct structured mutation plus activity/revert, bounded developer inspection, one vendor-independent universal execution operation, and dynamic Ability discovery/execution. Explicit resource/developer operations give the GPT strongly typed generic primitives while Abilities remain the extensibility escape hatch. New vendor capabilities should normally appear through generic resources, runtime inspection, or Ability discovery rather than one Action endpoint per plugin.

## Authentication and authorization

- External GPT access uses WordPress Application Passwords over HTTP Basic authentication. The wp-admin setup action may create/rotate a WPCommander-specific Application Password for the current administrator and returns only a one-time Base64 Basic token; WPCommander never stores the plaintext credential.
- Every operation runs as the authenticated WordPress user and checks the narrowest applicable capability.
- Sensitive options/meta are denied by default; credentials, salts, sessions, and secret-like values are never returned by generic discovery.
- Universal execution requires an additional explicit administrator feature gate. It is disabled by default and is never implied by possession of an Application Password or structured-write access alone.

## Command execution protocol

- Reads execute directly after authorization.
- Normal structured writes are direct commands from the user's perspective. A fresh resource fingerprint from inspection is required; WPCommander checks authorization/staleness, applies the narrow mutation, verifies the resulting state, and records bounded reversible before-state. Structured writes are off by default behind a wp-admin-only gate.
- Applied changes record actor, timestamp, target, operation, before/after fingerprints, and reversible payload where safe; revert is available as a later command when supported.
- Explicit confirmation is reserved for broad, destructive, irreversible, or privileged operations rather than every routine edit.
- Structured command access never implies arbitrary plugin/theme write Ability access. Privileged developer operations use a separate risk path because arbitrary PHP/SQL/filesystem actions cannot honestly provide the same automatic rollback guarantees as structured mutations.

## Boundary rule

Prefer structured resources and native Abilities first, then bounded developer inspection to understand unknown code/storage. Use universal execution as the final escape hatch rather than adding endless vendor adapters. Risk gates may add confirmation or stronger audit, but may not make a WordPress-accessible subsystem permanently unreachable. Add provider-specific code only when it improves semantics, safety, or ergonomics; it must never be required merely to gain access to that provider's underlying WordPress data.
