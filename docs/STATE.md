# Current state

## Current

- Mode: active product development
- Epic: Custom GPT connection -> safe change
- Feature: direct structured command execution on top of universal read/runtime inspection
- Branch: `feat/control-plane-foundation`
- Release candidate: `0.1.9`
- Live production site validated 0.1.2 search -> inspect for post, post-meta/Elementor, option, media, term, user, menu, plugin, theme, and site; comments had no sample object.
- Live Custom GPT Actions connection is authenticated successfully against production on 0.1.6 after allowing ChatGPT edge traffic and enabling WordPress Application Passwords in Wordfence.
- Live 0.1.8 runtime inspection proved the no-adapter model against Code Snippets 3.10.2: the GPT independently traced plugin source/bootstrap, confirmed 23 live REST routes under `/code-snippets/v1`, and discovered/described the plugin's `wp_snippets` table without reading snippet rows.

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
- 0.1.9 adds direct field-set commands for post/media/term/comment plus exact nested set/remove for post-meta/options. A fresh resource fingerprint is required; retries are idempotent; successful writes are verified and recorded for stale-safe revert.
- Structured writes are disabled by default and enabled only through an explicit wp-admin command-access gate. Enabling them does not enable arbitrary plugin/theme write Abilities or privileged PHP/SQL/WP-CLI/filesystem execution.

## Verification

- `npm run verify:full` passes for 0.1.9: formatting, lint, TypeScript, UI conformance, PHP parse/safety contracts, 3 unit tests, production build, Playwright/axe accessibility, and reviewed desktop/mobile visual regression.
- `npm run package:plugin` created `release/wpcommander-0.1.9.zip` with the canonical `wpcommander/` top-level directory and the new mutation engine.
- The PHP safety gate covers both the bounded read-only developer inspector and the structured mutation boundary; the workstation still has no native PHP/WordPress runtime.
- Live production has validated one-click WPCommander credential creation and an authenticated `getWPCommanderManifest` Custom GPT Action call on 0.1.6.
- Live WordPress now advertises Application Password authentication and the public WPCommander OpenAPI route returns HTTP 200 to `ChatGPT-User/1.0`.

## Next

1. Install 0.1.9, refresh the Action schema/instructions, and explicitly enable structured writes in wp-admin.
2. Confirm the manifest reports `write-enabled` while arbitrary plugin write Abilities remain blocked.
3. Live-test a deliberately low-risk structured edit through the Custom GPT, verify the changed resource, then revert it through the recorded activity entry.
4. Live-test one narrow Elementor setting via `_elementor_data`/JSON Pointer and verify whether Elementor runtime/cache side effects require a generic follow-up primitive.
5. Add the explicitly gated privileged execution plane for arbitrary filesystem/database/WP-CLI/PHP operations.

## Known issues

- Direct schema paste remains the default because earlier live REST responses included a UTF-8 BOM and URL import was unreliable; targeted WPCommander output cleanup still needs live re-verification.
- Structured writes currently cover post, post-meta, option, media, term, and comment only; numeric array add/remove, plugin/theme lifecycle, users/roles, menus, and high-impact core options remain outside the normal mutation surface.
- Privileged developer execution (arbitrary PHP/SQL/WP-CLI/filesystem mutation) is not implemented; developer inspection remains bounded/read-only.
- Comments legitimately warn when the site has no readable comment sample.
