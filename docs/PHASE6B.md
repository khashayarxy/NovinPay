# Phase 6B — Customer profiles and consultations

## Integration design (before schema changes)

Based on approved Phase 6A `74341f9137655dd72dbfd03894da39e3f594d826`. Existing repair request/intake encrypted snapshots, references, custody state and public tracking remain authoritative and unchanged. Customer profiles represent staff-reviewed identity, not a verified phone owner. No automatic backfill, profile creation from public submissions or phone-based linking/merging runs.

Add encrypted customer profiles and nullable customer foreign keys on existing repair requests/intakes and new consultation requests. Explicit linking requires customer-link/contact permissions, source-specific confidential-data permission, identity-review confirmation and a reason. A request and its physical intake are independently linked; neither implies the other. Customer edits never rewrite historical snapshots. Unlinking is explicit and audited.

Exact name/phone search uses purpose-separated keyed HMACs; raw search values never enter query URLs or session data. Phone equality signals a possible duplicate only. A unique normalized name+phone digest blocks identical concurrent profiles; a small keyed identity-lock table serializes same-phone creation/edit checks. Different people sharing a phone require an explicit staff review and encrypted reason. Controlled reconciliation keeps both profiles and snapshots, moves only explicit foreign-key associations, archives the source and records actor/reason; it does not overwrite the destination identity. Staff must verify identity independently. No destructive customer deletion.

Consultation intake has a session-issued token, unique submission digest, bounded encrypted inputs, private confirmation and rate limits. Each request is a snapshot, not a customer profile. Administrative status changes are explicit: new → in_progress → contacted → closed; new/in_progress may close without claiming contact, and contacted may return to in_progress. Contacted requires an actual contact-attempt record with a successful outcome. Assignment is to active staff with consultation management permission (or protected owner). Closed records are read-only. No messages are sent.

All writes use transactions, database locks, expected versions and atomic metadata-only activity records; notes/contact attempts/reconciliation reasons are encrypted and append-only. Separate permissions govern directory metadata, contact data, edits, identity links, reconciliation, private notes and consultation history. Source permissions remain independently required for repair navigation and confidential details. Tests/previews use disposable databases only. Original SQLite, .env and unrelated files are protected; no migrations or seeders may run against the original database.

## Modules and relationships

- `/admin/customers`: directory, exact full-name/mobile search, active/archive filter and 20-row pagination. Metadata-only staff see UUID/status; names and phones require a separate grant. Profile pages have independently paginated online requests, physical cases, consultations and private notes. They show only source modules the reader may access. Metadata access is organization-wide, as in Phases 5–6A.
- Profiles: create/edit/archival/reactivation with validated Iranian mobile normalization (Persian/Arabic digits, 09, +98, 0098), explicit identity-review confirmation and a private reason. Identical normalized name+phone cannot be created twice, including concurrent requests. Different names sharing a phone require duplicate review. Exact name search normalizes Arabic/Persian letter variants and whitespace; it is not plaintext substring search.
- Linking: source identifier, explicit independent identity check and encrypted reason. A previously linked record cannot be silently reassigned; unlink it explicitly first or use controlled reconciliation. Only the nullable association changes; physical/consultation versions also increment to reject stale forms. Historical encrypted identity, repair stage, reference, estimates, custody history and tracking credentials remain intact. No automatic propagation from an online request to its intake.
- Reconciliation: destination UUID plus both current versions, independent identity confirmation, private evidence and permission to access every moved source. Move associations, archive the source, retain both identities and each original private note, and expose the predecessor profile for authorized review. Destination identity is never replaced. The archived source is read-only; there is no automatic undo/reopen or deletion. Ordinary archival can be reversed through the existing edit form.
- `/consultation`: minimal public form, CSRF, private confirmation, single-use session token plus unique submission digest, IP (10/hour) and normalized-phone (3/hour) throttles. Confirmation discloses neither personal fields nor a record identifier. Public submissions do not create/link profiles, send messages or physically receive devices. Staff can record actual external contacts; the application makes no outbound contact.
- `/admin/consultations`: 20-row inbox, exact mobile search and status/topic/assignee filters, protected detail, qualified active-staff assignment, controlled transitions, encrypted append-only notes/contact attempts and timestamped actor-labelled assignment/status history. Closed requests reject further operational writes; customer association remains an independently authorized identity operation.

One additive migration, `2026_10_21_000001_create_customer_consultation_tables.php`, creates five tables: `customer_identity_keys` (keyed serialization locks only), `customers`, `consultations`, `customer_notes`, `consultation_entries`. It adds nullable restrictive `customer_id` foreign keys to `repair_requests` and `repair_cases`; consultations have the same nullable relationship. There is no data backfill or permission grant. Existing records stay unlinked until an authorized human reviews them.

