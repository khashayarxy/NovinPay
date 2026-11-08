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


## Owner archive comparison and independent operator revision (2026-11-08)

This section supersedes the earlier Laravel-staff coupling requirement above. PR #13 is merged; the next changes are on `codex/independent-support-performance`. Production remains at `ebc9c4d` until separately verified deployment.

Reference: owner-supplied `operator.zip`, SHA-256 `d52b6b72cf24244539dac504cd6155a01136c3e051ebdf4648b5f153ed410713`. It contains 82 files (86 entries): operator/customer frontend, icons and one PHP PWA manifest. It does **not** contain the chat API, storage/authentication PHP backend, widget PHP entry point, database schema or background jobs. The import baseline remains GitHub commit `7d6f6ddcfbde4fd3fb1857f62504d230e4c43bb5`; the ZIP has no verifiable source commit. Backend parity with the latest live ZizoLearn cannot be established from this archive alone.

File comparison after restoration: 53 identical, 28 integration-modified, 1 intentionally uninstalled server directive. No functional frontend file is absent.

| Reference file | Current status | Difference / justification |
| --- | --- | --- |
| `operator/.htaccess` | missing | Not copied: archive only disables caching; Laravel protects dynamic HTML/API and versioned public assets use separate caching. |
| `operator/analytics.js` | identical | Exact after line-ending normalization. |
| `operator/app.css` | identical | Exact after line-ending normalization. |
| `operator/app.js` | identical | Exact after line-ending normalization. |
| `operator/auth-lifecycle.js` | identical | Exact after line-ending normalization. |
| `operator/drafts.js` | identical | Exact after line-ending normalization. |
| `operator/faq-settings.js` | identical | Exact after line-ending normalization. |
| `operator/health.js` | identical | Exact after line-ending normalization. |
| `operator/icons/apple-touch-icon.png` | modified | NovinPay branding/icons only. |
| `operator/icons/icon-192.png` | modified | NovinPay branding/icons only. |
| `operator/icons/icon-512.png` | modified | NovinPay branding/icons only. |
| `operator/icons/maskable-512.png` | modified | NovinPay branding/icons only. |
| `operator/icons/operator-mark.svg` | identical | Exact after line-ending normalization. |
| `operator/index.html` | modified | NovinPay branding, canonical base, CSP and restored original independent login/team controls. |
| `operator/manifest.php` | modified | NovinPay branding and /support PWA scope; dynamic response stays private. |
| `operator/mobile.css` | identical | Exact after line-ending normalization. |
| `operator/mobile.js` | identical | Exact after line-ending normalization. |
| `operator/modules/analytics.js` | modified | NovinPay branding/icons only. |
| `operator/modules/contacts.js` | modified | NovinPay branding/icons only. |
| `operator/modules/dashboard.js` | modified | NovinPay branding/icons only. |
| `operator/modules/health.js` | modified | NovinPay branding/icons only. |
| `operator/modules/icons.js` | modified | NovinPay branding/icons only. |
| `operator/modules/knowledge.js` | modified | NovinPay branding/icons only. |
| `operator/modules/mobile-table.js` | identical | Exact after line-ending normalization. |
| `operator/modules/router.js` | modified | NovinPay branding/icons only. |
| `operator/modules/settings.js` | modified | NovinPay branding/icons only. |
| `operator/modules/team.js` | identical | Exact after line-ending normalization. |
| `operator/modules/replies.js` | identical | Exact after line-ending normalization. |
| `operator/overlays.js` | identical | Exact after line-ending normalization. |
| `operator/productivity.js` | identical | Exact after line-ending normalization. |
| `operator/profile.js` | identical | Exact after line-ending normalization. |
| `operator/pwa.js` | modified | Canonical /support worker scope; no access to unrelated pages. |
| `operator/readiness.js` | identical | Exact after line-ending normalization. |
| `operator/sw.js` | modified | Canonical /support navigation and customer/operator notification isolation. |
| `operator/widget-settings.js` | identical | Exact after line-ending normalization. |
| `operator/workspace.css` | modified | NovinPay navigation label/RTL styling; original dashboard structure retained. |
| `operator/emoji.js` | identical | Exact after line-ending normalization. |
| `operator/quick-reply-editor.js` | identical | Exact after line-ending normalization. |
| `support-client/attachments.js` | identical | Exact after line-ending normalization. |
| `support-client/attention-store.js` | identical | Exact after line-ending normalization. |
| `support-client/attention.js` | identical | Exact after line-ending normalization. |
| `support-client/bplus.css` | identical | Exact after line-ending normalization. |
| `support-client/bplus.js` | identical | Exact after line-ending normalization. |
| `support-client/chat.css` | identical | Exact after line-ending normalization. |
| `support-client/customer-entry.js` | identical | Exact after line-ending normalization. |
| `support-client/customer-faq.js` | identical | Exact after line-ending normalization. |
| `support-client/customer-layout.css` | modified | NovinPay source-page launcher placement/typography. |
| `support-client/customer-shell.js` | identical | Exact after line-ending normalization. |
| `support-client/customer-sw.js` | identical | Exact after line-ending normalization. |
| `support-client/customer-v2-renderer.js` | modified | NovinPay team/brand fallback; original rendering retained. |
| `support-client/customer-v2-tools.js` | modified | NovinPay fallback labels; original controls retained. |
| `support-client/customer-v2.css` | identical | Exact after line-ending normalization. |
| `support-client/customer-v2.js` | modified | NovinPay brand fallback. |
| `support-client/customer.js` | modified | Existing combined bootstrap, reconnect and message-queue handling; no feature removal. |
| `support-client/design-tokens.css` | modified | Local NovinPay fonts and theme tokens. |
| `support-client/engine.js` | modified | Existing tested single-use startup bootstrap, fresh-send optimization, replay-safe cursor reconciliation; protocol guarantees retained. |
| `support-client/interaction.css` | identical | Exact after line-ending normalization. |
| `support-client/issuance.js` | identical | Exact after line-ending normalization. |
| `support-client/launcher.css` | identical | Exact after line-ending normalization. |
| `support-client/menu.js` | identical | Exact after line-ending normalization. |
| `support-client/message-groups.js` | identical | Exact after line-ending normalization. |
| `support-client/network.js` | modified | Existing combined bootstrap, bounded redacted timings and strict response/cursor validation. |
| `support-client/outbox.js` | identical | Exact after line-ending normalization. |
| `support-client/persian-date.js` | identical | Exact after line-ending normalization. |
| `support-client/phone.js` | identical | Exact after line-ending normalization. |
| `support-client/recovery.js` | identical | Exact after line-ending normalization. |
| `support-client/reply.js` | identical | Exact after line-ending normalization. |
| `support-client/satisfaction.js` | identical | Exact after line-ending normalization. |
| `support-client/selection-toast.js` | identical | Exact after line-ending normalization. |
| `support-client/send-cooldown.js` | identical | Exact after line-ending normalization. |
| `support-client/theme-init.js` | modified | Owner-approved customer light default with persistent manual light/dark preference. |
| `support-client/theme.js` | identical | Exact after line-ending normalization. |
| `support-client/typing.js` | identical | Exact after line-ending normalization. |
| `support-client/unread-jump.js` | identical | Exact after line-ending normalization. |
| `support-client/widget.css` | identical | Exact after line-ending normalization. |
| `support-client/widget.html` | modified | NovinPay branding and local font loading. |
| `support-client/widget.js` | identical | Exact after line-ending normalization. |
| `support-client/icons.js` | identical | Exact after line-ending normalization. |
| `support-client/critical-shell.html` | modified | Existing safe loading/retry diagnostic and selected-theme handling. |
| `support-client/composer-size.js` | identical | Exact after line-ending normalization. |
| `support-client/message-edit.js` | identical | Exact after line-ending normalization. |
| `support-client/compat-bootstrap.js` | identical | Exact after line-ending normalization. |

