# SMS.ir and isolated cPanel deployment readiness

## Verified on 2026-11-03

- Official contract: <https://api.sms.ir/swagger/index.html>, linked specification <https://api.sms.ir/swagger/v1/swagger.json> (SHA-256 `5d8f1c93eace5117398b222d3c2f52703a4d05f666181965ef78908211d81122`). Authentication is `x-api-key`. Sending is `POST /v1/send/verify`, with `mobile`, integer `templateId`, and `parameters[{name,value}]`. Template inspection is **GET** `/v1/templates/{id}`.
- The private local configuration has a credential and positive numeric OTP template ID. Its parameter is `Code`. OTP sending and notifications are OFF; no notification templates are configured. The actual credential, template text and private configuration are not included in this document or Git.
- A single read-only template lookup returned **HTTP 401 / provider status 12**. Template identity, approval and exact parameter spelling could not be independently verified. Do not reinterpret this as a template rejection, successful authentication, or successful delivery. Resolve the access restriction with SMS.ir using the intended caller's outbound IP/API permissions; do not blindly rotate keys or relax IP protection.
- The public docs use example parameter names; they do not establish this account's template spelling. Neither `CODE` nor `Code` is assumed valid. The adapter preserves the explicitly configured spelling and the read-only checker compares it exactly with the template metadata, including case and parameter count.
- No live SMS was sent. An accepted provider request is not a handset-delivery receipt. `sent` in the existing outbox means provider-accepted with a positive integer `data.messageId`; no delivery-report integration is claimed.

## Private configuration — remain disabled pending provider acceptance

```dotenv
SMSIR_ENABLED=false
SMSIR_API_KEY=
SMSIR_OTP_TEMPLATE_ID=
SMSIR_OTP_PARAMETER=
SMSIR_APPROVED_TEMPLATE_IDS=
SMSIR_NOTIFICATIONS_ENABLED=false
SMSIR_NOTIFICATION_PARAMETER=
# Set the existing SMSIR_TEMPLATE_* IDs only for reviewed event templates.
ZIBAL_ENABLED=false
ZIBAL_LIVE_REQUESTS=false
POSTEX_INDEPENDENT_POSTAGE_VERIFIED=false
```

Copy the parameter name from the actual approved provider template, without `#` delimiters or case conversion. `SMSIR_APPROVED_TEMPLATE_IDS` is a comma-separated operator attestation of IDs whose provider approval AND exact parameter contract have been reviewed. It does not alter or bypass SMS.ir approval. Adding credentials or a template ID alone never makes it eligible. New notification templates require their IDs in that list; unconfigured/unapproved templates cannot starve configured events. Keep notification sending OFF until every enabled event/template is accepted, including allowed URL parameter lengths/content.

After the provider resolves access, this optional command performs only the documented OTP-template GET and reports non-secret metadata. It does not send, approve a template, change configuration or access customer rows:

```sh
cd /home/novinpay/novinpay
/opt/cpanel/ea-php84/root/usr/bin/php artisan customers:sms-status --provider
```

Without `--provider`, the command makes no external request. Neither command has been executed on production. A metadata match still requires approval confirmation in the provider panel. A live OTP test needs separate explicit authorization, a single owner-controlled number, and an approved template. No fixed-code/test authentication bypass exists.

## OTP, outbox and scheduler

OTP is synchronous, not a queue job: CSPRNG six digits, keyed phone index, encrypted phone, password hash only, three-minute expiry, one-minute resend cooldown, five guesses, single use and locked replacement. Existing IP and phone rate limits apply. Rejected/unknown send acknowledgements invalidate the new code; timeouts do not retry automatically. Customer sessions remain separate from administrator sessions, with revocation and private historical-account linking.

Notifications use the existing SQL outbox. Event keys are unique, claims are conditional, disabled accounts are cancelled, and interrupted/uncertain sends move to review without blind resends. Do not call the command against production merely to test it: it may send when enabled. At most 20 messages are attempted per run. It requires no persistent `queue:work` process. The existing scheduler runs both reconciliation and notifications with overlap locks; review the existing cron before adding anything.

For a later approved deployment, the intended single cron is `* * * * * cd /home/novinpay/novinpay && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /home/novinpay/novinpay/storage/logs/scheduler.log 2>&1`. This is a reviewed proposal only, not an installed task. Keep the cache store capable of atomic scheduler locks, make logs private, and include them in log rotation. Do not add duplicate cron entries, dev servers, or background workers.

## Isolated hosting candidate and release gates

1. Source comes from this feature branch's exact committed tree, not the unrelated untracked release tooling. Package with native `git archive`, then include the already-built `public/build` files and a per-file SHA-256 manifest. Exclude `.env`, credentials, local databases, runtime storage, Git metadata, Composer vendor and local QA files. No Node is required on cPanel. The candidate is for private isolated rehearsal, not a merged/approved production release.
2. Runtime remains Laravel 13 / locked PHP >=8.4.1 (including patched Symfony Mime 8.1.10), native MariaDB 10.6.28, InnoDB and the existing strict UTC/timestamp session settings. Check CLI `/opt/cpanel/ea-php84/root/usr/bin/php` and actual LiteSpeed web PHP independently. Do not change global SQL settings, handlers or OPcache. Use locked `composer install --no-dev` through the reviewed deployment procedure, never `composer update` on hosting.
3. A new isolated directory/database/hostname requires its own provisioning authorization. Use a sanitized copy of the current schema/data or an approved synthetic dataset, isolated sessions/cache/APP_URL and private configuration. Keep real providers disabled. Never share live storage, session/cache namespaces or the live database with staging; preserve the original APP_KEY securely where a copied encrypted dataset requires it. Do not generate a new production key.
4. The schema upgrade is 14 to 20 migrations. Existing CI already tests populated financial preservation, MySQL/MariaDB rollback/reapply, trigger invariants and competing allocations. Hosted rehearsal must retain customer/financial/inventory/repair rows and existing trigger definers. Retain separate runtime CRUD and migrator privileges. Do not rerun installation, provisioning or owner bootstrap.
5. The historical maintenance release pipeline is pinned to an older source and 14-migration/five-trigger assumptions. It must not be executed unchanged. Reuse its backup/off-host/receipt/fence principles, but prepare a separately reviewed manifest and compatibility rollback for these tables, nullable actor origins and AdminAction values. Once new history exists, migration down() deliberately refuses destructive rollback. Never restore an older database as an automatic code rollback.
6. Before production approval: exact-head protected PR review/CI, current quota and hosting checks, fresh consistent encrypted backup + isolated restore + independent off-host recovery, compatible rollback, authenticated customer/admin acceptance and finalized OTP. The live path stays `/home/novinpay/novinpay`, and `/home/novinpay/public_html` must retain its original target and identity. Preserve `.env`, uploads, sessions, private maintenance history, `.htaccess`, accounts and all business records.

An isolated disabled-provider rehearsal can proceed after its prerequisites/authorization. Public activation is **blocked** by unresolved SMS.ir access/template acceptance and the remaining hosted release gates. Existing public repair submission now requires a verified customer account; do not deploy it over the working site while OTP login is unavailable. Zibal live payments and prepaid postal billing remain disabled independently.
