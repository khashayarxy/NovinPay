# NovinPay — Codex adapter

## Start with the task, then the relevant source

- Follow the current owner's scope and approval boundaries. This file is a repository guide, not authorization to publish or operate production. Stop and report access or instruction conflicts; do not bypass sandbox restrictions.
- Inspect branch, HEAD, `git status --short`, and the relevant diff before editing. Preserve unrelated work. Back up any existing instruction file before changing it; never overwrite one blindly.
- Use targeted `rg` searches. Read only the documents and code needed for the task; reuse existing runners and conventions instead of creating another framework. Do not repeat completed setup or tests without a changed input or unresolved failure.
- Verify claims against current code, locks and CI. `docs/TASK.md` has older phase numbering; README setup snippets and dated release reports are historical, not the current roadmap or permission to run commands.

## Authoritative project references

For a new agent/session, start with [AI project handoff](docs/AI-PROJECT-HANDOFF.md) and [working preferences](docs/AI-WORKING-PREFERENCES.md). They distinguish dated evidence from open work; verify current Git/host state before relying on it. `CLAUDE.md` points to the same rules for Claude Code.

| Area | Read when relevant |
| --- | --- |
| Architecture and runtime | [README](README.md), [foundation](docs/FOUNDATION.md), `composer.lock`, `package-lock.json`, `config/database.php` |
| Admin authorization and privacy | [Phase 5](docs/PHASE5.md), [repairs](docs/PHASE6A.md), [customers/consultations](docs/PHASE6B.md) |
| Orders, inventory and financial integrity | [Phase 6C](docs/PHASE6C.md), [Phase 6D](docs/PHASE6D.md) |
| Articles, immutable versions and public SEO | [Phase 7A](docs/PHASE7A.md), [Phase 7B](docs/PHASE7B.md) |
| Maintenance design, state, recovery and rollback | [standalone page](docs/maintenance-page.md), [admin maintenance](docs/admin-maintenance.md) |
| Local environment and testing | [local troubleshooting](docs/LOCAL-DEVELOPMENT-TROUBLESHOOTING.md), [test runner](scripts/test.ps1), `phpunit.xml` |
| CI and integration | [CI guidance](docs/CI.md), [actual workflow](.github/workflows/ci.yml) — use the workflow for current jobs/commands |
| Deployment failure prevention | [Deployment lessons](docs/DEPLOYMENT-LESSONS-LEARNED.md) — read relevant current lessons before packaging; retired staging incidents are historical, not routine gates. |
| Hosting and release gates | [MariaDB compatibility](docs/MARIADB-COMPATIBILITY.md), [readiness evidence](docs/PRODUCTION-READINESS.md), [deployment guide](docs/DEPLOYMENT.md), and the latest separately approved release/rollback procedure |

Trace behavior through `routes/`, `app/Http`, `app/Services`, `app/Policies`, `app/Support`, models, migrations and matching tests. Preserve established service transactions, native Gates/policies and Blade components. Public content is Persian RTL; approved branding/contact data comes from existing assets and `config/business.php`.

## Runtime and data safeguards

- Laravel 13, PHP 8.4 (locked dependencies require >=8.4.1), Blade/Tailwind/Vite. Root `composer.json`'s broader PHP range does not override locked requirements. CI uses Node 24. Avoid new dependencies and lockfile changes unless required and authorized.
- Preserve SQLite test support, MySQL 8 and native MariaDB 10.6.28 compatibility. Retain strict/session timestamp behavior, InnoDB, enforced CHECK/FK/unique constraints and immutable-version/ledger triggers; never weaken invariants or change global SQL settings to make a test pass.
- Preserve default-deny permissions, protected owner access, session revocation, CSRF/password checks, encryption and private-data boundaries. Keep financial arithmetic, transaction locking, idempotency and immutable correction history intact.
- Do not infer a payment gateway from manual financial records. Inspect actual routes/services before changing callback or maintenance exemptions; preserve payment verification and replay protection where implemented. Do not add speculative public bypasses.
- Never mutate the original SQLite or a real database for tests. Preserve `.env`, APP_KEY, credentials, uploads, sessions, private maintenance state/audit, existing `.htaccess` modifications, untracked files and recovery archives. No secrets or customer data in Git, logs, screenshots or reports.

## Local work and proportionate validation

