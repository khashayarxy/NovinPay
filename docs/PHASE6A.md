# Phase 6A — Internal repair operations

Implemented on `codex/novinpay-repair-operations`, based on approved Phase 5 commit `9518ccab963cb8a4365b7dd6a2df5df71714c1ab`. Scope is internal repair operations only. No production deployment or original-database migration is included.

## Modules and records

- `/admin/repairs`: searchable, paginated physical repair cases; status, receipt-date, brand and technician filters, including historical assignments to inactive technicians. Metrics count actual records. Exact identifier searches use POST and keyed digests in the session; raw phone numbers, serials and tracking references are not put into pagination URLs or search-session values.
- `/admin/repairs/intake`: direct shop intake or an explicit link from an existing online request. Records identity/contact, brand/model/optional serial, issue, condition, accessories, receipt time and receiving staff. The receiving actor is the authenticated staff member; receipt requires an explicit physical-receipt confirmation. An online request never creates intake automatically.
- Case detail: protected customer fields, inspection history, versioned estimates/decisions, technician work, completion checks, handover and a timestamped actor-labelled timeline. Optional reassignment/reinspection/revision forms collapse when not the primary action; mobile jump links reach actions/history.
- Printable intake, repair-summary and delivery HTML documents. Internal notes and customer-confirmation evidence are excluded from the customer repair summary. Documents accurately show missing serials/evidence, actual accessories/condition/date and the distinction between repair completion and return without repair. They claim no captured signature, verified digital consent or payment.

Online requests remain under `/admin/repair-requests`. The original online record is unchanged after intake; a linked case is explicitly shown. Dashboard's online counter counts requests without physical intake, separately from open physical cases. Public tracking keeps the existing reference-plus-phone verification boundary: an online-only match shows initial registration; a physical-case match confirms only that a service record exists. It exposes no current detailed stage, history, amounts, personal fields, serial, technician notes or delivery document.

## Additive migration and integrity

`database/migrations/2026_10_20_000003_create_repair_operations_tables.php` adds seven tables:

| Table | Purpose |
| --- | --- |
| `repair_cases` | Physical custody record, UUID route identifier, random customer reference, digests, current stage, assignment and version |
| `repair_inspections` | Append-only findings, diagnosis, proposed actions and internal notes |
| `repair_estimates` | Immutable numbered revisions tied to an inspection, customer summary and service/parts amounts |
| `repair_estimate_decisions` | One immutable decision per estimate, channel/evidence, recording staff and server timestamp |
| `repair_work_entries` | Append-only actual work, descriptive parts, private notes, completion checks and result |
| `repair_deliveries` | One immutable actual handover/return, recipient, time, staff, established amounts and optional confirmation reference |
| `repair_events` | Append-only actor/action/state/reference IDs and timestamp; no personal payload |

Name, phone, model, serial, issue, physical condition, accessories, inspection/work text, internal notes, estimate summary, decision evidence, recipient and delivery evidence/outcome text use Laravel encrypted casts. Accidental model serialization exposes only case UUID/stage or child ID/date. Phone/serial/reference search uses purpose-separated HMACs. Amounts are integer **toman**, using the existing product-currency convention; prices are not payment records.

Uniqueness protects intake-token digests, the optional linked online request and a normalized active brand/serial. Active serial reservations are released only on actual delivery/return. Without a serial or online link, the system cannot identify two separately submitted forms as the same physical device with certainty; staff must check existing cases. The one-use session-issued intake token still prevents accidental replay, including stale concurrent submissions.

Every case mutation locks the case, rechecks authorization and expected version, writes its child/history rows and administrative activity in one transaction, then increments the version. Stale requests return 409 and cannot overwrite newer work. Assignment also checks/locks a valid active technician's grant. Unique/FK constraints provide a second boundary. Historical records have no edit/delete endpoints and reject Eloquent mutation. This is application-level audit integrity; privileged database access remains an operational responsibility.

## Permission matrix

Existing `repairs.view` permits metadata/timeline access to organizational cases; `repairs.customer_data` additionally permits protected details, estimates and work text. Read access is organization-wide, consistent with Phase 5. Assignment restricts technical writes, not metadata visibility.

