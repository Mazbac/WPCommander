# UI and interaction contract

WPCommander has a small operational admin UI. ChatGPT is the primary conversational interface; wp-admin exists for connection, visibility, and recovery.

## Information architecture

- `Overview`: connection readiness, site/control-plane status, and the shortest next action.
- `Capabilities`: what WordPress and WPCommander expose to the connected GPT, with read/write distinction.
- `Activity`: planned/applied/reverted changes and recovery actions.
- `Settings`: only configuration that cannot be inferred safely; keep this small.

## Core rules

- Use Mantine components and semantic theme tokens; no ornamental dashboard chrome or invented metrics.
- Lead with operational state: Ready, Needs setup, Warning, or Error, always with text in addition to color.
- Never display a saved credential after its one-time creation flow. Link to native WordPress Application Password management for revocation.
- Consequential actions name the target and consequence. Revert is explicit and unavailable when the audit entry is not safely reversible.
- Technical identifiers such as REST routes, resource addresses, and JSON pointers may be shown in secondary/detail UI but not as the primary label.

## Overview contract

The first screen should answer: Is WPCommander ready? How do I connect ChatGPT? What can it currently control? What changed recently?

Use a compact status row and one primary WordPress-style connection panel with three steps: explicitly generate/rotate a dedicated one-time Basic token, copy the site-specific Action schema, then copy the recommended GPT instructions. Direct schema paste is the default; the schema URL is secondary. Keep schema/instruction bodies collapsed behind details by default, and present diagnostics/capabilities as simple tables or rows rather than dashboard-card grids.

## States and accessibility

Cover loading, connection-not-configured, auth failure, unsupported WordPress version, no activity, partial capability discovery, stale plan, apply failure, and read-only permission states when applicable. WCAG 2.2 AA, keyboard operation, visible focus, semantic status announcements, responsive/reflow behavior, and safe long-value truncation are baseline requirements.

## Anti-drift

The local Vite preview renders the same React application code that is embedded in WordPress; development data comes through a fixture implementation of the production data contract rather than a separate mock screen.

## WordPress admin host

The plugin UI is hosted inside `wp-admin` and must use WordPress's existing admin chrome rather than rendering a second app shell. The React root owns only page content. Global `body` geometry/styles are forbidden on the plugin page; spacing and width belong to the WPCommander root so the WordPress sidebar, toolbar, notices, and responsive behavior remain authoritative.
