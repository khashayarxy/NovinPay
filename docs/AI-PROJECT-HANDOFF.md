# NovinPayPos: AI project handoff

Snapshot: **2026-11-04, Asia/Tehran**, including the final preservation and synchronization follow-up. This is a continuation record for Codex, Claude Code and other agents, not deployment authority. Read [working preferences](AI-WORKING-PREFERENCES.md) and [AGENTS.md](../AGENTS.md) first. Recheck changing facts before acting. Do not restart the release or repeat completed diagnostics merely because a helper exists.

## 1. Immediate continuation point

The active feature is simplified repairs plus the complete ZizoLearn-derived support chat, on isolated staging. The owner can open the widget and send messages, but reports a synchronization warning and excessive message latency. **Chat acceptance is incomplete.** The owner subsequently authorized preserving remaining legitimate work and prioritized the synchronization fix. That fix and passive latency instrumentation were installed on staging; performance and hosted functional acceptance are still pending.

The original intentionally failing regression is preserved in commit `8b0801e`. A subsequent real implementation fix now passes it without changing its assertions:

- File: `tests/Feature/SupportChatTest.php`.
- Method: `test_empty_installation_keeps_sync_epoch_before_and_after_first_send`.
- Command: `./scripts/test.ps1 --filter=test_empty_installation_keeps_sync_epoch`.
- Before fix: **FAIL**, expected second empty poll 200, received **409**; 1 test, 4 assertions. After fix: **PASS**, 1 test, 11 assertions. Compare commit `8b0801e` with the subsequent fix; do not hide or skip the regression.
- `resources/support-chat/support/storage.php::support_store` creates a new random `store_epoch` on each read when `chat_conversations.json` does not yet exist. Empty polls do not persist it. `support_message_page` then correctly rejects the previous epoch as `cursor_reset_required`.
- This is a **confirmed code defect**, not yet a proven complete explanation of the owner's particular browser warning. The fix publishes the empty store once under the existing exclusive lock during installation initialization; existing messages are preserved. Cursor validation remains strict.
- The original regression commit intentionally fails. Current source contains its real fix and additional rapid-send/retry tests. No assertion or CI exclusion was changed to fake success. Exact-head CI for the new fixes must be checked separately.

The client now performs one bounded fresh-window resync for explicit cursor reset, preserving the outbox and surfacing repeated resets or genuine integrity conflicts. New passive diagnostics retain safe operation/status/duration metadata and controller Server-Timing; no hosted speed improvement is claimed. See the final section of `DEPLOYMENT-LESSONS-LEARNED.md` for the read-only owner metrics expression.

## 2. Repository and Git evidence

