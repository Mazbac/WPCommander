# User lifecycle

## Primary journey: install to first successful change

1. Install and activate WPCommander on a WordPress 6.9+ site.
2. Open WPCommander in wp-admin and see whether the site is ready to connect, including whether Application Password authentication is actually available after security-plugin/site-policy filters.
3. Generate the dedicated WPCommander connection token in the plugin, then copy the Action schema and recommended GPT instructions.
4. In the Custom GPT editor, add an Action using the pasted schema and the generated Basic authentication token.
5. Ask the GPT about the site. It calls discovery/search/inspect and explains what it found.
6. Ask for a change such as replacing text or a color. The GPT creates a plan and reports the resolved target plus before/after value.
7. After approval, the GPT applies the plan. WPCommander rejects stale or unauthorized writes and records the change.
8. The admin can inspect activity and revert an eligible change from wp-admin or via the GPT.

## Returning use

The normal path is conversational: ask → discover if needed → inspect → plan → apply. Connection setup should not reappear unless the credential is missing/revoked or WordPress compatibility changes.

## Recovery

- Authentication failure: show a clear reconnect path; never ask for the normal WordPress password.
- Target not found/ambiguous: return bounded candidates and require a more specific target before planning.
- Stale target: invalidate the plan and make the GPT inspect/re-plan rather than overwriting newer work.
- Apply failure: leave the target unchanged when possible and return a machine-readable WordPress error.
- Lost credential: revoke it in the WordPress user profile and create a new dedicated Application Password.

## Uninstall

Remove WPCommander-owned settings and transient plan data. Preserve user-created WordPress content and audit records unless the admin explicitly chooses deletion.
