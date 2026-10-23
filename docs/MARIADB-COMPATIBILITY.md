# MariaDB 10.6.28 compatibility and existing-site release gate

## Verified scope

Native Laravel `mariadb` connection through PDO MySQL, InnoDB, strict mode, utf8mb4/utf8mb4_unicode_ci. Verified server: **10.6.28**; the preflight permits patched 10.6 versions from 10.6.28, not untested 10.7/10.11/11.x. MySQL 8.0.16+ and SQLite remain supported. No Composer/package lock change or new dependency.

The owner reports PHP CLI 8.4.26 at `/opt/cpanel/ea-php84/root/usr/bin/php`, Laravel 13.35.0, and seven migrations in batch 1 through `2026_10_14_000002_renormalize_search_text`. These remain owner-provided facts, not an imported schema audit. Read-only cPanel API independently confirms MariaDB **10.6.28-MariaDB**, the domain's `ea-php84` assignment, `suphp` handler, configured document root `/home/novinpay/public_html`, and that `public_html` is a symlink. Its resolved target remains owner-reported as `/home/novinpay/novinpay/public`. One database-mapped account has schema-level `ALL PRIVILEGES`; this does not prove the effective application account, global SUPER or binary-log policy. See [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) for the current evidence and remaining gate. No production file, database or setting was changed.

## Incompatibility inventory and changes

| Area | Finding and resolution |
| --- | --- |
| `2026_10_21_000003_create_financial_tables` | Old explicit MariaDB rejection now replaced by bounded server/version and capability checks. Identical financial CHECK expressions, immutable-entry triggers, identity trigger, FK, unique keys and numeric bounds remain. |
| `2026_10_21_000004_create_article_tables` | Old explicit rejection similarly replaced. Identical immutable version triggers, composite owner FKs and unique revisions remain. |
| TIMESTAMP in repair operations and later tables | MariaDB 10.6 defaults can implicitly add DEFAULT/ON UPDATE behavior to first TIMESTAMP, and reject later non-null timestamps under strict SQL modes. A fresh local migration failed on `repair_deliveries.created_at` before correction. Native connection now sets **SESSION** explicit_defaults_for_timestamp=1 on every PDO connection/reconnection; global settings are never changed. Native connection explicitly selects InnoDB. Schemas retain timestamp type/nullability and required insert values, rather than adding automatic timestamp updates or weakening constraints. Existing seven migrations use nullable timestamps/integers and their definitions are unchanged. |
| Trigger authority | Shared `DatabaseTriggerAccess` checks direct grants for every required table and binary-log policy before creating either module's tables. Financial preflight no longer accepts SUPER as a substring of a username. Role-only/wildcard grants are deliberately not inferred. Provider review required if metadata is denied. No grant, global policy or immutability guarantee is relaxed. |
| JSON | MariaDB stores JSON as validated text rather than MySQL's binary JSON. App uses Eloquent array casts, not binary-JSON-specific storage/functions. Round-trip and raw invalid-JSON rejection are tested. No indexed JSON path, generated column, MySQL 8-only collation, functional index or DB-specific external package is required by this app. |
| Other SQL | `LIKE ... ESCAPE '!'`, aggregates/joins, NULL-safe `<=>`, `SIGNAL SQLSTATE '45000'`, CHECK, FK/RESTRICT, unsigned BIGINT, unique nullable references and row locks run on 10.6.28. Inventory/financial/publication transactions and actual competing connections are regression-tested. Existing business services and authorization are unchanged. |
| Harnesses/CI | Disposable-target guards now accept native `mariadb` as well as `mysql`, retaining localhost/non-default-port/test-database/APP_ENV guards. A pinned MariaDB 10.6.28 CI job runs full tests, baseline upgrade, article upgrade, real concurrency and rollback/reapply in addition to existing MySQL/SQLite/frontend jobs. |