| Added permission | Action | Additional boundary |
| --- | --- | --- |
| `repairs.intake` | Explicit direct/linked physical intake | View + customer-data permission |
| `repairs.assign` | Assign/reassign an active qualified technician | View; target must be active owner or have inspection/work permission |
| `repairs.inspect` | Start/record inspection | View + customer data; assigned technician |
| `repairs.estimate` | Issue/revise a customer estimate | View + customer data; completed inspection |
| `repairs.approve_estimates` | Staff-recorded customer decision | View + customer data; latest pending estimate + evidence |
| `repairs.work` | Start work, record work/completion | View + customer data; assigned technician + current approval |
| `repairs.transition` | Queue, ready-for-collection, cancel further repair | View; specialized state checks remain mandatory |
| `repairs.deliver` | Actual handover/return | View + customer data; operational checks + explicit handover confirmation |
| `repairs.print` | View printable customer documents | View + customer data; viewing is audited, printing itself is not claimed |

The protected super-admin can act on assigned cases regardless of the assigned technician; history records the actual acting owner. Staff have no implicit new permissions. Staff provisioning enforces dependencies, and per-record Policy checks apply independently on every direct HTTP operation. Deactivation/session-version behavior and owner lockout protection from Phase 5 remain intact.

## Transition matrix

| From | Next stage / authorized trigger |
| --- | --- |
| Online request received | Separate explicit physical intake only; original request stays online-only |
| Device received (`received`) | Queue for inspection, or cancel further repair and await return |
| Waiting for inspection | Start inspection with an assigned technician, or await return |
| Inspection in progress | Save findings; explicitly complete inspection, or await return |
| Inspection completed | Issue estimate → waiting for customer decision, or await return |
| Waiting for customer approval | Recorded approval → approved; rejection → inspection completed; revision → new pending estimate; cancellation → await return |
| Approved | Start repair against the current approved estimate, issue a new pending estimate, reopen inspection, or await return |
| Repair in progress | Record actual work; explicit completion with checks/result → repair completed; reinspection pauses work and requires a new estimate/decision; cancellation → await return |
| Repair completed | Ready for collection only when current approval and completion checks exist |
| Ready for collection | Actual handover → delivered, with recipient, time, outcome and confirmed final amounts |
| Cancelled / awaiting return | Actual handover → returned without successful repair; device remains in custody until then |
| Delivered / returned | Closed, immutable operational record; another actual visit requires a new intake |

Generic status POSTs cannot manufacture approval, inspection completion, repair completion, physical intake or delivery. Even zero-cost repair work requires explicit approval. A new inspection invalidates the sufficiency of prior approval; a new estimate revision always needs its own decision. Work cannot start/continue using a rejected, stale or different-inspection estimate.

Approval is **recorded by staff**, using phone, in-person, customer message or document channel, evidence/reference, authenticated actor and server timestamp. It is not digitally verified customer consent. No customer portal, signature capture, SMS or email confirmation service is added.

Successful handover copies the final approved service/parts amounts and requires their explicit confirmation. Changing them requires the controlled inspection/estimate/approval workflow before completion. Return without repair leaves amounts unestablished rather than inventing a fee. No payment, invoice settlement or inventory deduction occurs.

## Privacy and failure handling

All new forms enforce native CSRF, authorization before validation, bounded input and applicable throttles. Personal inputs/evidence are never flashed into session old-input. Customer fields, history and documents are escaped in Blade, permission checked and served privately. Staff-facing conflict/data errors show safe recovery instructions without request/trace replay. Search is exact-identifier based because encrypted names/model/notes cannot be safely searched as plaintext substrings.

Phase 5's identified SQL-log exposure is addressed in the exception boundary: direct and wrapped `QueryException`s produce a generic response and a log containing only an allowlisted SQLSTATE. SQL, bindings, original exception, trace and request payload are withheld, even with debug enabled. Other exception behavior remains native. Existing historical logs were not edited or erased; restrict their access/retention and keep production debug disabled.

## Verification and visual evidence

