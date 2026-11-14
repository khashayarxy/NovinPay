## Phase 4 operational checkpoint — 2026-11-14 (development only)

Continue from `4ef444b`. Production was independently read back as `ecf6721`, with
no Laravel maintenance markers. No production deployment, migration, configuration,
provider operation or new recovery cycle occurred. Keep PR #22 Draft/unmerged.

### Confirmed defects corrected during real browser acceptance

- **CART-04, release blocker:** the first customer checkout returned HTTP 429 after
  ordinary background chat requests. Laravel's numeric throttle uses the same
  user/domain-IP key without a prefix, so chat's 600/minute counter exhausted the
  checkout's 10/minute budget. Regressions first failed, then separate prefixes
  isolated chat, shared add/remove cart mutations and checkout. Limits remain
  600/30/10; checkout locking, idempotency and OTP limits are unchanged. The same
  preserved browser cart then created exactly one successful order.
- **CATALOG-04, important:** clearing publication/sample checkboxes and failing
  another field restored the previously checked values. Submit explicit hidden
  zero values before the native checkboxes. A failing regression and actual browser
  retry now preserve the unchecked states; unrelated validation leaves stored
  records unchanged. Creation defaults are preserved.
- **HOST-04, release compatibility:** current production root `.htaccess` contains
  its hosting PHP handler and differs from Git. Move only the new immutable-cache
  rule into generated `public/build/assets/.htaccess`, published by the existing
  asset builder. The candidate root file is now byte-identical to the Git baseline;
  its production customizations must remain untouched. Tests verify build publication
  and exclusion of HTML, private, manifest, unversioned and chat paths.

### Operational evidence and boundaries

| Path | Actual acceptance | Limitation |
| --- | --- | --- |
| Admin | Native password login, persistent module navigation, logout and protected-route redirect. A native limited-staff login permits product listing but returns 403 for creation, finance, staff and repairs. Invalid financial reauthentication shows a validation error; correct password records the isolated receipt. | Expiry/replay and detailed permission matrix retain prior automated coverage, not a newly claimed physical-device acceptance. |
| Customer | Native phone/Enter/password login and explicit logout; account access then redirects to the initial phone screen. No SMS sent. | Local real SMS is deliberately disabled; starting the SMS step reports unavailable before using the existing password link. |
| Full repair | Browser-submitted request; browser assignment, diagnosis/estimate, recorded approval, repair/completion and password-protected pickup. Unpaid handover refused with no delivery row. Final delivered state, one handover and two balanced ledger entries. Account history and public tracking agree without exposing name/private issue. | Legacy full intake, reviewed CRM association, genuine synthetic ledger settlement and pickup-plan preparation used existing services in the isolated fixture. Do not call all ten steps browser-driven. |
| Simple repair | Native intake, repairing/ready, Persian 25,000-toman input, delivery-method edits, settlement-method selection and tracked Tipax handover. Unpaid handover refused; tracking is required. Final delivered state, one handover, two balanced ledger entries and carrier-dispatch timeline. | Reviewed CRM association and synthetic charge/receipt used existing services. No carrier or payment provider contacted. |
| Store | Native product create/edit, price validation, publication, inventory definition/receipt, customer product/cart/checkout, staff reservation/confirmation, financial issue/receipt and final fulfillment. Exactly one order; charged=paid, two ledger entries; final stock 1 physical / 0 reserved / 1 dispatched. Customer history accessible. Unpublication gives public 404. | Pickup plan used existing services. Browser image upload was explicitly denied by the tool's configured workspace-root check; no alternate tool/path/upload mechanism used. Image/MIME/storage regressions passed through the isolated HTTP suite. |
| Responsive/admin JS | Dashboard, product edit, repair record, customer repair history, product detail, cart and tracking checked at 320/390/768/1440: RTL and no document overflow. Admin chunk loads; native calendar/money initialization works, empty cost stays empty, 25,000 remains unchanged after unrelated edits. | Screenshot export restriction respected. Screenshots and owner visual approval remain separate. Phase 3 unchanged map/hero/font checks are reused. |