- Repository/remote: `https://github.com/khashayarxy/NovinPay.git`.
- Workspace: `C:\Users\Khashayar\Documents\NovinPay`.
- Branch: `codex/novinpay-customer-repair-workflow`.
- Original handoff implementation baseline: **`689bfdb266b067085ba800b2c9a263f55d3c3bd9`**. Latest pushed implementation: **`bec24334c458cf10678af82f7671889e7dfd6708`** (fresh-send optimization), following **`030d48edb2424d963e42948642726ee64a6aec18`** (epoch/resync fix); later documentation commits may follow.
- [PR #13](https://github.com/khashayarxy/NovinPay/pull/13): OPEN, **Draft**, targeting `main`, latest implementation head verified; recheck later documentation HEAD. **Do not merge without explicit approval.**
- Obtain the final Git/remote HEAD with `git log -1` and `git ls-remote`; implementation SHA and subsequent documentation HEAD can differ.
- [CI run 41819313200](https://github.com/khashayarxy/NovinPay/actions/runs/41819313200): all four jobs passed on `689bfdb`: Frontend 13s; SQLite 1m7s; MySQL 8 3m46s; MariaDB 10.6.28 2m23s. This predates the intentionally failing regression now committed; later exact-head CI must be reported separately.

Relevant history (inspect commits when needed, not the entire repository again):

| Commit | Result |
| --- | --- |
| `f481197` | Postex quote-only adapter; do not duplicate or undo |
| `38b9117` | Earlier customer/repair/SMS staging source |
| `a5f76363dd3ad6db056d1d539ab83b94fd796dff` | Repair/chat staging candidate, 200-file deployment baseline |
| `664f744` | MariaDB dump trigger parser and deployment lessons |
| `6a14ae3`, `952602d` | Preserve interrupted attempts; finalization without migration replay |
| `45b7fbee1496d47e5b6e4544be770340982fea71` | Private configuration disclosure correction |
| `37613a1`, `cccdde0f4fe5cde10cb4309388d5e1db2091e5eb` | Safe verifier diagnostics; secure 403/404 private denial |
| `d79804275d8418ad7e04b7049fc2b731580fbbf9` | Track original icon/OpenSSL support files |
| `689bfdb266b067085ba800b2c9a263f55d3c3bd9` | Laravel/native cookie continuity, customer light default, original team UI adaptation |
| `8b0801e` | Exact originally failing epoch regression, deliberately preserved |
| `f2f118c` | Historical release-tool source and tests, private fingerprint moved to manifest |
| `030d48edb2424d963e42948642726ee64a6aec18` | Real epoch initialization fix, bounded cursor resync, passive timing and rapid-send tests |
| `bec24334c458cf10678af82f7671889e7dfd6708` | Fresh-send critical path reduced from two serial requests to one; retry/ownership protections retained |

### Final preserved local differences

Ten pre-existing finance views show modified status, but `git diff --numstat` currently has no content changes (line-ending normalization). Preserve them: `resources/views/admin/finance/{customer,document,documents,filters,form,index,reports,source,summary,transactions}.blade.php`.

Release tooling was subsequently reviewed and preserved in Git as historical release-specific source (see `RELEASE-PIPELINE.md`), not qualified for current deployment:

- `docs/RELEASE-PIPELINE.md`.
- `scripts/release/`: `activation.php`, `backup-io.php`, `backup.php`, `bridge.ps1`, `health.php`, `lib.php`, `pipeline.php`, `production-environment.php`, `release.php`, `runtime.php`, `standalone.php`, `transfer.ps1`, `verify-offhost.ps1`.
- `tests/Unit/ReleasePipelineTest.php`.
- `SupportChatTest.php` now includes the committed failing regression described above; it is no longer an uncommitted exception.

Do not reset/clean, discard, blindly stage all files, or execute historical release tooling. The scripts and fixture tests are preserved for a fresh clone, but private manifests/approvals/credentials/server-only dependencies are deliberately excluded. Local release tests passed 42 tests / 101 assertions; PHP lint and PowerShell AST parsing passed. A private credential fingerprint was moved from source to a mandatory private-manifest pin, with fail-closed regression tests. This is not host-execution evidence.

## 3. Architecture and navigation

- Laravel 13; locked dependencies require PHP **>=8.4.1**, regardless of the broader root Composer range. Local/host CLI observed 8.4.27. Blade, Tailwind, Vite; CI Node 24. Herd serves local HTTPS at `https://novinpaypos.test`.
- MariaDB **10.6.28** on cPanel; SQLite isolated tests and MySQL 8 CI are supported. Preserve enforced FK/CHECK/unique constraints, financial/history triggers and trigger definers. No global SQL changes.
- `routes/web.php`, `routes/console.php`: HTTP and scheduled/CLI entry points. `app/Http`, `app/Policies`, `app/Support/AdminAccess.php`: boundaries and default-deny authorization.
- `app/Services`: transactional business services, including `SimpleRepairRecords`, `RepairOperations`, `StoreCheckout`, `DeliveryManagement`, `FinancialOperations`, `PaymentSettlement`, `ZibalPayments`, `SmsIr`, `CustomerLogin`, `CustomerNotifications`, `SupportTeamAdministration`.
- `app/Models`, `database/migrations`, matching `tests/Feature` and concurrency harnesses: state and invariants. Never test against the original SQLite or a live database.
- Chat: `resources/support-chat/` contains the original PHP/JS/CSS engine; public requests are allowlisted through `SupportChatController`. `app/Support/Chat/ChatBridge.php` binds native chat ownership/permissions to Laravel. Chat is **file-backed**, not rewritten as SQL messaging; SQL holds customer ownership bindings.
- `resources/payments/`: seven reference payment pages; preserve original design and interactions. `config/business.php` and existing brand assets are authoritative for identity/contact values.
- Maintenance: early `public/index.php` guard, `bootstrap/app.php`, `SiteMaintenanceState`, standalone fallback and admin control. Public maintenance and `artisan down` are separate mechanisms; preserve fail-closed state/audit handling.

## 4. What is implemented, versus what is accepted

Production historically includes public products/repair/tracking/contact/articles, administrative modules, Phase 8 navigation and the approved standalone/admin-controlled maintenance feature plus final redesign. The later customer/order/payment/repair simplification/chat branch is **not merged into production**.

Feature branch implementations include customer OTP/accounts/addresses, ownership-reviewed CRM linking, customer orders with stock/financial guards, SQL payment attempts/settlement/idempotency/reconciliation, private receipt review, delivery plans, notifications and Postex quotations. Read [customer/payment development](CUSTOMER-PAYMENTS-DEVELOPMENT.md), [Postex](POSTEX-QUOTATIONS.md) and [SMS readiness](SMSIR-CPANEL-READINESS.md). Older repair descriptions in the customer/payment document predate the simplified workflow; use [repair/support staging](REPAIR-SUPPORT-STAGING.md) and actual source for current behavior.

Current repair flow: compact six-field intake, four primary statuses, exceptional unrepaired/cancelled outcomes, random numeric tracking up to eight digits with database safeguards; legacy references/history remain valid. One monetary input normally takes thousands of toman; exact legacy amounts retain exact-toman handling. The settlement checkbox confirms existing reconciled receipts; it must never fabricate payment success. Pickup/freight collect remain separate from disabled prepaid postal billing.

Provider activation boundaries:

- SMS.ir approved template **124708**, exact parameter **CODE**. Owner confirmed delayed test messages arrived. API acceptance alone was not delivery proof; real-time production sending requires provider/site authorization. Do not repeat SMS investigations or enable sending/notifications now.
- Zibal live requests/payments remain OFF; placeholder/unavailable merchant and external approvals must not be invented. Local mocked development was authorized, live activation was not.
- Postex quote retrieval was verified. Independently deposited post-office tariff applicability is still unconfirmed: prepaid postal checkout/billing stays OFF. No shipment, pickup, label, wallet or charge operation.

## 5. Exact staging state and evidence limits

| Item | State / confidence |
| --- | --- |
| URL | `https://staging.novinpaypos.com`, existing HTTP Basic protection stays enabled |
| Application / document root | `/home/novinpay/novinpay-staging-workflow`; its `/public` directory |
| Database | `novinpay_stg38`, separate from production |
| Roles | Runtime `novinpay_s38run@localhost` remains restricted CRUD; authorized migrator `novinpay_s38mig@localhost` observes trigger metadata/applies staging DDL |
| Installed schema | Owner-run reconciliation/finalization evidence: **22 migrations, 13 exact triggers**, four new tables; do not replay the installer or migrations |
| Baseline source | `a5f76363dd3ad6db056d1d539ab83b94fd796dff`, 200 files verified, followed by scoped hotfixes; not evidence of a full Git fast-forward to latest HEAD |
| Finalization | Owner reported successful finalization and live staging; latest owner reports widget opens and sends messages |
| Disclosure verification | Owner-reported `CHAT_DISCLOSURE_HTTP_VERIFICATION_PASS`: 11 tested endpoints safe; private paths 403/404, anonymous operator API 401, Basic enforced |
| Latest patch | `030d48e` controller/bridge/network installed, then `bec2433` engine installed using its exact previous SHA-256. Both scoped updates read back and hashed; earlier team/theme/service patch retained. No migration, installer or initialization command replayed |
| Extra source files | Original `operator/icons/operator-mark.svg` and `support/openssl.cnf` uploaded/readback verified; branded PNGs preserved |
| Current data inspection | Read-only cPanel inspection during this task: schema-3 chat JSON, valid epoch, one conversation, four messages, no duplicate message IDs. Contents were not printed. This is not a complete delivery audit |
| Browser acceptance | Agent Chrome fails `ERR_INVALID_AUTH_CREDENTIALS`; owner's Chrome works. Owner will perform final authenticated acceptance. Do not alter Basic or extract/inject cookies to work around this |

Latest private staging evidence is under `/home/novinpay/novinpay-staging-38b9117-kit/`: `chat-sync-20261104-v1-receipt.json` binds the four files to `030d48edb2424d963e42948642726ee64a6aec18`, with four `chat-sync-20261104-v1-*.before` backups. It explicitly reports `STAGING_SYNC_PATCH_READBACK_PASS_HOSTED_ACCEPTANCE_PENDING`, hosted latency/two-way acceptance false. Earlier `chat-followup-20261104-v1-receipt.json` and five backups remain. No authenticated HTTP/body/OPcache observation of the latest patch has been claimed. Preserve original encrypted recovery snapshots, attempt markers and prior receipts.

Local session-specific QA/tooling is under `C:\Users\Khashayar\.codex\visualizations\2026\10\20\01a15969-8b3c-7783-9b3b-bade84862fc0\repair-chat-20261104`. These are optional evidence artifacts, not portable tracked dependencies and not proof of execution. Do not copy the entire folder into Git.

## 6. Production: historical evidence, not freshly inspected

- URL `https://novinpaypos.com`; application `/home/novinpay/novinpay`; `public_html` symlink must remain targeted to `/home/novinpay/novinpay/public`.
- Latest owner-confirmed production release in this conversation: **`1f39bb476fd31880600564e58599c4488622c4c0`**, maintenance OFF; PR #11/#12 final maintenance redesign included, no migrations/data restore. **Not independently rechecked during the current staging/handoff work.** Do not state that production is at the feature head.
- Historic Phase 8 baseline `5126bac82e1a0be19c53a859ffb3c8124aac5a60`; maintenance release `33d4a52c2c2862493dec9dcf4a81b18263cc4b74` preceded the final redesign.
- Preserve production `.env`, APP_KEY, data, uploads, sessions, dependencies, `.htaccess` modifications, Git metadata, existing untracked diagnostic evidence and hosting services. Never reset/clean production.
- Protected release requires exact approved source/CI, current quota/runtime evidence, consistent encrypted backup, isolated restore and independent off-host decryption, rollback compatibility and in-flight-request safeguards. Old backup receipts do not establish present freshness.
- Prior backups/keys/restore databases must remain intact. Do not re-use old Phase 8 updater, `release-redesign-v2.sh`, failed activation/install commands, or untracked pipeline tooling without reviewing current compatibility and authorization.
- Current task does not authorize production access or deployment, PR merge, provider activation or business-data changes.

## 7. ZizoLearn parity and security history

Authoritative source repository `khashayarxy/zizolearn`, verified pinned source **`7d6f6ddcfbde4fd3fb1857f62504d230e4c43bb5`**. Source code only: never connect to or copy its production data, secrets, messages, payment/bot state. Do not modify ZizoLearn.

See [file-by-file parity](CHAT-PARITY-REVIEW.md), `resources/support-chat/UPSTREAM.json`, `UPSTREAM-TOOLS.json` and [capability table](REPAIR-SUPPORT-STAGING.md). Import review found 127 files: 95 unchanged, 32 adapted after LF normalization. Inventory is not hosted feature-parity acceptance. Latest direct GitHub comparison found `support-client/engine.js` and `network.js` byte-equivalent after normalization; `support/storage.php` differs only by the native customer ownership mapping. Do not blame an unverified rewritten sync engine.

Preserve outbox/retry/ACKs, replay conflict checks, history/read states, presence/multi-operator features, images/avatars, lifecycle, CSAT, analytics, notifications, knowledge/FAQ, recovery and retention. Upstream attachments are JPEG/PNG/WebP images (three, 5 MiB each), not arbitrary PDF/file support; do not invent parity capabilities.

Current gaps:

1. Synchronization defect above; owner's actual 409 subtype still needs capture. Generic Persian 409 text comes from `support/bootstrap.php::support_error_response`, not necessarily an ACK conflict.
2. **No trustworthy before/after hosted send/ACK/visibility/server latency measurements exist yet.** `engine.auth()` adds an identity request before sending and active polling defaults to four seconds, as in upstream. They are inspection findings, not confirmed causes. Do not remove identity checks or increase polling speculatively.
3. Real two-way customer/operator, two-operator, attachment, reconnect/retry, unread, notifications and feedback acceptance remains incomplete.
4. Original team add/list/revoke UI restored and integrated with owner/password/CSRF/native audit/session revocation. Native profile password/role changes still use Laravel management; this remains an explicit UI/integration difference, not 100% original UI parity. Operator hosted acceptance is pending.
5. Light default/manual persisted dark implemented and unit tested; actual hosted light/dark visual acceptance remains pending. No site-wide dark mode.
6. External SMS/push/email transports and optional recovery issuance remain disabled/unqualified. Retention is deliberately unarmed unless separately configured; do not enable these to claim parity.

Security fixes to preserve:

- Raw installation configuration disclosure: native includes reused the controller's `$file` variable, causing later dispatch to include installation.env. Dedicated `loadModule` scope isolates includes; response guard rejects raw configuration assignments. Do not undo either protection.
- Cookie continuity: Laravel encrypts outgoing native chat cookies; PHP `$_COOKIE` still held encrypted wire values on the next request. Controller now scopes the authenticated/decrypted Request cookies into native dispatch and restores globals in `finally`. Encryption, CSRF and ownership checks remain enabled; tampered-cookie rejection is tested.
- Native duplicate login/password/account APIs remain controlled. Team management uses native `StaffAdministration` and protected owner current-password confirmation; support manager is not a global administrator. New staff password rotation and revocation remain enforced.

## 8. Deployment lessons: read before changing helpers

[DEPLOYMENT-LESSONS-LEARNED.md](DEPLOYMENT-LESSONS-LEARNED.md) contains detailed causes and regression tests. Summary:

| Failure | Verified lesson |
| --- | --- |
| `existing_triggers` | Runtime cannot see trigger metadata. Use the authorized migrator observer; do not grant runtime extra privileges |
| `web_probe_http_401` / `staging_bootstrap_identity` | DB/admin bootstrap file never contained Basic credentials. Use the dedicated private source and pinned active password file; no arbitrary reset |
| `dump_trigger_inventory` | Valid MariaDB dump has unquoted names and executable version wrappers; parser must validate full exact trigger contracts |
| `previous_attempt_preserve` | A later started marker proved installation progressed. Preserve evidence; reconcile exact state, then finalize; never replay DDL |
| `details_withheld` | Diagnostic label whitelist hid the real check; safe controlled diagnostics need durable receipts without sensitive bodies |
| Private-file HTTP 403 | Secure 403/404 are both valid when body is safe. Never change server denial rules to satisfy a 404-only test |
| Original backup/release blockers | Preserve approved privilege policy; do not needlessly ALTER matching DB defaults; local UAPI quota command is unusable, use authenticated HTTPS quota API; refresh evidence at the correct gate, not by weakening freshness |

Kit directory 0711 is intentional for web-server traversal to Basic password verification; private secrets stay 0600. Do not broadly chmod all directories to 0700. PHP CLI support alone does not prove LiteSpeed web OPcache/UID behavior.

## 9. Credential management and tool limitations

- Local `.env` is private and owner-edited. Never stage, print or overwrite it. Do not output `SMSIR_TEST_MOBILE`, OTPs, API values, session tokens or credential hashes.
- Windows secure access/recovery uses `C:\Users\Khashayar\AppData\Local\NovinPay-SecureAccess` and DPAPI. These assets are account/machine-specific and will not become available simply by switching AI accounts.
- cPanel authenticated HTTPS uses the owner's configured access to `leda.asemanhost.com:2083`; TLS verification stays enabled. No server token values belong here.
- Staging `.env`/APP_KEY and database/admin bootstrap are private. Basic client credential source is the kit's `.staging-http-basic.json`, separate from `bootstrap-secrets.json`; active `.htpasswd` is one-way verification data. Never display or copy them into documentation/Git.
- Existing cPanel file APIs allow guarded upload/readback, quota and inventory; **no verified shell-execution API exists**. A previous local staging DPAPI-decrypt/authenticated-request operation was policy-blocked. Never route around that block through another tool/credential source.
- Agent Chrome authentication failure is separate from website health. Owner explicitly said to continue code-level work and will do final browser acceptance. Do not request credentials in chat or repeatedly ask for Basic sign-in.
- When host execution is genuinely unavoidable, follow repository rules: one reviewed minimal SHA-256-verified Terminal procedure and wait for actual output. Do not create a public remote-command backdoor or execute an ambiguous operation again.

## 10. Validation and safe resume

Use existing guarded commands from the repository root (PowerShell):

```powershell
git branch --show-current
git log -1 --oneline
git status --short
git diff -- tests/Feature/SupportChatTest.php
./scripts/test.ps1 --filter=test_empty_installation_keeps_sync_epoch
```

That command fails at regression-only commit `8b0801e` and passes after the real fix. Run proportional tests:

```powershell
./scripts/test.ps1 --filter=SupportChatTest
./scripts/test.ps1 --filter=SupportChatAssetsTest
./scripts/test.ps1 --filter=StagingSecurityVerifierDiagnosticsTest
node --test tests/Frontend/support-theme.test.mjs tests/Frontend/support-sync.test.mjs
php vendor/bin/pint --test
npm run build
git diff --check
```

Verify PHP compatibility first. Use Pint only on intended paths when unrelated dirty PHP exists. Avoid shell metacharacters in `test.ps1 --filter`: a previous `|` filter was interpreted as a shell pipeline; a class or single method works. No application test is needed merely for a documentation edit.

Latest local implementation validation before the new regression: `SupportChatTest` **16 passed/139 assertions**, assets **1/79**, theme **4 passed**, Pint/build/diff checks passed. All four exact-head CI jobs passed. These do not prove hosted messaging performance or the new test's success. Full local CI matrix is unnecessary unless a changed risk warrants it.

Next implementation order:

1. Verify the qualified epoch/resync patch is installed on staging by exact hashes. The regression passes locally; do not rerun installers or reset existing chat data. Confirm hosted normal-flow behavior rather than assuming every reported 409 is the same defect.
2. Collect the newly available bounded timing/error-code evidence from owner acceptance. No hosted before/after latency samples exist yet. Compare equivalent upstream/candidate operations without touching ZizoLearn production, then optimize the measured bottleneck.
3. Optimize only measured bottlenecks. Preserve identity/replay/locking/durability guarantees, original features and bounded polling. Test rapid sends, lost ACK, reconnect and duplicate prevention.
4. Deploy only the qualified staging patch through fingerprint-guarded file updates, preserving private recovery and receipts. No installer/migration replay.
5. Owner performs authenticated acceptance: customer/operator messages both ways; no normal-flow warnings; ACK/retry/order/no-loss; attachments and reconnect; themes and original team UI. Record actual measured before/after latency, including unresolved limits.
6. Finish remaining parity gaps, update lessons and publish focused commits to Draft PR #13 under current authorization. Keep production, ZizoLearn and live providers untouched. Production release/merge needs separate approval and fresh release gates.

Do not claim completion, 100% parity, performance improvement, delivery, deployment or backup freshness without the corresponding evidence.

Latest focused fix validation: 18 chat tests / 188 assertions; 8 frontend sync/theme tests; historical release tooling 42 tests / 101 assertions; PHP lint, PowerShell AST and scoped Pint passed. Hosted latency, initialization reliability and two-way acceptance remain pending. Existing finance files are the only intended residual working-tree noise and must not be committed merely to clear Git status.

A later focused optimization removes only the redundant identity GET for fresh submissions enqueued in the current tab with matching role/scope/epoch and zero prior attempts. Server authorization/CSRF/ownership checks remain unchanged. Restored drafts and every retry still refresh identity and reconcile receipts. Executed frontend tests establish two serial critical-path requests before versus one after for a fresh send, for both roles; this is request-count evidence, not hosted millisecond improvement. Added POST tests reject old credentials after customer logout/operator revocation. Hosted initialization and two-way delivery still need acceptance; see the deployment lessons for exact measurement boundaries.

Latest private deployment receipt: `/home/novinpay/novinpay-staging-38b9117-kit/chat-send-20261104-v1-receipt.json`, bound to `bec24334c458cf10678af82f7671889e7dfd6708`, reports `STAGING_FRESH_SEND_PATCH_READBACK_PASS_HOSTED_ACCEPTANCE_PENDING`. Engine SHA-256 is `83459a2b0c0fb35d85f9bc23468d61fe0b7db7cd828ffb75fd808a854940b677`; previous engine preserved privately. No data/configuration/production operation occurred. Latest focused checks: 11 frontend tests passed; direct POST logout and revocation tests passed (6 and 4 assertions respectively); Pint/build passed. Prior full scoped chat run was 18 tests / 188 assertions before the two additional denial assertions. Do not infer that the latest full CI has passed without checking its exact head.

2026-11-05 continuation: removed redundant atomic/fsync operator-authority rewrites when the validated serialized state is byte-identical. Local before/after regression establishes one rewrite to zero for unchanged `syncOperators`, while revocation/expired metadata cleanup still persist. No privilege cache, polling increase or security relaxation. Browser access was rechecked with supported browser tooling and still failed Basic authentication (`ERR_INVALID_AUTH_CREDENTIALS`); no credentials were extracted or protection changed. Owner browser acceptance and hosted latency remain separate pending gates. See the last deployment-lessons section and latest commit/receipt for delivery status.

2026-11-05 precise latest blocker: `9a70b57175ba96a01d31ed366c4829b51e5b175a` contains the qualified operator no-op optimization, but staging transfer failed before source replacement. Read-only reconciliation confirms the old operators.php is still installed, private code backup exists, and no completion receipt exists. Do not claim it deployed or blindly retry. CI SQLite now fails `composer audit` on six newly published Symfony advisories (three locked v8.1.8 packages); previous green run is stale evidence for this gate. Minimum dry-run resolution is exactly http-foundation/http-kernel/routing v8.1.11 using `--with-dependencies --minimal-changes`; no lock/vendor changes made. Dependency remediation needs applicable owner authorization, then required checks, before further release progression. See deployment lessons for exact evidence/command. Performance milliseconds, real two-way delivery and startup acceptance are still unverified.

Owner approved the minimal Symfony remediation on 2026-11-05. Local lock now changes exactly http-foundation/http-kernel/routing 8.1.8 -> 8.1.11; composer.json and unrelated lock entries unchanged. Locked + installed audit report zero advisories; no security ignore/filter added. PHP platform check, 79 focused compatibility tests/901 assertions, 11 frontend tests and full Pint passed. Staging read-only package metadata still showed 8.1.8 pending controlled dependency deployment; local vendor success is not hosted remediation. Required CI and safe staging reconciliation/dependency installation remain the next gates.
