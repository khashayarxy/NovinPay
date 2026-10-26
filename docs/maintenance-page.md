# Standalone maintenance page — local implementation, not deployed

## Audit and design

The old `resources/views/errors/503.blade.php` extended `layouts.app`, which
unconditionally includes the public header, footer, route-generated shortcuts,
and Vite manifest. This was the direct source of the unwanted site chrome.

The standalone page follows the main site's light slate background, Vazirmatn
typography and blue link colors. It retains the original POS repair illustration,
using the existing optimized WebP, and the existing black/blue official logo
lockup on the light background. Only the requested maintenance message and
configured contact numbers are displayed.
Persian contact digits and `tel:` destinations come from `config/business.php`:
09362896373 and 09384144021. Raster phone text in the reference is not a source
of business contact data.

`resources/maintenance/page.php` contains semantic Persian RTL HTML and inline
CSS. It embeds local fonts, the existing SVG logo and WebP assets as data URLs.
It uses no application helpers, autoloader, database, environment file, session,
authentication, Vite manifest, JavaScript, remote font or API. The complete
rendered page is approximately 196 KB, including both embedded WOFF2 weights.
The WebP central illustration is 38,606 bytes. Vazirmatn's OFL license is retained.

## Three rendering paths

1. Ordinary Laravel 503: the error view requires the standalone template.
   The exception-response hook adds no-store/no-cache, noindex and Retry-After
   (60 by default, preserving an explicit existing value). Other status codes
   retain their existing response behavior.
2. Standard Laravel pre-render: `down --render=errors::503 --retry=60` stores
   the complete rendered HTML in the maintenance payload. `public/index.php`
   invokes Laravel's installed maintenance stub before Composer. The minimal
   entry-point additions provide HTTP cache/indexing/retry headers even on this
   early path. Laravel's retry value, when present, overrides the default.
   The existing bypass/exception behavior is not replaced. Laravel normally
   delegates JSON to bootstrap; when Composer is absent *and maintenance is
   already active*, the entry point instead uses the emergency endpoint and
   returns 503 HTML. No request reaches missing application dependencies then.
3. `public/maintenance.php`: an unconditional 503 endpoint with the same page,
   strict no-store, Retry-After 60 and basic security headers. It needs only PHP
   and the checked-in template/assets/business config, not Laravel or `.env`.
   A direct visit to this endpoint never returns 200. It does not itself enable
   maintenance or divert the rest of the website.

## LiteSpeed / cPanel boundary

The installed public entry point supports standard Laravel pre-rendering.
Production's previously verified handler is LiteSpeed PHP 8.4, not main-domain
PHP-FPM. Nothing in this change switches handlers, edits `.htaccess`, changes
cache settings, creates a bypass secret, changes public_html, or enables/disables
production maintenance. No live PHP/LiteSpeed verification was performed here.

The project root remains `/home/novinpay/novinpay`; the existing symlink remains
`/home/novinpay/public_html` → `/home/novinpay/novinpay/public`.

After separate deployment approval, normal maintenance can use the existing
approved guarded CLI workflow, with `--render=errors::503 --retry=60` added to
its reviewed `down` invocation. This is documentation, not an executed command
or authorization to alter the incident-recovery scripts.

If bootstrap fails without a usable pre-rendered payload, the independent PHP
endpoint can still serve the page while PHP itself is healthy. Activating a
site-specific server-side 503 rule and internal error document pointing to that
endpoint requires a separate reviewed hosting change. Do not redirect to a 200
page and do not edit global LiteSpeed/PHP configuration.

If PHP itself is unavailable, no PHP endpoint can guarantee a response. The
rendered, fully self-contained HTML should instead be used as a provider-level
static internal 503 error document. A non-public HTML preview artifact is
prepared for review; no publicly reachable 200 maintenance HTML is shipped.
The provider must verify status 503, no-store, Retry-After, internal error-document
handling, and absence of recursive rewrites. This static server integration is
proposed, not installed or claimed as tested on production.

Herd's local Laravel driver routed a direct `/maintenance.php` request into
Laravel rather than executing the additional public PHP file. Consequently
visual QA used a temporary static local preview of the identical emitted HTML;
that temporary public preview was removed after QA. Real HTTP status/header and
missing-Composer tests used a disposable native PHP HTTP fixture, not a second
server for the Herd application. No Docker, SSH, or unguarded Artisan was used.

## Local verification and deployment gates

- Guarded SQLite suite: 496 passed, 3 driver-specific skipped, 3894 assertions
  before the additional default-retry assertion; focused tests re-run afterward.
- Native HTTP fixture runs the installed Laravel `down` command with a temporary
  storage/compiled-view path. It never enables maintenance on the active site.
  The fixture has no vendor directory, `.env`, database or sessions; HTML and
  JSON requests remain 503 with no-store and Retry-After, and the emergency
  endpoint remains 503 after its maintenance marker is removed.
- Browser QA: 320, 390, 430, 768 and 1440 CSS px; an extra 1536 × 1024 capture
  matches reference dimensions. No horizontal overflow or missing images.
  Phone links are keyboard reachable and at least 44 px high with visible focus.
- Pint and frontend production build pass. No new dependency or lockfile change.
- Database, `.env`, APP_KEY, live configuration and production maintenance were
  not changed. Git changes remain local on a dedicated branch, without a commit,
  push, PR, merge or production activation.

Before future activation, review the local diff and screenshots, run required
CI after separately authorized push, include all standalone assets and license,
and verify real LiteSpeed responses (HTML and JSON), cache headers, assets,
maintenance enable/disable, admin bypass if used, and normal non-maintenance
application behavior. Do not use an HTTP 200 preview as availability evidence.
