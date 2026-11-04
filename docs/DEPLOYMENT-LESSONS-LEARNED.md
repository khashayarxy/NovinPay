# Deployment lessons learned

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
