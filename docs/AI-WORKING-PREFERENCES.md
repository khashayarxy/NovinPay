# AI working preferences

Applies to NovinPayPos work by Codex, Claude Code or another assistant. Read [project handoff](AI-PROJECT-HANDOFF.md), root [AGENTS.md](../AGENTS.md), then the relevant authoritative source. These preferences do not override platform restrictions or authorize new remote operations.

## Communication and initiative

- Write prompts, agent instructions and reusable handoffs in **English**. Application UI remains Persian RTL. Match the owner's requested reporting language; do not confuse instruction language with UI language.
- Work autonomously within the current explicit authorization and configured execution policy. Finish concrete permitted work; avoid repeated planning reports and routine permission questions.
- Ask only for missing information or a genuinely required protected approval. Explain the exact blocker and smallest owner action. Never request passwords, keys, OTPs or tokens in chat.
- Report verified results, historical owner reports, hypotheses and remaining acceptance separately. HTTP 200 is not functional chat acceptance; API acceptance is not handset delivery; a prepared script is not an executed deployment.
- Keep updates concise and useful. Do not repeat broad audits, setup or exhaustive tests without changed inputs or unresolved failures.

## Hard boundaries

- **Never use Docker, Docker Compose, containers or SSH for this website.** Unrelated trading-bot resources are outside scope. Existing remote CI services do not authorize local container operations.
- Do not modify or access production without explicit applicable authorization. Retiring staging does not authorize application changes in production.
- Keep PR #13 Draft and unmerged until the owner explicitly approves merging. Follow branch protection, required CI/reviews and the normal protected workflow; never force-push or rewrite history.
- Preserve the original ZizoLearn chat capabilities, workflows and UI. Adapt only necessary branding, paths, native authorization/storage integration and hosting compatibility. Do not replace it with a smaller chat or change ZizoLearn itself.
- Preserve business data, admin/customer identities, credentials, APP_KEY, sessions, uploads, financial/inventory invariants, audit history, encrypted backups, recovery keys, restore databases and deployment evidence.
- Never reset/clean the working tree or host, blindly stage all files, destructively reset databases, replay partial migrations/installers, or delete diagnostic evidence to obtain PASS.
- Do not bypass denied operations by using another tool or credential source. New AI accounts must re-establish authorized access; old tools/DPAPI material are not automatically portable.

## Engineering and validation

- Inspect branch/HEAD/status and scoped diff before editing. Preserve unrelated modified/untracked work. Explicitly stage reviewed paths and inspect the staged diff before committing.
- Reuse Laravel services, policies/Gates, Blade conventions and repository scripts. Avoid new dependencies, unrelated refactoring and another deployment framework.
- Use `scripts/test.ps1` for isolated tests, compatible PHP >=8.4.1, focused checks first. Never use the ordinary development or production DB for tests. Avoid unguarded `composer setup`, `composer test`, migrations or dev servers; Herd already serves local HTTPS.
- Preserve MariaDB 10.6.28/MySQL 8/SQLite compatibility and trigger contracts. Use integer money and existing conversions; never introduce 1,000x toman errors or fabricate settlement/gateway success.
- Keep SMS/notifications, Zibal live requests and Postex prepaid billing/shipment operations disabled until separately authorized and validated. The SMS delivery-support issue is not a reason to rewrite the existing integration.
- Test what changed. Focused success does not replace required CI, local owner approval and production acceptance after an authorized release, visual checks or release safety gates. Report skipped/unavailable validation honestly.
- Back up an existing instruction file before changing it. Reference authoritative documents rather than duplicating their entire contents.

## Hosting and deployment

The official workflow is defined in [AGENTS.md](../AGENTS.md) and [deployment guide](DEPLOYMENT.md). No new hosted staging environment is required or authorized.

- Prefer existing cPanel/LiteSpeed/PHP 8.4-compatible workflows. No persistent WebSocket/queue daemon requirement should be introduced without a real need and approval.
- Read `DEPLOYMENT-LESSONS-LEARNED.md` before preparing a package; add confirmed causes, exact fixes and focused regression coverage when resolving an issue.
- Use local Herd development/owner review, then explicitly approved cPanel production deployment and owner production acceptance. No routine hosted staging or staging Basic Authentication requirement. Historical staging evidence remains protected; do not replay its deployment scripts.
- Use authenticated HTTPS and exact-target/hash/readback checks for remote file work. Preserve previous versions privately. Do not blindly retry failed or ambiguous remote mutations.
- Keep deployment phases resumable through evidence: reconcile existing files/schema/markers first. A known partially installed candidate requires finalization, not another install.
- Require current quota, compatible CLI/web runtime, consistent encrypted backup, isolated restore, independent off-host recovery and compatible rollback before production activation. Never weaken freshness, locking or integrity gates just to pass.
- Genuine security/integrity failures stay fail-closed. Transient external verification uncertainty is PENDING, not PASS or a reason to unnecessarily take a healthy site offline.
- Cleanup only verified obsolete items after acceptance; retain recovery/audit assets and uncertain files. No broad wildcard deletion. Quarantine is preferable when a removal is not fully established.

## Preferred result format

Give a concise summary of actual changes, exact commit/PR/local and production state, focused test results, measured performance evidence where applicable, unresolved blockers and the next concrete action. Never imply that local tests establish live behavior or that all feature parity is complete from an import inventory alone.
