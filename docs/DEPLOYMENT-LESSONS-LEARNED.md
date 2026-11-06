# Deployment lessons learned

> Workflow superseded by owner decision (2026-11-06): local Herd development/owner approval, then explicitly approved controlled cPanel production release and owner production acceptance. Hosted staging is no longer a routine requirement. This document's staging commands and credentials/access instructions are historical evidence; do not execute them or recreate staging. Preserve recovery/incident evidence. Use [current deployment workflow](DEPLOYMENT.md).

Review this document before preparing a package. Add each confirmed failure and a regression test when resolving it. Historical PASS receipts are not proof that a later attempt ran.

## Isolated staging contract

- App: `/home/novinpay/novinpay-staging-workflow`; document root ends in `/public`; HTTPS staging.novinpaypos.com. Production and ZizoLearn are separate and must not be modified.
- Database: `novinpay_stg38`. Runtime `novinpay_s38run` keeps CRUD only; authorized migrator `novinpay_s38mig` performs isolated DDL and observes trigger metadata. No extra runtime privileges or role escalation.
- Baseline: 20 migrations / 11 triggers. Reviewed repair/chat update: 22 / 13. Historical rows, administrator, environment, APP_KEY and sessions must survive.
- `bootstrap-secrets.json` contains database/migrator/admin bootstrap values, NOT HTTP Basic credentials. Original Basic credentials are retained in the private local DPAPI recovery workflow. Active `.htpasswd` is one-way verification data, not a recoverable plaintext source.
- `.staging-http-basic.json` is the dedicated private client source (username/password), outside webroot, regular account-owned file mode 0600. Validate its schema and password against the pinned active password file before HTTPS. Never print credentials or hashes. Keep Basic protection and TLS; do not reset passwords to resolve a source-selection bug.
- Kit directory was intentionally provisioned 0711 so the web server can traverse to its password file. Do not change that to 0700 without reviewing Basic-auth consequences. Secret files remain 0600; no group/world writes.

## Confirmed failures

| Error / phase | Verified cause and correction | Prevention / limitations |
| --- | --- | --- |
| v2 `existing_triggers`, identity | CRUD runtime saw zero trigger metadata rows; migrator saw all 11. Use an identity-checked migrator observer in read-only transactions; preserve runtime grants. | `scripts/staging/trigger-inventory.php`; `StagingDeploymentContractsTest`. Check names/table/event/timing/exact action-body hashes/definers, not only counts. |
| v3 `web_probe_http_401`, web prerequisites | Updater read nonexistent Basic fields from the DB/admin bootstrap file. Original provisioning intentionally omitted them. Exact original curl closure with that four-field shape reproduced 401 without a valid Authorization header. | Separate source in `scripts/staging/http-basic.php`; reject missing/invalid client credentials before any network/DDL. Synthetic loopback wire test confirmed 200 with both protections, 404 without probe token, 401 without Basic. Active password corruption was an initial possibility, not established evidence. |
| diagnostic `staging_bootstrap_identity`, private Basic check | Diagnostic repeated the same incorrect file-schema assumption and stopped before password verification or HTTPS. | Contract test rejects bootstrap data as HTTP auth. No more credential resets or repeated diagnostic commands. Protected DPAPI operation was policy-blocked; owner supplied the existing password through private cPanel editor. Actual password verification remains a host-side gate. |
| v4 `dump_trigger_inventory`, private recovery snapshot | Real complete MariaDB dump contains all 11 triggers, with unquoted names and executable version-comment wrappers. Verifier demanded a backtick after TRIGGER. All 11 actual dump definitions, tables/events/timing/body hashes/definers matched the baseline on read-only inspection. | `StagingDumpTriggerInventory` and its test support quoted/unquoted names, dump wrapper newline and CRLF, while rejecting missing/extra/duplicate/truncated/altered triggers. Never merely remove the check or accept a matching string inside table data. |

## V5 replay stop: verified unexpected progress

`previous_attempt_preserve` is caused specifically by `repair-chat-started-v5.json`, not the known v4 dump. Read-only API inspection found the marker recording source `a5f7636` and a new recovery directory at **2026-11-04 12:46:38 Asia/Tehran**. That directory contains a mode-0600 encrypted snapshot; both candidate migrations and the chat bridge match the candidate. No final PASS receipt exists; maintenance remains active. This proves an earlier v5 invocation crossed the installation boundary. It does NOT prove migration completion or successful activation.

The preserve guard is correct. Never delete/rename the marker, allowlist it as an old pre-install failure, or replay the fresh installer. Confirm all installed file hashes and database migration/trigger inventories through a read-only reconciliation before selecting a narrowly scoped continuation. Actual database counts remain pending; file presence is not schema evidence. Preserve both recovery directories and the fence. `scripts/staging/attempt-markers.php` and `StagingAttemptPreservationTest` ensure a known failed snapshot never permits replay past a later started/PASS marker and report the exact blocking filename. The uploaded private reconciliation helper performs no deployment, database writes, Laravel boot or maintenance toggles. A supported owner Terminal read-only check is currently required because the available API has no command execution channel.

## Recovery state and sequence

