# NovinPay — verified release and deployment gates

This is a preparation workflow, not permission to deploy. No hosting connection, production migration, key generation, account provisioning or deployment is performed by CI or `scripts/prepare-release.ps1`. Live chat, SMS, online payments and eNAMAD remain deferred. Owner approval is required for the exact production change and window.

## 1. Approve source and release identity

Use feature branch → isolated SQLite/MySQL tests → desktop/mobile browser QA → Draft PR → PHP SQLite, PHP MySQL 8 and Frontend CI → owner review → protected merge. Never bypass protection. Record the final protected main SHA and post-merge CI run. Prepare the eventual production artifact from that approved main SHA; a feature-branch artifact is a rehearsal only.

Run `npm run build` using the existing locked dependency installation. From a clean reviewed checkout, run PowerShell `./scripts/prepare-release.ps1 -OutputDirectory ABSOLUTE_PRIVATE_DIRECTORY_OUTSIDE_REPOSITORY`. It packages committed source and only files referenced by the Vite manifest, excludes runtime data/environment/deployment ZIPs, records the source SHA and emits an archive hash. It never installs packages, boots Laravel, uploads or deploys. Inspect release-metadata.json and archive contents. Do not distribute an artifact containing unintended files. No secrets, database, uploaded customer files or historical recovery archive belong in the release.

The archive deliberately excludes vendor and node_modules. On a separate deployment build/staging host, install exactly composer.lock with PHP compatible with the lock; do not perform dependency upgrades. Use `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts` before loading the verified target environment. Package discovery runs only after target environment and database guards are reviewed. Do not use composer setup/create-project scripts on an existing installation: they include key/migration operations.

## 2. Hosting preflight — stop on any mismatch

- PHP **8.4.1+** matching composer.lock for CLI and web, required PHP extensions, locked Composer platform checks, HTTPS, and a document root pointing strictly to `public/`. Keep source, .env, vendor, storage/private and Git outside the web root.
- **MySQL 8.0.16+**, InnoDB, utf8mb4 and enforced CHECK/FK constraints. MariaDB and MySQL 5.7 are not supported by the financial/article migrations. The old deployment instructions permitting these engines are superseded.
- Provider-approved migration account with table/index/foreign-key privileges and direct target-scope **TRIGGER** authority; verify the existing binary-log policy. With logging enabled and trust disabled, the migration also conservatively verifies global SUPER. Role-only/wildcard grants may be refused; see docs/PHASE7A.md. Do not automatically grant broad rights or change global server settings to make migration pass.
- Verify that trigger definers remain valid and runtime accounts can perform permitted operations without altering/dropping audit/history triggers. Rehearse the exact schema upgrade, immutable-row refusal and backup restoration in provider-matched isolated staging using the intended deployment account.
- Confirm domain/document root, CLI access, private persistent storage, backup destination/retention, rollback decision owner and maintenance window. Hosting access/credentials are not assumed or tested by local validation.

## 3. Preserve environment and rehearse data migration

Obtain a consistent database backup, uploaded media and secure environment backup with hash verification and a successful restore rehearsal. Preserve the exact existing **APP_KEY**, owner identity/password and encrypted customer data. Never publish environment values. Do not copy Herd overrides, synthetic QA data, local SQLite, archived legacy .env or recovery credentials into production.

Identify whether the live installation already has an owner and which migrations it has applied. Select the exact target database explicitly, confirm host/database identity without exposing credentials, inspect migration status and compare the pending migrations to the approved source. Do not assume the old site's environment or database engine is compatible. Missing/invalid APP_KEY is a stop condition on an existing installation; key regeneration is not a repair.

All rehearsals use disposable staging copies with external delivery disabled. Never run migrate:fresh, reset, generic setup, sample seeders or blanket rollback against existing owner data. MySQL DDL is not fully transactional: on any failure, keep maintenance mode, inspect tables and migration records, then use the reviewed recovery plan. Never serve writes against a partial schema or mark a migration complete while an immutability trigger is missing.

## 4. Production execution — requires separate explicit owner authorization

After all prior gates and a reviewed exact command plan: enter host maintenance, install the approved release into an isolated release directory, bind reviewed persistent environment/uploads, verify target configuration, run only pending forward migrations, and inspect schema/trigger status. Keep configuration cache unique to the release and rebuild it only after target verification. Use the host's reviewed mechanism for activating the release; do not overwrite unrelated hosting files.

Migration does not grant administrators automatically. For the existing verified owner, first run `php artisan admin:bootstrap VERIFIED_EXISTING_USER_ID` as the documented identity-only dry run. Apply only if needed and separately reviewed with `--apply`; preserve credentials and refuse reassignment. See docs/PHASE5.md. Do not reseed/reset an existing owner.

Before reopening traffic, verify HTTPS/cookies, health/public navigation, genuine published catalog, a controlled repair/consultation flow, owner sign-in, ordinary-user denial, staff least privilege, privacy boundaries, existing operational records and immutable logs/history. Keep external integrations deferred. Remove synthetic records only through an approved staging/test workflow; they must not have entered production.

## 5. Rollback and recovery

Retain the previous code/assets and verified backups. Reverting code alone can be incompatible with a migrated database or the newer authorization model. Financial, administration and article rollbacks destroy history. Do not automatically execute migration rollback. Decide between a compatible code rollback and an explicit maintenance/restore operation using the rehearsed plan; preserve new business writes and encryption keys. Record outcome and owner approval without credentials or customer payloads.

## Outstanding owner decisions before deployment

Hosting capability and provider-account preflight, production environment/database identity, backup/restore rehearsal, exact release/main SHA, original product-photo usage rights or owner photographs, genuine stock/prices, and the maintenance/recovery window require verification. Local tests and green CI do not resolve these provider/data decisions. No cPanel API token, historical password, production host assumption or automatic deploy action is embedded here.
