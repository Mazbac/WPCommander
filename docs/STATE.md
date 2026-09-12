# Current state

## Current

- Mode: active product development
- Epic: Custom GPT connection -> safe change
- Feature: low-friction GPT Actions setup on top of universal read
- Branch: `feat/control-plane-foundation`
- Release candidate: `0.1.8`
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
- 0.1.8 adds one bounded read-only developer-inspection primitive for runtime inventory, REST route discovery, plugin/theme/core source browsing/search, and WordPress-prefix database table/schema/sample inspection with path/size limits and secret redaction.
- Normal structured changes are a direct command from the user/GPT perspective; preflight, stale-state protection, verification, audit, and reversible capture are internal mechanics rather than a visible plan/apply workflow.

## Verification

- `npm run verify:full` passes for 0.1.8: formatting, lint, TypeScript, UI conformance, the PHP read-only contract gate, 2 unit tests, production build, Playwright/axe accessibility, and desktop/mobile visual regression.
- `npm run package:plugin` created `release/wpcommander-0.1.8.zip` and validated that the archive contains only the canonical `wpcommander/` top-level plugin directory, including the new developer inspector.
- All four PHP files parse successfully with the committed `php-parser` gate; the workstation still has no native PHP/WordPress runtime.
- Live production has validated one-click WPCommander credential creation and an authenticated `getWPCommanderManifest` Custom GPT Action call on 0.1.6.
- Live WordPress now advertises Application Password authentication and the public WPCommander OpenAPI route returns HTTP 200 to `ChatGPT-User/1.0`.

## Next

1. Install 0.1.8 over the canonical `wpcommander/` folder.
2. Live-test the authenticated read chain including `inspectWordPressRuntime`: inventory -> targeted plugin source search/read -> REST route discovery -> WordPress-prefix table discovery/schema/sample.
3. Confirm the WPCommander REST response cleanup removes the site's historical UTF-8 BOM for WPCommander routes; direct schema paste remains the default regardless.
4. Build direct structured mutations with internal preflight/stale checks/verification/audit/revert capture, without exposing a user-facing plan/apply ritual.
5. Add the explicitly gated privileged execution plane for arbitrary filesystem/database/WP-CLI/PHP operations.

## Known issues

- Direct schema paste remains the default because earlier live REST responses included a UTF-8 BOM and URL import was unreliable; targeted WPCommander output cleanup still needs live re-verification.
- Generic resource writes are not implemented yet.
- Privileged developer execution (arbitrary PHP/SQL/WP-CLI/filesystem mutation) is not implemented; 0.1.8 only adds bounded read-only inspection.
- Comments legitimately warn when the site has no readable comment sample.