Local Herd identity was checked before writes: private dedicated SQLite acceptance
database, APP_ENV local, SMS and Zibal disabled, existing private owner password valid.
Its missing existing nullable-column migration was applied locally only, once, after
an isolated SQLite checkpoint; hashes of every pre-existing business table's original
columns/rows matched afterwards. Local inventory is now 24 migrations and 29 SQLite
triggers (engine-specific; not the MariaDB 13-trigger contract). No shared Herd guard,
production schema or original credentials changed. New synthetic records/credentials
stay private and local; the acceptance product was unpublished afterwards.

CSRF distinction: real local non-browser POST with no token/fetch metadata returned
419. Same-origin secure browser fetch without a token reached validation (422), as
Laravel 13 intentionally accepts trusted Sec-Fetch-Site; this is not evidence of a
CSRF bypass. No exemptions or protection changes were introduced.

Read-only cPanel evidence: production revision `ecf6721`, maintenance markers absent,
public_html remains a link, 4,726.44 MiB quota headroom and quota/inode status flags
within limits. Zibal live requests and both scheduled-provider switches remained
false. Current live SQL, effective CLI/web PHP patch/extensions, symlink resolution,
web-identity permission writes and new LiteSpeed headers still require the authorized
release preflight; prior 24/13 SQL/restore evidence is not described as a new live query.

### Release decision

Application acceptance defects above are corrected. **NOT READY for production
activation:** the current `activate-source.*` contract explicitly rejects this
candidate's Composer change and accepts only a two-member CSS/manifest ZIP; its
application mode requires a new migration although this release has none. Do not
invoke it with a renamed manifest or bypass its rejection. A narrowly reviewed
vendor/multiple-asset/no-migration extension or conventional compatible procedure
must be qualified before an owner activation command can safely be issued. This is
a tooling blocker, not permission to deploy, start another backup or use PR13 tools.
See the concrete package/release checklist in [DEPLOYMENT.md](DEPLOYMENT.md).

Owner acceptance still needed: image upload through the native file picker; visual
review of dashboard/product/repair at mobile and desktop; service-availability behavior
of Neshan; then approval of the exact release SHA and compatible activation procedure.
Aggregate SMS budget, MFA/recovery and scheduled notifications remain separate policy
decisions; optional scanners/AI metadata are not promoted to release blockers.

## Phase 3 development checkpoint — 2026-11-14 (not deployed)

Continue from `8f30f82`; preserve Phase 2 and the `ecf6721` production baseline.
No migration, provider operation, production change, backup cycle or merge in this phase.
PR #22 stays Draft. Exact-head CI is recorded in the PR, separately from local evidence.

| Area | Implemented and verified locally | Remaining qualification |
| --- | --- | --- |
| Neshan | Existing approved iframe URL now stays in inert configuration until the native activation button is used. No SDK/key/static-map API or tile copying. Noninteractive branded/location-pin preview, keyboard activation, fixed 260px/240px frame, once-only initialization, timeout/error retry and permanent external fallback. | A cross-origin `load` event does not establish usable provider content. Real provider/device availability remains external; simulated failure/retry and actual local keyboard activation pass. |
| Hero | Add AVIF sources from the unchanged 853×933 owner artwork; retain original WebP sources/fallback, sizes, eager high priority and intrinsic ratio. Decode/review the generated image. | Physical-device visual acceptance; no claim that conversion is pixel-identical. |
| Fonts/JS | Same licensed Vazirmatn variable face; static weight fallbacks retained for older browsers. Lazy-load calendar/numeric/repair-admin initialization only in the admin shell. | Authenticated admin browser acceptance; unit regressions retained. |
| Cache | Immutable rule matches only content-hashed `/build/assets/*.css`, JS and WOFF2; regression rejects private/unversioned/HTML/manifest/chat paths. HTML/private cache policies unchanged. | Herd uses Nginx, not hosting LiteSpeed: effective `.htaccess` response headers require a separately authorized release verification. |
| Browser security | HTTPS-only host-only HSTS `max-age=300`, no preload/includeSubDomains. Existing stronger headers preserved. Enforce object/base/frame restrictions without replacing endpoint-specific CSP. Permissions restrict unused device/payment capabilities, retain same-origin camera/microphone and WebOTP. | Full resource CSP is report-only, not an enforced XSS protection claim. No sensitive reporting endpoint or external telemetry. |
| SEO | Reuse Phase 2 canonical/sitemap. Add contact canonical, public-only Open Graph/Twitter metadata and JSON-LD Organization/Service, published Product/BreadcrumbList and current published Article. Encode untrusted text safely. Exclude sample/private/draft/query variants. | No fabricated offers, ISO currency conversions, stock, ratings, reviews, authors, service areas or hours. Product data without offers is not a rich-result eligibility promise. |
| Discovery | Optional `/llms.txt` points only to five real public destinations using configured origin; robots crawler policy unchanged. Sitemap/llms no longer create session cookies (confirmed before/after). Public content remains server-rendered. | This is navigation, not an official crawler requirement or ranking/recommendation guarantee. |

