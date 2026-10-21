# Continuous integration and protected main

`.github/workflows/ci.yml` runs for every pull request targeting `main` and every
push to `main`. Keep these job names stable: they are the required GitHub checks.

| Check | Validation |
| --- | --- |
| PHP SQLite | PHP 8.4, locked Composer install/audit, Pint, full PHPUnit suite with in-memory SQLite |
| PHP MySQL 8 | Full PHPUnit suite on a disposable MySQL 8 service, eight inventory and six financial competing-connection scenarios, complete migration rollback and reapply |
| Frontend | Node 24 (within locked Vite engine requirements), npm ci, npm audit, Vite production build |

Both PHP jobs set testing environment variables before Composer hooks execute,
use array sessions/cache and a randomly generated masked application key, and
avoid cached application configuration. No owner environment, database, key,
configuration or data is copied into CI. Each runner creates an empty `.env` to
avoid missing-file warnings; all testing settings come from explicit variables.
Both HTTP suites build Vite assets before rendering pages and fail on warnings.
No hosting credentials or GitHub repository secrets are required. MySQL credentials
in the workflow are public disposable service credentials, not deployment values.
The concurrency harness independently rejects non-disposable database targets.
Migration rollback is destructive only to that job's synthetic service data.
The job enables trigger creation on its own disposable MySQL service; deployment
still requires a separate provider/privilege preflight. No production setting changes.

Actions are pinned to full commit SHAs. Checkout does not retain credentials;
the workflow token has only `contents: read`. Composer download archives and npm's
download cache are cached; databases, vendor directories, node_modules and
application data are not. No logs, fixtures or customer-data artifacts are uploaded.
This workflow performs no deployment. Audit failures require review, not automatic
dependency updates. Review and refresh action pins separately when needed.

## Integration procedure

Work on a feature branch and open a pull request to `main`. All three checks must
pass on the current change, the branch must be up to date, and review conversations
must be resolved. No external reviewer is mandatory for this sole-owner repository.
Merge through GitHub, then fetch and fast-forward local `main` without discarding
unrelated files. Confirm the subsequent `main` CI run succeeds. Direct pushes,
force pushes and deletion of `main` are restricted by the repository ruleset;
there is no administrator bypass entry. Ruleset settings are managed in GitHub,
not by this workflow. Verify their active state after administrative changes.

CI validates disposable databases; it does not approve a production migration.
Deployment still requires owner authorization, backups, restore planning, preserved
APP_KEY, and review of pending migrations and their data-loss implications.