1. Inspect exact source/archive/tool hashes, account, isolated DB, permissions, fresh authenticated cPanel quota plus physical reserve, and all pending journals. No Docker or SSH. Local UAPI is not a reliable quota source here.
2. Validate dedicated Basic credentials, baseline source/migrations and migrator-visible trigger contracts. Verify PHP web prerequisites while Basic remains enabled.
3. Fence staging and verify HTTPS 503. Snapshot recovery data using the migrator and consistent `mariadb-dump`; verify completion and exact trigger contracts, then encrypt app/config/uploads/sessions and validate decryption. Never overwrite older recovery directories or remove plaintext failure evidence blindly.
4. Publish a durable started marker before source installation/DDL. Run only approved isolated migrations; confirm final 22/13 contracts and unchanged historical rows. Restore runtime account; validate owner, private chat initialization, caches and app-scoped OPcache.
5. Admit traffic only after readiness. Verify HTTPS routes, Basic protection, anonymous admin/operator denial, assets and probe cleanup. Hosted operator/customer/browser acceptance is distinct from local tests.
6. Any STOP preserves evidence. A recovery directory without a started marker is still an interrupted snapshot, not permission for a blind retry. Reconcile exact artifact hashes and the existing maintenance fence; use a uniquely named corrected attempt. Never remove an unexplained fence or restore live data automatically.

V4 itself stopped before source installation or DDL; a subsequent v5 invocation progressed further as recorded above. The v4 recovery directory retains a mode-0600 SQL dump, not a completed encrypted backup; staging maintenance is active. Preserve that dump and fence while preparing the controlled continuation. Command execution is not available through the configured file/quota APIs; a reviewed owner Terminal action remains necessary unless an authenticated supported Terminal session is actually available.

## Required pre-offer regression review

Test the producer AND consumer contracts: original bootstrap shape, separate HTTP auth, runtime vs migrator visibility, actual dump formatting, complete inventory, archive/dump footer checks, migration ordering, fresh-process configuration, row preservation, application-scoped opcode handling, HTTP statuses and cleanup. Use synthetic fixtures without customer data. Review all remaining checks rather than patching one substring and rerunning. Do not claim hosted success from source existence or local tests. Retain backups, rollback tools and audit evidence until acceptance.

## Interrupted v5: finalization-only recovery

Owner-run read-only reconciliation confirmed all 200 candidate files, exactly 22 migrations/13 candidate trigger contracts, four new tables, private chat and route cache, with providers disabled. The started marker and encrypted snapshot remain; no PASS exists and maintenance is active. Do not run either installer or migrator again. `scripts/staging/finalize-v1.php` binds source/manifest/marker/recovery identity, authenticates snapshot decryption in memory, verifies runtime/owner/routes/storage/chat, regenerates reproducible route/view caches, scopes OPcache invalidation, then admits traffic and checks HTTPS/Basic isolation before publishing a separate finalization receipt. The original started marker is retained. Re-execution verifies the same installed state and existing receipt identity; it never reinstalls or restores data. A genuine post-admission failure re-establishes maintenance; no PASS is emitted. Hosted authenticated repair/operator usability remains owner acceptance, not something proved by an anonymous redirect. `StagingFinalizerSafetyTest` guards migration/install/init exclusion and readiness/HTTPS-before-PASS ordering. Actual finalization has not yet been executed.

## Raw support configuration in widget (security incident)

The Laravel controller selected an endpoint in `$file`, then required native bootstrap/configuration code in the same method scope. That code reused `$file` for private installation.env; subsequent dispatch required the configuration file and emitted raw text. Correct PHP execution and HTTP 200 did not imply correct content. Isolate all native includes in a method scope, and reject raw configuration assignment output as defense in depth. `SupportChatTest::test_widget_renders_interface_without_private_configuration_output` requires actual widget markup, absence of private keys/paths and denial of direct config/data routes. Staging controller patched through fingerprint-guarded API write with private old-file backup; web OPcache/HTTPS verification remains pending. Never report 100% parity from an import inventory: a full source comparison also identified PWA/OpenSSL paths absent from the import manifest (already present in the destination) and a reduced team-management UI (see CHAT-PARITY-REVIEW.md).

## Chat verifier `details_withheld`: diagnostic defect; underlying HTTP check pending

The v1 verifier constructs controlled failure labels from request paths. Labels for `/support-chat/...` contain hyphens, but its catch whitelist allowed only letters/digits/underscores and replaced the actual label with `details_withheld`. V1 persisted no private failure receipt. This explains lost diagnostic detail, not the underlying HTTP/OPcache/authentication outcome. Read-only API confirms the patched controller hash still matches; no application log was available through the existing API. Do not claim hosted disclosure closure or infer a particular failing route from this opaque output.

`verify-chat-security-v2.php` retains every security assertion and reports only its own controlled check label, phase, last route/status/cURL code, completed checks, exception class and code location. Arbitrary exception text, bodies, credentials and configuration values are never emitted. A unique account-private 0600 failure receipt is flushed for subsequent autonomous API inspection. Its endpoint-path label regression is covered by `StagingSecurityVerifierDiagnosticsTest`. The deployed private helper is byte-pinned independently; repository formatting may differ without behavior changes. Owner Terminal execution remains necessary because no command-execution API/authenticated Terminal browser is available; the previously blocked credential operation must not be repeated via a different tool. No authenticated customer/operator or full-parity acceptance claim until the real helper result.