`check_constraint_checks`, `foreign_key_checks`, explicit timestamp semantics and InnoDB must be enabled. Failure stops before financial/article DDL. Earlier repair DDL requires correct native connection too: do not invoke migrations using a `mysql://` DB_URL that overrides the chosen driver. Use `DB_CONNECTION=mariadb` and either omit DB_URL or use a consistent `mariadb://` URL in the private production configuration during a separately authorized release. The connection's session SET is a property of the proposed code; this task has not run it against production.

No application can preserve invariants against a privileged DBA disabling enforcement or changing triggers. Direct SQL access and backup privileges must remain protected. MySQL/MariaDB DDL is not fully transactional; a later DDL failure requires stopping to inspect migration records, actual tables and triggers, not blindly retrying or marking a migration done.

## Local evidence

- MariaDB 10.6.28: **440 passed, 2965 assertions**.
- MySQL 8.0.46 and SQLite: **437 passed, 2959 assertions; 3 MariaDB-specific integration tests intentionally skipped**.
- Each SQL server: 8 real inventory/order races, 6 financial races, 6 article-edit/publication races pass.
- Each SQL server: reported seven-migration baseline reconstructed with synthetic retained owner/product; additive upgrade, retained original rows/password hashes/migration batch IDs, no automatic admin grant, rollback of only new batch, and reapply pass (13 checks, including command status and completed migration inventory).
- Each SQL server: article upgrade/rollback/reapply preservation: 73 checks pass.
- New integration checks verify settings on a fresh connection, created_at not changing during unrelated UPDATE, JSON validity; preflight tests reject disabled invariants and unverified versions before DDL.
- No test connects to `/home/novinpay`, `novinpay_DB` or any live hostname. Credentials in CI are disposable service-only values, unrelated to hosting credentials.

CI results must be read from the exact PR head; local passes do not imply a queued CI result succeeded. Actual production schema-only export and provider-matched staging remain release prerequisites; a reconstruction is not proof of live schema equivalence.

## Read-only hosting/schema audit limitations

The verified cPanel API token is DPAPI-encrypted outside the repository; only allowlisted read-only metadata calls are used, TLS verified, redirects disabled, no raw responses/credentials/cookies printed. No .env/private-file contents, customer data or SQL password grants are downloaded. API responses may have host-specific shape; missing values are reported as unknown rather than inferred. The API's DB account-management/backup endpoints are not arbitrary read-only SQL clients. A full backup export can include customer rows and is not a substitute for a schema-only export; no full-backup job or web shell is created here.

The supported read-only `Mysql/dump_database_schema` endpoint was tested on 2026-10-23. It returned 1,383 characters of database-level DDL with **zero CREATE TABLE definitions**. Its encrypted private snapshot is not a usable table/constraint/trigger snapshot. No actual-production-schema upgrade rehearsal has been performed. Use the complete owner-run export in [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md), which includes trigger definitions; the narrower command below remains a baseline-table-only alternative and deliberately omits triggers.

The owner can run the following read-only schema export manually in cPanel Terminal. It creates only a private local output file, outside the webroot; no SQL changes or table locks are requested. Enter the database account name at the first prompt and its password at the dump tool's hidden password prompt. Do not put passwords in arguments, shell history or chat.

```bash
(
  set -e
  umask 077
  audit_dir="$HOME/novinpay-private-audit"
  mkdir -p "$audit_dir"
  chmod 700 "$audit_dir"
  dump_bin="$(command -v mariadb-dump || command -v mysqldump)"
  test -n "$dump_bin"
  read -r -p 'Database username: ' np_db_user
  schema_file="$(mktemp "$audit_dir/schema-only.XXXXXX.sql")"
  "$dump_bin" --user="$np_db_user" --password \
    --no-data --skip-triggers --skip-comments \
    --skip-lock-tables --skip-add-locks \
    --skip-add-drop-table --no-tablespaces \
    novinpay_DB \
    migrations users password_reset_tokens sessions \
    cache cache_locks jobs job_batches failed_jobs products \
    > "$schema_file"
  chmod 600 "$schema_file"
  sha256sum "$schema_file"
)
```

