# PR13 production release: migration-aware preparation

Owner authorized production deployment and conditional merge after hosted acceptance. Baseline independently read through cPanel: `1f39bb476fd31880600564e58599c4488622c4c0`; maintenance OFF, account free4728.74MiB at inspection. These observations are dated, not reusable quota/freshness proof. Preserve the ten uncommitted finance views; build only committed Git bytes.

## Release inputs

- Runtime delta: customer accounts/orders, simplified repairs, shared payment records, delivery, notifications and the full adapted support tree. The generated manifest contains exact changed/deleted paths and SHA256s, source tree/commit and eight pending migration names. Do not extract a source archive over production blindly.
- Composer: only http-foundation/http-kernel/routing change from8.1.8 to8.1.11; composer.json and package-lock unchanged. Use the reviewed lock, no update/resolution on production. Composer availability and PHP/extensions need actual host verification before choosing install versus a verified no-dev vendor artifact.
- Build: current Vite manifest/assets, plus directly served support resources. No local DB, private configuration, auth.json, QA output or dirty finance files belongs in the release.
- `/support` serves the authenticated operator document with an absolute `/support-chat/` base. Legacy document/admin URLs redirect there. The PWA manifest/worker use the new entry; the worker never caches private HTML/API/upload/POST responses, and filters push clients to the operator document only despite the overlapping URL prefix.

## Migration and recovery gates

The baseline repository has14 migrations; candidate22. Confirm the actual production migration names before any DDL. Inspect five current trigger bodies/definers using the retained migrator observer, never infer missing triggers from runtime metadata visibility. `pr13-preflight.php` is read-only inventory, **not activation or permission to proceed**. It checks exact HEAD/worktree, symlink/runtime/cached configuration, migrator role/DDL privileges and migration history; outputs trigger metadata/hashes without bodies, SQL, credentials or customer rows.

Eight extensions, in filename order: streamline repair intake/contact history; customer accounts/challenges/addresses/requests; payment records and nullable system ledger actors with retained financial invariants; delivery plans; customer order origins/checks; notification outbox; simple repair records/tracking/immutable revisions; chat ownership. No historical columns/history are dropped by up(). MariaDB DDL auto-commits: a failure requires reconciliation, not blind migrate/rollback retry. Upgraded populated-ledger preservation is covered by CustomerPaymentMigrationTest and required DB CI.

Before activation require a **fresh** encrypted database/config/uploads/source backup, meaningful isolated restore and independently decrypted off-host copy. October recovery generations remain protected; they do not cover newer transactions. Identify an authorized empty restore target without overwriting retained evidence. Do not implicitly provision a new database or change privileges.

The old presentation-only pipeline is incompatible: it explicitly assumes14 migrations/five triggers and no migrations. Its quota/backup/journal primitives may be reused, not its activation unchanged. A schema-aware activation is not ready until actual preflight evidence and recovery prerequisites are reconciled.

## Activation sequence and rollback boundaries

1. Pin exact candidate/archive/build/dependency manifests and green CI. Reconcile current production state; retain modified .htaccess, untracked diagnostic files, .env/APP_KEY, uploads/sessions and public_html link. Refresh authenticated API quota immediately before the fence, retaining2GiB reserve/physical headroom; never invoke broken local uapi.
2. Verify fresh recovery and private affected-file/vendor/config rollback archive off-host. Review compatibility overlays before claiming code rollback ready: baseline AdminAction lacks repair.contact_attempt/finance.evidence_viewed; old code must safely read new records. Retain patched Symfony8.1.11 during rollback rather than restoring vulnerable8.1.8. Current data-bearing down() methods deliberately refuse destructive reversal. Never restore the live DB automatically.
3. Fence public traffic and account for already-admitted requests before DDL/file changes. Update only manifest paths in place, install locked dependencies if needed, and run only eight reviewed pending migrations under the retained migrator account; runtime CRUD permissions remain unchanged. A partial DDL receipt stays stopped for reconciliation.
4. Enable only the new chat configuration after private initialization review; preserve all existing environment values and payment/SMS settings. Never copy local fixture settings. Live Zibal/SMS/Postex billing stay disabled. Reconcile scheduler requirements without overwriting existing cron jobs.
5. Generate relevant caches under verified configuration; use scoped PHP/OPcache checks. Admit traffic after local readiness and integrity pass. Transient HTTPS uncertainty is PENDING; genuine application/security failure remains fenced.
6. Verify production public/admin/support routes and actual authenticated two-way chat, persistence/reconnect/images/lifecycle. Do not create financial/customer transactions as probes. Only after hosted acceptance mark PR13 ready and merge normally under protection. Verify merged tree equals tested release before advancing production Git metadata; never deploy new untested content to chase main.

## Execution access

Authenticated cPanel file/quota API works. No authorized server-shell execution API is established. Computer Use was stopped by URL-verification policy; do not retry/bypass it or add a public execution backdoor. The next owner action is one integrity-verified private Terminal inventory command, supplied with the uploaded manifest. No backup, migration or activation is executed by that command. Reconcile its results before preparing the executable activation.
