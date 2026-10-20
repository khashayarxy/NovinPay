# Phase 5 — Administration foundation

Completed locally on `codex/novinpay-admin-foundation`, based on Phase 4 commit `33ab87389e6f1633d7213157cf500330e87ab148`. No push, merge or production deployment is included. This document supersedes the email-based authorization described in the historical Foundation guide.

## Implemented modules

- Persian RTL dashboard with permission-scoped database counts, recent online requests, recent activity, operational shortcuts and empty states. Desktop sidebar and native mobile disclosure navigation use the existing logo, typography and Blade/Tailwind stack.
- Protected super-admin and explicitly permissioned staff: creation, editing, activation/deactivation and password reset. No public registration, promotion, account deletion or owner reassignment endpoint exists.
- Account security page, forced first password change, session revocation, private response headers and existing login throttling.
- Read-only activity history and online repair-request list/detail. No repair mutation routes were introduced.
- Existing product management is permission-controlled and audited; public website behavior is retained.

## Authorization and owner preservation

`admin_accounts` has one unique user grant and a nullable unique `owner_slot`. Only an active `super_admin` grant with slot `1` is the owner; staff grants must have role `staff` and no owner slot. The unique slot prevents a second protected owner. Authorization reads persisted grants on every request and defaults to denial. Email configuration and mutable/in-memory User attributes do not grant runtime access.

| Permission | Access |
| --- | --- |
| `dashboard.view` | Overview; each count and shortcut also requires its module permission |
| `products.view` | Product list |
| `products.manage` | Create/update/delete products; assignment requires `products.view` |
| `repairs.view` | Request list and non-personal detail |
| `repairs.customer_data` | Customer name, phone, device model and issue description; assignment requires `repairs.view` |
| `activity.view` | Read-only activity history |
| `staff.manage` | Owner-only Gate; never assignable to staff |

Owner grants bypass the known module permission checks. Unknown permission values are rejected. Future modules can extend the native enum/Gate structure using names such as `orders.view`, `inventory.manage`, `customers.view`, `consultations.view`, `communications.manage` and `settings.manage`; these names are not currently grantable and no corresponding business modules exist.

Staff administration requires both the protected owner Gate and the owner's current password, checked again in the transaction. Explicit field assignment prohibits role/user-ID/owner-slot/session-version injection. Existing users cannot be claimed through staff creation. The owner's account cannot be edited, demoted, disabled or deleted through staff routes; its credentials are preserved. Owner self-service password change is separate and requires the current password.

New/reset staff passwords require at least 12 characters, mixed case and numbers, with confirmation. The recipient must change the temporary password before any module action. Deliver temporary credentials through an owner-approved private channel; this phase sends no credential emails. No MFA, invitation or automated recovery workflow is implemented.

Every staff update rotates the remember token and increments `session_version`, including permission edits, activation/deactivation and password resets. Existing sessions fail on their next protected request; reactivation does not revive them. Password changes increment the version and regenerate the current session; other sessions are revoked. Legacy admin sessions without a version must log in again. Logout remains available after revocation.

## Repair-request and activity boundaries

Repair access is enforced by Gate and record Policy, including direct HTTP requests. `repairs.view` currently grants access to all organization's online requests; there is no per-technician assignment model yet. Lists and dashboard rows select metadata rather than customer fields. Customer fields are only rendered/decrypted with the additional permission; a sensitive detail view is audited before rendering. Online submission explicitly does not confirm physical receipt.

Activity rows record only actor ID, typed action, target type/ID and UTC timestamp. They cover bootstrap, login/logout, own password changes, staff changes, permission/activation/password-reset changes, product mutations and customer-data views. No passwords, tokens, emails, customer payloads or before/after values are stored. Staff/product mutations and their log insert share a database transaction. An audit insertion failure rolls back the staff mutation, verified by a regression test.

History has no write HTTP routes; Eloquent update/delete attempts are rejected. This is application-level append-only history, not cryptographic tamper evidence against a privileged database operator. Protect database access and backups separately. Events retain numeric identifiers without storing personal labels; there is no export or retention-deletion interface in this phase.

## Files and migration

The only new migration is `database/migrations/2026_10_20_000002_create_administration_tables.php`. It adds `admin_accounts` and `admin_activities`; it does not modify/backfill users or repair requests. Existing owner records and password hashes are not saved by bootstrap.

Implementation is in `app/Enums/Admin*`, `app/Models/Admin*`, `app/Support/Admin*`, `app/Services/StaffAdministration.php`, `app/Policies/RepairRequestPolicy.php`, `app/Console/Commands/BootstrapSuperAdmin.php`, the administration controllers/middleware/request, `AppServiceProvider` and `routes/web.php`. UI changes are under `resources/views/admin`, `resources/views/components/admin`, the admin layout/login, and `resources/css/admin.css`. User factory, owner seeder and authorization/product regression fixtures were updated. `AdminFoundationTest` and `SuperAdminBootstrapTest` add security coverage. README documents the new boundary; no runtime dependencies were added.

## Later deployment: safe migration and bootstrap

