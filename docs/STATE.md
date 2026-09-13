# Current state

## Current

- Mode: active product development
- Epic: universal WordPress control
- Branch: `feat/control-plane-foundation`
- `0.1.11` shipped the universal CRUD control plane; `0.1.12` fixed the Custom GPT 300-character Action-description validator limit and added a static contract for it.
- `0.1.13` adds generic conversation-image transfer: user-uploaded or ChatGPT-generated current-conversation images can be imported into the WordPress Media Library through `openaiFileIdRefs`, and existing WordPress images can be returned as normal image/download URLs with dimensions and common size variants.
- Media import is bounded to 10 images, Edit site/Full control plus `upload_files`, supported image MIME/content, WordPress upload-size limits, a 40 MP processing ceiling, HTTPS OpenAI file hosts, and idempotent replay keyed by a hash of the stable OpenAI file ID.
- Production was last explicitly API-verified on `0.1.10`; subsequent plugin installs must be confirmed from the live manifest before relying on the reported version.
- Production authentication through the dedicated WordPress Application Password is working. The live manifest reported both structured writes and universal execution enabled at the time of verification.
- Production content work remains paused until `0.1.13` is installed, the refreshed Custom GPT Action schema imports cleanly, and controlled CRUD/media acceptance is completed.

## Working foundation

- Generic search/inspect covers 11 first-class resource kinds plus bounded structured-value search and RFC 6901 JSON Pointer addressing.
- Bounded developer inspection covers runtime inventory, REST routes, plugin/theme/core source, database tables/schema/sample rows, and hash-only filesystem metadata with secret-aware redaction.
- 0.1.10 provides the universal Execute fallback: internal REST, loaded PHP callables, bounded PHP, SQL, filesystem operations, and WP-CLI when available.
- Universal capability is invariant per D019: a WordPress/PHP-accessible subsystem must retain a generic control path without a provider adapter.
- Application Password authentication uses WordPress itself; WPCommander never stores a second plaintext API key.

## 0.1.11 work

- The machine-facing model is now generic Discover/Inspect → Create/Read/Update/Delete → Execute (D021).
- Structured Create supports posts/pages/custom post types from scratch or from an inspected source resource. Duplication is therefore Create-from-source, not a clone adapter/action.
- Structured Update keeps the existing exact pointer/field path and adds batch Update for up to 50 non-overlapping changes to one resource.
- Batch Update prepares the full new state in memory, performs the minimum WordPress write(s), verifies once, records one bounded activity entry, and supports stale-safe revert where the before-state fits the reversible envelope.
- Structured Delete currently covers posts/pages/custom post types, requires a fresh fingerprint, defaults to WordPress Trash, and records reversible activity; permanent deletion is irreversible.

## Verification status

- Full browser and static verification passed for the 0.1.11 foundation on 2026-09-12.
- Release 0.1.12 passed the repository checks.
- Release 0.1.13 passed repository checks plus browser accessibility and visual tests on 2026-09-13.
- The 0.1.13 backend checks cover seven plugin PHP files including media transfer.
- Release package: release/wpcommander-0.1.13.zip, 184940 bytes.
- SHA-256: 504A0B0EB7E98DD502BEE501CFEB7DB87A74876F90C41A565BFB0824BC40F371.
- The workstation has no native WordPress/PHP runtime; live backend acceptance still follows installation.

## Next

1. Install 0.1.13 and refresh the Custom GPT Action schema and recommended instructions.
2. Confirm schema import, then live-test both a user-provided image and a ChatGPT-generated image through import, WordPress media use, and frontend verification.
3. Run the controlled CRUD acceptance without provider adapters.
4. Resume normal production content work only after those acceptance checks succeed.

## Known bounds

- Structured CRUD is intentionally smaller than the universal surface; unsupported resource kinds or operations continue through native Abilities or Full-control Execute.
- Numeric array insertion/removal is not yet a structured JSON Pointer operation.
- Universal execution cannot promise generic automatic rollback and must be verified after execution.
- Conversation-image ingress depends on the temporary OpenAI Action download URL still being valid when the Action runs.
- WordPress-to-chat images use normal HTTPS image/download URLs because Custom GPT Actions do not return image/video files as file responses.
- WP-CLI depends on host process permissions and the wp binary; production availability has not yet been proven.
- Direct schema paste remains the default Custom GPT setup path because URL import can be affected by hosting/WAF/encoding behavior.
