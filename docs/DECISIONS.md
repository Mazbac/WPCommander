# Durable decisions

Record only decisions a future agent might otherwise undo. Git history is not repeated here.

## 2026-09-11 — D001: Repository is project memory

Chat history is disposable. Product truth, current state, durable decisions, and working rules must live in the repository.

## 2026-09-11 — D002: Speed through constraints

Optimize for fastest path to a professional releasable product: reuse defaults, build vertical slices, automate verification, and avoid speculative infrastructure or corporate ceremony.

## 2026-09-11 — D003: Mantine is the default React component system

Use Mantine and shared product primitives before custom controls. A project may choose another library only for a concrete product/platform reason.

## 2026-09-11 — D004: Standards-led UI

Accessibility follows current WCAG guidance; platform-specific behavior follows current platform guidance; common UX uses mature design-system patterns. AI aesthetic preference is the final fallback, not the source of truth.

## 2026-09-11 — D005: Evolution must be system-wide

Intentional design-system changes update shared primitives/tokens and reviewed baselines. Local exceptions are not an acceptable substitute for coherent evolution.

## Adding decisions

Use: date, stable ID, decision, and short reason. Add only when the choice is durable enough to affect future work.

## 2026-09-11 — D006: WordPress Abilities API is the native capability substrate

Require WordPress 6.9+ and build on its discoverable, schema-described, permission-aware Abilities API instead of inventing a parallel capability registry.

## 2026-09-11 — D007: Generic resources before vendor adapters

WPCommander addresses WordPress resources and structured nested values through stable resource addresses and JSON Pointer-style paths. Provider-specific adapters are exceptional compatibility shims, not the product architecture.

## 2026-09-11 — D008: External GPT authentication uses Application Passwords

Custom GPT Actions authenticate as a real WordPress user through a dedicated, revocable WordPress Application Password over Basic auth. WPCommander does not store a second plaintext API key or bypass WordPress capabilities.

## 2026-09-11 — D009: Consequential writes are two-phase and auditable

A write is planned against a captured target state and applied only with a valid non-stale plan. Applied changes record enough safe information for inspection and, where possible, authorized revert.

## 2026-09-11 — D010: WordPress owns the admin shell

WPCommander admin pages embed as content inside the native WordPress admin chrome. Do not render a second application sidebar/header inside wp-admin; standalone preview geometry must not leak into the plugin surface.

## 2026-09-11 — D011: Production diagnostics default to read-only

The control plane defaults to read-only diagnostics mode while the mutation engine is incomplete. Discovery and readonly abilities may execute against production, but non-readonly abilities are rejected until write access is explicitly enabled by a future controlled flow.

## 2026-09-11 — D012: Universal access uses layered control primitives

WPCommander must be capable of reaching the whole WordPress installation without requiring a dedicated adapter for every plugin or builder. The architecture therefore combines structured resources, native WordPress Abilities, and an opt-in privileged developer plane for PHP, WP-CLI, database, and filesystem operations. Provider-specific integrations improve semantics and ergonomics but are never the only route to underlying data.

## 2026-09-11 — D013: Privileged developer access is explicitly gated

Arbitrary PHP, SQL, WP-CLI, and filesystem mutation are fundamentally higher-risk than structured WordPress changes. They remain disabled on production by default and require an explicit privileged feature gate. Structured production writes keep the plan/apply/stale-check/audit/revert workflow; privileged operations use separate warnings and cannot claim universal automatic rollback.

## 2026-09-11 — D014: Custom GPT setup is generated inside WPCommander

The admin UI owns the shortest safe setup path: an explicit button creates or rotates one WPCommander-specific Application Password for the current user, returns a Base64 HTTP Basic token once, and provides copy-ready schema and GPT instructions. Plaintext credentials are never persisted by WPCommander.

## 2026-09-11 — D015: Direct schema paste is the default GPT Actions setup

WPCommander keeps its Action schema compact and presents it directly in wp-admin for copy/paste. URL import remains optional because hosting layers, redirects, caching, WAFs, or response encoding can make import less reliable. The schema exposes typed generic resource operations plus Ability discovery/execution rather than one endpoint per vendor.