SMS status clarification (owner-reported): two delayed test messages arrived; API/test-mode integration works. Production timely delivery still requires provider/site authorization. Keep SMS sending and notifications disabled; do not rewrite the integration or perform more live sends for this chat incident.

## Private storage returns 403: valid denial, not a disclosure

Owner-run v2 verification reached the private storage URL after successfully checking widget HTML, public JSON API, assets and operator/admin denial. Its response body passed the disclosure scan but HTTP 403 failed an overly specific 404-only assertion. An independent anonymous check sees the staging Basic challenge on that path, homepage Basic 401, and generic safe /.env 403. Authenticated 403 response headers still require the new verifier result; do not claim they were collected by v2. The v3 verifier accepts 403/404 only for explicit private-file paths, retains exact public/API/operator status requirements, strengthens raw-key detection and records safe response header metadata. Never change web-server denial rules to force 404. StagingSecurityVerifierDiagnosticsTest guards the scoped status sets and continued disclosure/Basic/cleanup assertions. Hosted widget disclosure is resolved according to the owner-run v2 checks; final all-checks PASS and operator browser acceptance remain pending.


## Hosted disclosure verification and browser initialization (2026-11-04)

Owner-reported `CHAT_DISCLOSURE_HTTP_VERIFICATION_PASS` completed all 11 endpoints: no raw configuration in tested responses; private paths deny with 403/404, operator API with 401, staging Basic protection remains enforced. This closes disclosure for the tested endpoints, not customer application acceptance. Preserve the v3 regression tests.

A real authenticated staging browser reached the session phase, then aborted `get_session` after timeout. Laravel encrypts outgoing native chat cookies, but native modules read PHP `$_COOKIE` (the encrypted wire value) instead of Laravel's authenticated/decrypted cookie bag. A regression reproduces the second-request 401 with valid encrypted transport and proves tampered-cookie denial. The controller now installs the decrypted Request cookie bag only within native dispatch and restores globals in `finally`; cookie encryption/CSRF/ownership rules stay enabled. Existing synthetic tests had supplied plaintext PHP globals and missed the real middleware boundary. Browser confirmation after guarded staging delivery is required before closing initialization. Diagnostic references are browser-generated, not server-log correlation IDs.

Customer themes intentionally differ from upstream defaults: light regardless of OS, installation-scoped manual light/dark preference retained, no inheritance from site-wide `theme` or system mode. Critical loading/error bootstrap follows the same rule. `node --test tests/Frontend/support-theme.test.mjs` covers device-dark default, persistence, storage denial, legacy preference and unchanged operator system mode.


## Empty-store epoch reset and latency qualification (2026-11-04)

The exact regression is preserved in commit `8b0801e`: a second read-only draft poll before the first stored message returns 409 because a missing chat JSON file receives a new random epoch on every read. The subsequent fix publishes an empty schema-3 authority once under the existing exclusive chat lock during `ChatBridge::initialize`; an existing store is not rewritten. The unchanged regression now passes (11 assertions). This is a confirmed defect shared with the upstream missing-store path; it is not yet proof of the precise subtype of the owner's reported 409.

`ConversationSync` now immediately re-reads one fresh window only after explicit `cursor_reset_required`; the old window is invalidated, while outbox entries and ACK identities stay untouched. A repeated reset still throws. Other conflicts, including `message_integrity` and `idempotency_conflict`, are never retried or suppressed. Focused Node tests cover bounded reset recovery, real conflict propagation and conversation-switch races. No polling interval, retry protection, session check or durable ACK rule was relaxed. Six consecutive unique sends plus lost-ACK retries preserve exactly six ordered messages in isolated integration tests.

Owner reported initial loading failure reference `mw21zx46` followed by recovery without refresh; reliability is still unqualified. References are browser-local diagnostics, not known server correlation IDs. Automatic recovery is not acceptance. Agent Chrome Basic authentication remains unavailable; owner will perform hosted two-way acceptance.

No honest hosted before/after send-latency comparison is available yet. Original `engine.js` and `network.js` matched authoritative upstream `7d6f6ddcfbde4fd3fb1857f62504d230e4c43bb5` before this patch. Do not remove the identity preflight or increase polling on an assumption. New passive evidence records at most 80 request durations, operation categories, HTTP/error codes and the `Server-Timing: chat;dur=...` controller duration; existing ACK elapsed samples remain available. No message text, recipient, conversation/session identifiers, URLs, cookies, SQL or credentials are recorded. Controller duration excludes Laravel bootstrap, wire time and client queueing; never call it total server processing time. This instrumentation is not a claimed speed optimization.

For owner-run staging acceptance, reload the page, observe initial load and send a few distinct synthetic messages; test reply, retry/reconnect and persistence separately. In the owning page's browser console, this read-only expression returns only the bounded diagnostics (do not export a raw HAR containing cookies):

```javascript
const chat = [...document.querySelectorAll('iframe')].find(f => f.src.includes('/support-chat/support-widget.php'))?.contentWindow;
JSON.stringify({ delivery: chat?.SupportDeliveryMetrics, initialization: chat?.SupportInitDiagnostics });
```

Compare identity/send/sync HTTP durations, controller durations, ACK elapsed samples and initialization stages. Record actual samples before diagnosing network, PHP/controller, identity round trips or rendering as the bottleneck. Operator metrics are available as `window.SupportDeliveryMetrics` in the operator document. Hosted two-way success, handset-like browser behavior and measured speed improvements remain pending evidence.

