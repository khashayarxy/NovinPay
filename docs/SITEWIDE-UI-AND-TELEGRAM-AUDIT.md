# Responsive UI and Telegram audit

Baseline: `599f241` (2026-11-25). No database migrations, dependency changes or provider activation.

## UI changes

- Shared layout elements and form controls can shrink within their containers; long identifiers and receipt values wrap without widening the page. Numeric displays retain tabular figures and directional isolation.
- Customer order/repair timelines use a vertical layout below 600px, keeping all stage labels visible. Wide layouts retain the horizontal timeline.
- Payment forwarding and VPN screens use NovinPay blue. The indeterminate screen has one amber instruction and the existing two payment actions. Successful receipts use the requested heading without the extra eyebrow, status lead or copy button.
- Explanatory sections remain visible. Customer/admin/support action menus use real buttons with expanded state and keyboard dismissal; FAQ disclosures retain custom SVG chevrons. Native disclosure markers are suppressed.
- Support asset publication must include the new `support-client/action-menu.js` and the complete generated support manifest, retaining earlier content-versioned directories for open tabs.

## Telegram changes

- Human-facing payment, order, SMS and inventory labels are Persian; internal metric keys and tracking identifiers remain unchanged.
- Minor monetary units are converted to Toman using the existing hundredths convention, including daily and sales summaries.
- The paid-order confirmation suppresses its redundant raw transition while recording the transition metric. Independent administrative confirmations still produce a status alert.
- Same-site HTTPS admin URLs become inline buttons. Authentication, topic routing, redaction and delivery deduplication remain enforced.
- Ordinary slow redirects and routine `cache_locks` queries are filtered. Redirects taking at least 10 seconds (or five times the configured threshold) and lock queries taking at least 5 seconds (or ten times the threshold) remain actionable.

## Backup reporting

Production's configured path is `/home/novinpay/novinpay-private-backups`; backups are nested inside generation/release directories. The previous immediate-file scan missed these archives and could select unrelated metadata.

Discovery now scans at most three directory levels, skips symlinks and selects archive files. `backup.npb` requires a successful adjacent restore receipt with matching size; its snapshot timestamp determines age, rather than a copied file's mtime. A report describes file freshness, not independent recovery readiness.

Keep these existing environment settings:

```dotenv
TELEGRAM_TOPICS_ENABLED=true
OBSERVABILITY_BACKUP_PATH=/home/novinpay/novinpay-private-backups
OBSERVABILITY_BACKUP_MAX_AGE_HOURS=72
```

Keep the existing bot token, chat ID and `TELEGRAM_TOPIC_BACKUPS_ID`; no credential replacement is required. Missing topic/configuration and delivery errors are logged without secrets. Rebuild configuration with the existing controlled release procedure.

`novinpay:telegram-backup-report --force` explicitly bypasses report throttling for a manual delivery check. Command output now distinguishes sent from unsent notifications and exits unsuccessfully when delivery fails. Fresh files notify `backups` only; missing, empty or stale files also notify `errors`.

The source schedule is daily at 06:00 Asia/Tehran with overlap protection. Production cached topic routing and backup-path configuration were inspected read-only. Hosting cron execution and actual post-release Telegram receipt remain pending; a source schedule or mocked send is not evidence of hosted delivery.

## Evidence and limits

Isolated feature tests supplied 115 rendered HTML documents, including customer account sections, checkout, order/repair detail states, payment outcomes and administration. Targeted mobile screenshots and existing Chromium navigation/drawer/connectivity tests were reviewed. The bulk browser runner did not complete, and the browser tool rejected the external bulk script as outside its private root. Full automated viewport coverage and physical-device/owner acceptance are not claimed.

Local checks: production build, full Pint check, 251 focused PHP tests, 16 additional notification/payment tests, and 65 frontend tests passed (one Linux-only test skipped on Windows). Temporary capture instrumentation was restored; its streamed-response errors are not application regression results. Exact-head four-job CI is required separately.

Production activation must retain current runtime/quota/integrity and independent recovery checks. A zero-migration change does not require running migrations or reconciling historical migration counts. New release-backup files alone do not replace the 72-hour independent recovery proof.
