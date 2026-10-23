# Phase 7B — public articles and foundational content SEO

## Baseline and delivery boundaries

Deployed baseline: `d85c1e40c0b7e43b1861aea07d3363be87192205`. PR #7 was merged through the normal GitHub ruleset as `779f681a3eb792cf741958d39e3cfd31962bc049`; its source tree is identical to that deployed baseline. No unchanged production files were redeployed. Development branch: `codex/novinpay-public-articles`.

No tables, migrations, dependencies, permission grants, operational writes or encryption changes are added. Comments, media uploads/library, sitemap, structured data, general SEO settings, SMS and payments remain outside this phase.

## Public read model

| Route | Behavior |
| --- | --- |
| `/articles` | Twelve published articles per page, latest publication first with an ID tie-breaker |
| `/articles/category/{slug}` | Active category, filtered by the current published version's category |
| `/articles/{slug}` | Current published snapshot only, using the stable article slug |

The shared `Article::published()` query requires a current publication timestamp, a published version belonging to the same article, and an active category. Listing queries select only public metadata; detail loads only the current published blocks. Neither reads the draft relation, source text, authors or historical versions. No public UUID/ID preview or version endpoint is introduced. The administration UUID binding and all Phase 7A permission, concurrency, immutability and publication rules are unchanged.

Saving a draft cannot change public title, summary, category, content, order or canonical URL. Explicit publication switches the public snapshot. Explicit unpublication makes the next listing/category request omit it and its detail return 404. Requests already in flight or copies previously downloaded cannot be recalled; no claim is made that search-engine caches disappear immediately.

Each public article response, including a 404, uses `Cache-Control: no-store, max-age=0, must-revalidate`; no response/fragment cache, ETag, Last-Modified or conditional 304 is used. This prevents application/browser/shared caches following those directives from retaining stale publication state. Production proxies/CDNs must respect these headers; purge or disable any independently configured full-page cache for `/articles*` before activation.

## Rendering, SEO and discovery

The existing `article-content` / `article-inlines` components render escaped text and the existing restricted parsed blocks. No raw HTML, extra editor or new link parser is introduced. Only the Phase 7A publisher can create content; public routes remain read-only and private previews remain authenticated and noindex.

Article metadata is escaped independently of legacy layout sections. Canonical origins come from trusted `config('app.url')`, not the incoming Host header; unknown query parameters are not copied into pagination or canonical links. Page one has a query-free canonical; later pages have their own canonical and numbered title. Nonempty normal pages use `index, follow`; empty pages and query variants use `noindex, follow`; missing/invalid pages use a 404 and `noindex, nofollow` response header. Page numbers are positive decimal strings capped at 100000; malformed or out-of-range pages return 404. Inactive/missing categories also return 404; active empty categories have an intentional empty state.

Breadcrumbs, semantic headings, Persian pagination, visible keyboard focus, responsive cards and a readable article column are included. Publication dates are explicitly labelled Gregorian rather than incorrectly claiming a Jalali conversion. The category navigation shows up to thirty active categories with published content; every public article also links to its own category. The header disclosure, desktop navigation and footer gain one articles link. The pinned mobile header and the two shop/repair shortcuts remain unchanged.

Final owner adjustment: within the mobile breakpoint only, the existing hamburger actions use flex order -1 and a 12px gap so the 44x44px control sits immediately to the right of the logo in RTL. Desktop rules and JavaScript are unchanged. Browser checks at 320/390px confirm the original 116.8px header height, stable scrolling, open/closed menu, keyboard activation, Escape/focus return and unchanged shop/repair shortcuts.

## Validation and local safety

`scripts/test.ps1 --compact --display-warnings --fail-on-warning` runs the full suite on isolated in-memory SQLite. MySQL 8 and MariaDB 10.6.28 run the same full suite and existing migration/concurrency checks in GitHub CI; no local Docker commands are used. Repository-wide Pint and the production Vite build are required. Focused tests cover draft/history isolation, publication/category replacement, unpublication and republication after a 404, conditional requests, stable slugs, private HTTP boundaries, unsafe source, escaped title/summary, canonical origin/query handling, deterministic pagination, bounded queries and navigation regressions.

Executed local result: **487 passed, 3 driver-specific skipped, 3672 assertions**, zero failures/errors or warnings. Pint, Vite and whitespace checks pass. Browser listing/detail/empty/pagination screens at 320/390/1440px have no horizontal overflow, one main heading and zero scoped axe WCAG A/AA violations in main/header/footer. Keyboard skip navigation, pagination activation, menu Escape/focus return and sticky scrolling pass. Additional 851/1024px checks found no logo/navigation overlap. Automated accessibility checks do not replace assistive-technology testing.

Browser QA uses only `https://novinpaypos.test` with the existing guarded isolated database and clearly synthetic article fixtures. Check listing, detail, pagination and empty states at 320/390/1440px, plus 851/1024px navigation boundaries. Check keyboard skip link, mobile disclosure, Escape/focus return, sticky scroll behavior, heading count and horizontal overflow. Screenshots and fixture scripts stay outside Git under the task's `phase7b` visualization directory. Do not migrate or provision the original database for QA.

## Production activation gate and recovery

Read-only cPanel inspection on 2026-10-24 returned no dated backups from `Backup/list_backups`. A retained legacy application directory exists, but that is not a backup of `novinpay_live1`. External/provider backups and a successful current-data restore have not been independently verified. **Production activation remains blocked until a viable current backup/recovery set is confirmed.** Code review and CI can finish independently; no production update or database export was performed in this phase.

Required recovery set: a consistent dump of the actual `novinpay_live1` database including all five triggers and original DEFINER identities, current application source/locked dependencies/prebuilt manifest, uploaded files, and the production environment/APP_KEY. Keep secrets and customer data in private restricted storage and an encrypted off-host copy; verify archive hashes and restoration to an isolated destination, never the live or legacy database. Retain the migrator/trigger-definer account. Record the snapshot time, restore evidence and the approved source identity before activation. An old demo backup or schema-only export is insufficient.

After owner review, green CI and the recovery gate, reuse the existing in-place GitHub-to-cPanel update: pre-upload hash-verified fingerprinted assets, fast-forward the existing application checkout to the approved source, atomically activate its build manifest, then rebuild the application route cache and compiled views with the explicit PHP 8.4 binary. The route cache must be refreshed because this phase adds routes. The verified production Composer loader is not classmap-authoritative; no dependency installation is required for these PSR-4 classes. Do not invoke broad `optimize:clear`/`cache:clear`, migrations, seeders, bootstrap or key generation. Preserve `.env`, sessions, uploads, the cPanel PHP-handler additions and the existing `public_html` symlink. No installation or directory replacement is needed.

Verify real HTTPS list/category/detail/404 behavior, response headers, assets, private-preview denial and authentication before considering activation complete. Use only pre-approved disposable synthetic content if a hosted write test is separately required; never auto-publish QA content on production.

Code rollback is a reviewed switch back to the retained previous application source and build manifest, followed by refreshing only route/view caches. Since the schema does not change, do not roll back migrations or overwrite the database; preserve content and business writes made after release. A data-recovery incident is a separate maintenance/restore operation with review, not an automatic side effect of code rollback. Any required cPanel Terminal procedure must be supplied as one versioned, SHA-256-verified command after the gate is satisfied.
