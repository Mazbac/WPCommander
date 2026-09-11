# User lifecycle

Select only the stages that apply to the product, but design those stages deliberately. A feature is not complete if it breaks the end-to-end journey around it.

## Lifecycle map

- Discover/acquire: how users reach the product and understand its purpose.
- Install/open: platform-appropriate acquisition, prerequisites, failure/retry, and permissions.
- First launch: reach useful product UI quickly; avoid ceremonial screens.
- Required setup: ask only for information or connections needed to function.
- Onboarding: teach contextually and minimally; optimize for the first successful task.
- Normal/returning use: preserve appropriate preferences/state and make common work efficient.
- Interruption/recovery: handle lost connectivity, expired sessions, restarts, denied permissions, retries, and partial work when relevant.
- Update/migration: preserve data/config compatibility and provide recovery for consequential migrations.
- Account/data management: make ownership, export, retention, cancellation, sign-out, and deletion semantics explicit.
- Uninstall/leave: remove app-owned artifacts cleanly while preserving user-created data unless deletion is explicitly requested.
- Reinstall/return: deliberately choose whether state is restored or reset.

## Product-specific journey

Replace this section during intake with the actual shortest path from acquisition to the first successful outcome, plus consequential recovery/exit paths.

For each new feature ask whether it changes setup, onboarding, permissions, returning state, updates, export, account deletion, uninstall, or recovery. If none apply, do not add lifecycle ceremony.
