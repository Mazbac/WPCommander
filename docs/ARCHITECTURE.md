# Architecture

WPCommander is a WordPress plugin with a small React admin application and a stable machine-facing control API.

## Platform baseline

- WordPress: 6.9+ so the native Abilities API is always available.
- PHP: follow the minimum supported by the selected WordPress baseline; do not introduce a separate server runtime.
- Admin UI: React 19 + TypeScript + Mantine 9, built with Vite 8 and npm.
- Tests: Vitest for UI/unit behavior; Playwright + axe-core for browser/accessibility/visual behavior. Add WordPress/PHP integration coverage as the plugin backend lands.

## Universal control model

WPCommander has three complementary control layers. The goal is broad WordPress control without an endless catalog of vendor adapters.

1. **Structured resource plane** — safe, introspectable access to posts, post meta, options, terms, media, users where permitted, and nested structured values through stable addresses and JSON Pointer paths.
2. **Native ability plane** — discover and execute permission-aware WordPress Abilities registered by core, plugins, themes, and WPCommander itself.
3. **Privileged developer plane** — opt-in escape-hatch abilities for runtime PHP, WP-CLI, database operations, and filesystem inspection/editing when structured resources and native abilities cannot express the task.

Provider-specific integrations are optional expertise, not required access. Elementor, Bricks, WooCommerce, ACF, or a future plugin should remain reachable through the generic planes even when WPCommander has no dedicated adapter.

## External API

The stable ChatGPT Action surface stays compact: manifest/diagnostics, discovery, generic ability execution, and mutation/audit operations. New WordPress capabilities should appear through discovery rather than requiring OpenAPI growth.

## Authentication and authorization

- External GPT access uses WordPress Application Passwords over HTTP Basic authentication.
- Every operation runs as the authenticated WordPress user and checks the narrowest applicable capability.
- Sensitive options/meta are denied by default; credentials, salts, sessions, and secret-like values are never returned by generic discovery.
- Privileged developer abilities require an additional explicit feature gate. They are disabled on production by default and are never implied by possession of an Application Password alone.

## Mutation protocol

- Reads may execute directly after authorization.
- Structured writes are two phase: `plan` resolves targets and captures before-state/version; `apply` references that plan and rejects stale state.
- Applied changes record actor, timestamp, target, operation, before/after fingerprints, and reversible payload where safe.
- Revert is another authorized mutation.
- Privileged developer operations use a separate risk path because arbitrary PHP/SQL/filesystem actions cannot honestly provide the same automatic rollback guarantees as structured mutations.

## Boundary rule

Prefer structured resources and native Abilities first. Use privileged developer abilities as the universal escape hatch rather than adding endless vendor adapters. Add provider-specific code only when it improves semantics, safety, or ergonomics; it must never be required merely to gain access to that provider's underlying WordPress data.
