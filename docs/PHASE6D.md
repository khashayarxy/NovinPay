# Phase 6D — financial operations

## Domain decisions before implementation

This is an internal receivable, manually recorded receipts and expenses ledger,
not a general accounting, bank verification, tax or statutory reporting system.
Currency is TOMAN, with two decimal places matching Phase 6C. Calculations use
integer hundredths only; stored aggregate sums must stay within signed 64-bit
range. No opening balances, historical payments or implicit obligations are added.

Documents are explicitly issued by authorized finance staff: a sales document
captures a fulfilled order's immutable agreement; a repair document captures the
latest approved estimate only after completed repair. Issuance establishes a
reviewed receivable and preserves the source version, original customer and amount.
Confirmation, estimate approval and delivery alone create no financial entry.
Every document has one historical customer; subsequent CRM linking/reconciliation
does not move it. A document never combines unrelated identities or source debts.

Every payment is allocated to exactly one document. Partial and multiple receipts
are supported; overpayments and unallocated deposits are rejected. Cash uses a
unique internal voucher reference; transfer/card-terminal recordings use an
evidence reference. References are normalized, HMAC indexed and encrypted, with
global duplicate prevention across receipts/expenses. No card, CVV or bank login
fields exist. No entry claims independent bank settlement. Expense categories are
parts, repair labor, supplies, operating expenses and other, with optional document
association. Recorded expenses do not create assumed liabilities or stock costs.

Posted entries are immutable, including at database level. Correction means a
new compensating reversal and, when needed, a new explicitly authorized entry.
No reversal of a reversal; no deleting or editing amounts. Original evidence
references remain reserved even after reversal; corrected evidence must use a
new distinct correction reference and cite the original entry in its private note.
Debit/credit adjustments require evidence and a reason and change only a selected
document, never an arbitrary opening balance. An adjustment cannot reduce the
document charge below its net recorded receipts. Reversals must preserve the same
invariant. A zero-charge document is cleared; otherwise settlement is unpaid,
partial or settled according to net posted receipts, independently of bank status.

Document charge and receipt projections must equal their signed ledger sums.
For each document: 0 <= paid <= charged, outstanding = charged - paid. Expense
reversals offset original expenses. Operation UUIDs bind actor, target and canonical
payload; exact retries return the original result, changed retries conflict.
Transactions lock operation, persisted administrator grant and financial document;
source/customer locks protect issuance against operational/identity races. A
current-password check uses the persisted user inside the write transaction.
No private amounts, references, reasons or customer identity enter global activity.

Reporting distinguishes gross issued sales-document source snapshots, net posted charges,
manually recorded net receipts, recorded net expenses and outstanding receivables.
Receipt less expense is only recorded cash movement, never net profit. The date
filter applies to entry posting timestamps (UTC), not backdated claimed bank dates;
receivables/settlement are current positions, clearly labelled. Reports are bounded,
indexed and do not decrypt private fields for aggregates. No exports or external
integrations are included.

## Preservation and deployment

Only additive migrations are permitted. Local tests use memory SQLite or an
explicitly disposable MySQL service. Herd uses its separately verified development
database and synthetic fixtures. Never migrate or seed database/database.sqlite.
Keep the existing APP_KEY: reference indexes and encrypted history depend on it.
MySQL DDL is non-atomic; failed upgrades require inspection/restore, not blind retry.
MySQL requires enforced CHECK constraints (8.0.16+) and TRIGGER privileges. With
binary logging, the hosting provider must permit trigger creation through an
appropriately privileged deployment account or its existing server policy. CI
enables trust only on its ephemeral MySQL service using the disposable root account;
no production setting is changed. Confirm these capabilities before deployment;
do not weaken production logging or application privileges as an automatic fix.
Rollback permanently removes the new financial documents, entries and operation
keys, including correction evidence. Back up and test restoration before deployment.
No historical transactions are inferred during upgrade. Owner review must approve
financial authority, evidence standards, categories, retention, invoice legitimacy,
and accounting treatment before real use. Production operations remain separate.

## Permission matrix

| Permission | Capability | Required companion grants |
| --- | --- | --- |
| `finance.view` | Summary, redacted history and document positions | Active administrator session |
| `finance.details` | Decrypt evidence reference and private reason | `finance.view` |
| `finance.charge` | Explicitly issue a legitimate source document | `finance.view` and source access |
| `finance.receive` | Allocate a manual receipt | `finance.view`, `finance.details` and source access |
| `finance.expenses` | Record an expense | `finance.view`, `finance.details`; source access if linked |
| `finance.correct` | Compensating reversal or reasoned debit/credit | `finance.view`, `finance.details`; source access if linked |
| `finance.balances` | Historical customer positions and transactions | `finance.view`, `customers.view` |

Source access means `orders.view` or `repairs.view` as applicable. Customer names
also require `customers.contact`. All financial writes verify the current password
against the persisted user inside the transaction. The protected super-admin keeps
the existing authority; staff remain default-deny. Financial grants confer neither
staff management nor operational source privileges. Existing account deactivation,
password-change and permission-change session invalidation remain in force.

## Verification and review evidence

