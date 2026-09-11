# Current state

## Current

- Mode: active product development
- Epic: universal read → safe change
- Feature: first-class generic WordPress resource coverage
- Branch: `feat/control-plane-foundation`
- Release candidate: `0.1.2`
- Live production site has validated the 0.1.1 read-only diagnostics foundation.

## Working

- WordPress-native admin surface embedded inside wp-admin.
- WordPress 6.9+ Abilities discovery plus one stable generic ability executor.
- Production defaults to read-only diagnostics; non-readonly abilities are rejected.
- Generic resource search/inspect supports 11 kinds: post, post-meta, option, media, term, user, comment, menu, plugin, theme, and site.
- Structured JSON/serialized values support bounded previews, search, redaction, and JSON Pointer addressing.
- Global post-meta discovery can locate builder keys such as `_elementor_data` without a vendor adapter.
- Diagnostics now performs an actual search → inspect probe for every first-class resource kind and reports the concrete address read.

## Verification

- Frontend format/lint/types/UI conformance/unit/build gate passes.
- Playwright + axe browser test passes after exercising diagnostics.
- PHP files parse successfully with `php-parser`; the workstation still has no native PHP/WordPress runtime.
- The live site remains the runtime validation target; writes remain blocked there.

## Next

1. Install `0.1.2` and run the stronger production access test against the live site.
2. Use the real report to close any permission/object gaps, especially classic menus or comments if the site has none.
3. Build `plan → apply → verify → audit → revert` for the structured resource plane.
4. Add an explicitly opt-in privileged developer plane for filesystem/database/WP-CLI/runtime access without making it the default production path.

## Known issues

- Generic resource writes are not implemented yet.
- Privileged developer-plane access is architecture-only; no PHP/WP-CLI/filesystem/database executor exists yet.
- Classic menu diagnostics can warn legitimately on block-theme sites with no classic menu objects; block navigation remains addressable as posts.