### Feature and trust-boundary result

- Original operator app/login, native team creation/revocation, per-account roles/passwords/profile management restored from the archive; `app.js`, `profile.js`, `modules/team.js` are exact reference matches. Operator/customer dashboards, inbox, drafts, replies/macros, history, ACK/outbox, read/typing/presence, attachments, recovery, lifecycle, satisfaction, analytics, settings and PWA modules remain.
- Previous coupling came from `ChatBridge` mapping Laravel staff/grants on every operator request, controller interception of native account actions, and frontend redirects to admin login/password/staff screens. These mappings/interceptions and unused confirmation/team adapters are removed.
- Native operator sessions/credentials/CSRF/roles now stand alone. Laravel customer-to-conversation ownership remains unchanged. Public operator UI assets contain no account/session data; APIs remain authenticated and no-store. Admin login/logout cannot authenticate/logout support and vice versa (focused tests).
- Transition: one explicit console enrollment preserves operator IDs/names/profiles/history and a hash-pinned private preimage of the authority. It invalidates prior sessions and push subscriptions, disables Laravel-generated mapped accounts except the explicitly enrolled existing support administrator, and preserves other native account passwords. No admin password hash is imported. No SQL migration, permission grant or provider activation is needed. Enrollment cannot replay silently; existing data is never reset.
- The owner must privately select the new independent support password. Before enrollment, inherited sessions fail closed and the native login reports setup required. Production must not be switched without arranging this enrollment. Old coupled code is incompatible with the new authority contract; after enrollment use forward recovery, not automatic coupled-code rollback.
- Static build publishes an allowlisted, content-versioned tree under `public/build/support-chat/`; PHP/config/runtime/HTML are excluded. Relative module versions are canonicalized so preloads/imports share URLs. LiteSpeed cache headers apply only within this public tree; dynamic HTML, API, tokens and conversations remain no-store. Original four-second polling is unchanged.
- SMS/push/email issuance, Zibal and postal billing remain disabled; no provider delivery or latest upstream backend parity is claimed.

