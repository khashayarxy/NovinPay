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

## Local approval and controlled production release

Use local Herd development and owner approval, then the explicitly authorized production workflow in [DEPLOYMENT.md](DEPLOYMENT.md). Do not create or require hosted staging. Keep providers disabled until separately approved. Owner confirmed delayed test SMS delivery; API/template approval does not establish authorized real-time production sending. Do not deploy customer-login-dependent repair submission without an approved usable authentication path.

Use the exact committed source/build manifest, locked Composer install (never host update), verified PHP/MariaDB compatibility, current production schema evidence and a reviewed migration plan. Preserve CRUD/migrator separation, trigger definers, owner access, credentials, existing data and public_html identity. Existing CI/isolated restore evidence remains applicable only when matched to the affected change. Older maintenance release scripts pinned to 14 migrations/five triggers must not be replayed unchanged. Code rollback must not restore older business data. No installation/bootstrap/provisioning by implication.
