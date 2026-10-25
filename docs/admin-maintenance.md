# Admin-controlled public maintenance — local implementation, not deployed

## Scope and authorization

The approved maintenance template, official logo, illustration, fonts, colors,
copy and telephone details are unchanged. The existing emergency endpoint and
Laravel deployment-maintenance mechanism remain available.

New permissions default to denied for staff:

- `maintenance.view`: status, private preview wrapper and its rendered page.
- `maintenance.manage`: enable/disable, also requiring `maintenance.view`.

The protected active super-admin retains both via existing native Gates. Only
the existing owner-controlled staff workflow can grant permissions. Every route
retains auth, current admin-session version, active-account and Gate checks.
Every mutation is a CSRF-protected POST with a required explicit confirmation,
current password and observed state version. Services lock/recheck the persisted
account and password before touching state. Revoked permissions take effect in
existing sessions. No secret URL, public cookie bypass or broad authenticated
visitor bypass is introduced.

## Routes

| Method | Route | Additional permission |
|---|---|---|
| GET/HEAD | /admin/maintenance | maintenance.view |
| GET/HEAD | /admin/maintenance/preview | maintenance.view |
| GET/HEAD | /admin/maintenance/preview/page | maintenance.view |
| POST | /admin/maintenance/enable | maintenance.view + maintenance.manage |
| POST | /admin/maintenance/disable | maintenance.view + maintenance.manage |

The early gate and Laravel middleware inspect the raw request-target using the
same rule: only origin-form targets starting with exactly one slash are eligible;
network-path (`//host/admin`), absolute-form, control characters, raw whitespace,
backslashes and fragments are rejected before extracting the path. Neither layer
URL-decodes or normalizes a target into an exemption.
The early gate lets only literal ASCII `/admin` path segments proceed to the
normal application routing. This is a bootstrap corridor, not an authorization
grant. Login still validates credentials and throttles attempts; all operational
admin routes still apply their existing session, permission and business checks.
Encoded slashes/dots, `..`, double slashes, `/administrator`, uppercase lookalikes,
query parameters and Laravel-maintenance cookies do not bypass public maintenance.

## State, audit and concurrency

The independent control lives under `storage/framework/site-maintenance/`, never
in the database or public webroot. Git ignores this directory. Its POSIX mode is
0700; `state.json`, `state.lock`, `audit.jsonl` and publication temporaries use
0600. The existing `storage/app/public` upload link does not expose this directory.

Readers read one bounded JSON snapshot. Writers take an exclusive `flock` with
a bounded wait, compare an opaque 256-bit observed version, prepare/fsync a new
same-directory file and replace the state using a single rename. No in-place
overwrite/unlink gap is used. A stale form returns 409 and must be refreshed.
Each committed update gets a new random version, including emergency recovery.

Enabling stores the exact fully rendered standalone HTML and its SHA256, so public
HTML and JSON/HEAD responses are handled before Composer, sessions, database or
application bootstrap. HTML is 503 with Retry-After 60, no-store/no-cache and
noindex; JSON receives a generic 503 payload and HEAD has no body. No payment or
customer payload is included. Corrupt, incomplete or unreadable state, or an
unreadable state parent, fails closed for public requests; the admin corridor
remains available if Laravel/authentication can boot.

State timestamps must round-trip through the exact stored ISO-8601 format with
no parser warnings. NUL bytes, invalid calendar dates/times/offsets and unexpected
types become `MaintenanceStateFault`, including parser exceptions. Public HTML,
JSON and HEAD remain fail-closed; the independent emergency disable can replace
this malformed state under the existing lock and audit procedure.

The authoritative filesystem audit records only actor ID (null for CLI), action,
target, UTC timestamp, previous/new version and prepared/applied phase. It never
records passwords, input arrays, HTML, keys, tokens, contact data or IP addresses.
A durable prepared event precedes atomic publication; its version can be compared
with state to distinguish an interrupted attempt from an applied change. A later
applied event completes the record. Partial audit tails block further mutations
instead of being silently appended over. The journal has an 8 MiB ceiling; archive
it under a reviewed operational procedure before it reaches that ceiling.

Successful changes also mirror to the existing append-only admin activity table.
The filesystem and MariaDB cannot share one atomic transaction: if DB commit or
audit completion fails after publication, the control explicitly reports that the
state was applied but audit reconciliation is pending. The durable filesystem
record and state version remain available. No blind compensation can overwrite
another administrator's later state. No automatic database transaction retry is
used around the filesystem transition.

No database outage prevents serving an already enabled public maintenance page.
A DB outage can prevent admin authentication; use the dedicated CLI recovery
below, rather than weaken authentication or create a public recovery endpoint.

## Private read-only preview

The admin wrapper clearly says this is a preview and does not change the site.
Its sandboxed iframe renders exactly the approved template. The inner response
is private/no-store/noindex, explicitly identified by
`X-NovinPay-Maintenance-Preview: true`, and has a restrictive CSP/sandbox with no
scripts, forms, external images/fonts, base URL or top-level navigation.

The two preview GET/HEAD routes read the existing session but do not save it,
renew its session cookie, or run session garbage collection. All other routes
delegate unchanged to Laravel's normal session lifecycle. Existing invalid-session
revocation and authorization still run; a rejected revoked session is never
granted access. Preview writes no maintenance state, business records or activity
events. It does not activate maintenance, post forms or initiate telephone calls.

