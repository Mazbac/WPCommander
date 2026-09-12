# Product

WPCommander lets a WordPress administrator control a site through ChatGPT without building a bespoke integration for every theme or plugin.

## Goal

- Problem: WordPress behavior and design are spread across posts, metadata, options, media, plugins, themes, custom tables, REST routes, PHP runtime, and files. Automating vendors separately does not scale.
- Target user: a WordPress site owner, builder, or agency operator who wants to state the desired result and let ChatGPT find and perform the relevant WordPress operation.
- Core successful outcome: install WPCommander, grant the intended access level, then ask ChatGPT to find, explain, create, change, or remove WordPress state without needing a vendor adapter first.
- Why this product should exist: WordPress/PHP already exposes common data/runtime primitives. WPCommander should expose those foundations as one deterministic control plane.

## Product profile

- Surface/distribution: installable WordPress plugin with a small operational wp-admin UI and an authenticated REST/OpenAPI interface for ChatGPT clients. Custom GPT Actions are a first-class connection path, not the product architecture.
- Primary environment: WordPress 6.9+; develop and verify against currently maintained WordPress releases.
- Risk level: high-consequence because authorized writes can materially change a production website.
- Valuable/sensitive assets affected: published content, design data, media, site settings, plugin/theme-owned state, code, database state, and operational configuration.

## MVP

- Connect an authorized ChatGPT client to one WordPress site with a revocable WordPress Application Password and a copy-ready OpenAPI schema.
- Discover and inspect WordPress resources, native Abilities, plugin/theme source/runtime, REST routes, database structure, and filesystem metadata without assuming vendor support.
- Use one generic control language: Discover/Inspect, Create, Read, Update, Delete, then Execute when ordinary resource CRUD cannot express the requested result.
- Support multi-field updates to one structured resource in one request so builder/plugin data does not require dozens of network roundtrips.
- Create from an inspected source resource is the generic way to duplicate WordPress state; it must not become a page-builder or vendor clone adapter.
- Keep structured operations deterministic with WordPress capability checks, fresh-state fingerprints, verification, idempotent retry, bounded activity, and revert where technically safe.
- Keep a universal execution fallback for everything WordPress/PHP can legitimately reach through internal REST, loaded callables, PHP, SQL, filesystem operations, and WP-CLI when available.
- Let risk change the execution path, confirmation, audit, and rollback guarantees; risk must not remove the fundamental control path.

## Product rules / non-goals

- No Elementor-, WooCommerce-, ACF-, theme-, or plugin-specific adapter catalog. A plugin installed tomorrow must be controllable through generic discovery, CRUD, Abilities, runtime inspection, or Execute without a WPCommander release first.
- Do not expose implementation history as product UX. The user chooses Inspect only, Edit site, or Full control; internal write/execution gates remain implementation details.
- Do not invent a plan/apply ceremony. The normal path is ask → discover/inspect if needed → execute → verify/report.
- Do not ask for repetitive approval when the user's command already clearly requests the privileged result. Ask again only when a broad, destructive, irreversible, or privileged consequence was not reasonably implied.
- Credential extraction or secret browsing is never a product goal; privileged execution exists to control the site, not expose credentials.
- Multisite fleet management and scheduled automation are later workflow/scale capabilities, not prerequisites for universal site control.
- WPCommander is the deterministic WordPress control plane; ChatGPT remains the conversational planner. The plugin does not need its own general-purpose chatbot.

## Success

The product succeeds when a fresh supported WordPress site can install WPCommander, connect ChatGPT, discover an unknown plugin/theme without an adapter, express normal work through generic CRUD, fall back to universal Execute when needed, verify the resulting state, and report activity without making the user understand the implementation layers.
