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

## Current WordPress surface

- `GET /wp-json/wpcommander/v1/openapi` — public Action schema; contains no site secrets.
- `GET /wp-json/wpcommander/v1/manifest` — authenticated readiness metadata.
- `GET /wp-json/wpcommander/v1/abilities` — authenticated exposed Ability discovery.
- `POST /wp-json/wpcommander/v1/abilities/execute` — executes one exposed Ability as the authenticated WordPress user.

Configure the Custom GPT Action with the OpenAPI URL and Basic authentication using a dedicated WordPress Application Password. The normal WordPress account password should never be entered into the GPT Action configuration.
