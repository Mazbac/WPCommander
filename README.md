# WPCommander

WPCommander is a WordPress control plane for ChatGPT Actions. It exposes a small, stable API that lets a Custom GPT discover and execute WordPress capabilities without requiring a bespoke adapter for every theme or plugin.

## Direction

- WordPress 6.9+ native Abilities API is the capability substrate.
- WordPress Application Passwords provide revocable external authentication.
- WPCommander uses generic resource capabilities for content/settings that do not already expose an Ability.
- Consequential changes use inspect → plan → apply, with stale-state protection and audit/revert where safe.
- Arbitrary SQL, filesystem access, PHP execution, and secret browsing are not part of the generic control surface.

See `docs/PRODUCT.md`, `docs/ARCHITECTURE.md`, and `docs/STATE.md` for durable project truth.

## Development

```bash
npm ci
npm run dev
```

The Vite preview renders the same React admin application that the WordPress plugin mounts. Development fixture data implements the same bootstrap contract as WordPress.

## Build and verify

```bash
npm run verify
npm run verify:full
```

The production build writes deterministic assets under `dist/assets/` for the plugin admin page.

Create an installable WordPress release with:

```bash
npm run package:plugin
```

Every archive is validated to contain one fixed top-level `wpcommander/` directory. Keep that internal folder name stable across releases so WordPress replaces the existing plugin instead of installing version-named duplicates.

## Current WordPress surface

- `GET /wp-json/wpcommander/v1/openapi` — public compact Action schema; contains no site secrets.
- `GET /wp-json/wpcommander/v1/manifest` — authenticated readiness and access-mode metadata.
- `POST /wp-json/wpcommander/v1/resources/search` — bounded generic resource discovery.
- `POST /wp-json/wpcommander/v1/resources/inspect` — bounded/redacted resource or JSON Pointer inspection.
- `POST /wp-json/wpcommander/v1/resources/search-values` — search inside structured builder/theme data.
- `GET /wp-json/wpcommander/v1/abilities` and `POST /abilities/execute` — dynamic WordPress Ability discovery/execution.

The wp-admin setup flow can create/rotate a dedicated WordPress Application Password for the current administrator, returns the Base64 Basic token once, and provides copy-ready Action schema + Custom GPT instructions. Direct schema paste is the default setup path; URL import remains optional. The normal WordPress account password is never requested by WPCommander.
