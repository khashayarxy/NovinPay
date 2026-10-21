# Phase 6C — internal orders and physical inventory

## Domain decisions recorded before implementation

Catalog products remain marketing records. An inventory item is a separately approved SKU, optionally linked to a catalog product, with a fixed new/used condition and fixed serialized/bulk mode. Creating inventory never imports sample products or invents opening stock. SKU and normalized physical serial are globally unique. Each serialized receipt creates one unit; removed/dispatched serials cannot be reused.

Orders are internal sales agreements in **TOMAN**, not payments or invoices. Prices use DECIMAL(15,2), string parsing and integer hundredths; no floating arithmetic. Lines snapshot SKU, description, condition and agreed prices. At most 30 distinct items and 10,000 bulk units (100 serialized devices) per line; changes are allowed only in draft. No customer name, phone or address is copied into unencrypted order fields. Fulfillment instructions are encrypted and separately protected. Earlier line revisions remain stored after draft edits, including references from released reservations.

Order state machine: `draft -> reserved -> confirmed -> fulfilled`. Draft/reserved/confirmed can be cancelled. Reserved/confirmed can explicitly release stock back to draft with a reason. Terminal orders cannot change. Fulfillment is deliberately **all or nothing**: partial delivery requests are rejected; split deliveries require separate orders. Confirmation is neither payment nor delivery. Fulfillment records the physical handover only; Phase 6D owns payment/accounting. Orders require an active, unmerged customer when created/edited; historical associations survive later customer archiving/reconciliation.

Inventory: `available = on_hand - reserved`, all quantities nonnegative and reserved <= on_hand. Reservation changes only reserved; release reverses reserved; fulfillment decreases on_hand and reserved and increases dispatched in the same transaction. Receipt adds on_hand; negative correction removes only available stock. Serialized unit states: on_hand -> reserved -> dispatched; reserved -> on_hand on release; on_hand -> removed for a justified negative adjustment. Removed/dispatched are terminal. Corrections append compensating movements and reasons, never edit existing history. Positive correction uses a new receipt/serial; thresholds alone can be edited.

Every write uses a UUID idempotency key bound to actor, action, target and canonical validated payload digest. Replaying the identical command returns its original result even after version changes; reusing a key for another command returns 409. Failed commands roll back the key and every business/audit write together. Keys are retained as long as business history. Version checks reject stale commands.

Lock order: idempotency record, order (if applicable), customer for draft association, inventory items ascending id, units/reservations. All stock-changing paths lock the same inventory parent rows. This serializes competition for an item without a global application lock. Duplicate SKU/serial are backed by unique database constraints; repeated fulfillment is prevented by the locked order state and version, with a unique operation key for identical retries. Transactions retry database deadlocks, with safe conflict responses after exhaustion. A read-only order view does not reserve anything. A test failure injected after movement writes must roll back the complete command.

Authorization remains default-deny with independent view, stock receive, adjust, configure, order create/edit, reserve/release/cancel, confirm, fulfill and customer-data permissions. Customer selection/contact requires existing CRM view/contact plus the new order-customer permission. Ordinary order readers see only a customer UUID; they cannot decrypt contact/delivery details. All actions reauthorize inside the transaction. Critical actions append metadata-only global activities and protected domain history; reason/delivery text is encrypted and never logged or flashed.

Queries are bounded and paginated. Lists select only required fields; SKU/serial search uses indexed exact equality, with bounded name prefix search. Order number is an indexed UUID. No wildcard searching encrypted customer values. No new public catalog joins, counts or stock side effects. Bulk fixtures and profiling remain outside production assets. No Redis, persistent workers or new production dependency.

## Entities and indexes

One additive migration, `2026_10_21_000002_create_order_inventory_tables.php`, creates eight tables: `commerce_operations`, `inventory_items`, `inventory_units`, `sales_orders`, `sales_order_lines`, `stock_reservations`, `stock_movements`, `sales_order_events`. It does not alter earlier tables or create stock from catalog samples. All ownership/history references use restricted foreign keys. A catalog record referenced by inventory cannot be deleted; unpublish it instead.

Unique keys cover operation UUID, item UUID/SKU, global normalized serial, order UUID, order/item/revision, operation/unit reservation pair, operation/item movement and operation/order-event. List indexes cover condition/id, low-stock flag/id, item name, unit item/state/id, order status/id and customer/id, current order lines, reservation line/status, movement item/id, order/id, kind/id and order-event order/id. Index names are explicitly shortened where required by MySQL's 64-character limit. The indexed low-stock flag is maintained in every stock/threshold transaction and covered by reconciliation tests.

## Permission matrix

