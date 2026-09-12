# Product

WPCommander lets a WordPress administrator control a site through ChatGPT without building a bespoke integration for every theme or plugin.

## Goal

- Problem: WordPress behavior and design are spread across posts, blocks, metadata, options, media, themes, plugins, and vendor-specific storage. Automating each vendor separately does not scale.
- Target user: a WordPress site owner, builder, or agency operator who is comfortable asking ChatGPT to make site changes.
- Core successful outcome: the user can ask ChatGPT to find, explain, preview, and safely change site content or configuration through one stable WordPress interface.
- Why this product should exist: WordPress already has common primitives and a machine-readable Abilities API; WPCommander should expose those foundations instead of recreating every vendor UI.

## Product profile

- Surface/distribution: installable WordPress plugin with a small WordPress admin UI and an authenticated REST/OpenAPI interface for Custom GPT Actions.
- Primary environment: WordPress 6.9+; develop and verify against currently maintained WordPress releases.
- Risk level: high-consequence because authorized writes can change a production website.
- Valuable/sensitive assets affected: published content, design data, media, site settings, plugin/theme-owned metadata, and operational configuration.

## MVP

- Connect one Custom GPT to one WordPress site with a revocable WordPress credential and a copy/import-ready OpenAPI schema.
- Discover what the site can do using WordPress Abilities plus WPCommander generic resource capabilities.
- Search and inspect WordPress resources without knowing which builder or theme produced them.
- Inspect bounded plugin/theme/core source, registered runtime surface, and WordPress-prefixed database structure when an unknown plugin cannot be understood from generic resources alone.
- Execute a normal structured change directly from the user's command while WPCommander performs internal target resolution, capability checks, stale-state protection, and result verification.
- Record each applied change automatically with enough before/after state for inspection and safe revert where possible.
- Require explicit confirmation only for broad, destructive, irreversible, or privileged operations.

## Later / non-goals

- No endless Elementor-, Divi-, theme-, or plugin-specific adapter catalog. A thin compatibility layer is allowed only when a high-value capability cannot be represented through generic WordPress primitives or a registered Ability.
- No arbitrary SQL, filesystem editing, PHP execution, credential extraction, or secret browsing in the normal control surface.
- Plugin/theme installation, code editing, multisite fleet management, scheduled automation, and broad media transformation are later capabilities.
- WPCommander is the deterministic WordPress control plane; ChatGPT remains the conversational planner. The plugin does not need its own general-purpose chatbot.

## Success

The first useful version succeeds when a fresh WordPress 6.9+ site can install WPCommander, connect a Custom GPT, discover both normal resources and unknown plugin storage/source without a vendor adapter, execute a requested structured edit directly, verify it, and show/revert the resulting activity.
