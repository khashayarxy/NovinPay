# Existing production upgrade: evidence and remaining checks

As of 2026-10-23. This is a release gate, not deployment authorization. No production files, records, schema, settings or privileges were changed. Preserve the existing environment, APP_KEY, users, uploads and data. The release/backup/rollback sequence is in [MARIADB-COMPATIBILITY.md](MARIADB-COMPATIBILITY.md).

## Independently verified through read-only cPanel UAPI

| Item | Evidence and limit |
| --- | --- |
| Database | `Mysql/get_server_information`: `10.6.28-MariaDB`, local to the hosting server. |
| Target DB | `novinpay_DB` exists; reported usage 376,832 bytes; one mapped database account. No customer rows read. |
| Privileges | That mapped account reports schema-level `ALL PRIVILEGES`, which includes TRIGGER. The app's effective connection account and global/binlog policy are not established by this account-management response. No passwords or authentication hashes fetched. |
| PHP | Domain assignment `ea-php84`; handler `suphp`. These are configuration evidence, not independent proof of effective web PHP patch/extensions. |
| Public root | Configured `/home/novinpay/public_html`; metadata confirms type `link`. The API does not expose its resolved destination. |
| App paths | `/home/novinpay/novinpay` and `public` exist, mode 0755. `storage` and `bootstrap/cache` exist, mode 0775, readable/writable by account. This is not a filesystem write test under the web identity. |
| Public storage | `public/storage` exists, type `link`; target still needs resolution. Symlink mode 0777 describes the link, not its target's directory permissions. |
| Quota | Account limit 1,024 MB; used 59.95 MB; remaining 964.05 MB at inspection. This is quota headroom, not physical disk free space or proof that a full backup fits. |
| Schema API | Supported `Mysql/dump_database_schema` succeeded but returned only 1,383 characters with no table definitions. Encrypted outside Git/public directories. It cannot establish table/constraint/trigger compatibility. |

Owner-reported, still not independently rechecked: CLI PHP 8.4.26, default PHP 8.1.34, Laravel 13.35.0, clean production main, seven batch-1 migrations, exact public-root symlink target. Effective engines, SQL modes, trigger grants as seen by the actual deployment connection and binary logging remain pending.

The cPanel API specification offers database/account management and database-level schema DDL, not a general SQL-query or arbitrary-command execution endpoint. No terminal emulation, phpinfo file, web shell, full-backup API or production script upload was used. Read-only GET is not assumed merely from HTTP method: only documented read-only operations were allowlisted.

## Owner-run minimum checks in cPanel Terminal

These commands are provided for owner execution. They do not migrate or change application configuration. The export creates a private audit file outside the webroot/application; do not upload it to Git or chat. If the actual application DB username is uncertain, check it privately first; do not send the password or `.env`.

### Filesystem and CLI metadata

```bash
readlink -f /home/novinpay/public_html
readlink -f /home/novinpay/novinpay/public/storage
stat -c '%a %U:%G %n' /home/novinpay/novinpay/storage /home/novinpay/novinpay/bootstrap/cache
df -Pk /home/novinpay/novinpay
git --no-optional-locks -C /home/novinpay/novinpay status --short
git -C /home/novinpay/novinpay rev-parse HEAD
/opt/cpanel/ea-php84/root/usr/bin/php --version
/opt/cpanel/ea-php84/root/usr/bin/php -r 'echo implode("\n", get_loaded_extensions()), "\n";'
```

CLI extensions do not verify the web SAPI. Request a private, read-only effective-domain runtime report from the provider: PHP version/SAPI and required extensions, especially PDO MySQL, mbstring, openssl, tokenizer, XML/DOM, ctype, fileinfo, bcmath and the locked platform requirements. Do not create a public phpinfo/probe file or change the PHP handler for this check.

### Actual SQL capabilities and complete table/trigger schema

Use the actual deployment/application DB account, not a more privileged phpMyAdmin session. Passwords are entered only at the client's hidden prompt. The queries return metadata and migration names, not customer/password/session rows. No SHOW GRANTS output is requested because MariaDB may include authentication metadata in it.