Super-admin protection and the original role/session gates remain in force. Existing staff receive none of these new permissions automatically. The owner assigns grants explicitly through the established staff workflow; dependency checks reject incomplete grants.

| Action | Required permissions |
| --- | --- |
| Inventory list/detail/movements and serials | `inventory.view` |
| Define SKU / change threshold | above + `inventory.configure` |
| Receive stock | above + `inventory.receive` |
| Associate a SKU with an existing catalog product | define-SKU grants + `products.view`; otherwise only independent stock can be defined |
| Correct available stock | above + `inventory.adjust` |
| Order list/detail and nonpersonal history | `orders.view` |
| Contact and encrypted delivery details | above + `orders.customer_data`, `customers.view`, `customers.contact` |
| Create/edit draft and choose a customer | contact grants + `orders.create`, `inventory.view` |
| Reserve, release or cancel | `orders.view`, `orders.reserve` |
| Confirm | `orders.view`, `orders.confirm` |
| Fulfill | contact grants + `orders.fulfill` |

Customer profiles show paginated order links only with `orders.view`. CRM reconciliation moves the previously supported repair/consultation associations; sales orders deliberately retain their historical customer association. The interface explicitly explains this. A new draft can be created for the destination after identity review; confirmed history is never silently moved. Drafts can change their customer through the normal versioned, audited edit workflow.

Reasons in stock/order history and delivery information are encrypted. Order-history reasons/delivery/contact are hidden without the contact grants; stock readers do not see order reasons through movement history. Global activities contain only actor/action/target/time. No deletion or history-edit endpoint exists. All Blade text is escaped, private forms do not flash input, and errors retain the earlier SQL/binding redaction. Native Laravel 13 request-forgery protection and the existing private/no-store headers remain active. Mutations are rate limited. The navigation uses a single persisted permission snapshot per rendered menu; actual route and service authorization still queries current grants and rechecks inside transactions.

## Executed verification

Full SQLite **3.53.4** and disposable loopback MySQL **8.0.46** suites each pass **338 tests / 2,550 assertions**, including all preceding phases and 44 order/inventory test cases. Coverage includes conservation, new/used separation, receipt/correction, serial uniqueness, draft revisions, release/cancellation, valid/invalid transitions, partial/repeated delivery attempts, money precision, idempotency/actor binding, stale writes, injected transaction failure, direct HTTP denials, live grant revocation, private data and constant list query counts. Pint and Vite pass. Composer locked audit and npm audit report zero advisories; neither dependency manifest/lockfile changed.

Isolated upgrade/rollback/reapply harnesses pass **13 checks on each database driver**: unchanged preexisting synthetic owner credentials/grants, products and customer records, empty new modules, removal of the eight new tables on rollback, unchanged prior migration records and empty reapply. No original-database operation was used. MySQL testing caught and corrected an overlong generated index name; SQLite alone would not have caught it.

`tests/Concurrency/run.php` and `worker.php` exercise **eight actual competing-process/connection scenarios**: bulk/serialized last-unit reservation, bulk/serialized double fulfillment with different keys, same-key reservation and fulfillment, adjustment versus reservation and duplicate global serial receipt. The first worker signals after its protected write and holds the transaction for 1.2 seconds; the second uses a distinct PID/connection and must actually wait. Outcomes are 200/409 for competing commands and 200/200 with a single ledger effect for identical retries. Inventory quantities and serial uniqueness are checked after competition. This harness was itself executed successfully, separately from sequential PHPUnit tests.

The standalone harness intentionally rebuilds only an explicitly disposable MySQL schema. It refuses unless `APP_ENV=testing`, uncached configuration, `NOVINPAY_DISPOSABLE_COMMERCE=1`, MySQL at `127.0.0.1` on a nondefault port above 1024, a database named `novinpay_phase6c` (or that prefix plus `_suffix`) and `NOVINPAY_COMMERCE_ARTIFACTS` naming an existing directory outside the repository. Set a complete disposable `DB_URL` as well as the connection fields so the ordinary `.env` cannot redirect it. Then invoke `php tests/Concurrency/run.php`. Credentials must be process-scoped and never committed. Do not run it against a shared or original database.

## Measured performance

Representative MySQL fixtures add 10,000 items, 30,000 orders, 90,000 lines, 4,200 serialized units and 10,000 movements. Including competition fixtures, the measured tables contain 10,006 items / 30,006 orders / 90,006 lines and seven catalog products. Measurements use ten warm controller-plus-Blade renders per operation, excluding HTTP/TLS/middleware. They are local measurements, not a shared-hosting capacity guarantee.