Staging delivery of the synchronization patch: four source files from `030d48edb2424d963e42948642726ee64a6aec18` installed by guarded cPanel API writes after comparing existing bytes against `689bfdb`. Private code backups and `chat-sync-20261104-v1-receipt.json` are retained in the staging kit. New files were read back and hashed; no installer, initialization command, migrations, data reset or production operation occurred. This is file-level deployment evidence, not authenticated web/OPcache, initialization or delivery acceptance. Owner-run metrics/acceptance is the next required evidence.


## Fresh-send critical path: one redundant round trip removed

Before this optimization, original upstream and imported `ChatEngine::drain` always awaited a separate `get_csrf`/identity request before a first send. The send POST independently validates native/Laravel session, CSRF, ownership, current staff/customer privileges and idempotency before committing. Focused execution now verifies **two serial requests before ACK previously, one for a fresh same-tab submission now**, for both customer and operator roles. This is a measured request-count reduction, not a hosted millisecond benchmark or proof of why the two hosting environments differ. It saves the separate identity round trip on this path; no artificial latency was injected to fabricate a speed result.

Only keys produced by `enqueue` in the current engine instance are eligible, with zero prior attempts, matching role, scope and epoch. The marker is memory-only, never trusted from persisted outbox storage. Restored drafts, old epochs, retries and uncertain/lost responses retain explicit identity refresh and receipt reconciliation. Success/failure/cancel/delete/freeze removes the marker. The server POST checks are unchanged. Added direct POST tests prove a logged-out customer and revoked operator cannot send using old chat credentials even without a preceding identity GET. Existing CSRF/ownership/replay tests remain in force.

`node --test tests/Frontend/support-send.test.mjs` covers fresh/restored request counts for both roles, old-epoch hold and lost-ACK recovery with exactly one send and the original draft preserved. `support-sync.test.mjs` and `support-theme.test.mjs` remain required focused checks. Controller/request/ACK timings are passive evidence, not a new network poll. Hosted millisecond before/after, initialization reliability and real two-way acceptance remain pending; do not label them PASS.

Staging file delivery of the fresh-send optimization completed: `bec24334c458cf10678af82f7671889e7dfd6708`, engine SHA-256 `83459a2b0c0fb35d85f9bc23468d61fe0b7db7cd828ffb75fd808a854940b677`. Old engine backed up; private receipt `chat-send-20261104-v1-receipt.json` uploaded/read back. This reports file delivery only; effective browser/OPcache, actual hosted milliseconds, startup reliability and two-way delivery remain unverified. No credentials, Basic protection, providers, database or production settings changed. Owner explicitly asked not to block code-level progress on manual metrics and will perform browser acceptance; no additional HAR/credential request is necessary.


## Unchanged operator authority rewrites (2026-11-05)

Local profiling found `ChatBridge::session('operator')` calls `syncOperators`, which opens `support_ops_store(..., true)`. The original store atomically rewrote/fsynced the private operator authority even when the encoded state was identical. The regression first confirmed unchanged bytes but a changed file timestamp; after correction, the same operation leaves the timestamp unchanged. **Measured local redundant authority replacements: one before, zero after per unchanged sync.** No hosted millisecond reduction is claimed. This is a code-level disk-I/O cost, not proof of the owner's overall network bottleneck.

`support/operators.php` now compares the fully validated/cleaned encoded state with the bytes read under the existing exclusive lock. Only identical bytes avoid replacement; private file mode 0600 is still enforced. Changed state keeps the original durable atomic replacement. The tests prove session-version changes still persist, expired audit/subscription metadata is still written out, corrupt authorities still fail closed, and message/ownership/replay controls still pass. No caching of privileges or longer revocation window was introduced. Original source is preserved except this necessary hosting optimization; no upstream website was modified.

Validation: scoped chat suite 19 tests / 194 assertions passed before adding the extra cleanup regression; that additional regression passed 1 test / 4 assertions. Frontend send/sync/theme suite 11 passed. PHP syntax passed. A Pint invocation in the same shell after the isolated test wrapper hit the previously observed local `LARAVEL_START`/configuration bootstrap failure; use a fresh process and report its actual outcome. Do not count the failed invocation as a pass.


## 2026-11-05 delivery interruption and new dependency-audit blocker

The operator no-op optimization is committed/pushed at `9a70b57175ba96a01d31ed366c4829b51e5b175a`. Its staging transfer failed with an HTTPS client send error. Read-only reconciliation, not a blind mutation retry, found the private backup `chat-authority-20261105-v1-operators.before` present and no completion receipt. Deployed `resources/support-chat/support/operators.php` still matches the previous source (SHA-256 `b75003008d5b76b79115c136ba8b0b7b62d4db541caec9454e9652f14ce8e8b7`); the new optimization is **NOT installed**. Earlier epoch/cursor/fresh-send patches remain the latest file-level delivery evidence. Preserve the backup and reconcile before any later write. Browser access was retried once through the supported browser workflow; it still reports `ERR_INVALID_AUTH_CREDENTIALS`. No password/auth configuration was changed.

