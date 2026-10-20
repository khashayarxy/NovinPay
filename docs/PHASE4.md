# Phase 4 — Public website and repair requests

Completed locally on `codex/novinpay-public-website`, based on approved main
`7d3d35740fbc474e0962972de85e0475a22575f8`. No push, merge or deployment.

## Implemented pages

| Route | Result |
| --- | --- |
| `/products` | Homepage-derived visual system, supported-brand search shortcuts, responsive cards and filters; existing search, category filters and pagination preserved |
| `/products/{product}` | Existing slug binding and published-only visibility; uncropped product images, existing condition/stock/price/specifications, contact actions |
| `/services` | Sales and repair services; ownership/account questions link to contact; no repair-time or guarantee promises |
| `/repair` | Working CSRF-protected online request form with Persian validation and duplicate protection |
| `/repair/confirmation` | Session-only confirmation after submission, random tracking reference and explicit online-only status |
| `/repair/track` | POST verification using the complete reference and submitted mobile number; minimal status only |
| `/contact` | Configured phone, hours, address and verified map links |
| `/about` | Factual introduction, supported brands and centralized business details |
| `/terms`, `/privacy` | Readable, unindexed drafts; unresolved policy statements clearly marked for owner approval |

The public layout now shares the approved homepage header/footer, active navigation,
brand colors, Vazirmatn, icon library and responsive spacing. The administrator layout
and `access-admin` gate remain separate. Homepage artwork placeholders and supplied
logo bytes are preserved. No product records, specifications, prices or availability
were invented or changed.

## Files and migration

- Backend: `RepairRequestController`, `RepairRequest` model, `RepairBrand` and
  `RepairRequestStatus` enums, `RepairIdentity`, three form-request classes,
  `PrivateRepairResponse` middleware, `RepairServiceProvider`.
- Integration: `routes/web.php`, `bootstrap/providers.php`, `PageController` (about
  page), and the baseline security middleware (preserves stricter response-specific
  referrer policies).
- Migration: `database/migrations/2026_10_20_000001_create_repair_requests_table.php`.
  Creates one table with an internal ID, unique public reference, unique submission
  digest, encrypted personal fields, phone digest/index, approved brand, explicit
  status and timestamps. It does not alter existing tables.
- UI: shared public layout/header/footer; product catalogue/detail and image/card
  components; services, contact, legal, repair, confirmation, tracking and about
  views; page-heading, repair-field and error-summary components; `errors/429`;
  `resources/css/public.css` and its import.
- Tests: new `RepairRequestTest` and `PublicWebsiteTest`; expanded catalogue
  regression coverage; public-page/homepage expectations updated for the approved
  Phase 4 behavior. README and this report document the result.
- No dependency, lockfile, administrator permission, product controller/model or
  product image-upload logic changes.

## Data preservation

The existing `database/database.sqlite`, unrelated modified `.env.example` and
untracked `public/build-upload.zip` retain matching before/after SHA-256 hashes.
The real `.env` was not edited. No existing users, products or migrations were
changed. No migration or seeder ran against the original database.

The full suite uses the established in-memory SQLite runner. Browser tests used a
new temporary copy, `novinpay-phase4-preview.sqlite`, with only the new migration
applied. Two clearly synthetic repair requests were submitted there, with no SMS,
email, payment or external side effects. The copied sample catalogue remains
labelled as sample data. No database, preview data, screenshots or build archives
are committed.

The temporary Phase 4 preview server was stopped after verification. The earlier homepage preview was left as found; it is not the migrated repair test environment.

The new table will require an explicitly authorized migration before use on any
other database. Its rollback drops that table and its requests; it is not a way
to preserve repair data. Back up data before any later migration/rollback action.

## Security decisions

- Public references use 128 random bits (`NP-` plus 32 uppercase hex characters),
  are unique in the database and never use the internal numeric ID.
- Name, mobile number, model and reported issue use Laravel encrypted casts.
  Brand, initial status and timestamps are metadata. The indexed phone value and
  submission token are purpose-separated HMAC digests keyed with `APP_KEY`.
- Keep `APP_KEY` securely backed up. Losing it loses access to encrypted data;
  rotating it requires an explicit encrypted-data and digest re-indexing plan.
  Previous decryption keys alone do not update the phone lookup digest.
- Only validated fields are explicitly assigned. Client-supplied IDs, status,
  reference or other extra fields cannot change the stored workflow state.
- Approved brands are PAX, NewPOS and Newland. Model is bounded free text from
  the device's own label, since an approved model catalogue was not supplied.
  Name is 2–100 characters, model up to 100, issue 10–2000.
- Contact input accepts Iranian mobile numbers beginning `09`; Persian/Arabic
  digits, spaces/hyphens and `+98`/`0098` prefixes are normalized. No phone
  ownership or reachability claim is made.