| Screen, same large dataset | Before navigation optimization | After optimization | Final query count |
| --- | ---: | ---: | ---: |
| Inventory list | 81.42 ms | 27.17 ms | 8 |
| Low-stock list | 70.12 ms | 26.41 ms | 8 |
| Exact SKU | 59.02 ms | 24.37 ms | 8 |
| Draft orders | 67.53 ms | 35.54 ms | 8 |
| Movement list | 66.69 ms | 29.58 ms | 8 |

New list screens previously issued 28 queries because of repeated menu permission checks; they now issue eight, independent of page row count. List pages are bounded at 20/25 records, domain detail histories at 15/20/30 records. Draft suggestions are bounded to the first 100 inventory/catalog entries; full UUID input and the paginated directory remain available beyond that limit. Customer contact information is not bulk-decrypted for suggestions.

MySQL EXPLAIN confirms unique/constant access for exact SKU and serial, the primary descending index for inventory pages, low-stock/status/history indexes for filters, and a name-index range for escaped prefix search. Name search may filesort its matching range; broad prefixes and deep offset pages remain scale limits. No unbounded display query or per-row related fetch was introduced. The optimized CLI process peaks at approximately **28 MiB**.

Approved Phase 6B public controller sources were loaded separately for an actual same-dataset comparison, using the unchanged public Blade views: home 14.38 -> 14.40 ms with zero queries; catalog 12.88 -> 13.37 ms with two queries. This small local variation shows no material regression in the measured paths. Public controllers/catalog queries were not changed.

Cold-cache Herd DevTools traces (local synthetic SQLite, no CPU/network throttling) measured inventory LCP **173 ms**, TTFB **75 ms**, CLS **0.01**; order detail LCP **191 ms**, TTFB **93 ms**, CLS **0.01**. Normal page network requests were successful and console had no errors/warnings. Shared fonts still account for approximately 284 KB of cold-cache resources; production static-cache policy is deployment work. Private pages must remain no-store.

## Browser review and tools

Playwright exercised synthetic stock creation/receipt/serial correction, multi-line order creation, exact decimal totals, reservation, confirmation, fulfillment, draft price revision, release and cancellation on the verified isolated Herd database. Read-only reconciliation confirmed every synthetic item's counters match its movement sums and serialized states. Restricted order readers see neither contact nor delivery text, have no action forms, and direct write/create attempts return 403. A separate authenticated HTTP client without `Sec-Fetch-Site` receives 419 without a token and 422 for a correctly tokened invalid payload. Same-origin browser requests can legitimately pass Laravel 13's origin check without a token; authorization still denies them.

Twenty desktop/mobile captures across ten screens (1440x1000 / 390x844) were checked with axe-core **4.14.0** for WCAG 2 A/AA and 2.1 AA: no violations, one h1 and no document overflow. Native full-page PNG bounds may exclude the scrollbar. Additional restricted-reader captures also pass. Review fixed two shared text colors with 4.44:1 contrast; final mobile Lighthouse accessibility/best practices scores are **100/100**. Low admin SEO is intentional because of noindex/no public metadata. Automated accessibility checks complement visual/keyboard review and do not certify every assistive-technology scenario.

The requested tooling evaluation is in [PHASE6C-QUALITY.md](PHASE6C-QUALITY.md). Existing Context7/Playwright/DevTools/Herd were reused. QA reports, temporary tool downloads, credentials, fixtures, traces and screenshots remain outside Git and production assets. Original SQLite, `.env`/`APP_KEY`, modified `.env.example`, upload ZIP and both dependency lockfiles remain checksum-identical to the phase start; neither extra Documents directory nor the unapproved mascot was modified.

## Deployment and owner decisions

Source delivery is local-only. Deployment requires separate approval, backups of database/uploads/environment, a tested restore procedure and the same `APP_KEY`. Apply all pending earlier migrations in order if upgrading the old original schema, then use the established trusted-owner bootstrap and explicitly assign new permissions. Never use `composer setup` or generate a replacement key on an encrypted installation. Build frontend assets for the approved target; the preserved upload ZIP was not regenerated.

MySQL DDL is not atomic. A failed migration can leave partial new tables; stop and inspect/restore rather than blindly retry. Rollback drops all Phase 6C inventory, serials, reservations, orders, movements, history and operation keys. Reapply creates empty modules and cannot reconstruct transactions. Back up new business data before any rollback. No original/production migration or hosting change occurred during this phase.

Owner decisions before operational use: approve SKU/serial naming and real opening counts, set reorder thresholds, confirm TOMAN and two-decimal agreement semantics, assign receiving/adjustment/reservation/confirmation/delivery authority, define delivery evidence and retention policy, and approve the all-or-nothing delivery and historical-customer-association rules. Returns, partial delivery, payments, accounting, gateways, financial reports, public checkout and Phase 6D are not implemented.
