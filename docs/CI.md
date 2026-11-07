# Continuous integration and protected main

`.github/workflows/ci.yml` runs once on each branch push, including feature branches
and `main`. There is no second pull-request event matrix or local pre-push suite.
Superseded in-progress revisions on the same branch are cancelled. Keep these job
names stable: they are the required GitHub checks. GitHub can evaluate pushed head
checks for PRs ([status-check documentation](https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks)).
Fork-only changes require verified checks in the existing review workflow before
merge; missing checks stay a blocker, never a skipped-check substitute.

| Check | Validation |
| --- | --- |
| PHP SQLite | PHP 8.4, locked Composer install/audit, Pint, full PHPUnit suite with in-memory SQLite, article upgrade/rollback/reapply preservation |
| PHP MySQL 8 | Full PHPUnit suite on a disposable MySQL 8 service, eight inventory, six financial and six article competing-connection scenarios, article upgrade preservation, complete migration rollback and reapply |
| PHP MariaDB 10.6.28 | Native production-version MariaDB, full PHPUnit/integration suite, competing-connection/financial/article scenarios, upgrade preservation and rollback/reapply |
| Frontend | Node 24, npm ci/audit, chat frontend functional/recovery regressions and Vite production build |

All three PHP jobs set testing environment variables before Composer hooks execute,
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

Work on a feature branch and open a pull request to `main`. All four checks must
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

No active local commit/pre-push hooks were installed at the workflow audit. Keep
it that way: no heavy automatic test/build/backup hook or new hook framework.
Owner local acceptance and minimal risk-specific checks belong to development;
the comprehensive matrix belongs to push. Real browser acceptance is manual or
explicitly scoped, not claimed from frontend unit tests. No new E2E stack added.