```bash
(
  set -e
  umask 077
  audit_dir="$HOME/novinpay-private-audit"
  mkdir -p "$audit_dir"
  chmod 700 "$audit_dir"
  sql_bin="$(command -v mariadb || command -v mysql)"
  dump_bin="$(command -v mariadb-dump || command -v mysqldump)"
  test -n "$sql_bin" && test -n "$dump_bin"
  read -r -p 'Actual application/deployment DB username: ' np_db_user
  "$sql_bin" --user="$np_db_user" --password --database=novinpay_DB --batch <<'SQL'
SELECT VERSION() AS server_version, @@session.sql_mode AS sql_mode, @@session.time_zone AS time_zone, @@session.explicit_defaults_for_timestamp AS explicit_dates, @@session.check_constraint_checks AS enforced_checks, @@session.foreign_key_checks AS enforced_fks, @@default_storage_engine AS default_engine;
SELECT @@global.log_bin AS binary_logging, @@global.log_bin_trust_function_creators AS trusted_trigger_creators;
SELECT ENGINE, SUPPORT, TRANSACTIONS FROM information_schema.ENGINES WHERE ENGINE='InnoDB';
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;
SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE REPLACE(REPLACE(TABLE_SCHEMA, CHAR(92,95), '_'), CHAR(92,37), '%')=DATABASE() AND GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))) ORDER BY PRIVILEGE_TYPE;
SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))) ORDER BY PRIVILEGE_TYPE;
SELECT TABLE_NAME, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE TABLE_SCHEMA=DATABASE() AND GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))) ORDER BY TABLE_NAME, PRIVILEGE_TYPE;
SELECT migration, batch FROM migrations ORDER BY id;
SQL
  schema_file="$(mktemp "$audit_dir/production-schema.XXXXXX.sql")"
  "$dump_bin" --user="$np_db_user" --password \
    --no-data --triggers --skip-comments --skip-lock-tables \
    --skip-add-locks --skip-add-drop-table --no-tablespaces \
    novinpay_DB > "$schema_file"
  chmod 600 "$schema_file"
  sha256sum "$schema_file"
)
```

The schema-privilege filter handles escaped underscores/percent signs in MariaDB's literal schema grants; it was checked using the disposable 10.6.28 server. Role-derived and wildcard/global access still need careful interpretation; empty metadata does not by itself prove lack of access. This is schema-only, not a recoverable full backup. Defaults, view bodies, trigger bodies and DEFINER metadata must be reviewed privately before any sharing. Do not suppress existing triggers in the actual comparison. If access is denied, stop and report the specific metadata check to the provider; do not grant privileges, change binary-log settings or use a privileged workaround. No schema import/upgrade rehearsal is considered verified until this export is reviewed and reproduced in a disposable environment. Restored dump statements, definers and database names must be inspected before execution; never point the replay at `novinpay_DB`.

## Release decision

**NO-GO for production.** Local synthetic-baseline tests and the existing four green CI jobs establish code compatibility under tested conditions, not live-schema equivalence or restore readiness. Do not repeat those full suites merely for these documentation changes; run the actual-schema upgrade/rollback/invariant checks once the export and equivalent staging privileges are available.

Required next evidence: actual table/trigger DDL and applied migration inventory; resolved symlink targets; effective web runtime/platform checks; actual-account grants and binary-log policy; provider-matched isolated staging; verified full backup and restoration rehearsal. Preserve all triggers and history. Financial/article destructive down migrations are not the default live rollback plan. Protected merge and deployment each require separate approval.

Sources: [cPanel MySQL metadata](https://api.docs.cpanel.net/specifications/cpanel.openapi/database-information/mysql-get_server_information), [database privileges](https://api.docs.cpanel.net/specifications/cpanel.openapi/user-management/mysql-get_privileges_on_database), [schema API](https://api.docs.cpanel.net/specifications/cpanel.openapi/database-management/mysql-dump_database_schema), [file metadata](https://api.docs.cpanel.net/specifications/cpanel.openapi/manage-files/fileman-get_file_information).
