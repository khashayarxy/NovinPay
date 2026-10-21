# Phase 7A — content model and publishing workflow

## Scope and state

Baseline: `39e65a98add57070d5ec1cd05b4e2751840df904`. This milestone adds private article/category administration only. Public article routes, comments, sitemap, media uploads and SEO expansion remain for separately approved milestones. No new dependencies or operational-table changes.

## Schema and publishing

The additive `2026_10_21_000004_create_article_tables` migration creates `article_categories`, `articles` and immutable `article_versions`. Every content version belongs to exactly one category and stores its title, summary, restricted source and parsed blocks. An article holds separate draft and published version pointers. Saving appends a revision and advances only the draft pointer. Explicit publication requires confirmation of the reviewed draft ID and expected article version; unpublication clears the live pointer while retaining history. Published timestamps change only on these explicit actions. The stable article slug is normalized and protected by a unique index; conflicts return 409 rather than overwriting another article. UUID routing does not replace authorization.

Composite foreign keys prevent cross-article pointers. Database triggers and model guards prohibit version updates/deletion. Category deactivation is denied while a published snapshot uses it; historical versions retain their category relationship. Category rename is a metadata change, not a versioned taxonomy snapshot. Deletion is intentionally unavailable. Publishing a new version rechecks that its category is active.

Optimistic version checks, conditional updates and transaction row locks reject stale edits/publications with 409. The service locks and rechecks persisted administrator access to serialize with permission revocation. Deadlock retries are bounded; failure does not leave a partial version or audit record.

## Permissions and privacy

All four staff permissions default to denied: `articles.view`, `articles.manage`, `articles.publish`, `article_categories.manage`. Manage/publish require view during provisioning and execution. Category management is independent. Super-admin access uses the existing protected account model. Routes and service writes both authorize; active-account and admin-session protections remain in place. No account bootstrap or credentials change is required.

Private previews require view access and expose only the selected draft or published snapshot. There are no public article routes in 7A. Admin responses retain private/no-store and noindex protections. Failed forms do not flash private article source to sessions. List queries load only metadata; logs record actor/action/version or category IDs, never content/title payloads. Original operational permissions and encrypted customer-data boundaries are unchanged.

## Restricted format and UI

The small dependency-free parser accepts paragraphs, level-two/three headings, flat ordered/unordered lists, links and basic emphasis. Limits: 40,000 characters, 100 blocks and 100 items per list. Arbitrary HTML, image markup and unsafe links are rejected. Links require absolute HTTP(S), without credentials/control characters. Blade escapes text, labels and URLs; external links carry safe rel attributes. No executable uploads or arbitrary HTML rendering exists.

Persian RTL screens include paginated title search/status/category filters, category creation/editing/activation, draft creation/editing and private preview with explicit publication controls. Slugs remain LTR; Persian title/name fields are RTL. Conflict screens require refresh instead of silently retrying stale submissions.

## Validation evidence

Local full suites: SQLite **398 tests / 2,875 assertions** and disposable MySQL 8.0.46 **398 / 2,875**, without warnings. Focused cases cover publication isolation, direct HTTP permissions, revocation/deactivation, malicious source/links, slug collision, stale actions, category constraints, immutable history, pointer ownership, audit privacy and transaction rollback.

Separate migration rehearsals on both engines pass **73 preservation checks each**: pre-existing table row hashes through additive upgrade, explicit rollback with published content, prior migration records and clean reapply. This rehearses synthetic data, not the owner's database.

Real separate-connection MySQL tests pass eight existing inventory, six financial and six article races. Article cases cover edit/edit, edit/publish, publish/edit, publish/unpublish, duplicate slugs and category deactivation/publication. Distinct process/connection IDs and blocked second writers are checked.

Pint and Vite pass. Locked Composer/npm audits report no advisories. Scoped PHPStan passes at level 5 for the parser/input helpers and level 0 for the new models/request/controllers/service; this is not a claim of whole-project static-analysis coverage. CI retains the three required job names and adds article migration/concurrency rehearsals.

Canonical browser QA uses only `https://novinpaypos.test` and the verified isolated database. Ten screens were captured at 1440px, 390px and 320px (30 captures), with no horizontal overflow, one main heading and no scoped main-content axe WCAG A/AA violations. Manual keyboard, RTL, permission, publication/draft isolation, validation, stale publication, unpublication and CSRF checks passed. Automated accessibility checks do not replace assistive-technology testing. Screenshots and private synthetic fixtures remain outside Git.

## Environment and later deployment

Use only the existing guarded CLI runners and verified disposable database targets. Never run unscoped Artisan against the owner's `.env`. Canonical Herd isolation, legacy-host guard and disabled automatic dev stack remain unchanged. Original SQLite SHA-256 before/after: `466981E70C0FCCE755AB4399D40E0C54609C1A995E3AD9A48E33608953AD0EAB`. `.env`, APP_KEY, unrelated `.env.example`, upload ZIP, lockfiles and legacy directories are preserved.

Deployment is a separate owner-authorized operation: back up and rehearse restoration, preserve APP_KEY, verify MySQL 8.0.16+ with required table/FK/trigger privileges and binary-log policy, then review pending migrations before applying. The migration checks unsupported MySQL versions/logging policy before creating tables, but MySQL DDL is not fully transactional; provider/privilege checks remain necessary. No production configuration or global server setting is changed by this migration.

Rollback drops the three new content tables and loses their article history; it preserves prior operational tables. Use a reviewed backup/restore plan rather than routine rollback once real content exists. No production migration, seeding, key change or deployment occurred. The feature stays in a Draft PR pending owner review; do not merge or begin 7B automatically.