- Use the existing Herd site `https://novinpaypos.test` and verify the isolated development database before browser submissions. Preserve Herd safety guards; do not start an unscoped Artisan/Composer dev stack or another application server.
- Prefer dedicated local MariaDB 10.6.28 and PHP 8.4, matching production patches where practical. Preserve the current persistent local data/guard until a safe conversion is prepared; it is currently SQLite, not MariaDB. Never run destructive regression tests against the owner acceptance database. Retain SQLite CI compatibility. Add migrations only for real schema changes; do not create empty, duplicate or environment-alignment migrations.
- Never use SSH or Docker for NovinPay, or touch unrelated containers/projects. Existing remote CI database services are not permission to use local Docker.
- On Windows use `./scripts/test.ps1 --filter=RelevantTest` for focused checks and `./scripts/test.ps1 --compact --display-warnings --fail-on-warning` for full SQLite regression. The runner isolates DB_URL, sessions/cache and config-cache paths; do not replace it with unguarded Artisan or `composer test/setup`.
- During development/commit, run only minimal syntax/diff or focused tests justified by the changed risk. No automatic full suite, integration/E2E/browser/database matrix, build, backup or migration rehearsal on commit. The owner performs local acceptance. Reuse verified evidence; do not rerun broad checks merely because a commit is being made.
- On push, the single existing CI workflow runs the required comprehensive checks once per pushed revision. Keep job names/security checks stable; no duplicate PR-triggered matrix or local pre-push matrix. Reuse guarded disposable migration/concurrency harnesses in CI. No test database provisioning without scope/authorization.
- Required CI jobs are **PHP SQLite**, **PHP MySQL 8**, **PHP MariaDB 10.6.28**, and **Frontend**. Distinguish local results, skipped/unavailable checks and exact-head CI; a green build is not visual approval or deployment proof.
- For UI changes preserve RTL, desktop/mobile sizing, keyboard/focus and overflow safeguards. Use targeted browser/visual checks when requested or needed for a specific defect, not automatically on commit; the owner reviews the local UI. Keep QA artifacts out of Git. For instruction-only changes, validate references, coverage and diff without running the application/build.

## Official workflow (owner decisions, 2026-11-07)

- Develop and test at `https://novinpaypos.test` (Herd). Present UI/features locally for owner approval.
- Keep development, push verification and production release separate. A commit/push is not an imminent deployment request. There are no active local Git test hooks; do not add heavy commit/pre-push hooks or a new hook framework.
- Backup interval and maximum deployment/recovery age are both **72 hours**, configured in `scripts/release/backup-policy.php`. Change this only with explicit owner authorization. Age never replaces integrity, encryption, independent off-host recovery, restore or compatibility evidence.
- No production backup, restore database, off-host transfer, rehearsal or manifest regeneration during ordinary iteration. Expiry during development is not a reason to provision another restore target. Prepare fresh evidence only for an imminent approved activation, after non-expiring code/CI preparation; reuse successful evidence while applicable and preserve established production data protection.
- After local approval and focused checks/required CI, obtain explicit production deployment approval and release directly to `https://novinpaypos.com` through cPanel. Owner performs final production acceptance; fixes follow the same local/controlled-release cycle.
- Hosted staging is retired from normal work. Do not create/require another staging environment, Basic login, hosted staging acceptance, or staging deployment package. Historical staging scripts/receipts are recovery evidence, not active instructions.
- Retain affected-file/data backup and verified rollback, migration safety, production identity/integrity checks and post-release verification. This workflow does not authorize merging Draft PR #13, unfinished releases, or live payment/SMS activation.
- Investigate chat performance locally against authoritative ZizoLearn source. Staging retirement is not evidence of the cause or resolution of latency.

## Release and rollback boundaries

- Publishing, protected merge and production deployment require their own explicit owner authorization; none follows from implementation or CI success. Use normal feature branches/PRs and approved merge strategy; no force-push, reset/clean or discarding unrelated work.
- Production stays at `/home/novinpay/novinpay`; preserve `/home/novinpay/public_html` and its existing link identity/target `/home/novinpay/novinpay/public`. cPanel/LiteSpeed web PHP and the explicit CLI `/opt/cpanel/ea-php84/root/usr/bin/php` must be verified independently. Do not switch handlers, alter hosting or broadly reset caches/OPcache.
- Use authorized cPanel API operations; if Terminal execution is needed, provide the reviewed minimal SHA-256-verified procedure and wait for its output. No migrations, seeding, provisioning, key regeneration or production writes by implication. Never blindly retry partial DDL or deployment journals.
- Require current quota/runtime/filesystem evidence and a verified consistent encrypted backup, isolated restore and independently verified off-host recovery before activation. Preserve the trigger-definer account. Historical physical free space or a legacy code directory is not backup/quota proof.
- Preserve maintenance fences and private audit/state across releases. Rollback must account for new `AdminAction` values and old code ignoring the new maintenance flag; follow the reviewed compatibility procedure, not a pristine Phase 8 checkout or the old updater unchanged. Never drop audit/history or restore live data as an automatic code rollback.
- Admit traffic only after required readiness checks; genuine integrity/security failures remain fail-closed. Report transient verification uncertainty as pending, never PASS. Report evidence, exact changes, skipped checks, remaining risks and the next authorized step; do not silently advance phases.

## Shared skills

Use only relevant, currently exposed/readable shared Skills and needed references. No project-owned MCP registrations or Skills existed at the 2026-11-06 audit; do not duplicate global tools/configuration locally. Preserve shared tools used by other projects. Skill defaults do not expand authorization or override safety gates; report missing required capabilities or conflicts.
