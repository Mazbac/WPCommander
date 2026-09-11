# Roadmap

## MVP

### Epic: Product intake

- [x] Normalize raw idea into a product contract and explicit non-goals.
- [x] Classify the product as a high-consequence WordPress integration.
- [x] Define the install → GPT connection → inspect → plan → apply → revert journey.

### Epic: Control-plane foundation

- [x] Package the repository as an installable WordPress 6.9+ plugin while retaining the React admin application.
- [x] Add the WordPress-native WPCommander admin surface with connection, capabilities, diagnostics, and activity views.
- [x] Expose a generated OpenAPI document and readiness/manifest endpoint.
- [x] Register WPCommander abilities and discover already-registered WordPress Abilities.
- [x] Add copy-ready Custom GPT setup: one-click Application Password/Basic token, pasted Action schema, and recommended GPT instructions.

### Epic: Universal read

- [x] Define stable resource addresses for posts, post meta, options, media, terms, users, comments, menus, plugins, themes, site state, and structured nested values.
- [x] Implement bounded/redacted search and inspect operations with WordPress capability checks.
- [x] Make generic structured data useful for page-builder/theme storage without vendor-specific adapters.
- [x] Add production-safe search → inspect diagnostics for every first-class resource kind.
- [ ] Add privileged developer-plane reads for filesystem, database, WP-CLI, and runtime introspection.

### Epic: Safe change

- [ ] Implement plan/apply for structured set, replace, and remove operations.
- [ ] Reject stale plans and duplicate applies.
- [ ] Record activity with reversible payloads where safe.
- [ ] Implement authorized revert and verify the end-to-end Custom GPT flow.

## Later

Media upload/transform, plugin/theme lifecycle management, multisite, scheduled automation, compatibility shims for exceptional products, and richer native Ability passthrough. Arbitrary SQL/filesystem/PHP execution stays outside the generic control surface.
