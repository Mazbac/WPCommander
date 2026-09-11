# Current state

## Current

- Mode: active product development
- Epic: Custom GPT connection -> safe change
- Feature: low-friction GPT Actions setup on top of universal read
- Branch: `feat/control-plane-foundation`
- Release candidate: `0.1.4`
- Live production site validated 0.1.2 search -> inspect for post, post-meta/Elementor, option, media, term, user, menu, plugin, theme, and site; comments had no sample object.

## Working

- WordPress-native admin surface embedded inside wp-admin.
- Generic resource search/inspect supports 11 first-class kinds plus bounded structured-value search and JSON Pointer addressing.
- Direct REST Action operations now expose resource search, inspect, and search-inside with explicit OpenAPI schemas; dynamic WordPress Abilities remain available.
- Production defaults to read-only; non-readonly Abilities are rejected.
- WPCommander can explicitly create/rotate a dedicated Application Password for the current administrator and returns a Base64 Basic token once for GPT Actions.
- wp-admin provides copy-ready site-specific Action schema and recommended Custom GPT instructions; direct paste is the default setup path.
- The generated OpenAPI now satisfies the stricter Custom GPT Actions object-schema validator by giving every exposed object schema `properties` and emitting `components.schemas` as an object.

## Verification

- `npm run verify:full` passes for 0.1.4: formatting, lint, TypeScript, UI conformance, unit tests, production build, Playwright/axe, and visual regression.
- The intentionally changed native WordPress-style desktop/mobile layouts were manually inspected before refreshing the visual baselines.
- PHP files parse successfully with `php-parser`; the workstation still has no native PHP/WordPress runtime.
- Live WordPress runtime validation is still required for one-click Application Password generation, authenticated GPT Actions, and the targeted REST BOM cleanup.

## Next

1. Install 0.1.4 on the live site. Generate a connection token and paste the generated schema/instructions into a private Custom GPT Action.
2. Test real authenticated Action calls: manifest -> resource search -> inspect -> structured-value search -> Ability discovery/execution.
3. Confirm the WPCommander REST response cleanup removes the site's global UTF-8 BOM for WPCommander routes; URL import is secondary regardless.
4. Build plan -> apply -> verify -> audit -> revert for structured resources.
5. Add the explicitly gated privileged developer plane for filesystem/database/WP-CLI/runtime access.

## Known issues

- The live site currently emits a UTF-8 BOM before all WordPress REST JSON responses; this likely explains unreliable schema URL import. 0.1.3 adds targeted output-buffer cleanup for WPCommander routes and defaults setup to direct schema paste.
- Generic resource writes are not implemented yet.
- Privileged developer-plane access is architecture-only.
- Comments legitimately warn when the site has no readable comment sample.
