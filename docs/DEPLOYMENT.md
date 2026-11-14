# NovinPayPos: controlled cPanel production release

## Shared tooling

Use the tracked `scripts/release/recover.php` with an explicit private manifest and its SHA-256. `inspect` performs read-only production/database checks; `capture` checks an empty approved restore target, obtains live quota evidence, captures durable storage plus transactional SQL, restores only the isolated target, and publishes an encrypted receipt. The Linux `capture.py` monitor rejects any durable-storage mutation, including a write followed by restoration of the same bytes. The companion `bridge.ps1 -QuotaOnly` consumes that same pinned manifest and performs quota response, encrypted transfer and independent verification through `transfer.ps1` and `verify-offhost.ps1`.

Deploy these helpers together in an immutable private tool directory and checksum the whole tool bundle. Release-specific facts belong in manifests, including current branch, commits, exact migration/trigger contracts, retained files, state/audit pins, isolated destination and a unique backup generation. Never copy a historical PHP helper and replace commit strings. Keep private manifests, encryption keys and receipts outside Git. The cPanel File Manager API supports preparation/readback, but this environment has no authorized server-execution API; the owner invokes the verified CLI command.

After independent recovery PASS, `activate-source.sh` and `activate-source.php` consume a separately pinned release manifest and the existing approved source/CSS artifacts. They cover modified existing files, preserve dependencies/routes/schema and configuration, verify file rollback archives, use conventional Laravel maintenance, and verify HTTPS/assets before publishing PASS. They reject migrations, dependency changes, additions/deletions/renames and unsupported asset shapes. Such releases need a reviewed extension of this workflow; never fall back to the retired PR13 activation pipeline.

A failed capture or occupied restore target is preserved and cannot be replayed. Preflight STOPs produce a manifest-bound private receipt with the specific check and safe metadata. Only an explicit `resume` of a recorded pre-capture quota PENDING is allowed, with the same pinned manifest and a new full preflight/SQL-empty check; other failures require reconciliation. Completed captures are recognized by their manifest and archive hashes and reused without another restore. Never delete a journal or provision another database automatically. An off-host transfer interruption preserves the encrypted partial file and cannot trigger another backup. Read-only quota requests alone have bounded transport retries; integrity/authorization failures and database mutations are never retried automatically. The 72-hour limit is checked at activation, alongside integrity and independent recovery, rather than during ordinary development.

Recovery and activation share an exclusive operation lock; capture also holds a database advisory lock through isolated restoration. Only the diagnostic `error_log` may append while preserving its original prefix. All durable chat/security/session files remain covered by the strict capture monitor. The enabled private test-account flag can coexist with approved real SMS delivery and logout reset; verify effective/fresh configuration agreement instead of requiring the obsolete all-test-features-OFF state. No backup operation changes provider settings or sends an SMS.

Shared recovery qualification passed on 2026-11-13 against production baseline `9082e64`, with 23 migrations, 13 triggers, matching schema/definers/rows, and independent encrypted off-host verification of all protected files and 82 persistent files. Source activation remains pending. Reuse this evidence within policy instead of repeating capture/restore. See [deployment lessons](DEPLOYMENT-LESSONS-LEARNED.md).

Owner workflow decision: 2026-11-06. This replaces the old fresh-install/staging sequence. Production is already installed: do not reinstall, recreate users/databases, regenerate APP_KEY, replace directories, or replay Stage 1/2/Phase 8.

1. Develop on the existing feature branch at `https://novinpaypos.test` using Herd and the isolated test runner. Preserve unrelated work and private configuration. Present UI/features locally for owner approval.
2. Keep commits fast: owner local acceptance and only risk-justified syntax/diff/focused tests. No automatic heavy suite, browser/E2E/integration/database run, build or backup on commit. Push once to run the existing comprehensive CI workflow (SQLite, MySQL 8, MariaDB 10.6.28, frontend); do not duplicate that matrix locally. Prefer local MariaDB/PHP parity without resetting existing data or adding redundant migrations. Keep PR #13 Draft/unmerged without explicit approval.
3. Obtain explicit owner authorization for production deployment of the exact approved revision. Local approval or CI alone is not deployment permission. Never deploy unfinished changes to replace staging acceptance.
4. Reconcile actual production HEAD/files/previous receipts. Use the existing cPanel HTTPS/file workflow and reviewed release tooling; do not blindly rerun an interrupted operation. No Docker, SSH, global hosting changes or generic fresh-install commands.
5. Only when activation is imminent, verify affected-file backup and rollback. Owner policy is a72-hour backup interval and72-hour maximum age (`scripts/release/backup-policy.php`). Reuse an eligible verified backup; age alone is insufficient. If data/schema is affected, require compatible isolated restoration, independent off-host verification and data-preservation evidence; retain keys/receipts and earlier generations. Refresh only for expiry at actual release or a concrete integrity/data/compatibility change, after code/CI preparation. Never provision another restore DB solely because a window expired during development; never disable established production backup protection. Verify current quota/runtime/filesystem requirements proportionately.
6. Review each migration separately: preservation, trigger/definer/role compatibility, concurrent requests, recovery and rollback. No production migration by implication. Code rollback must not restore or overwrite newer business data.
7. Deploy only the approved manifest in place at `/home/novinpay/novinpay`. Preserve `.env`, APP_KEY, DB records/schema, uploads/sessions, dependencies outside the release scope, existing `.htaccess`, private maintenance/audit state and `/home/novinpay/public_html -> /home/novinpay/novinpay/public`. CLI: `/opt/cpanel/ea-php84/root/usr/bin/php`; verify effective web runtime separately when relevant.
8. Use the reviewed traffic fence/readiness procedure when needed. Fail closed for real security/integrity failures. Transient external verification uncertainty is PENDING, not PASS or automatic downtime for a healthy site. Never suppress a failed check.
9. Verify public HTTPS/assets, required authorization/critical routes, database identity when relevant and maintenance OFF. Owner performs authenticated production acceptance without synthetic financial/customer mutations. Record deployed revision, results and remaining limitations. Keep rollback ready; fix remaining issues locally then repeat the approved cycle.