Validation used PHP 8.4.26, MySQL 8.0.46, the locked dependencies and synthetic data.
The complete regression suite passed on SQLite and disposable MySQL: **365 tests,
2,706 assertions per engine**. The financial suite adds 27 executed cases covering
exact decimals (including Persian input), payments, adjustments, immutable history,
duplicate evidence, idempotency, failure rollback, reconciliation, encryption,
direct HTTP denial, private input/session boundaries, source/customer authorization
and repair eligibility. Earlier Phase 2–6C tests remain included.

Two independent MySQL worker connections/processes exercised six financial races:
over-allocation, identical operation retries, globally duplicated evidence on
different documents, double reversal, credit versus receipt and duplicate source
issuance. The harness verifies overlapping execution and connection identities,
and reconciles the resulting ledger. All six passed, alongside all eight existing
inventory/order races. Upgrade, rollback and reapply passed 18 preservation checks
per engine against disposable databases; no owner database operation was performed.

Pint and Vite passed. Composer and npm audits reported zero advisories. Existing
standalone PHPStan 2.3.1 reported zero errors at level 5 for the three finance support
classes and level 0 for the controller, request, services and models. This scoped
check is not complete Laravel/Eloquent type coverage. Larastan was evaluated as
compatible, but not installed: obtaining stronger framework-aware analysis can be
reviewed separately without changing dependency lockfiles in this phase.

Actual Herd browser QA exercised receipt posting, a rejected password (without
replayed private input), expense posting and compensating reversal. A summary-only
staff account saw no private/write links and received HTTP 403 on direct detail,
balance, payment and staff-administration requests. All eight requested screens
were captured at 1440px and 390px; all 16 main-content axe WCAG A/AA scans had no
violations and no horizontal document overflow. This scoped automated scan does
not replace a full manual accessibility audit. Screenshots were visually reviewed.
The final synthetic development ledger had two documents, eight entries and zero
reconciliation discrepancies. Its net receipts were 550,000.30 TOMAN, expenses
125,000.10 TOMAN and outstanding receivables 900,000.20 TOMAN.

DevTools observed HTTP 200 and a local Vite HMR WebSocket handshake error on the
existing development host. Herd/Vite configuration was left unchanged; the
production build passed. This development tooling issue does not demonstrate an
application exception, and should be checked separately before relying on HMR.

## Measured performance and limits

A disposable MySQL dataset held 10,000 documents and 30,000 ledger entries. Ten
warm controller-plus-Blade renders averaged 150.40ms/14 queries for the dashboard
and 138.92ms/13 queries for the customer balance page, with 42MiB peak PHP memory.
Summary aggregation averaged 95.72ms/4 queries. A single persisted authorization
snapshot for presentation reduced dashboard queries from 43 to 14; route and
transaction authorization still recheck persisted grants. EXPLAIN showed indexed
customer lookups, unique reference lookup and a bounded backward index scan for
the filtered list. All 10,000 document positions reconciled exactly.

The local synthetic desktop HTTP trace measured LCP 383ms, TTFB 168ms and CLS 0.00,
without CPU/network throttling. These are local measurements, not production
capacity or field-performance guarantees. Pages use 20/25-row pagination and
366-day date limits. Current-position and discrepancy aggregates still scan the
ledger/documents; deep offset pagination and aggregate growth need future
monitoring. No export, permanent worker or new runtime dependency was introduced.

## Owner decisions and later deployment

Before real financial recording, approve which staff can issue receivables,
whether fulfilled-order/completed-repair eligibility matches business policy,
the unique evidence/correction-reference convention, two-decimal TOMAN handling,
retention/access rules and the treatment of unallocated deposits/overpayments
(currently rejected). Review these internal document definitions with the owner's
accountant; this module does not implement a statutory invoice or net-profit report.

Deployment requires an explicitly approved maintenance/backup plan, tested restore,
the unchanged encryption key, verified MySQL CHECK/TRIGGER/server-policy support
and application/runtime compatibility. The additive migration is not a production
deployment authorization. Review-only CI/PR publication must not enable automatic
merge or deployment. No gateway, bank API, online refund, SMS, tax filing or Phase 7
work is included.

## Historical local preservation exception discovered at final audit

The original SQLite file's final checksum differed from the Phase 6D starting
checksum. A strictly read-only SQLite investigation found integrity `ok`, no foreign
key violations, and the same schema, seven migration records, owner user row and
seven product rows as the retained earlier audit (full row hashes matched).
No Phase 6D financial tables or migrations exist in that database.

The current database has anonymous session activity for the separate legacy
`novinpay.test` host, most recently at 2026-10-21 13:25:50 UTC, coincident with the
file modification time. Cache/session contents differ from the older retained
audit. The canonical `novinpaypos.test` host retains its explicit isolated database
configuration. Both host configurations were regenerated when Herd started at
13:25:17 UTC. Access logging is disabled, so the initiating client/process cannot
be identified and an exclusive cause cannot be proven from available evidence.
There is no row-level snapshot at the exact Phase 6D start, only its file checksum.

The original database was not repaired, restored, migrated or seeded. The owner
subsequently approved host-specific guards and removed obsolete Herd mappings;
the separate local safety gate passed before final polish resumed. The final
polish audit retained the current checksum and all table row hashes throughout
canonical browser testing. See PHASE6D-POLISH.md for the new validation results
and remaining unscoped CLI risks. Production database operations and deployment
still require separate owner authorization and the prerequisites above.
