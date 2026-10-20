# NovinPay — Phase 3 homepage delivery

## Implemented

Rebuilt the homepage around the approved nine-part composition: compact branded
header, split hero with tall art column, four benefits, paired sales/repair
promotions, seven popular services, three supported brands, why-section,
location and dark footer. Display typography uses the existing Vazirmatn package
with its ExtraBold face. Homepage-specific CSS and components leave the existing
public pages and administrator layout intact.

HomeController no longer queries featured products: the homepage introduces the
business and links to the existing catalogue. No product model, public product
controller, route, authentication rule, authorization rule or schema was changed.
Four old homepage catalogue expectations were updated for the newly approved
service-focused homepage; all catalogue-route regressions remain in the suite.

## Artwork and sources

`config/homepage.php` contains five null public-relative artwork paths: hero,
sales, repairs, support and store. Supply original approved local files and set
their paths to replace the corresponding labelled slots. Missing paths retain
the placeholder rather than producing broken images. The hero image receives
high fetch priority; supporting images load lazily.

Repository assets and the supplied logo package were inspected. A different blue
NEWPOS mascot was found in the nearby NovinPay-redesign archive; it is not the
approved reference character and was not copied into the application. The
reference screenshot was never used as a site asset or as a source of crops.

Missing: approved blue hero mascot/background; original sales and repair
promotional images; approved support artwork; a verified actual storefront
photograph; independent original PAX/NewPOS/Newland logos. Brand names are plain
text, not traced or approximated logos. Official NovinPay assets are unchanged.

The small UI icon set comes from the official Tabler repository at commit
`a4ce1404bc6d24d3c365afe7b258d6bf6f48d62d`. All 22 SVGs are original copies; source
provenance and MIT license are in `public/vendor/tabler/`. No npm/Composer package
was installed and no lockfile changed. No new homepage JavaScript was needed.

## Verification

- Runtime: existing Herd PHP 8.4.26, using the Phase 2 isolated launcher.
- Command: `.\scripts\test.ps1 --compact` — 149 passed, 769 assertions, zero
  failed or skipped tests. Existing security/admin tests remain successful.
- `npm run build` — passed; five local Vazirmatn font files emitted.
- Existing Pint formatter passed on the four changed PHP files.
- Actual browser checks: 1440px desktop, matching 1024px reference, 390px mobile,
  plus 768px tablet. No horizontal overflow, missing logos/fonts or browser
  warnings/errors observed. Product and repair CTAs were exercised.
- At least two substantive visual refinement passes corrected density,
  typography, mirrored promotions, service CTA placement, hero/trust clearance
  and mobile supporting-copy readability.
- Full screenshots and detailed comparison: see project-root `design-qa.md`.
  The QA result is passed for the authorized placeholder scope, not a claim of
  pixel-perfect artwork fidelity.

## Preservation and review

Work is on `codex/novinpay-homepage`, derived from Phase 2 commit `1ccbd5b`.
No push, merge, PR or deployment was performed. Phase 4 has not started.

The original `.env.example` edits and `public/build-upload.zip` retain their
starting fingerprints and are excluded from the commit. The normal SQLite file
bytes changed during work; read-only comparison with the preview snapshot shows
only session records differ. Products (7), users (1), migrations (7), and all
other tables match. No test/preview was configured to write to the normal
database: tests use memory; the local browser preview uses a temporary copy,
array sessions/cache and process-only local URL overrides. No migrations or
seeders were run against normal/production data, and no session changes were
reverted or discarded.

Review at `http://127.0.0.1:8765/` while the local preview remains running. It is
an isolated local preview, not a production deployment. To roll back the code,
revert only the focused Phase 3 commit and rebuild frontend assets; no database
rollback is required. Preserve unrelated working-tree changes.

Stop here for the owner's visual review and explicit approval before Phase 4.

## Complete changed-file manifest

Modified:

- `app/Http/Controllers/HomeController.php`
- `resources/css/app.css`
- `resources/views/home.blade.php`
- `resources/views/layouts/app.blade.php`
- `tests/Feature/ProductCatalogTest.php`

Created:

- `config/homepage.php`
- `design-qa.md`
- `docs/HOMEPAGE.md`
- `public/vendor/tabler/LICENSE`
- `public/vendor/tabler/SOURCE.md`
- `public/vendor/tabler/antenna-bars-5.svg`
- `public/vendor/tabler/arrow-left.svg`
- `public/vendor/tabler/arrow-up.svg`
- `public/vendor/tabler/battery-charging.svg`
- `public/vendor/tabler/building-bank.svg`
- `public/vendor/tabler/check.svg`
- `public/vendor/tabler/clock.svg`
- `public/vendor/tabler/diamond.svg`
- `public/vendor/tabler/headphones.svg`
- `public/vendor/tabler/map-pin.svg`
- `public/vendor/tabler/phone.svg`
- `public/vendor/tabler/photo.svg`
- `public/vendor/tabler/power.svg`
- `public/vendor/tabler/printer.svg`
- `public/vendor/tabler/search.svg`
- `public/vendor/tabler/settings.svg`
- `public/vendor/tabler/shield-check.svg`
- `public/vendor/tabler/shopping-bag.svg`
- `public/vendor/tabler/tag.svg`
- `public/vendor/tabler/tool.svg`
- `public/vendor/tabler/truck.svg`
- `public/vendor/tabler/user.svg`
- `resources/css/home.css`
- `resources/views/components/home/artwork.blade.php`
- `resources/views/components/home/footer.blade.php`
- `resources/views/components/home/header.blade.php`
- `resources/views/components/home/icon.blade.php`
- `tests/Feature/HomePageTest.php`

Deleted: none. Ignored Vite build output was rebuilt locally; screenshots are saved outside the repository.
