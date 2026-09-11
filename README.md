# AI Project Starter

A lean starter for building products quickly with ChatGPT, Remote Desktop Commander, and GitHub without making project quality depend on chat memory.

## Start a project

1. Create a repository from this template.
2. Open [`docs/PROMPTS.md`](docs/PROMPTS.md) and copy the **Start a new project** prompt into a new ChatGPT chat.
3. Put raw screenshots, links, notes, and non-sensitive inspiration in `references/` when useful.
4. Give the AI your messy idea; the repository intake process turns it into product requirements, MVP, UX direction, architecture, capabilities, risk, and current state.
5. Keep `npm run dev` running for a live preview while building vertical slices.

For a fresh chat on an existing project or a quick change, `docs/PROMPTS.md` also contains copy-ready continuation prompts.

## Commands

- `npm run dev` — live development preview.
- `npm run doctor` — environment sanity check.
- `npm run verify` — format, lint, types, UI conformance, unit tests, and production build.
- `npm run verify:full` — verification plus browser accessibility/E2E and visual regression.
- `npm run test:visual -- --update-snapshots` — update intentional visual baselines only after review.

## Principles

Professional defaults, minimal ceremony, reusable components, one source of truth per concern, automated verification, and progressive rigor based on product risk.

The template intentionally does not include authentication, databases, billing, analytics, or other product-specific infrastructure. Activate only what the project actually needs.
