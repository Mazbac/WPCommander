# Current state

## Current

- Mode: active product development
- Epic: Custom GPT connection -> safe change
- Feature: low-friction GPT Actions setup on top of universal read
- Branch: `feat/control-plane-foundation`
- Release candidate: `0.1.7`
- Live production site validated 0.1.2 search -> inspect for post, post-meta/Elementor, option, media, term, user, menu, plugin, theme, and site; comments had no sample object.
- Live Custom GPT Actions connection is authenticated successfully against production on 0.1.6 after allowing ChatGPT edge traffic and enabling WordPress Application Passwords in Wordfence.

## Working

- WordPress-native admin surface embedded inside wp-admin.
- Generic resource search/inspect supports 11 first-class kinds plus bounded structured-value search and JSON Pointer addressing.
- Direct REST Action operations now expose resource search, inspect, and search-inside with explicit OpenAPI schemas; dynamic WordPress Abilities remain available.
- Production defaults to read-only; non-readonly Abilities are rejected.
- WPCommander can explicitly create/rotate a dedicated Application Password for the current administrator and returns a Base64 Basic token once for GPT Actions.
- wp-admin provides copy-ready site-specific Action schema and recommended Custom GPT instructions; direct paste is the default setup path.
- The generated OpenAPI satisfies the stricter Custom GPT Actions object-schema validator by giving every exposed object schema `properties` and emitting `components.schemas` as an object.
- Production diagnostics include a ChatGPT-style public-edge probe using `ChatGPT-User/1.0`, so host/CDN/WAF bot blocks are visible before Action testing.
- Release packaging now always places plugin files under one canonical `wpcommander/` directory, so future uploaded ZIPs replace the existing WordPress plugin instead of creating version-named duplicate plugins.
- Connection readiness now checks actual Application Password availability after WordPress/security-plugin filters, including per-user availability; setup and diagnostics explain when authentication is blocked instead of reporting a false READY state.

## Verification

- `npm run verify:full` passes for 0.1.7: formatting, lint, TypeScript, UI conformance, 2 unit tests, production build, Playwright/axe accessibility, and desktop/mobile visual regression.
- `npm run package:plugin` created `release/wpcommander-0.1.7.zip` and validated that the archive contains only the canonical `wpcommander/` top-level plugin directory.
- All three PHP files parse successfully with `php-parser`; the workstation still has no native PHP/WordPress runtime.
- Live production has validated one-click WPCommander credential creation and an authenticated `getWPCommanderManifest` Custom GPT Action call on 0.1.6.
- Live WordPress now advertises Application Password authentication and the public WPCommander OpenAPI route returns HTTP 200 to `ChatGPT-User/1.0`.

## Next

1. Install 0.1.7 over the canonical `wpcommander/` plugin folder; the next manual upload should use WordPress's replace-existing flow.
2. Test the remaining authenticated Action chain from the Custom GPT: resource search -> inspect -> structured-value search -> Ability discovery/execution.
3. Confirm the WPCommander REST response cleanup removes the site's historical UTF-8 BOM for WPCommander routes; direct schema paste remains the default regardless.
4. Build plan -> apply -> verify -> audit -> revert for structured resources.
5. Add the explicitly gated privileged developer plane for filesystem/database/WP-CLI/runtime access.

## Known issues

- Direct schema paste remains the default because earlier live REST responses included a UTF-8 BOM and URL import was unreliable; targeted WPCommander output cleanup still needs live re-verification.
- Generic resource writes are not implemented yet.
- Privileged developer-plane access is architecture-only.
- Comments legitimately warn when the site has no readable comment sample.
