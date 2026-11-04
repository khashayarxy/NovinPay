# Repair simplification and support chat (Draft PR #13)

## Repair workflow

New physical intake uses six fields in three rows. Dates are recorded by the server and displayed in Tehran time. The compact record replaces required technician, diagnosis, estimate and customer-decision stages for new/simple records. Existing history and legacy operation services remain for historical compatibility; they cannot operate on a simple record.

Repair prices use integer toman; the compact input normally accepts thousands of toman. Existing amounts not divisible by 1,000 use exact-toman mode. Issued financial documents are never rewritten. The settlement checkbox confirms an already recorded, reconciled receipt; it cannot create a gateway success or a new receipt. A paid document cannot be unchecked into an unpaid state. Delivery still requires the existing settlement and delivery-plan guards.

New tracking codes are reserved by a database primary key across online and physical intake. Eight random digits are not an authentication secret: public tracking continues to require the normalized phone and rate limits. Old NP references remain valid, including the linked original request. New encrypted revisions have update/delete database triggers; rollback refuses to drop used history or ownership mappings.

## Chat source and adaptation

Authoritative source: `khashayarxy/zizolearn`, commit `7d6f6ddcfbde4fd3fb1857f62504d230e4c43bb5`. `resources/support-chat/UPSTREAM.json` and `UPSTREAM-TOOLS.json` pin the original files. Only engine/client/tool source and generic fonts/icons were imported. No ZizoLearn configuration, messages, customer records, payment or bot code was imported. Changes are NovinPay branding, the private resource loader, Laravel response/session/permission boundaries, native staff/password management, local fonts and worker bootstrapping.

The original engine is file-backed, not a SQL chat rewrite. Its private file locks, atomic replacement, durable ACKs, retained receipts, deletion ledger and independent CSAT authority are retained. SQL only binds proven customer sessions to authenticated NovinPay customer accounts. No identity is granted by matching a supplied name or phone.

| Upstream capability | NovinPay implementation | Validation / activation |
| --- | --- | --- |
| Customer launcher, full chat, themes, mobile layouts, RTL, emoji, links, image viewer, reply grouping | `support-widget.*`, `support-client/*`, NovinPay fonts/color/logo | Import closure and syntax checked; hosted visual acceptance required |
| Near-real-time polling, cursor history, visible reads, typing and presence | Native `api.php`, activity/typing endpoints, sync/attention engine | Local request integration covers history and reads; hosted two-browser presence pending |
| Reliable browser outbox, retry, ACK lookup, payload conflict protection, drafts/session recovery, edits | Native engine/outbox/sync/edit modules and private stores | Duplicate ACK, conflicting retry, edit and foreign-session denial tested; interrupted-network browser QA pending |
| Guest conversations and authenticated customer history | Native secure cookie plus `ChatBridge`, private SQL owner bindings | Guest ownership and account logout/revocation tested; no phone-only recovery |
| Complete operator inbox, assignments, notes, labels, priorities, snooze, search, contacts, tabs and quick replies/macros | Native operator application/modules and API | Inbox/reply/read/lifecycle integration tested; remaining UI acceptance on staging |
| Operator authentication, permissions, profile/avatar, team administration | Laravel login + fresh persisted grants; `support.view`/`support.manage`; native public profiles; existing `/admin/staff` and `/admin/account/password` | Native duplicate password/account creation is rejected. Only the protected Laravel owner manages staff credentials. Session-version and password confirmation tested |
| Image attachments and avatars | Native validated JPEG/PNG/WebP decoding and WebP re-encoding; private authorized downloads | Original max 3 images, 5 MiB each retained. Arbitrary files/PDFs were not supported upstream. Real multipart upload/download acceptance on staging required |
| Close, reopen, new episodes, unified history, unread/operator read states | Native lifecycle, independent visible-read and episode state | Close/reopen and duplicate CSAT tested |
| CSAT, feedback reasons/comments, dashboards, aggregate analytics, response/business-hour reporting, CSV | Native satisfaction/analytics/operations/health modules | CSAT and analytics request tests pass; hosted screen/CSV acceptance pending |
| Knowledge/FAQ, suggested questions, public operator profiles, branding settings | Native content/knowledge/branding/profile modules | Complete source retained; staging functional acceptance pending |
| Foreground alerts, notification preferences, PWA and worker recovery | Native attention/PWA/notification modules; service-worker scope restricted to `/support-chat/operator/` | Foreground behavior retained; external push/SMS/email transports remain OFF and unqualified |
| Optional secret/code/phone recovery, recovery email and durable notification workers | Native modules plus private CLI tools; Laravel-verified customer account binding is the current recovery path | Retained but external issuance/delivery disabled. No borrowed credentials. SMS remains disabled per owner |
| Retention, attachment pruning, bounded audit/security history, worker inspection and recovery | Native CLI tools and `support:cleanup` | Dry-run by default. Staging/testing guard; retention requires explicit arming, warmup and apply. Never auto-delete active conversations |

This inventory records ported capability, not a declaration of completed hosted parity. Two authenticated operators, uploads, network interruption/recovery and browser accessibility/320px/390px acceptance remain mandatory before calling the integration complete.

## Isolated staging operation

- Existing staging only: `/home/novinpay/novinpay-staging-workflow`, database `novinpay_stg38`; production is not an update target.
- Preserve Basic authentication, private `.env`/APP_KEY, administrator and all storage. Add only the two reviewed migrations (22 total, 13 triggers).
- `SUPPORT_CHAT_ENABLED=true` is staging-only. `php artisan support:initialize` creates a new private installation under `storage/app/private/support-chat` (0700 directories/0600 files). It never imports ZizoLearn state or resets an existing authority.
- The public wildcard exposes an explicit PHP endpoint allowlist and static browser assets; engine source, CLI tools, installation configuration and provenance are never public.
- Native chat CSRF protects every state-changing endpoint. Laravel CSRF exemption is confined to that protocol route; admin reauthentication continues to use Laravel CSRF/current-password checks.
- Runtime needs GD/WebP, fileinfo, mbstring, OpenSSL, private native PHP sessions, flock, rename and fsync. No daemon or WebSocket process is required.
- Do not enable SMS, live Zibal, prepaid Postex billing, shipment operations or real push/email workers during this staging phase.
- No scheduler is required for chat polling. Optional cleanup: `php artisan support:cleanup` (dry-run); `--apply` prunes eligible orphan attachments. History retention additionally needs a separate `--arm-retention`, the native warmup, and `--apply --retention`. All commands are staging/testing-scoped.
- Back up the entire private chat directory together with the SQL ownership table. Never restore only chat JSON while discarding the newer deletion/CSAT/security authorities. Do not roll back to code that ignores simple-workflow fields or ownership mappings after usage; use a reviewed forward-compatible code rollback.

Production, payment backend, credentials and provider activation are outside this change. Draft PR #13 must remain unmerged.