Transfer privately outside Git. This exports only the ten expected baseline table definitions, no rows, `.env`, APP_KEY or grants/password hashes. Schema defaults must still be reviewed before sharing. The reported baseline has no application triggers; any unexpected triggers, extra tables or schema drift require separate read-only inventory, not omission by assumption. This command is not a full backup and does not export the contents of `migrations`; its seven applied names/batch are owner-reported separately.

## Safe existing-production release plan — not executed

1. **NO-GO until** actual schema-only comparison, applied migration list, data/owner inventory, resolved public symlink, web PHP patch/extensions, DB timezone/SQL modes/engine, direct trigger/table grants and binary-log policy are verified. PHP default command is 8.1.34 per owner: always use `/opt/cpanel/ea-php84/root/usr/bin/php`; Composer is invoked through that binary with `/home/novinpay/bin/composer.phar`. Do not run generic composer setup, key:generate, seed, migrate:fresh, db:wipe or rollback on the existing site.
2. Rehearse against a **disposable staging copy on equivalent host settings** with the actual deployment-account privileges. Use an approved synthetic/sanitized dataset and protected existing key only if encrypted data is copied under separate authorization. Compare schema to reported baseline; verify new grants/triggers, check failures, date immutability, JSON, user/password preservation, admin lockout prevention, transactions and public forms.
3. Complete owner approval of privacy/terms, real Neshan place ID and publication content. Protected merge of exact green PR head requires separate owner approval. Record target commit, old commit and migration state.
4. Before release, quiesce writes through an approved maintenance mechanism; take encrypted external backups of MariaDB **including triggers**, uploads, private configuration and exact APP_KEY, plus the previous code/build. Verify backup integrity and a tested restore. Keep backups outside webroot and Git; do not expose credentials in commands/logs.
5. Build an immutable Linux/PHP-compatible artifact using locks, no development data/debug UI, no local config cache or public/hot. Stage beside the current release; never overwrite active files piecemeal. Verify platform requirements with explicit PHP 8.4 CLI. No dependency upgrades.
6. Under separate release authorization, configure native MariaDB connection privately, preserve the existing APP_KEY and credentials, confirm actual database is `novinpay_DB`, validate preflight in staging, and review pending migrations before production `migrate --force`. No global DB settings are changed by app. Stop on partial DDL. Bootstrap the **verified existing user** first in dry-run then explicitly apply the protected grant; never seed/reset an existing owner. Do not print identity/secret details in public logs.
7. Verify storage/bootstrap permissions, assets, root/HTTPS/cookies/cache, owner sign-in, ordinary-user denial, revoked sessions, repair privacy and public routes. Switch release/root atomically if host supports it; refresh only release-specific caches with explicit PHP binary. Remove maintenance only after owner-approved smoke checks.
8. Record version, nonsecret checks and backup/rollback owner. Revoke the temporary cPanel token after inspection. This document does not authorize server operations.

## Rollback

Keep writes paused and preserve a post-failure snapshot before deciding. If old code remains compatible with added schema, switch code/build back and rebuild its cache; do not drop new tables unnecessarily. If schema/data restoration is required, coordinate DB, uploads and original APP_KEY from the same backup point, with owner-approved treatment of any intervening writes. Financial and article rollback drops history; it is not the default live rollback strategy. Never reset the database or regenerate the key as a recovery shortcut. A server/database upgrade is an alternative only if provider cannot satisfy trigger/engine/version/CLI requirements; rehearse a logical dump/restore to a supported MySQL 8 target rather than swapping MariaDB's data directory.

## Sources

- MariaDB [TIMESTAMP behavior](https://mariadb.com/docs/server/reference/data-types/date-and-time-data-types/timestamp) and [system variables](https://mariadb.com/docs/server/server-management/variables-and-modes/server-system-variables): explicit timestamp session setting available in this 10.6 patch line.
- MariaDB [constraints](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint), [JSON](https://mariadb.com/docs/server/reference/data-types/string-data-types/json).
- Local locked Laravel connector/grammar, all migrations and raw SQL usages were inspected; no claim of hosting capability is inferred from generic framework support.
