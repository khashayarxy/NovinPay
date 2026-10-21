# Phase 6D — final presentation and validation

This focused follow-up preserves the approved integer-hundredths ledger, UTC
storage, posting rules, authorization, encryption, migrations and dependencies.

## Presentation

- At 640px and below, document positions and transaction history become labeled
  cards, including every amount, settlement state, actor, timestamp and authorized
  detail action. Native table headers and explicit table roles remain available
  to assistive technology; desktop tables retain keyboard-accessible scrolling.
- All finance amounts use string-based thousands grouping and exactly two decimal
  places, including negative corrections and zero. No floating-point formatting
  or change to input parsing/calculation is introduced. Amounts use bidirectional
  isolation in RTL text.
- Financial timestamps use ICU's Persian calendar in Asia/Tehran. Machine-readable
  timestamps and hover titles retain the exact UTC instant. A missing intl runtime
  falls back to explicitly labeled Gregorian Tehran display. The existing intl
  extension is already enabled in CI; no dependency was added.
- Date inputs deliberately remain native Gregorian dates in UTC. Their explanatory
  text distinguishes them from displayed Jalali timestamps. The inclusive posting
  date bounds, 366-day limit and current-position reporting semantics are unchanged.
- Finance-only form fields associate errors with inputs, mark invalid fields,
  provide a decimal keyboard, use current-password autocomplete and retain empty
  private input after rejection. Focus, control height and confirmation wording
  distinguish an internal posting/reversal from an actual bank transfer/refund.

## Validation on 2026-10-21

SQLite memory and disposable MySQL 8.0.46 each passed 374 tests / 2,732 assertions.
New cases cover exact large/negative/zero display, Jalali New Year and Tehran
midnight boundaries, source timezone independence, and private validation redirects.
All eight commerce and six financial competing-process scenarios passed. The
existing disposable upgrade/rollback/reapply harness passed 18 checks per engine.
Pint, the production Vite build, Composer audit and npm audit passed with no
advisories. Standalone PHPStan 2.3.1 found zero errors at level 5 for finance support
classes and level 0 for finance services, controller, request and models. This is
the established scoped analysis, not full Laravel-aware Larastan coverage.

Fresh Playwright captures cover 12 screens at 1440px, 390px and 320px. All 36
main-content axe WCAG A/AA scans reported zero violations; no document overflow
or unexpected console/network failures occurred. Sixteen baseline captures cover
the original eight screens at desktop/mobile widths. Screenshots were visually
reviewed, including narrow forms, balances, reports and settlement histories.
Keyboard testing verified field order, visible focus and access to table actions.
These checks do not constitute a complete assistive-technology audit.

Live synthetic operations verified rejected passwords without private replay,
receipt/expense retries, duplicate evidence rejection (409), and compensating
reversals. The two synthetic documents reconcile with zero discrepancies and
unchanged net receipts, expenses and outstanding positions. A summary-only staff
account receives 403 on evidence, customer balances, financial writes and staff
administration; private evidence/customer names are absent from summary responses.
Responses remain no-store/private, and synthetic private text was absent from
application logs. Laravel 13's default same-origin request verification remains
intact: cross-site, same-site and unknown-origin tokenless requests returned 419;
a same-origin tokenless request reached ordinary validation, as documented by the
framework. No CSRF exemption or middleware change was made.

## Measured performance

The same disposable MySQL harness measured ten warm controller-plus-Blade renders
per page with 10,000 documents / 30,000 entries; all document positions reconciled.

| Operation | Before mean | After mean | Queries before / after |
| --- | ---: | ---: | ---: |
| Summary | 102.11ms | 90.03ms | 4 / 4 |
| Dashboard | 153.36ms | 144.25ms | 14 / 14 |
| Customer balance | 144.54ms | 145.48ms | 13 / 13 |

Peak memory remained 42MiB. Sub-millisecond customer-page variation does not show
a material regression; the lower summary/dashboard samples are not proof of a
query improvement. No reporting query, pagination or index changed. EXPLAIN still
uses indexed customer/evidence lookups and a bounded backward scan for the sampled
period/category list. Aggregate growth and deep offset pagination remain limits.

A separate DevTools canonical desktop trace measured LCP 353ms, TTFB 143ms and
CLS 0.00, without CPU/network throttling. All 24 sampled resources returned 200;
no console errors/warnings or HMR connection failures appeared. These local lab
measurements are not production performance guarantees. The injected Herd toolbar
is local development UI and was left unchanged.

## Preservation and release boundary

Original SQLite SHA-256 before/after:
`466981e70c0fcce755ab4399d40e0c54609c1a995e3ad9a48e33608953ad0eab`.
Read-only integrity/schema and all retained table row hashes match this task's
baseline. The original seven migrations, owner and seven products remain intact.
The environment, key, unrelated example changes, upload ZIP, lockfiles, legacy
directories and Herd guards remain untouched. Canonical browsing and synthetic
writes use only the explicitly isolated development database; all regression,
concurrency, migration and benchmark operations use disposable targets.

Herd's site-info tool reads raw .env metadata and therefore still reports the
original database; it is not the effective canonical PHP request configuration.
Nginx and the host-specific loader apply the verified overrides. Automatic dev,
queue and Vite processes stayed stopped. Continue using guarded CLI runners;
bare Artisan/dev commands and raw database tools do not inherit those web guards.

The three CI job names and all audit, formatting, build, concurrency and migration
steps remain unchanged. Publication updates only the existing feature branch and
Draft PR #2. Owner merge approval and the backup/restore, unchanged-key and MySQL
CHECK/TRIGGER prerequisites in PHASE6D.md remain required before deployment.