No routine hosted staging, staging Basic login, staging acceptance gate or new test subdomain is required. Retained staging snapshots/helpers are historical evidence, not deployment instructions. Retirement must never expose old debugging endpoints or private data.

SMS/notifications, Zibal live payments and Postex billing/shipment operations remain disabled unless separately authorized. Never copy ZizoLearn secrets/data or modify its website.

References: [AGENTS](../AGENTS.md), [handoff](AI-PROJECT-HANDOFF.md), [lessons](DEPLOYMENT-LESSONS-LEARNED.md), [MariaDB compatibility](MARIADB-COMPATIBILITY.md), [maintenance recovery](admin-maintenance.md), and the exact separately approved release/rollback manifest. Prepared scripts are not proof of execution.

## Current schema inventory and post-cleanup tools

Use the shared `recover.php` / `activate-source.*` bundles, not removed standalone
installers or historical PR13 activation commands. Package `schema-contract.php`
and `trigger-contracts.json` alongside `lib.php`; validate the entire bundle checksum
list before execution. The JSON file is the reviewed trigger contract, including
introducing migration, body hash and definer. Migration identity is the sorted name
set from the exact baseline/candidate `database/migrations`, never a historical count.
`backup.php` verifies the live baseline names and exact reviewed trigger contracts;
additive candidate migrations must be reviewed separately. A healthy HTTP response
or an old restore receipt is not a fresh SQL inventory. Use the authorized migrator
observer for trigger metadata; never expand runtime privileges.

The older `pipeline.php` is not the supported production entry point. Standalone
`login-submit-*` / `login-recovery-*` checksum lists left on the host reference removed
helpers and must not be replayed. Current shared-tool syntax/checksum validation does
not authorize an actual deployment, restore, migration, or cron installation.

## Scheduler readiness (not installed automatically)

The observed production cache driver is `database`; Laravel's database cache store
implements cross-process locks and the baseline includes `cache` / `cache_locks`.
No live lock acquisition or current CLI execution was performed through cPanel API.
The configured web handler is ea-php84; verify the explicit CLI version at activation.
After separate owner authorization, use this cPanel cron once per minute:

```cron
* * * * * cd /home/novinpay/novinpay && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

`operations:status` is read-only and reports heartbeat age, stale/review counts and
cache-lock capability, without customer identifiers or network calls. Missing/stale
heartbeat is not scheduler success. The heartbeat should update within three minutes.
Payment and notification schedules retain overlap locks, but default to disabled via
`OPERATIONS_SCHEDULED_PAYMENTS=false` and `OPERATIONS_SCHEDULED_NOTIFICATIONS=false`.
Enabling cron does not authorize provider processing; payment live switches and
notification configuration remain separate gates. Never enable them by implication.

Do not schedule `recover.php` as a recurring backup job: it is a unique, guarded
capture/isolated-restore operation and may encounter an occupied destination. Reuse
verified encrypted recovery within the approved 72-hour policy. A separately approved
recurring encrypted capture/transfer task is still pending; do not silently introduce
a restore database, recurring restore, new daemon, or another backup during development.


## Reviewed additive application release

The existing `activate-source.*` entry point also accepts the separately reviewed
`novinpay-application-release-v1` manifest. The original source-only format still
rejects route/config/schema changes. Application mode requires exact candidate blob
hashes for ALL changed config/routes/migrations, new migration names only, and the
baseline-plus-additions name inventory. Composer/Node dependency changes, historical
migration edits, deletions/renames/type changes and changed trigger contracts remain
unsupported. A current baseline cached-config hash is mandatory.

The same operation flock and quota/recovery gates precede a unique non-replayable
journal. During conventional Laravel maintenance, stage and hash only reviewed
migration files from Git, run only those through the existing migrator account in
process memory, verify the candidate migration set/trigger contracts, then fast-forward
application code. The additive nullable payment-attempt metadata is compatible with
baseline code; Laravel request admission is closed and live payment/cron workers stay
disabled. This is not a claim of global process draining or chat-writer fencing; the
independent chat does not write the altered payment-attempt table.

Rebuild config/routes/views; compare protected configuration semantically against the
checksum-pinned original (only the reviewed new local defaults may differ). Recheck
IPPanel/test-reset preservation and provider-OFF gates; invalidate changed application,
route and config opcodes through the authenticated existing web probe. Publish checked
Vite assets and reopen only after readiness. Reusing an existing CSS file is allowed
only when byte-identical to the checked package.

Any migration-started/partial-DDL failure keeps maintenance and all journals; NEVER
replay, run down(), restore an older database, or blindly restore old application
files. Forward recovery is required after migration uncertainty/new customer writes.
Before DDL, cancellation still requires verified baseline compatibility. A successful
local/CI contract test does not establish hosted activation: current live SQL inventory,
applicable encrypted recovery/rollback, exact artifact pins and explicit production
operation approval remain release gates. No production activation was performed here.
