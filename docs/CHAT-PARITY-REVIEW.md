# ZizoLearn chat parity and disclosure review

GitHub HEAD verified: `7d6f6ddcfbde4fd3fb1857f62504d230e4c43bb5`. Same upstream commit as original import. This is a source comparison, not a claim of complete hosted feature parity.

127 imported files compared against the actual GitHub tree using Git blob identities after CRLF normalization: 95 unchanged, 32 adapted. All 32 original versions and diffs retrieved for review. Original source and NovinPay production were not modified.

## Required integration differences and open gaps

- Private Laravel resource routing replaces direct public PHP placement. Private support files are outside webroot and public endpoint/static assets are allowlisted.
- `ChatBridge` maps customer ownership, staff permissions, current session versions and password confirmation to Laravel; no ZizoLearn data/accounts/keys are reused.
- Branding, fonts, asset paths and service-worker scope are NovinPay-specific. No new visual direction was introduced in this fix.
- Message/storage/outbox/read/typing/presence/lifecycle/CSAT/analytics modules are retained. Local integration tests exercise ACK/replay/edit/isolation/operator replies/reads/lifecycle/CSAT/analytics and image-only normalization. Hosted two-operator, multipart attachment and reconnect acceptance remain pending.
- **NOT full parity:** `operator/modules/team.js` replaces the original team-management UI with a staff-directory link; `operator/profile.js` redirects password and role management to Laravel. Latest owner requirement requires preserving the full original UI while mapping operations securely to native staff authority. Do not enable native duplicate account/password APIs merely to hide this gap.
- External SMS/push/email issuance and notifications remain disabled by existing staging/provider restrictions; retained modules do not establish operational delivery. Cleanup/retention requires explicitly armed private CLI workflow.
- Four NovinPay-branded PWA PNGs already existed and remain unchanged. Two additional upstream files, operator/icons/operator-mark.svg and support/openssl.cnf, are now restored as tracked source. These two files were absent from the deployed archive and still require staging delivery. An import-manifest omission alone is not proof that a destination file is missing.

## Disclosure

Affected endpoint: `/support-chat/support-widget.php`; other dynamically included chat endpoints shared the same controller execution scope. `configuration.php` assigns `$file` to installation.env in its include caller scope. The controller later executes `require $file`, rendering private configuration text. Existing tests asserted HTTP 200 only and missed the wrong response body.

Fix: isolate every native module include in a dedicated method scope, including bootstrap/operators/profile helpers and endpoint dispatch. Defense in depth rejects raw SUPPORT_/CHAT_DATA_DIR assignment output before returning it. No CSS hiding, PHP-handler changes, private-file changes or authentication weakening. Regression requires actual widget markup and rejects raw private configuration/paths; direct private/config/data path requests must return 404.

Staging controller hotfix uploaded through cPanel with exact old-file fingerprint guard; old controller preserved privately, mode 0600. Readback matches patched source. Effective web OPcache and authenticated HTTPS checks are pending one owner-run security-verification helper. No claims of hosted remediation or complete exposure scope until that check. Exposed reported data is installation metadata/paths/feature flags; no evidence yet of APP_KEY, provider credentials or customer-message disclosure. Do not infer absence of exposure beyond tested responses.

## Per-file comparison