### Resource and performance evidence

Exact encoded image sizes (bytes), generated with the installed PHP 8.4 GD encoder:

| Width | Existing WebP (retained) | WebP quality 85 candidate (not shipped) | AVIF quality 80 (shipped) |
| --- | ---: | ---: | ---: |
| 400 | 67,464 | 14,270 | 14,679 |
| 640 | 151,594 | 24,940 | 25,148 |
| 853 | 266,674 | 34,282 | 36,489 |

AVIF retained alpha and had lower sampled RGBA error than the similarly sized WebP
candidate; decoded artwork was inspected. The original WebP remains the compatibility
fallback. The browser selected the 14,679-byte AVIF at the current 250px mobile and
400px desktop display sizes. Do not compare against a hypothetical full-size mobile download.

One actual pre-change local page observation loaded Neshan (2,158ms resource duration;
cross-origin transfer size unavailable) and four font files totalling 203,856 bytes.
After: zero Neshan requests until activation; one 111,152-byte font. Before/after admin
splitting, the map-enabled public JS build fell from 13.94kB to 8.10kB uncompressed;
the 7.17kB admin chunk was not requested on the public homepage. No GA/GTM initializer
or tracking ID was found in scoped application assets/rendered dependencies. The
provider's iframe dependencies are external; do not strip analytics inside its document.

Three **after-change local** Chromium runs: 390×844, DPR1, no CPU/network throttling,
cache-bypassing reload, warmed Herd PHP/site, metrics read 500ms after navigation:

| Metric (ms unless stated) | Runs | Median |
| --- | --- | ---: |
| TTFB | 130.3 / 189.0 / 66.3 | 130.3 |
| LCP | 200 / 272 / 148 | 200 |
| Load event | 677.1 / 759.7 / 770.1 | 759.7 |
| Observed long-task excess above 50ms | 104 / 147 / 132 | 132 |
| CLS | 0 / 0 / 0 | 0 |

These are not Lighthouse TBT/scores or production benchmarks. The initial cold
pre-change TTFB was 4,078ms, but not a comparable three-run baseline; no TTFB/LCP
speedup is claimed. Herd injects its own local debug assets, so hosting/network and
application timing cannot be inferred from that cold sample. No hosting settings changed.

Local browser checks: homepage 320/390/768/1440 has no horizontal overflow, RTL and
responsive artwork preserved; map height unchanged on activation, visible keyboard
focus/Enter activation, one chat loader, launcher present, no broken local images.
Read-only GETs for home/catalogue/contact/login/repair/cart/admin-login/support and
crawler documents succeeded. No authenticated submissions, messaging or physical-device
acceptance is claimed. Prior screenshot-export restrictions were respected; no alternate
export path/tool was used. Visual screenshot comparison remains pending.

Regression commands: guarded `scripts/test.ps1` filtered to HomePage,
PublicPerformanceSeo, SecurityHeaders, PublicArticles and ProductCatalog; native
Chromium click-map test; public asset-cache matcher; existing admin-field/money tests.
The single standard CI workflow remains responsible for the complete four-job matrix.

