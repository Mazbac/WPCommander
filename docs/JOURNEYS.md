# User lifecycle

## Primary journey: install to first successful change

1. Install and activate WPCommander on a WordPress 6.9+ site.
2. Open WPCommander in wp-admin and see whether the site is ready to connect, including whether Application Password authentication is actually available after security-plugin/site-policy filters.
3. Generate the dedicated WPCommander connection token in the plugin, then copy the Action schema and recommended GPT instructions.
4. In the Custom GPT editor, add an Action using the pasted schema and the generated Basic authentication token.
5. Ask the GPT about the site. It calls discovery/search/inspect and explains what it found.
6. Ask for a normal change such as replacing text, a color, or a nested layout setting. The GPT resolves the target and sends one structured mutation command; WPCommander performs the safety preflight and verification internally.
7. The GPT reports what changed. WPCommander rejects stale or unauthorized writes and records the change automatically.
8. Broad, destructive, irreversible, or privileged operations ask for explicit confirmation; the admin can inspect activity and revert an eligible prior change from wp-admin or via the GPT.

## Returning use

The normal path is conversational: ask → discover if needed → inspect → execute → verify. Internal preflight/audit mechanics stay invisible unless something is ambiguous, stale, destructive, or privileged. Connection setup should not reappear unless the credential is missing/revoked or WordPress compatibility changes.

## Recovery

- Authentication failure: show a clear reconnect path; never ask for the normal WordPress password.
- Target not found/ambiguous: return bounded candidates and require a more specific target before executing.
- Stale target: reject the mutation and make the GPT re-inspect current state rather than overwriting newer work.
- Mutation failure: leave the target unchanged when possible and return a machine-readable WordPress error.
- Lost credential: revoke it in the WordPress user profile and create a new dedicated Application Password.

## Uninstall

Remove WPCommander-owned settings and transient command/preflight data. Preserve user-created WordPress content and audit records unless the admin explicitly chooses deletion.