Preview HTTP 200 is intentional and authenticated. It is never a public health
check or evidence that the application is available.

The iframe height is capped at 1000px and the viewport height (`100svh`) to avoid
an artificial tall viewport on mobile. It retains its sandbox and normal inner
scrolling for narrow/short screens; no script or content/design change is needed.
The administrator screen uses plain Persian; HTTP 503, worker behavior, the
separate `artisan down` fence and cPanel recovery details remain documented here.

## Actual payment, API and background inventory

Reviewed source: `routes/web.php`, `routes/console.php`, controller/service classes,
`FinanceInput`, Composer dependencies and dispatch/scheduling references.

- There are no gateway initiation, return/callback, verification, webhook or API
  routes, no provider integration and no `routes/api.php` in this source baseline.
- `FinanceInput` explicitly labels cash/transfer/terminal as manual internal
  recording. The existing `/admin/finance/*` routes require their original
  permissions/password/business checks; they are not public payment callbacks.
- There are no application-dispatched queue jobs or scheduled business tasks.
  Database queue configuration and the optional development worker exist, but
  configuration alone is not a running payment job.
- Therefore no hypothetical `/payment`, `/api`, `/callback` or `/webhook` wildcard
  is exempted. Tests assert the actual external-route inventory is empty and
  hypothetical callback requests receive 503 with no business mutations.
- Admin manual financial operations can continue with their existing authorization,
  transactional/idempotency rules. Regression tests replay the same expense
  operation while maintenance is enabled and assert exactly one financial entry.

This switch controls admission of new public application requests; it is not a
request drain, transaction cancellation or queue pause. A request admitted before
the atomic switch can finish. Existing database transactions and idempotency
remain in force, and no in-flight process is killed. No real online payment
scenario can be claimed tested because this repository has no online gateway.
Adding one later requires explicit callback authentication/signature, verification,
replay/idempotency, settlement and retry review before any maintenance exemption.

## Distinction from deployment maintenance

Laravel's existing `artisan down`/pre-rendered response retains priority and its
markers are not managed by the panel. If CLI deployment maintenance is active,
it can still block admin bootstrap. Neither the panel nor the emergency helper
executes `artisan up`, deletes `down`/`maintenance.php` or weakens a deployment fence.

Normal assets are served by the web server so the admin UI can load; static asset
availability grants no access to application records. The independent
`public/maintenance.php` endpoint always returns 503, even while the control is off.

## Emergency cPanel Terminal runbook — not executed on production

Only use these after the code has been separately reviewed and deployed, from
the authorized hosting account, keeping the permanent path and public_html link.

Read-only status (no Laravel, Composer, `.env` or DB required):

```bash
/opt/cpanel/ea-php84/root/usr/bin/php /home/novinpay/novinpay/scripts/maintenance-control.php status
```

Explicitly disable **only the admin-controlled mode**:

```bash
/opt/cpanel/ea-php84/root/usr/bin/php /home/novinpay/novinpay/scripts/maintenance-control.php disable --confirm-disable
```

The helper serializes with writers, durably audits, atomically writes a fresh
disabled version, and can replace corrupt state without reading credentials.
Missing confirmation is rejected. If a separate Laravel `down` marker exists,
the output explicitly says deployment maintenance remains active. Do not delete
that marker or run `artisan up` as an automatic follow-up.

If the helper stops for storage permissions, quota, lock contention, an incomplete
audit or a full audit file, do not retry blindly or unlink state/lock files.
Preserve the directory and hashes; inspect owner/free quota and ensure no active
writer. If a partial/full journal must be retired, securely archive the exact
private journal first with verified hash and restrictive permissions, then perform
a separately reviewed rename/recovery. Never truncate old audit records to clear
an incident. The helper does not silently repair those conditions.

After a confirmed disable, independently verify status, public HTTPS, admin access,
and the unchanged source/DB/environment/symlink. No restore, migration, seed, key
generation, cache reset, provisioning or dependency update is part of this runbook.

## Local evidence and remaining deployment gates

Tests cover active-owner/staff/default-deny/revocation, CSRF, password/confirmation,
CAS and two competing native PHP processes, interrupted publication/audit, corrupt
and malformed state, emergency recovery without dependencies, early 503 HTML/JSON,
admin login/disable, private preview isolation including DB-session write/GC checks,
and existing manual-finance idempotency. Old standalone maintenance tests remain.

Screenshots use HTML returned by actual authenticated controllers with an isolated
in-memory SQLite database and synthetic administrator. The visual fixture embeds
the exact private preview response as iframe srcdoc, so no browser login session,
real credentials or bypass route is exported. Authorization is verified by HTTP
tests independently. Temporary public visual fixture files are removed after QA.

Before any future production activation, verify POSIX ownership/modes, local-disk
`flock`, atomic replacement/fsync behavior and quota on the actual host; these were
tested locally, not claimed proven on cPanel. Existing PHP/LiteSpeed and public_html
configuration must remain intact. No new migration or DB grant is needed. Refresh
route/view caches through a newly reviewed deployment procedure because routes and
middleware changed; the old five-file Phase 8 updater is not applicable unchanged.

Persist/backup the private state/audit directory across code updates. Before a
rollback to code that predates this feature, establish a separate reviewed
deployment maintenance fence: old code will not honor the new flag, and old
AdminAction enums may not understand new activity values. Do not overwrite the
live DB or discard audit records to make a rollback appear clean.