Full existing regression suite plus focused Phase 6A tests passed on isolated in-memory SQLite and disposable **MySQL 8.0.46**. Coverage includes online/intake separation; direct/linked intake; duplicate token/link/active serial; invalid generic transitions; inactive/unqualified/other technicians; explicit approval evidence; estimate revisions/reinspection; forbidden work; completion/delivery checks; optimistic conflicts; rollback of intake/work/state/history on audit failure; encrypted storage/escaping; printable document accuracy; confidential public tracking; SQL-log/response redaction including wrapped failures; search/filter metrics; historical technician filtering; and Phase 4–5 regressions.

Final results: **255 tests, 1934 assertions passed on each driver** (SQLite 18.97 seconds; MySQL 29.06 seconds). Repository-wide `vendor/bin/pint --test`, `npm run build` and `git diff --check` passed. SQLite used the established `scripts/test.ps1 --compact` runner; MySQL used explicitly scoped throwaway credentials/DB URL for the isolated container. Logs/JUnit reports are in the local review artifacts. No dependencies or PDF package were added.

Separate guarded migration harnesses passed 11 checks each on SQLite 3.53.4 and MySQL 8.0.46: additive preservation of Phase 4/5 rows and credentials, synthetic workflow/encryption, dependency-safe rollback, preservation of existing grants/history, reapply and complete ten-migration fresh install. A real two-process MySQL test held the first row lock: one assignment committed; the other received a 409 stale-version conflict. Test databases and credentials were isolated from the owner's database.

Actual UI inspection used 1440×1000 desktop and 390×844 mobile browser viewports against a new temporary SQLite database with explicitly synthetic identities/devices. Browser intake and handover were submitted only for synthetic cases. Reviewed list/filter, intake, pending-estimate decision, assigned-technician work, readiness/delivery, customer documents, mobile navigation and restricted views. Labels, heading count and horizontal overflow were inspected; measured controls had labels and no document overflow. Captures are JPEG viewport images; image bounds may exclude browser/scrollbar areas.

Review artifacts are outside Git at `C:/Users/Khashayar/.codex/visualizations/2026/10/20/01a15969-8b3c-7783-9b3b-bade84862fc0/phase6a/`. The screenshot gallery, QA metrics, SQL-driver test logs and migration/concurrency results are retained there. They contain synthetic test records only.

## Safe future migration and owner decisions

The owner's original SQLite database was not migrated, seeded or selected by application test/preview connections. Existing `.env.example` changes and untracked `public/build-upload.zip` are excluded from this commit. The unrelated local files are preserved; any checksum difference caused by separately running local-site traffic must be distinguished from this isolated workflow.

For a separately approved deployment: back up database/uploads/environment and rehearse restoration; preserve `APP_KEY`; use maintenance mode and the existing Phase 5 owner-bootstrap procedure when upgrading the current older original schema. Apply pending migrations in filename order, verify owner access and ordinary-user denial, and explicitly grant staff only needed repair permissions. Verify HTTPS/private cookies and production debug-off configuration before reopening traffic. Do not run `composer setup` or `key:generate` against an existing encrypted installation.

The new migration touches no existing table or permission grant. MySQL DDL can leave partial schema on interruption; stop and review recovery against the backup rather than blindly retrying/destructively cleaning. Rolling Phase 6A back drops all operational intake, inspection, estimates, decisions, work, delivery and event data. Preserve that history before any rollback; earlier admin activities and online requests remain. Reapply starts empty and cannot reconstruct custody records.

Owner review remains necessary for legally sufficient customer-consent evidence, document wording, retention/access policy, external confirmation/signature methods and any inspection/return fee policy. This phase does not claim these are resolved. Closed-record correction/reopening, customer-field corrections after intake, evidence-file uploads, automated customer verification, billing/tax/warranty decisions, parts stock movement, payments, SMS and Phase 6B/6C implementation are not included. Parts are descriptive; no stock is deducted. No new automatic technician action or completed work is inferred.

Stop after the local feature-branch commit for owner review. No push, merge, deployment or next phase is authorized by this delivery.