- A session-issued random form token is consumed after success. Its unique
  database digest prevents same-form replay and concurrent double submission.
  A new form may intentionally create another request, subject to quotas; there
  is no permanent block on a customer reporting the same issue again.
- Submission limits: 10 attempts/hour/IP and 3/hour/normalized mobile number.
  Tracking limits: 10 attempts/minute/IP and 60/hour/IP. Invalid attempts count;
  429 responses include `Retry-After`. Cache keys contain keyed digests, not raw
  phone/IP values. Deployment must use a persistent shared cache (not `array`)
  and correctly configured trusted proxies where applicable.
- CSRF remains native Laravel web middleware. Invalid customer input and tracking
  credentials are not flashed to the session. Validation errors remain Persian
  and field-associated. Users re-enter fields after a server-side validation
  failure; this is an intentional privacy tradeoff.
- Confirmation uses POST/redirect/GET and a session flash containing only the
  reference. The code is not placed in a URL. Users are told to save it; refreshing
  or leaving the confirmation may consume the one-time flash.
- Tracking is a knowledge check: full unpredictable code **and** submitted phone.
  It does not verify possession of the phone. Only the initial online-submitted
  state is shown, with no name, phone, brand, model, issue, internal ID, reference
  echo or timestamps. The query does not load/decrypt personal fields.
- An unknown reference and an incorrect number return the same generic page and
  HTTP status. A code alone, GET query parameters or numeric record URLs do not
  disclose a record. Validation errors describe format only.
- Repair responses use `no-store, private`, `no-referrer`, and `noindex, nofollow`.
  Existing escaping, product visibility, admin authorization and security headers
  remain covered by regression tests.

## Scope limitations and owner decisions

1. Approve the initial mobile-number-plus-reference knowledge check. Before
   exposing any richer repair details in a later phase, decide how to verify phone
   possession/identity and handle lost codes. No OTP or recovery procedure was
   invented in Phase 4.
2. Decide retention/deletion periods, backup retention, permitted access and
   privacy contact handling; approve the legal drafts before public release.
3. Approve operational handling of online requests, physical intake and later
   status changes. There is no repair dashboard, staff workflow, staff permission
   expansion, receipt, price, payment, delivery confirmation or notification here.
4. Confirm whether non-mobile or international contact numbers are needed later.
5. Original approved homepage artwork and real catalogue photos remain outstanding
   where previously missing; no substitute photos or screenshot crops were added.

An online submission never means the device has been physically received. No
automatic turnaround, warranty, customer contact or delivery promise is made.

## Verification

- PHP 8.4.26; `./scripts/test.ps1 --compact`: **196 passed, 1327 assertions**.
  Includes encrypted storage, initial status, malformed/oversized/array input,
  forged fields/tokens, replay and simulated concurrent duplicate, normalized
  phone/IP submission limits, minute/hour tracking limits, CSRF, generic tracking
  failure, no sensitive output/session flash, combined search/category/pagination,
  hidden products, navigation and configured contacts. Existing admin regressions
  and official-logo fingerprints pass.
- Repository-wide `php vendor/bin/pint --test`: **passed**.
- `npm run build`: **passed**, using existing dependencies.
- Browser: actual desktop **1440×1000** and mobile **390×844** output reviewed for
  all nine listed page types (catalogue, detail, services, repair, tracking,
  contact, about, terms, privacy). One H1, no horizontal overflow and no broken
  images were observed on each page.
- Browser interactions: synthetic desktop and mobile repair submissions;
  mismatched/correct tracking number; catalogue search/category filtering and
  no-match state; keyboard skip-link focus with a visible outline. Mobile main
  navigation has 44px-high targets.
- Refinement pass: restored map links throughout the public footer, corrected
  footer-heading contrast, routed ownership/account enquiries to contact, kept
  brand chips on one mobile row, and isolated mixed-direction brand names.
- Captures are native viewport screenshots. Full-page exports in this local
  browser produced clipping/stitching errors, so they were replaced with verified
  viewport captures and supplemental result/form views. That export issue did
  not occur in the live page or its measured layout.

## Screenshot evidence

Local review artifacts are in the task's visualization folder, outside Git:

`C:/Users/Khashayar/.codex/visualizations/2026/10/20/01a15969-8b3c-7783-9b3b-bade84862fc0/`

Open `phase4-gallery.html` for desktop/mobile screenshots of all nine page types.
Each page has `phase4-{catalogue,product,services,repair,tracking,contact,about,terms,privacy}-{desktop,mobile}.png`.
Supplemental images show repair controls, confirmation, the minimal tracking result
and catalogue cards. `phase4-qa-metrics.json` contains the recorded layout checks.

Phase 4 stops here for owner review. No Phase 5 or Phase 6 implementation was begun.
