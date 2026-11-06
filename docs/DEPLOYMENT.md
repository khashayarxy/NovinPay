# NovinPayPos: controlled cPanel production release

Owner workflow decision: 2026-11-06. This replaces the old fresh-install/staging sequence. Production is already installed: do not reinstall, recreate users/databases, regenerate APP_KEY, replace directories, or replay Stage 1/2/Phase 8.

1. Develop on the existing feature branch at `https://novinpaypos.test` using Herd and the isolated test runner. Preserve unrelated work and private configuration. Present UI/features locally for owner approval.
2. Run focused affected tests and applicable Pint/build/diff checks; retain required exact-head CI (SQLite, MySQL 8, MariaDB 10.6.28, frontend). Do not repeat full local matrices without a changed risk. Keep PR #13 Draft/unmerged without explicit approval.
3. Obtain explicit owner authorization for production deployment of the exact approved revision. Local approval or CI alone is not deployment permission. Never deploy unfinished changes to replace staging acceptance.
4. Reconcile actual production HEAD/files/previous receipts. Use the existing cPanel HTTPS/file workflow and reviewed release tooling; do not blindly rerun an interrupted operation. No Docker, SSH, global hosting changes or generic fresh-install commands.
5. Verify affected-file backup and rollback. If data/schema is affected, require a current consistent encrypted backup, isolated restoration and independently verified off-host recovery; retain keys/receipts and earlier generations. For code-only releases, use the existing approved recovery policy and document why its evidence covers the change; do not bypass freshness gates. Verify current quota/runtime/filesystem requirements proportionately.
6. Review each migration separately: preservation, trigger/definer/role compatibility, concurrent requests, recovery and rollback. No production migration by implication. Code rollback must not restore or overwrite newer business data.
7. Deploy only the approved manifest in place at `/home/novinpay/novinpay`. Preserve `.env`, APP_KEY, DB records/schema, uploads/sessions, dependencies outside the release scope, existing `.htaccess`, private maintenance/audit state and `/home/novinpay/public_html -> /home/novinpay/novinpay/public`. CLI: `/opt/cpanel/ea-php84/root/usr/bin/php`; verify effective web runtime separately when relevant.
8. Use the reviewed traffic fence/readiness procedure when needed. Fail closed for real security/integrity failures. Transient external verification uncertainty is PENDING, not PASS or automatic downtime for a healthy site. Never suppress a failed check.
9. Verify public HTTPS/assets, required authorization/critical routes, database identity when relevant and maintenance OFF. Owner performs authenticated production acceptance without synthetic financial/customer mutations. Record deployed revision, results and remaining limitations. Keep rollback ready; fix remaining issues locally then repeat the approved cycle.

No routine hosted staging, staging Basic login, staging acceptance gate or new test subdomain is required. Retained staging snapshots/helpers are historical evidence, not deployment instructions. Retirement must never expose old debugging endpoints or private data.

SMS/notifications, Zibal live payments and Postex billing/shipment operations remain disabled unless separately authorized. Never copy ZizoLearn secrets/data or modify its website.

References: [AGENTS](../AGENTS.md), [handoff](AI-PROJECT-HANDOFF.md), [lessons](DEPLOYMENT-LESSONS-LEARNED.md), [MariaDB compatibility](MARIADB-COMPATIBILITY.md), [maintenance recovery](admin-maintenance.md), and the exact separately approved release/rollback manifest. Prepared scripts are not proof of execution.