CI run `42048033743`, SQLite job `127316255330`, stopped at **Audit locked PHP dependencies** before Pint/tests. Local `composer audit --locked --format=json` independently exited 1 with six advisories across `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing`, all currently v8.1.8. These were published November 4, after the previous green run. Do not suppress audit, add ignores or claim the last green CI remains current. No exploitability of this application or live production was established; production has not been inspected.

Primary references: [Symfony routing CVE-2026-118516](https://symfony.com/cve-2026-118516), [HTTP Foundation generated-URL advisory](https://github.com/symfony/symfony/security/advisories/GHSA-5mx8-mwrp-5693). Six reported IDs: `PKSA-24ph-xkrh-y79j`, `PKSA-pr6q-2w5s-kzzf`, `PKSA-nxjs-s6hn-vb6m`, `PKSA-b2gg-vk1g-6d5q`, `PKSA-3gxv-7xhq-mgmz`, `PKSA-y8c5-1b7f-r1gj`.

A read-only solver check found the minimum patch set, with no added/removed packages and no PHP-major change:

```powershell
composer update symfony/http-foundation symfony/http-kernel symfony/routing --with-dependencies --minimal-changes --dry-run --no-scripts --no-plugins --no-install
```

Observed proposal: exactly these three packages v8.1.8 -> v8.1.11, solver reports no advisories for the proposed set. **Dry-run only**: composer.json, composer.lock and vendor were not changed. Applying dependencies is outside the chat-I/O optimization and needs applicable authorization under AGENTS.md. Then retain all mandatory CI/regression gates, including routing/auth/maintenance/ACK checks; published routing guidance requires rebuilding affected compiled routes during an authorized release. Do not clear hosting caches or replace vendor as part of this diagnosis. No production operations occurred.

The fresh-process Pint check passed; the earlier same-shell Pint bootstrap failure is a local harness issue, not the CI cause. The additional metadata-cleanup regression passed separately; do not repeat the entire local test matrix solely to diagnose an audit failure.


## Approved minimal Symfony security update (2026-11-05)

Owner explicitly authorized exactly `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing` to v8.1.11. The targeted Composer update used explicit versions, `--with-dependencies --minimal-changes --no-scripts --no-plugins`. Structured comparison against the previous lock proves exactly three package entries changed, no added/removed packages, no unrelated/dev-package or root metadata changes. `composer.json` and the private local `.env` stayed unchanged. Composer synchronized an already-drifted local `symfony/mime` installation from 8.1.7 to the **already locked** 8.1.10; this was not a lockfile upgrade. Staging already reported mime 8.1.10.

Both locked and installed `composer audit --format=json` now return empty advisory/abandoned/filter lists: all six previously reported IDs are absent, with no ignores added. PHP 8.4.27 platform requirements passed. Focused compatibility tests covering support chat, admin login/authorization, maintenance control/private preview and security headers: **79 tests / 901 assertions passed**. Frontend send/sync/theme: **11 passed**. Full Pint and diff checks passed. Required exact-head CI is a separate release gate.

Read-only cPanel evidence confirms staging currently has Laravel 13.35.0 and the three Symfony packages 8.1.8, with its package lock matching the prior lock's versions. The local patch does not prove the host is patched. Updating hosting vendor files requires a controlled staging-only dependency operation and rebuilding the compiled route cache; never copy a Windows dev vendor tree over staging, update production, run migrations or reset chat data. Preserve the interrupted operator-patch backup and reconcile existing source first.


## Symfony patch CI and staged execution package (2026-11-05)

Security commit `4df6f68edb66e20d3df40f34be61a513640ccffa` passed all four CI jobs in run `42049709704`: SQLite 1m15s, MySQL 8 4m37s, MariaDB 10.6.28 2m32s, Frontend 9s. Both Composer audits report zero advisories; exact three-entry lock comparison and PHP platform check passed. No production operation occurred.

After CI, read-only staging reconciliation verified the complete installed runtime package version/reference inventory against the pre-patch lock, old operators.php, the retained interrupted-transfer backup, Basic helper integrity and account quota reserve. A **private, staging-only** execution package is prepared under `/home/novinpay/novinpay-staging-38b9117-kit`, not executed. Files: `repair-chat-symfony-security-v2.php`, `repair-chat-symfony-security-v2.json`, `repair-chat-security-composer-v1.b64`, `repair-chat-security-composer-v1.lock`, `repair-chat-security-operators-v1.php`. All are 0600. The bundled Composer is the existing trusted local Composer 2.10.2 binary transported as base64, not a new server-wide installation. The helper validates both encoded and decoded hashes. Private manifest/auth material and binary transport stay out of Git.

Upload of the 4,852,372-byte base64 artifact timed out at the client after 45 seconds but left a complete-size private server file. Read-only reconciliation found it; **it was not retransmitted**. Fileman refuses content readback above 1 MiB. Its full SHA-256 is therefore **pending the mandatory on-host checksum**, not claimed verified. The four smaller files passed byte readback/hash checks. No application/vendor/fence operation ran, so staging still has the pre-patch Symfony packages and previous operators.php. This is a transport/tool limitation, not a new permission request or a reason to bypass checks.

Reviewed helper flow: exact account/path/file/lock/package/auth identity; preserve previous evidence; exclusive operation lock and unique started journal; private code-only vendor/lock/route-cache snapshot; staging-only 503 fence; Composer install from the new lock with no scripts/plugins/dev packages; exact version/reference inventory after install; zero-advisory audit; resume only the verified operators.php change; rebuild routes; fresh-process readiness with read-only DB transaction (22 migrations/protected owner/provider flags); token-protected temporary web version/OPcache check with app-scoped invalidation only; remove probe; preserve env/Basic configuration/maintenance state; admit traffic and check public/widget/operator-denial endpoints. No migrations, restore, credential changes or chat-data reset. A genuine failure preserves the fence and writes a private STOP; an authenticated network-only uncertainty after traffic admission rechecks local readiness and preservation and returns PENDING/75, never PASS. Existing started evidence prevents blind replay.

Local helper validation: main PHP, generated readiness child and generated protected probe all pass syntax; wrong-host invocation stops at identity before host/database/file mutation. This is not proof that hosted execution will pass. The normal cPanel API has no shell execution facility; one owner Terminal action is still required. Do not improvise a public command endpoint or extract credentials to bypass unavailable browser access.

Historical v2 command (STOP occurred; do not run again; use reconciled v3 below):

```sh
cd /home/novinpay/novinpay-staging-38b9117-kit && printf '%s  %s\n' \
  62dbb94263b5810ce64a9c2358abc7f60a011ebe96c443c38b1e19e98f9bde86 repair-chat-symfony-security-v2.php \
  9ac5ea63f05cdfeff4d441042d88ca5b17a3228e8b2f59ccc720280e4a31c0d8 repair-chat-security-composer-v1.b64 \
  b674d99593e038bf4b9a2bb518ba535ba784c179d44d2542b40e878fcab0af2e repair-chat-security-composer-v1.lock \
  9b35655127a62c01ead1bb549876bc947024cff7579da25f1bc0d35d147d3227 repair-chat-security-operators-v1.php \
  f30408ddcc3df1fcc108983aab6735c4c019e9898add9a8282c21519289f526d repair-chat-symfony-security-v2.json \
  | sha256sum -c - && /opt/cpanel/ea-php84/root/usr/bin/php ./repair-chat-symfony-security-v2.php f30408ddcc3df1fcc108983aab6735c4c019e9898add9a8282c21519289f526d
```

Expected successful result: `STAGING_SYMFONY_SECURITY_PATCH_PASS` with three patched versions, advisories 0, migrations 22, maintenanceOff true and probeRemoved true. It explicitly does not claim real browser two-way acceptance. Do not rerun if STOP/PENDING occurs; preserve the unique evidence directory, inspect phase/results and reconcile. No current proof of hosted security remediation or new latency improvement exists until the actual output and independent checks are collected.

Final execution helper is **v2**. Unexecuted v1 files remain private evidence and must not be run. Final review corrected a cleanup issue before any execution: PHP `exit` cannot be relied on to execute `finally`, so the exact created temporary probe is explicitly removed before both failure/PENDING exits. An isolated PHP fixture verified that only the generated probe path is removed and an unrelated file remains. V2 main/child/probe syntax passed, and v2 helper/manifest were uploaded mode 0600 with full readback hashes. Shared payloads were not retransmitted. Hosting execution and large-artifact checksum remain pending the single command above.


## Symfony security v2 `identity / manifest_pin` (2026-11-05)

**Verified cause:** the v2 helper still read `repair-chat-symfony-security-v1.json`, although the reviewed command pinned v2's hash. Readback verified helper SHA `62dbb94263b5810ce64a9c2358abc7f60a011ebe96c443c38b1e19e98f9bde86`, v1 manifest SHA `911a80c3d5de46e744a347b6dc021b4ffeef5297667c588aad13625f0bbf26fb`, and v2 SHA `f30408ddcc3df1fcc108983aab6735c4c019e9898add9a8282c21519289f526d`. This is a copied filename defect, not changed hosting identity or an invalid security guard. My previous wrong-host/syntax tests did not exercise the real manifest selection; that validation gap is now covered.

Read-only cPanel reconciliation found the old lock, composer.json, operators.php and preserved operator backup still matching the approved fingerprints, all three installed Symfony packages still v8.1.8, no Laravel maintenance marker, no active private maintenance state, no security operation lock/journal directory and no generated public probe. The code failed before autoloading Laravel, DB access, operation journaling, snapshots or a maintenance fence. Database rows were not independently scanned again; no production path was inspected. Preserve v1/v2 artifacts and all prior evidence.

**Correction:** tracked `scripts/staging/repair-chat-symfony-security-v3.php` derives the adjacent manifest path from its executing `__FILE__`, verifies the supplied SHA before parsing it, and uses `basename(__FILE__)` for helper artifact verification. No pin, owner/path, package inventory, backup, concurrency, authentication, fence or HTTP safeguard is removed. Apart from this selection fix and a no-execution include boundary for contract tests, v2 flow is retained and formatted for review. Private manifests stay outside Git.

**Prevention:** `StagingSecurityManifestTest` reproduces the old mismatch, exercises actual loader selection with retained versions, rejects altered bytes and malformed pins, and checks ordering before backup/fence/dependency operations. Related finalizer/attempt-preservation tests: **7 tests / 29 assertions passed**. Syntax and scoped Pint pass. Wrong-host execution still stops before mutation. A test invocation using a pipe-separated filter hit the Windows wrapper's shell parsing; explicit file arguments passed. Pint after the wrapper in the same process hit its known environment/bootstrap issue; a fresh-process Pint passed. No full matrix/build repeated for this release-tool-only fix.

**Execution:** only new v3 helper/manifest uploaded; shared payloads and all old artifacts preserved. Owner output establishes all five v2 transport SHA checks passed. V3 helper/manifest readback and 0600/UID 1321 are required before use; the command still independently verifies every payload. New manifest hash creates a distinct attempt journal; no previous attempt is deleted or treated as permission to replay. Normal cPanel Fileman has no command execution; one owner Terminal invocation is unavoidable. V3 has not been executed, so hosted patching remains pending. On STOP/PENDING preserve evidence, do not retry blindly.

Current v3 command:

```sh
cd /home/novinpay/novinpay-staging-38b9117-kit && printf '%s  %s\n' \
  78ee45faf3e6fced525687e476af5170c6d3aaad42294f9a86cae9f9547660cc repair-chat-symfony-security-v3.php \
  9ac5ea63f05cdfeff4d441042d88ca5b17a3228e8b2f59ccc720280e4a31c0d8 repair-chat-security-composer-v1.b64 \
  b674d99593e038bf4b9a2bb518ba535ba784c179d44d2542b40e878fcab0af2e repair-chat-security-composer-v1.lock \
  9b35655127a62c01ead1bb549876bc947024cff7579da25f1bc0d35d147d3227 repair-chat-security-operators-v1.php \
  e4434545aa9be18a71ff8458fd6b96f5b68f9bea9553c39c295b22a90c0f090b repair-chat-symfony-security-v3.json \
  | sha256sum -c - && /opt/cpanel/ea-php84/root/usr/bin/php ./repair-chat-symfony-security-v3.php e4434545aa9be18a71ff8458fd6b96f5b68f9bea9553c39c295b22a90c0f090b
```

CI on 6264b5f caught a remaining Pint formatting pass in the newly expanded helper. Five whitespace-only lines were fixed; a fresh full repository `pint --test` and the same 7 tests / 29 assertions passed. Before issuing the command, both unexecuted v3 draft artifacts are preserved privately as `.pre-pint.before`, then updated only against their exact old hashes and read back. Final command uses the new hashes. No v3 execution occurred.


## 2026-11-06 startup parity investigation (not accepted as resolved)

Read-only cPanel reconciliation found the v3 `STAGING_SYMFONY_SECURITY_PATCH_PASS` receipt: audit zero, migrations 22, maintenance OFF, probe removed, code backup present. All three installed packages are v8.1.11. Operator no-op optimization and engine/network hashes match local; customer/customer-v2 also match after CRLF normalization. Do not rerun the security installer. This receipt explicitly does not establish two-way browser acceptance.

GitHub main for ZizoLearn still resolves to `7d6f6ddcfbde4fd3fb1857f62504d230e4c43bb5`. The fetched customer.js blob `02193942795d486cc85335443c7423e69e8009ff` matches NovinPay; customer-v2 differs only in fallback brand. Therefore the observed startup failure is not established as a missed frontend port. Two startup error controllers exist (12-second module import watchdog and config/session failure handling); their causal involvement in the owner's delayed recovery remains unverified. Do not simply extend timeouts or suppress failure UI.

Confirmed architectural difference: NovinPay routes assets through Laravel with `no-store, private`; upstream files are standalone. A five-level static dependency discovery chain is customer-v2 -> customer -> engine -> network -> outbox. Exact-URL modulepreloads now expose those same five modules in the initial document; they do not add polling, new dependencies, credentials, cache lifetime or another module instance. This removes discovery dependencies for these modules, not proven network round trips or measured milliseconds. It is not a complete fix for initialization or delivery latency.

Regression `tests/Frontend/support-preload.test.mjs` verifies exact query-string identity against actual imports and one preload per module. Send/ACK/lost-ACK/sync/theme tests remain unchanged. Browser attempt through the supported DevTools workflow still returned ERR_INVALID_AUTH_CREDENTIALS. Do not disable Basic, extract credentials or request HAR/message contents. Cold/warm p50/p95, real two-way ACK/attachment/reconnect tests and hosted before/after performance remain unverified. Source-level/local success is not performance acceptance. Production/ZizoLearn/live provider settings and unrelated finance files remain untouched.


Preload delivery evidence: implementation `dbb370a4c669aa4f454a0fca05946470b3b99300`, all four CI jobs passed in run `42142010319`. Focused PHP chat 20/198 and assets 1/79 passed; frontend 12 passed; full Pint/build/diff checks passed. An initial assets invocation used the wrong Feature path and did not run; corrected Unit path passed. No assertions were weakened.

Fingerprint-guarded cPanel update preserved the old widget in `/home/novinpay/novinpay-staging-38b9117-kit/chat-preload-20261106-v1-widget.before` before replacement. Source readback new SHA `d6bf813036b59beec3e013ef68c1758536a1c0e615e18ff37c17f7e4cb6f19c3`; old SHA `c0d1e93614b28d68f2bdf4c592bac2f42a91031f83b756be0d399612ac857498`. Private receipt `chat-preload-20261106-v1-receipt.json` reports `STAGING_PRELOAD_SOURCE_READBACK_PASS_HOSTED_ACCEPTANCE_PENDING`. No database operation, migration, data reset, runtime configuration change or maintenance toggle. Effective web OPcache and authenticated browser behavior were not verified; do not call this a completed performance fix. Remaining work: reproduce the startup failure with sanitized event/resource timing; determine actual network/server/IDB delays; verify two-way ACK/retry/recovery/attachments and comparable cold/warm upstream samples. No before/after milliseconds are available.


## 2026-11-06 workflow replacement and staging retirement

Owner discontinued routine hosted staging. Current workflow: Herd local development/tests/owner approval -> explicitly approved controlled cPanel production release -> owner production acceptance. Do not recreate a test subdomain or require staging Basic login/acceptance. This does not resolve chat latency or authorize unfinished production releases/PR #13 merge. Keep local profiling/upstream comparison and security/recovery requirements.

Retirement: exact DomainInfo identity `staging.novinpaypos.com` -> `/home/novinpay/novinpay-staging-workflow/public` verified. Staging Basic directives were privately backed up, then replaced with explicit deny-all. Before subdomain removal, root/index/widget/one diagnostic/private path all returned403 without WWW-Authenticate and without scanned configuration/path disclosure. cPanel SubDomain::delsubdomain succeeded; subsequent DomainInfo/list_domains confirms staging absent and primary domain identity unchanged. Normal cPanel API2 cron inventory found no staging cron (UAPI Cron is unsupported here). No production files/config/data were changed.

Private receipt: `/home/novinpay/novinpay-staging-38b9117-kit/retirement-20261106-receipt.json` (`STAGING_RETIRED`); old access file retained alongside it. Data/app/private config/keys, novinpay_stg38 and its roles, two recovery directories, security snapshot and all deployment/incident evidence preserved. No DB restore/drop/grant/revoke or file purge. Storage reclaimed0. Local old instructions backed up outside Git before editing. Post-removal HTTPS check failed certificate validation (SEC_E_CERT_EXPIRED); no post-removal HTTP result is claimed and no TLS bypass/renewal was attempted. The old document root remains deny-all independently of cached domain routing.

No application behavior changes, build/test matrix or new release package was needed. Documentation validation is references/diff/scope; retirement validation is exact domain/readback/HTTP denial/retained resource metadata. Future deletion of inactive retained data requires reference/recovery classification first; do not confuse retirement with evidence destruction. Existing blocked credential operations remain blocked; Basic credential values were not read or transmitted.


## 2026-11-06 cleanup and local bootstrap optimization

Workflow documentation is now authoritative: local owner approval then explicitly approved production release, no hosted staging. Remaining active README/MariaDB/SMS/readiness staging requirements were removed; historical records preserved. Ten finance views remain unrelated and excluded.

Local inventory included hidden/ignored/tracked files and dependencies. Required vendor/node_modules/build/font assets, database/.env, active logs, caches and fixtures remain. 37 obsolete ignored `.playwright-mcp` captures/logs total187679 bytes were classified, but execution review rejected the local removal command (`blocked by policy`). No local file was deleted and no alternative tool was used to circumvent that decision. Do not claim reclaimed space.

Retired hosting public probes `np-chat-security-3bbe84078d1129cac93d7755.php`, `np-chat-security-f8a761068dcc0ad1e4cd9625.php`, `np-chat-web-d32b595fc9cc92517daeaee8.php`, `np-chat-web-f2c9f1b52d64b8a7111b76e1.php`, `np-chat-web-f30a8630ca8df62dbf69e760.php` were moved to private cPanel trash after retirement/deny-all, regular-file/UID checks, production entry/reference checks and prior no-staging-cron evidence. Source absence, destination sizes and hashes verified. Active directory bytes removed4581, physical bytes reclaimed0 (reversible quarantine). No production changes. Private kit/snapshots/rollback/keys/database and older release packages retained because recovery/incident references remain or full redundancy is unproven. No broad deletion or new cleanup framework.

Local source startup had serial get_csrf then get_session requests for the same customer context. New opt-in `get_session=1&bootstrap=1` returns the same ownership-filtered session plus its token/context in one request; regular get_session still omits token. Only initial customer startup requests it; operator identity path unchanged. Frontend strictly validates identity/scope and session structure, consumes the result once, clears it on any later identity refresh, and retains legacy fallback for other paths. No permission cache, CSRF/retry relaxation, timeout increase, message/data migration or live provider request.

Seven alternating samples on the isolated PHP test kernel: legacy two requests median15.746ms/p95(7-sample maximum)19.565ms; combined one request median8.448ms/p959.259ms. This is warm in-process PHP/local synthetic-store evidence, not browser/network/TLS, cold-load, hosted performance or full upstream parity. No latency thresholds are used as test assertions. To profile explicitly, set process-only NP_CHAT_PROFILE=1 with the combined-bootstrap test; it writes only timing numbers to a unique np-chat-profile-*.json under OS temp. An initial STDERR profiling output caused PHPUnit separate-process rejection; report now uses the bounded private synthetic timing file and test passes. No real .env or original SQLite changes.

Focused chat tests21/210, frontend16 passed; full Pint/build/diff passed. Frontend CI now runs these regression files rather than only building assets. Normal-flow startup failure/recovery and complete two-way browser parity remain open. Existing Herd local config defaults to original SQLite/database sessions; no browser submissions were made to that database. Keep using the isolated runner until a separate approved local browser-data target is established. No production deployment or PR merge is authorized by these results.