| Upstream path | Status |
| --- | --- |
| `api.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `customer-notifications.php` | Original GitHub content, normalized LF |
| `get_activity.php` | Original GitHub content, normalized LF |
| `logout.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator-api.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator-avatar.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-attachment.php` | Original GitHub content, normalized LF |
| `support-avatar.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-content-admin.php` | Original GitHub content, normalized LF |
| `support-content.php` | Original GitHub content, normalized LF |
| `support-loader.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-profile-image.php` | Original GitHub content, normalized LF |
| `support-theme.php` | Original GitHub content, normalized LF |
| `support-typing.php` | Original GitHub content, normalized LF |
| `support-widget.js` | Original GitHub content, normalized LF |
| `support-widget.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `update_activity.php` | Original GitHub content, normalized LF |
| `operator/analytics.js` | Original GitHub content, normalized LF |
| `operator/app.css` | Original GitHub content, normalized LF |
| `operator/app.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/auth-lifecycle.js` | Original GitHub content, normalized LF |
| `operator/drafts.js` | Original GitHub content, normalized LF |
| `operator/emoji.js` | Original GitHub content, normalized LF |
| `operator/faq-settings.js` | Original GitHub content, normalized LF |
| `operator/health.js` | Original GitHub content, normalized LF |
| `operator/index.html` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/manifest.php` | Original GitHub content, normalized LF |
| `operator/mobile.css` | Original GitHub content, normalized LF |
| `operator/mobile.js` | Original GitHub content, normalized LF |
| `operator/overlays.js` | Original GitHub content, normalized LF |
| `operator/productivity.js` | Original GitHub content, normalized LF |
| `operator/profile.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/pwa.js` | Original GitHub content, normalized LF |
| `operator/quick-reply-editor.js` | Original GitHub content, normalized LF |
| `operator/readiness.js` | Original GitHub content, normalized LF |
| `operator/sw.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/widget-settings.js` | Original GitHub content, normalized LF |
| `operator/workspace.css` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support/analytics.php` | Original GitHub content, normalized LF |
| `support/attachments.php` | Original GitHub content, normalized LF |
| `support/bootstrap.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support/branding.php` | Original GitHub content, normalized LF |
| `support/code-recovery.php` | Original GitHub content, normalized LF |
| `support/configuration.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support/contact.php` | Original GitHub content, normalized LF |
| `support/context.php` | Original GitHub content, normalized LF |
| `support/customer-notifications.php` | Original GitHub content, normalized LF |
| `support/health.php` | Original GitHub content, normalized LF |
| `support/knowledge.php` | Original GitHub content, normalized LF |
| `support/lifecycle.php` | Original GitHub content, normalized LF |
| `support/message-edits.php` | Original GitHub content, normalized LF |
| `support/messages.php` | Original GitHub content, normalized LF |
| `support/notifications.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support/operations.php` | Original GitHub content, normalized LF |
| `support/operator-notifications.php` | Original GitHub content, normalized LF |
| `support/operator-profile.php` | Original GitHub content, normalized LF |
| `support/operator-reads.php` | Original GitHub content, normalized LF |
| `support/operator-security.php` | Original GitHub content, normalized LF |
| `support/operators.php` | Original GitHub content, normalized LF |
| `support/phone.php` | Original GitHub content, normalized LF |
| `support/presence.php` | Original GitHub content, normalized LF |
| `support/productivity.php` | Original GitHub content, normalized LF |
| `support/profile-images.php` | Original GitHub content, normalized LF |
| `support/readiness.php` | Original GitHub content, normalized LF |
| `support/recovery-email.php` | Original GitHub content, normalized LF |
| `support/recovery.php` | Original GitHub content, normalized LF |
| `support/replies.php` | Original GitHub content, normalized LF |
| `support/retention.php` | Original GitHub content, normalized LF |
| `support/satisfaction.php` | Original GitHub content, normalized LF |
| `support/sms.php` | Original GitHub content, normalized LF |
| `support/storage.php` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support/typing.php` | Original GitHub content, normalized LF |
| `support-client/attachments.js` | Original GitHub content, normalized LF |
| `support-client/attention-store.js` | Original GitHub content, normalized LF |
| `support-client/attention.js` | Original GitHub content, normalized LF |
| `support-client/bplus.css` | Original GitHub content, normalized LF |
| `support-client/bplus.js` | Original GitHub content, normalized LF |
| `support-client/chat.css` | Original GitHub content, normalized LF |
| `support-client/compat-bootstrap.js` | Original GitHub content, normalized LF |
| `support-client/composer-size.js` | Original GitHub content, normalized LF |
| `support-client/critical-shell.html` | Original GitHub content, normalized LF |
| `support-client/customer-entry.js` | Original GitHub content, normalized LF |
| `support-client/customer-faq.js` | Original GitHub content, normalized LF |
| `support-client/customer-layout.css` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-client/customer-shell.js` | Original GitHub content, normalized LF |
| `support-client/customer-sw.js` | Original GitHub content, normalized LF |
| `support-client/customer-v2-renderer.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-client/customer-v2-tools.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-client/customer-v2.css` | Original GitHub content, normalized LF |
| `support-client/customer-v2.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-client/customer.js` | Original GitHub content, normalized LF |
| `support-client/design-tokens.css` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-client/engine.js` | Original GitHub content, normalized LF |
| `support-client/icons.js` | Original GitHub content, normalized LF |
| `support-client/interaction.css` | Original GitHub content, normalized LF |
| `support-client/issuance.js` | Original GitHub content, normalized LF |
| `support-client/launcher.css` | Original GitHub content, normalized LF |
| `support-client/menu.js` | Original GitHub content, normalized LF |
| `support-client/message-edit.js` | Original GitHub content, normalized LF |
| `support-client/message-groups.js` | Original GitHub content, normalized LF |
| `support-client/network.js` | Original GitHub content, normalized LF |
| `support-client/outbox.js` | Original GitHub content, normalized LF |
| `support-client/persian-date.js` | Original GitHub content, normalized LF |
| `support-client/phone.js` | Original GitHub content, normalized LF |
| `support-client/recovery.js` | Original GitHub content, normalized LF |
| `support-client/reply.js` | Original GitHub content, normalized LF |
| `support-client/satisfaction.js` | Original GitHub content, normalized LF |
| `support-client/selection-toast.js` | Original GitHub content, normalized LF |
| `support-client/send-cooldown.js` | Original GitHub content, normalized LF |
| `support-client/theme-init.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-client/theme.js` | Original GitHub content, normalized LF |
| `support-client/typing.js` | Original GitHub content, normalized LF |
| `support-client/unread-jump.js` | Original GitHub content, normalized LF |
| `support-client/widget.css` | Original GitHub content, normalized LF |
| `support-client/widget.html` | Adapted; source diff reviewed/retained; integration or parity review required |
| `support-client/widget.js` | Original GitHub content, normalized LF |
| `operator/modules/analytics.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/modules/contacts.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/modules/dashboard.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/modules/health.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/modules/icons.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/modules/knowledge.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/modules/mobile-table.js` | Original GitHub content, normalized LF |
| `operator/modules/replies.js` | Original GitHub content, normalized LF |
| `operator/modules/router.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/modules/settings.js` | Adapted; source diff reviewed/retained; integration or parity review required |
| `operator/modules/team.js` | Adapted; source diff reviewed/retained; integration or parity review required |

## Additional source paths

- `operator/.htaccess`: not imported; private Apache deny rules replaced by Laravel allowlist, or non-runtime development/example source; explicit review remains
- `operator/icons/apple-touch-icon.png`: present in destination; verify upstream/branding adaptation
- `operator/icons/icon-192.png`: present in destination; verify upstream/branding adaptation
- `operator/icons/icon-512.png`: present in destination; verify upstream/branding adaptation
- `operator/icons/maskable-512.png`: present in destination; verify upstream/branding adaptation
- `operator/icons/operator-mark.svg`: present in destination; verify upstream/branding adaptation
- `support-package.env.example`: not imported; private Apache deny rules replaced by Laravel allowlist, or non-runtime development/example source; explicit review remains
- `support-v2-preview.php`: not imported; private Apache deny rules replaced by Laravel allowlist, or non-runtime development/example source; explicit review remains
- `support/.htaccess`: not imported; private Apache deny rules replaced by Laravel allowlist, or non-runtime development/example source; explicit review remains
- `support/openssl.cnf`: present in destination; verify upstream/branding adaptation

## Supporting CLI inventory

| Source path | Present | Source reference verified | Identical normalized blob |
| --- | --- | --- | --- |
| `tools/support-attachments.php` | True | True | False |
| `tools/support-cleanup.php` | True | True | False |
| `tools/support-customer-notifications.php` | True | True | False |
| `tools/support-notifications.php` | True | True | False |
| `tools/support-operator-notifications.php` | True | True | False |
| `tools/support-operators.php` | True | True | False |
| `tools/support-recovery-email.php` | True | True | False |
| `tools/support-satisfaction.php` | True | True | False |
| `tools/support-security-reset.php` | True | True | False |
| `tools/support-sms.php` | True | True | False |
| `tools/support-test-cleanup.php` | True | True | False |


## Current follow-up

The original team table/filter/add/revoke UI is restored locally, with native email login, password confirmation and protected-owner current-password verification. Roles grant support permissions only; revocation preserves unrelated staff access and increments native session version. Newly provisioned staff remain subject to password rotation. Native profile password/role editing is still an explicit integration difference; full hosted parity is not established. Customer light default/manual persistence is an owner-requested integration difference. Hosted disclosure verification passed all 11 endpoints (owner result); customer initialization and two-operator acceptance remain separate gates.
