# NovinPay — Phase 2 foundation

## Scope

This phase integrates the supplied identity, centralizes approved contact data,
and restricts administration to the existing configured administrator. Homepage
composition, catalogue behavior, repair workflows and business modules are unchanged.
No migrations or dependencies were added. Do not proceed to Phase 3 without approval.

## Local runtime and reproducible tests

The installed Composer platform check requires PHP >=8.4.1 despite the root
composer.json allowing ^8.3. Existing Herd CLI and CGI runtimes were verified as
PHP 8.4.26. No installation, global PATH change or PHP constraint change was needed.
The runtime includes pdo_sqlite, pdo_mysql, mbstring, fileinfo, GD, OpenSSL and XML.

On Windows run from the repository:

```powershell
.\scripts\test.ps1 --compact
npm run build
```

The launcher checks PHP on PATH, then Herd's per-user php84/php85 locations. It
requires PHP >=8.4.1 and scopes PATH changes to its own process. It forces testing,
in-memory SQLite, array sessions/cache/mail, synchronous queues and an unused
configuration-cache path, so a cached deployment configuration cannot select the
normal database. Environment values are restored before exit. It never seeds or
migrates the normal database. Existing PHPUnit configuration supplies the other
test settings. On other platforms use a compatible PHP CLI with the same testing
environment and `php artisan test`.

In restricted execution environments, access to the installed Herd runtime or
Vite's config-loader parent-directory traversal may require host approval. Do not
change application code, dependencies or platform checks to bypass such restrictions.

## Administrator boundary

`access-admin` is a Laravel Gate defined in AppServiceProvider. It checks the
authenticated user's persisted primary key against the database account whose
email matches the trusted `config('admin.email')` value. It does not accept a
request-supplied email/role flag or trust an overwritten in-memory email.
Missing/empty/non-string configuration, an unpersisted user, or an unmatched
account denies access. The current local ADMIN_EMAIL was confirmed to match
exactly one existing user, without changing that record or its credentials.

All dashboard/product routes use `auth` and `can:access-admin`, including direct
POST/PUT/PATCH/DELETE requests. Login uses Auth::attemptWhen with the same Gate
before creating a session, retaining the generic failure message and throttling.
Logout deliberately requires authentication only, allowing a revoked user to end
their session. Public registration remains absent.

No schema change or provisioning is needed for an existing matching administrator.
Keep ADMIN_EMAIL aligned with the intended existing account and refresh cached
configuration through the normal deployment procedure when that value changes.
Changing it can revoke access; do not select an ordinary user's email. Do not rerun
AdminUserSeeder to deploy this change: it can reset a password/create another user.
This is the single-administrator boundary; Phase 5 will introduce staff permissions.

## Brand and contact source

All 13 integrated assets were copied byte-for-byte from the owner's supplied
`NovinPay-Logo-Package.zip`: three original square SVG variants and ten favicon,
application-icon, social-preview and manifest files. No paths, colors, metadata or
pixels were edited. Source entry prefixes are `NovinPay-Logo-Package/svg/` and
`NovinPay-Logo-Package/favicon/`; the instructional head-snippet was not copied.
Logo/favicons have regression fingerprints in BusinessFoundationTest.

The brand-logo component preserves 1:1 geometry, reserves 10% clear space on every
side and supplies Persian alternative text. Shared public/admin heads use the
official icons. The public footer uses the original white SVG on a dark background.
Vazirmatn continues to be bundled locally. Brand-500 is exactly #098CF7; darker
blue shades serve small text, focus outlines and buttons for contrast.

`config/business.php` owns the approved name, phone, tel URI, complete address,
hours and three map links. Shared contact/map components read it. Homepage contact
placeholders and repair coordination text read the same configuration without
changing their page structure. No email, social links or new business claims were
introduced. Map links announce new tabs and use noopener/noreferrer.

## Verification and preservation