Content outlines for owner review only (not published): repair intake/diagnosis/approval
steps; factors affecting a repair estimate; device-specific symptoms and safe preparation
before sending a terminal. Require a technician's verified facts/model details and
approved service terms; do not manufacture turnaround promises or keyword landing pages.

References: [CSP rollout](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP),
[HSTS](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Strict-Transport-Security),
[Organization](https://schema.org/Organization), [Service](https://schema.org/Service),
[optional llms.txt proposal](https://llmstxt.org/).

## Hardening checkpoint — 2026-11-14

Production remains `ecf672110214a24a9171d942a2730d9bfad5f65c`, with
`APPLICATION_RELEASE_PASS`. The release gates checked 24 exact migrations and 13
trigger contracts; the follow-up readback matched 55 changed source files and both
asset checksums. Seven public HTTPS routes returned 200 with valid TLS and maintenance
was OFF. Fresh recovery subsequently checked the exact 24/13 source inventory and an isolated restore; a separate read of the captured production SQL confirmed the three nullable payment-review columns.
IPPanel remains active; Zibal, scheduled provider processing and SMS.ir sending stay
disabled. No real SMS/payment, new production product or deployment is authorized here.

### Confirmed development corrections (not deployed)

- **OWN-01, medium, conditional cross-account disclosure:** a staff draft edit could
  change an online order's CRM customer while retaining the original account. The
  account controller's OR scope then returned explicitly owned records to a different
  CRM-linked account. Reproduced as HTTP 200 where 404 was required. The service now
  refuses reassignment; the shared order/repair/request scope falls back to CRM only
  when no explicit account exists. Original-account access, same-customer draft edits,
  legacy CRM-only history, immutable events and normal profile flows are retained.
- **STORE-01, reliability:** catalogue validation accepted prices outside the existing
  ten-digit order-money range. New/edited prices now share that bound; legacy stored
  prices remain unchanged and cannot be offered for checkout when unrepresentable.
- **PHP CLI startup:** the private release wrapper uses invocation-level
  `session.gc_divisor=100`. A real subprocess regression reproduces the invalid host
  setting without altering global/web INI. Hosted effective INI origin and web-SAPI
  configuration remain unverified; see the deployment lessons.
- **DEP-01, low, GHSA-63hm-g96r-2m2q:** exact-head CI surfaced a newly reported
  `laravel/framework` advisory. The lock now updates only that package from 13.35.0
  to the minimum patched 13.40.0; `composer audit --locked` reports zero advisories.
  The documented exploit requires user-controlled task stdout through Laravel's
  Concurrency facade; no such application caller was found. This is a confirmed
  affected dependency, not proof of exploitation. Production is still unpatched.

### Gates and remaining work

| Area | Status | Evidence / next step |
| --- | --- | --- |
| Security hardening | PARTIAL | Ownership regression fixed; required exact-head CI and remaining scoped security checks remain separate gates. No claim of an exhaustive clean audit. |
| Repair lifecycle | PARTIAL | Full/simple workflow regression tests pass locally, including protected history, settlement and handover; final operational/browser acceptance remains. |
| Store lifecycle | PARTIAL | Existing isolated checkout, manual settlement, stock reservation and delivery tests pass; real catalogue/operator acceptance remains. Zibal and unverified billed postal processing stay unavailable. |
| UX / SEO | PARTIAL | Existing catalogue/metadata regression checks pass; no redesign or new visual acceptance claimed. |
| End-to-end | PARTIAL | Isolated HTTP/service lifecycle coverage reused; no real-provider or new browser acceptance claimed. |
| Next production changes | BLOCKED | Development changes require separate deployment authorization. Fresh 24/13 recovery has passed; do not repeat it for ordinary development. |

The older encrypted recovery generation was independently verified at 23/13; it is
preserved and is not a post-migration recovery point. Owner-approved isolated target
`novinpay_restoreecf1` now exists with only SELECT, INSERT, CREATE and TRIGGER for the
existing migrator, exclusively on that schema. The fresh post-deployment generation completed `DATABASE_RECOVERY_PASS` with
24 exact migrations / 13 triggers, matching schema, definers and every restored row.
The encrypted off-host archive passed SHA-256, authenticated decryption and full
archive validation. Snapshot time: 2026-11-14 00:18 Asia/Tehran; archive SHA-256:
`c12b8ef38e9bd2183e28d289e32d276a6b65b39a4e713ba509980567d579e336`.
The restore target is now occupied evidence, not an empty destination. Preserve it,
all prior snapshots and the private completion receipts. The existing coordinator
exited successfully. No further backup/restore/provisioning is needed during this
development phase; apply the owner-approved 72-hour policy only for a future actual
activation, together with the unchanged integrity/compatibility gates.

After the framework update, 186 focused tests / 1,618 assertions passed in the
isolated runner (accounts, checkout/settlement, products, inventory, full/simple
repairs and release contracts). These are local results. The four required CI
jobs must pass on the final pushed head before any release is proposed.


### Phase 2: complete business paths and additional scoped corrections

These changes remain development-only; production stays at `ecf6721`. No new
migration, dependency, background service, cron, provider activation or backup cycle.

| Finding | Evidence and correction | Regression |
| --- | --- | --- |
| CART-02, reliability | A repeated successful checkout returned 422 after its cart was cleared. Resolve the existing operation only after fresh account/session authorization; reject an unknown empty checkout or conflicting cart. A replay never clears a newly assembled cart. Serialize cart mutations with the existing Laravel session lock. | `SharedPaymentsTest`: lost response, empty/identical/different new cart, cross-account key and revoked session. |
| SEC-02, medium | The full-workflow handover endpoint bypassed the password/finance checks already required by the simple and delivery-plan paths. Reproduce unauthorized completion, then apply the existing actor reauthentication inside the transaction for account-linked financial repairs. Forward the password from the existing dispatch path; never record it. Legacy non-account handover is unchanged. | `RepairOperationsTest`: missing/wrong password, insufficient permissions, unchanged case/plan on denial, successful authorized pickup/shipping and private password input. |
| DEL-02, integrity | The full-workflow endpoint could mark carrier handover without tracking. Require the existing 3-200 character tracking contract before completing a non-pickup delivery plan. | Full repair lifecycle rejects direct untracked shipment and completes through the authorized dispatch path. |
| TRACK-02, accuracy/privacy | Public tracking reported a carrier dispatch as final handover. After code+phone verification, select only the delivery state; reuse the customer timeline. Walk-in simple shipping also uses the recorded shipping method after closure. Never retrieve addresses, recipient or tracking data for public output. | Full pickup/shipping and simple shipping tracking; wrong-phone/privacy regressions retained. |
| CART-03, usability/privacy | An unpublished cart item disappeared from the UI but blocked checkout with no removal control. Render only a generic unavailable row from its session slug, allow removal, and disable checkout until resolved. Missing/unrepresentable prices show an inquiry instead of a zero quote or misleading total; stored prices remain untouched. Never hydrate or show private product data. | `SharedPaymentsTest`: retained cart on failure, generic removal control, private name withheld, remaining cart preserved. |
| SEO-02, missing functionality | Public homepage/catalogue canonicals and sitemap were absent. Add trusted-origin canonicals, noindex search/sample variants, and a bounded public sitemap index/parts. Use current published-product/article scopes, exclude private/sample/draft/future/inactive content, no stale sitemap cache, no fabricated lastmod/offers/ratings. | XML parsing, 1001-product pagination, unpublication, invalid paths, Host/query injection, published-only visibility. Existing private article/preview denials remain. |

Acceptance now joins the previously separate HTTP and service boundaries:

- **Full repair:** customer profile/request -> real intake -> diagnosis/estimate ->
  recorded approval -> completed work -> actual isolated ledger charge/transfer ->
  password/permission-protected pickup or tracked carrier dispatch -> account history
  and public tracking. Existing rejection/return, fee, stale-version and audit tests stay.
- **Simple repair:** intake -> reviewed CRM association -> repair/ready -> actual
  isolated ledger charge and idempotent transfer -> handover -> tracking. Unpaid and
  duplicate handovers are rejected; no fake payment flags or full-workflow records.
- **Store:** admin product publication -> stock intake -> customer browsing/cart ->
  checkout/retry -> reservation/confirmation -> invoice -> real isolated in-store receipt
  -> authorized fulfillment -> customer history and ledger reconciliation. No live gateway.
- The existing guarded MySQL/MariaDB harness adds same-cart, cleared-cart and
  conflicting-cart checkout races to the original 12 financial races. Require the
  **15 completed scenarios** and distinct competing connections, not just a green job.

Public catalogue DOM checks at 320, 390, 768 and 1440 CSS pixels found no horizontal
overflow; filter controls have labels and no visible button lacks a name. No new
private authenticated browser acceptance or screenshot approval is claimed. An attempted
screenshot file export was denied by the browser tool's private-root policy and was not
retried through another path/tool. HTTP acceptance fixtures always use disposable tests,
never the owner acceptance database. A real Tab key reached a named link with a visible focus indicator. Read-only production cookie configuration reported Secure=true, HttpOnly=true and SameSite=Lax. Herd's site inventory identifies the existing local
site/PHP 8.4; its .env points at SQLite, so no local DB conversion or migration was attempted.

### Remaining explicit policy/acceptance dependencies

- Customer OTP retains per-IP 20/minute and 180/hour limits, per-phone 60-second
  cooldown and 15 real dispatches/24h, two-minute expiry and five attempts. Approved
  IPPanel/private-test reset behavior is covered with mocked HTTP. An aggregate
  site-wide SMS budget has no owner-approved threshold; do not invent one or add
  provider balance monitoring. No real SMS was sent.
- Privileged MFA enrollment/enforcement/recovery policy is not defined. Existing
  protected-owner rules, role/session revocation and sensitive-operation password
  checks remain; do not claim MFA exists or roll out an improvised recovery path.
- Notification templates and enabling scheduled notification delivery need separate
  approved provider configuration. Durable events are tested; disabled delivery is
  not proof of handset receipt. Zibal and scheduled provider processing remain OFF.
- Keep existing manual settlement, carrier-paid delivery and refund-obligation records.
  Do not invent live refunds, billed postal integration or unsupported bank instructions.
- No known unresolved critical/high issue was identified in this scoped review; this
  is not an exhaustive security certification. Final exact-head CI, owner operational
  acceptance and a separately authorized production release remain distinct gates.
  CI outcomes belong to the exact head in PR #22; keep it Draft/unmerged.

---

> The following section is historical evidence, not current authorization. Use [DEPLOYMENT.md](DEPLOYMENT.md): local approval -> controlled production release -> owner acceptance. No hosted staging prerequisite.

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

**NO-GO for production.** Local synthetic-baseline tests and the existing four green CI jobs establish code compatibility under tested conditions, not live-schema equivalence or restore readiness. Do not repeat those full suites merely for these documentation changes; run the actual-schema upgrade/rollback/invariant checks once the export and equivalent isolated test/restore privileges are available.

Required next evidence: actual table/trigger DDL and applied migration inventory; resolved symlink targets; effective web runtime/platform checks; actual-account grants and binary-log policy; local owner approval and relevant isolated schema/restore verification; verified full backup and restoration rehearsal. Preserve all triggers and history. Financial/article destructive down migrations are not the default live rollback plan. Protected merge and deployment each require separate approval.

Sources: [cPanel MySQL metadata](https://api.docs.cpanel.net/specifications/cpanel.openapi/database-information/mysql-get_server_information), [database privileges](https://api.docs.cpanel.net/specifications/cpanel.openapi/user-management/mysql-get_privileges_on_database), [schema API](https://api.docs.cpanel.net/specifications/cpanel.openapi/database-management/mysql-dump_database_schema), [file metadata](https://api.docs.cpanel.net/specifications/cpanel.openapi/manage-files/fileman-get_file_information).