## Permission matrix

All new grants default to denied for existing staff. The protected owner retains existing access without credential/bootstrap changes. Native Gates protect route groups, FormRequests and service mutations; persisted grants are rechecked after locks. Existing admin session-version/deactivation checks remain in force.

| Permission | Access | Required additional grants |
| --- | --- | --- |
| `customers.view` | UUID/status directory, profile metadata | Active administrator session |
| `customers.contact` | Name, mobile, duplicate candidates, private exact search | `customers.view` |
| `customers.manage` | Create/edit/archive/reactivate | View + contact |
| `customers.link` | Explicit association/unlink | View + contact; source-specific view + confidential-data access |
| `customers.reconcile` | Controlled duplicate reconciliation | View + contact + manage + link + notes; access to every moved source |
| `customers.notes` | Read encrypted notes/review reasons | View + contact; writing also requires manage |
| `consultations.view` | Inbox metadata, topic/status/assignee | Active administrator session |
| `consultations.contact` | Name, mobile, question, device info, mobile search | `consultations.view` |
| `consultations.manage` | Assign, change stage | `consultations.view`; no implicit contact/history access |
| `consultations.history` | Private contact attempts/notes/status history | View + contact; appending requires manage |

Repair source linking/reconciliation additionally requires `repairs.view` + `repairs.customer_data`. Customer permissions never grant repair transitions, intake, estimates or delivery. Metadata navigation to a repair still requires repair-view permission; confidential details remain governed by its existing Policy. Consultation source linking requires consultation view + contact. The staff editor enforces these dependency rules, and each action independently checks them even for malformed persisted grants.

## Consultation status matrix

| Current stage | Allowed next stage | Guard |
| --- | --- | --- |
| `new` (جدید) | `in_progress`, `closed` | Progress needs an assigned colleague; closing does not claim contact |
| `in_progress` (در حال بررسی) | `contacted`, `closed` | Contacted needs an actual successful contact entry |
| `contacted` (تماس برقرار شده) | `in_progress`, `closed` | Explicit staff action; history retained |
| `closed` (بسته شده) | None | Operationally read-only |

Contact entries distinguish phone/in-person/external-message and answered/no-answer/unreachable; these are staff-recorded observations, not verified digital consent or application-sent communications. Notes/events cannot be edited/deleted through models or HTTP routes. Database administrators remain outside application-level audit integrity.

## Privacy, verification and visual review

Personal fields and note bodies use encrypted casts. Exact search uses HMACs with purpose separation; plaintext criteria are absent from pagination URLs, stored search state and flashed old input. Inputs are bounded, Blade output is escaped, model serialization is restricted and private response headers are reused. Activity records contain action/actor/target/time only. Phase 6A's SQL/binding/exception redaction remains in force, and new conflict/validation pages avoid replaying form data. Existing `APP_KEY` is unchanged; future rotation requires coordinated re-encryption and HMAC re-indexing.

Final recovery validation: **294 tests, 2315 assertions passed on both SQLite 3.53.4 and disposable MySQL 8.0.46**. Coverage includes 39 new cases for permissions, direct HTTP denial, normalization, duplicates, stale versions, explicit links, historical-data preservation, reconciliation guards, archival, private searches, encrypted fields, append-only notes, provisioning dependencies, session revocation, public validation/throttling, assignment/status control and transaction rollback/redaction. Prior public, product, bootstrap and repair regressions remain green. Repository-wide Pint and Vite 7.3.7 build pass. No dependency/lockfile changes or new packages.

Separate isolated migration harnesses pass **12 checks per driver**: additive preservation of complete prior rows/credentials/ciphertexts/grants, no fabricated profiles/links, explicit link integrity, encryption, Phase 6B rollback, reapply, complete rollback and fresh eleven-migration installation. Two actual competing MySQL connections verify both identity creation and conflicting updates: first worker commits after holding the lock; the competing worker waits then receives 409. Reports are outside Git.

Actual browser review uses synthetic-only SQLite fixtures and desktop 1440×1000 / mobile 390×844 viewport overrides. The directory, profile, public form, inbox and consultation detail were captured at both sizes, with additional edit, confirmation, action, empty-state and restricted-staff captures. Native capture bounds may exclude scrollbars/browser areas (see screenshot metadata). Measured screens have one h1, labelled controls and no document overflow; data tables scroll within their containers. A clipped mobile empty state was corrected and re-inspected. Browser checks submitted a synthetic consultation and contact-attempt/status action only; no real customer was contacted. Mobile shortcuts reach actions/history, and restricted profiles hide unavailable action links.

