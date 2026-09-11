# Product classification and capability packs

The core stays lean. During intake, classify the product and activate only relevant packs; each activated pack triggers current domain/platform research before implementation when requirements are consequential or unfamiliar.

## Product profile

Record: distribution surface, primary device/input, user type, valuable/sensitive assets, external systems/actions, expected scale, regulatory domain, and risk level (`lightweight`, `standard`, or `high-consequence`).

## Capability packs

- `auth`: sessions, recovery, reauthentication, authorization, password managers/identity providers.
- `billing-money`: decimal precision, atomicity, duplicate prevention, checkout/renewal/cancellation/failure.
- `files-import-export`: validation, size/type limits, progress, retry, corruption, compatibility, portability.
- `background-jobs`: queued/running/retrying/failed/cancelled states, duplicate execution, restart recovery.
- `realtime-collaboration`: freshness, reconnect, conflict/version strategy, presence/concurrent edits.
- `offline-sync`: local state, reconciliation, conflict handling, multi-device behavior.
- `notifications`: permission timing, preference controls, duplicates, delivery failure, fatigue/abuse.
- `integrations-webhooks`: authentication/signatures, rate limits, retries, ordering, duplicates, provider outage.
- `time-scheduling`: timezone, DST, recurring/wall-clock semantics, locale/calendar behavior.
- `ai`: model/provider boundaries, prompt/version changes, malformed output, cost/rate limits, consequential confirmation.
- `user-content`: hostile input, reporting/moderation where needed, rendering safety, abuse.
- `desktop-mobile`: install/update/uninstall, window/background lifecycle, DPI/safe areas, OS conventions.
- `regulated-domain`: apply current jurisdiction/domain requirements before design is locked.

## Abuse check

For consequential features ask: how can a normal authenticated user intentionally or accidentally repeat, race, bypass, exhaust, or misuse this operation? Add controls only where the answer creates meaningful risk.
