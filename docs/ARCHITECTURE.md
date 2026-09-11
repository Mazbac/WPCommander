# Architecture

This starter provides a client-side React baseline, not a mandatory architecture for every future product.

## Locked baseline

- Package manager: npm
- Runtime: Node.js 22.12+; current workstation uses Node 24
- UI runtime: React 19 + TypeScript
- Build/dev: Vite 8
- Component library: Mantine 9
- Unit tests: Vitest
- Browser, accessibility, and visual tests: Playwright + axe-core

## Structure

- `src/theme/` — global design tokens/theme configuration
- `src/components/ui/` — reusable product UI primitives
- `src/layouts/` — canonical page/application geometry
- `src/dev/` — development-only showroom/stress fixtures
- `tests/` — user-visible behavior and regression coverage

## Boundaries

Add backend, database, auth, billing, queues, analytics, or other infrastructure only when product requirements activate them. External providers must sit behind application-level adapters instead of leaking provider-specific logic throughout the codebase.

Do not replace the locked baseline casually. Record justified durable architecture changes in `DECISIONS.md` and migrate coherently rather than mixing competing systems.