Review artifacts are retained outside Git: the earlier `phase6b` gallery, screenshots, metrics, SQL-driver JUnit/logs, migration and concurrency reports; and the final validation report/manifest, new JUnit/logs and migration checks, browser privacy metrics, Lighthouse reports and desktop/mobile captures. Owner-specific artifact locations are provided in the delivery report rather than committed configuration.

Final browser QA uses the established Herd HTTPS site and its separately verified synthetic SQLite database. It exercises customer creation with Persian mobile normalization, separate online/physical/consultation linking, public submission, assignment and guarded contact status changes. Metadata-only staff cannot see contact fields or history; contact-only staff cannot see private history or mutate records. Direct unauthorized writes return 403; contacting without a successful contact entry returns 409. Public confirmation and invalid submissions do not replay personal fields. Final validation corrected two observed error-message defects: public validation now uses Persian field names, and malformed source identifiers receive identifier-specific messages instead of a phone-number error. Automated and browser checks cover the corrections.

The mobile public consultation form's Lighthouse accessibility score is 100. The customer profile scores 96 because the existing shared admin footer has a 4.44:1 contrast ratio (below 4.5:1); the footer is unchanged from Phase 6A. Private admin pages intentionally remain outside public search indexing. No significant new responsive or accessibility defect was found in the reviewed Phase 6B screens.

## Original database preservation finding from the earlier session

No migration, seeder, bootstrap or application test/preview connection targeted the owner's original SQLite database. `.env`, the existing `.env.example` modification and untracked `public/build-upload.zip` remain checksum-identical to the Phase 6B start and are excluded from this commit.

The original database checksum nevertheless changed from `1C098B8575E6E5DF37D8A81DCAF8D08CD072EF04B7A456EDCA27F97C7248B80A` to `15F5E1B271747683C78AA9811472D2298BE3B405175DB2BC1E8C6BF90DD9C88B`; byte-for-byte preservation cannot be claimed. An independent `SQLITE3_OPEN_READONLY`/query-only investigation reports integrity OK, no FK violations, unchanged schema version 25 and the same seven migration records, complete owner row/credentials and seven complete product rows as the retained earlier audit baseline. No Phase 4–6B tables exist in the original. Compared with that retained baseline, only guest-session contents differ; other tables match.

Session metadata records `https://novinpay.test/` traffic at 21:22:50 and 21:22:55 UTC on 20 October, matching the original file's final mtime. The separately running Herd site still points to this checkout; the isolated preview uses a distinct Temp database and loopback port 8768. These observations support local-site session writes as the explanation, but no per-write/PID trail establishes the exact process. No original data was repaired/restored/overwritten. Evidence: `original-database-readonly.json`; sensitive values are not reproduced. This remains an operational caveat for use of the unmigrated local site, with no observed business-data loss or source-integration blocker.

The temporary port-8768 preview was subsequently stopped. Recovery/final validation uses the canonical Herd site `https://novinpaypos.test`, a distinct session cookie and a guarded isolated development database. The original database's final-validation baseline is SHA-256 `D4659B16F3F11089D2A96B3C0FE61408F2FD7DFA572DF6A13AABDC8CB786563E`; it remains checksum-identical throughout this final validation task, as do `.env`, `.env.example` and `public/build-upload.zip`. No original migration, seeding or key change is part of this commit.

## Deployment, rollback and owner decisions

Deployment is separately authorized work. Back up database/uploads/environment, rehearse restore, preserve `APP_KEY`, enter maintenance, apply all pending migrations in order and use the established Phase 5 trusted-owner bootstrap procedure if upgrading the original seven-migration schema. Verify owner/ordinary/staff access, then explicitly grant only required new permissions. Keep production debug off, HTTPS/secure cookies and private redacted logs. Do not run `composer setup` or `key:generate` on an encrypted existing installation. The old Herd database cannot serve the new modules until that approved upgrade.

MySQL DDL is not atomic: interruption can leave a partial schema, including one of the two nullable foreign-key additions. Stop and review against backups rather than blindly retry or clean up. Phase 6B rollback removes all customer profiles, consultation requests, notes, identity-lock rows and associations; it preserves earlier repair snapshots/state/history and global administrative activities. Reapply starts empty and cannot reconstruct identity links or consultation history. Preserve new data before any rollback.

Owner decisions remain: acceptable independent identity evidence and reconciliation authority, shared-phone handling, correction/undo policy after reconciliation, retention/deletion and backup policy, final privacy/consent wording and consultation assignment/closure practices. There is no public customer portal, automatic SMS/email/marketing, outbound messaging, AI chat, CRM automation or Phase 6C work. No push, merge, deployment or original-database operation is included. Stop after the local feature commit for owner review.