Baseline: 123 tests passed, 461 assertions, no failures or skips, on isolated SQLite.
Final: 144 tests passed, 638 assertions, no failures or skips. The first Phase 2
run exposed two new snapshot comparisons that compared freshly-created model
attributes with database-hydrated attributes; both were corrected to compare
database snapshots. The final full run passed without suppressing any tests.
The new suite covers administrator access/login, ordinary-user read/write denial,
guest protection, configuration failures, identity spoofing, revocation/logout,
approved contacts, central configuration consumers, shared branding and asset integrity.

The standard Vite build emits the manifest, CSS, JS and all four font files.
The local visual preview uses a temporary copy of the existing SQLite file,
array sessions/cache and explicitly local HTTP APP_URL/ASSET_URL. The normal .env
is never edited to run the preview. Desktop/mobile inspection covers 1440 and
390 pixel widths, loaded logos/font, wrapping navigation and footer, and horizontal
overflow. Production services are not involved.
The mobile administrator login was also inspected at 390 pixels with a loaded
logo, visible keyboard focus and no horizontal overflow. No credentials were
entered into the browser. The temporary viewport override was reset afterwards.

Preservation fingerprints taken before implementation:

| Existing file | SHA-256 |
| --- | --- |
| .env.example | 02a4dbd5ec3c7cec25ad83c885bfbf64dd53498a5c24cd9772be615ee0990738 |
| public/build-upload.zip | 292027f23b1d915d3843802db8d7482cef9f52b26412120f253863d2ab3302cc |
| database/database.sqlite | 1be40604246cd811bf2d052cd8e4ac13ae46e9bc6326be3a275ce56426cb9669 |

The existing public ZIP remains untouched and must be excluded from a future
deployment package. Current product records and administrator credentials are
preserved; no normal/production migrations or seeders were executed.

## Rollback and remaining work

Revert only the Phase 2 commit on this feature branch and rebuild frontend assets.
There is no database rollback. Preserve unrelated local changes and do not reset
or clean the checkout. Reverting the access boundary restores the previous weaker
authentication-only behavior, so do not introduce other user accounts after rollback.

Before deployment, independently verify production PHP >=8.4.1, ADMIN_EMAIL,
HTTPS/cookies, document root, filesystem permissions and MySQL compatibility.
Phase 2 tests use SQLite; no current MySQL regression run or production assessment
is claimed. Original mascot/promotional imagery remains a Phase 3 dependency.

## Complete Phase 2 file manifest

Modified:

- `README.md`
- `app/Http/Controllers/Admin/LoginController.php`
- `app/Providers/AppServiceProvider.php`
- `config/admin.php`
- `docs/DEPLOYMENT.md`
- `public/favicon.ico`
- `resources/css/app.css`
- `resources/views/home.blade.php`
- `resources/views/layouts/admin.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/pages/contact.blade.php`
- `resources/views/pages/repair.blade.php`
- `routes/web.php`
- `tests/Feature/Admin/AuthorizationTest.php`
- `tests/Feature/Admin/LoginTest.php`
- `tests/Feature/Admin/ProductManagementTest.php`
- `tests/Feature/PublicPagesTest.php`

Created:

- `config/business.php`
- `docs/FOUNDATION.md`
- `public/apple-touch-icon.png`
- `public/brand/novinpay-np-black.svg`
- `public/brand/novinpay-np-blue.svg`
- `public/brand/novinpay-np-white.svg`
- `public/favicon-16x16.png`
- `public/favicon-32x32.png`
- `public/favicon.svg`
- `public/icon-192.png`
- `public/icon-512.png`
- `public/icon-maskable-512.png`
- `public/og-image-1200x630.png`
- `public/site.webmanifest`
- `resources/views/components/brand-head.blade.php`
- `resources/views/components/brand-logo.blade.php`
- `resources/views/components/business-contact.blade.php`
- `resources/views/components/map-links.blade.php`
- `scripts/test.ps1`
- `tests/Feature/BusinessFoundationTest.php`

Deleted: none. Generated, ignored `public/build/` files were rebuilt; they are not committed.
Pre-existing `.env.example` changes and `public/build-upload.zip` are excluded from Phase 2.