### Measured evidence (local browser, not production acceptance)

- Production baseline HTTP engine.js fetches before deployment: 448.3/397.6/446.6 ms, no-store/private (three samples from this workstation; network-inclusive). Launcher samples were 4925.2/1530.1/424.5 ms and are not a stable benchmark.
- Same local browser, same engine payload: Laravel endpoint 72.5/93.4/50.5 ms (median 72.5); versioned static endpoint 3.0/2.6/2.3 ms (median 2.6). These are asset-fetch timings, **not message ACK latency**. Herd does not apply Apache `.htaccess`, so production immutable headers still require hosted verification.
- Five local iframe reload-to-enabled-composer samples: 728/409/494/482/463 ms (median 482, empirical p95=max 728 with only five samples); a final 642 ms check verified zero duplicate JS resources after canonical imports and SVG-icon module deduplication. Earlier startup instrumentation mistakenly waited for removal of a deliberately hidden loading mask; it did not indicate an application failure.
- Browser confirms the composer initializes, public modules use static paths, no legacy module requests remain, no console errors were observed. Cold full-widget before/after, send ACK and hosted two-way measurements remain pending; do not extrapolate asset improvements into unmeasured message latency.
- Focused tests cover native login/CSRF, session/logout separation, native roles, password revocation, safe enrollment/replay rejection, customer identity, rapid/lost-ACK sends, replay/order/isolation, reply/read/lifecycle/CSAT/analytics, image-only submission and public-asset publication boundaries. See current test output/CI for exact revision results.


### Subsequent owner-approved operator additions

Inactive operators can be soft-deleted by a different native support administrator. A retained tombstone preserves ID/name/profile/message references and audit history; credentials are randomized and sessions invalidated. Deleted accounts are excluded from both management directories and cannot be reactivated through profile/password actions. Username reuse remains blocked. No SQL schema or customer-message changes are involved; any recovery must preserve tombstone and authentication guards.

The operator transcript places every support reply (including other operators and FAQ automation) on the existing blue/right side. Consecutive messages from the same trusted sender share one subtle right-edge label, without operator avatars; a different sender starts a new group. Operator-only sender lookup resolves stable stored operator IDs even for deactivated/deleted accounts. Unknown confirmed history uses «پشتیبان», automation «پاسخ خودکار». Bootstrap supplies the current operator's trusted public sender ID/name before optimistic rendering, retaining that identity through ACK reconciliation; an unknown pending name is deferred. Customer rendering and public-profile visibility remain unchanged. These are explicitly requested deviations from the original source UI.

### Owner-approved five-minute reply ownership

The existing conversation-store lock atomically checks and records an optional `reply_ownership` lease on a new accepted human reply. It expires 300 seconds after the last accepted reply. Replaying an accepted UUID returns its receipt without renewing or stealing ownership. Reads, presence, typing, customer messages and FAQ automation never renew it. Closure/new episode, account revocation/deletion/version change and an authorized assignment change invalidate it. Closing a tab does not. No SQL migration, worker or cron is added.

Only authenticated operators receive owner/name/expiry snapshots. Other operators may read but receive `409 conversation_owned` on send or image upload; image publication rechecks inside the same conversation lock. The native assignment action permits handoff by the current owner or an administrator. The composer uses existing four-second sync plus an elapsed-time expiry timer; blocked drafts remain queued and accepted lost ACKs can still reconcile without resending.

Focused regression coverage: `SupportChatTest` (including two competing PHP processes), `tests/Frontend/support-{reply-ownership,operator-transcript,send}.test.mjs`. The optional synthetic browser fixture `tests/Browser/support-operator-ownership.js` runs only on `novinpaypos.test`, without authentication or message writes. Its execution was blocked by the browser tool's workspace-path policy in this session; desktop/mobile geometry and authenticated hosted behavior remain acceptance checks, not claimed PASS results.
