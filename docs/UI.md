# UI and interaction contract

WPCommander has a small operational admin UI. ChatGPT is the primary conversational interface; wp-admin exists for connection, access, visibility, troubleshooting, and recovery.

## Primary information architecture

The main page follows one operational flow:

1. **Connection** — credential readiness, create/rotate credential, and connection health.
2. **Site access** — one understandable access level: Inspect only, Edit site, or Full control.
3. **Recent activity** — what WPCommander changed and whether an eligible structured change can be recovered.

Diagnostics, detailed capabilities, Action schema, GPT instructions, REST identifiers, and other implementation detail are secondary disclosures. Do not make implementation history into permanent dashboard sections.

## Core rules

- Use Mantine components and semantic theme tokens; no ornamental dashboard chrome or invented metrics.
- Lead with operational state: Ready, Needs setup, Warning, or Error, always with text in addition to color.
- Never display a saved credential after its one-time creation flow. Revocation remains owned by native WordPress Application Password management.
- Technical identifiers such as REST routes, resource addresses, JSON pointers, PHP/SQL primitives, and internal gate names belong in secondary/detail UI, not primary labels.
- Full control is a normal access choice, not an alarm screen. Consequences should be stated clearly without repetitive warnings or fear-oriented styling.
- Consequential actions name the target and consequence. Revert is explicit and unavailable when an activity entry is not safely reversible.

## Connection contract

Show one WordPress connection panel. The normal state answers whether authentication is available and whether a dedicated WPCommander credential exists. Creating/rotating a credential returns the Basic token once; the UI must tell the user to copy it now without ever pretending it can be recovered later.

Keep `Run diagnostics` available for troubleshooting. The detailed report stays collapsed until requested. Keep Custom GPT Action schema/instructions under one secondary `Custom GPT setup` disclosure; direct schema paste remains the default setup path and the schema URL is secondary.

## Site access contract

Expose exactly three nested choices:

- **Inspect only** — read/search/inspect and readonly abilities; no WordPress state changes.
- **Edit site** — normal structured Create/Update/Delete with stale-state checks, verification, activity, and revert where supported.
- **Full control** — includes Edit site and additionally permits write Abilities plus the universal execution fallback for anything the WordPress/PHP process can legitimately reach.

The user must never need to understand `structuredWritesEnabled` versus `universalExecutionEnabled`. Full control implies normal edits. Moving from Full control to Edit site disables only the universal layer; moving to Inspect only disables both write layers.

## States and accessibility

Cover loading, connection-not-configured, auth unavailable/failure, diagnostics failure, access-update failure, no activity, stale target, mutation failure, read-only permission, long content, responsive/reflow, keyboard operation, and destructive/recovery states when applicable. WCAG 2.2 AA and visible focus are baseline requirements.

## Anti-drift and WordPress host

The Vite preview renders the same React application embedded in WordPress; development data uses the production data contract. The plugin page lives inside native wp-admin chrome and must not render a second application shell or global body geometry.