These instructions are for a separately approved deployment. **They were not run against the owner's database.** Do not run the generic new-install steps against an existing installation.

1. Schedule maintenance and back up the database, uploads and secure environment configuration. Preserve `APP_KEY`: Phase 4 customer fields depend on it. Verify restoration procedures and PHP >= 8.4.1. Rehearse on an isolated copy, including the target MySQL/MariaDB version when applicable.
2. Verify the existing owner's exact user ID and email through trusted local administration. Set trusted `ADMIN_EMAIL` to that existing identity. Do not run seeders to reset an existing owner. `AdminUserSeeder` now preserves an existing user's full credentials and grants no role.
3. Enter maintenance mode using the host's normal procedure, deploy the reviewed code/assets, refresh configuration through the host's normal cache procedure, and run `php artisan migrate --force`. Migration grants nobody access: maintenance must continue until bootstrap succeeds.
4. Run `php artisan admin:bootstrap VERIFIED_EXISTING_USER_ID` without `--apply`. This is identity verification only and makes no changes. Inspect the result and persisted identity. Then explicitly run `php artisan admin:bootstrap VERIFIED_EXISTING_USER_ID --apply`.
5. The command creates one protected grant and an activity event in a transaction. It does not change the user's email, name, password or remember token. Repeating it for the same active protected owner is a no-op. It refuses another owner, recovery/reassignment, a mismatched configured identity and applying over an existing staff grant. A dry run verifies identity, not all concurrent database conditions; inspect any apply failure and remain in maintenance.
6. Verify owner sign-in, ordinary-user denial, session renewal and the public website on the deployed target. Configure HTTPS/secure cookies normally; no local-preview overrides belong in production. Leave maintenance only after these checks. Create staff later with explicit least privileges.

Rollback of the migration drops grants and activity history; back up before any rollback and retain maintenance mode. Rolling code back to the historical email Gate changes the authorization model and must be explicitly reviewed. This phase provides no automatic owner recovery or role reassignment command. A lost owner credential requires a separately reviewed operational recovery procedure.

## Verification and evidence

- Full isolated PHP suite: **220 passed, 1600 assertions**, including existing public/product regressions. The PowerShell test runner forces in-memory SQLite, isolated configuration and array session/cache/mail.
- Security tests cover DB-backed owner access, default deny, direct unauthorized HTTP requests, forged privilege fields, reauthentication, forced password changes, permission/session revocation, deactivation/reactivation, audit immutability and transaction rollback, customer-data boundaries and idempotent bootstrap preserving the full existing User attributes.
- Repository-wide `vendor/bin/pint --test`, `npm run build` and `git diff --check` passed. No dependency changes. Phase 5 was tested on SQLite; its new migration has not been tested against a real MySQL/MariaDB server in this phase (older MySQL results in README describe earlier phases only).
- Actual browser inspection at **1440px desktop and 390px mobile** used a copied temporary SQLite database with clearly synthetic owner/staff/customer fixtures. Counts reflect that database, not fabricated UI numbers. Reviewed dashboard, mobile navigation, owner staff list/form, request list, permitted customer detail and restricted staff detail. No password changes or staff mutations were submitted through the browser. Those actions are covered by automated tests.
- Screenshots are local review artifacts outside Git: `phase5-dashboard-desktop.png`, `phase5-dashboard-mobile.png`, `phase5-navigation-mobile.png`, `phase5-staff-desktop.png`, `phase5-staff-form-desktop.png`, `phase5-repairs-desktop.png`, `phase5-repair-detail-desktop.png`, `phase5-repair-restricted-mobile.png`. Directory: `C:/Users/Khashayar/.codex/visualizations/2026/10/20/01a15969-8b3c-7783-9b3b-bade84862fc0/`. Images contain synthetic QA identities, not production users. They are viewport captures, not stitched full-page renders. Browser viewport settings were 1440×1000 and 390×844; the stored dashboard image bounds are 1425×990 and 375×811 respectively. Actual file dimensions are recorded in `phase5-screenshot-metadata.json`; `phase5-gallery.html` links all eight captures.
- After resuming the session, the browser tool refused access to the stale error-page tab under its URL policy. No alternate browser path was used to bypass that restriction; previously captured screenshots were inspected from disk for delivery.
- `.env.example` and `public/build-upload.zip` still match their initial SHA-256 hashes and are excluded from the Phase 5 commit. The original SQLite file's checksum changed between sessions, so byte-for-byte preservation of that database is not claimed. A read-only comparison with the pre-migration preview copy confirms the original owner row (including credentials) and all seven product rows are identical. The original schema still has seven migrations and no Phase 5 tables; no original-database migration or seeder was run. The precise source of the checksum change is not established; existing session/cache tables are mutable. No original data was restored or overwritten. No production hosting changes, push or merge were performed.

## Deferred to Phase 6 or separate approval

Physical device intake, repair status transitions, estimates, receipts, delivery, SMS and payments remain absent. Orders, inventory, customer management, consultations, communications and settings modules are also unimplemented. No migration/deployment approval is implied by this local implementation.
