# Customer authentication and chat reliability

## Current development phase

Production baseline: `c9c46dc39f79aad59601045cb44e342168536e9c`. This branch is development-only; no deployment, migration, provider switch or live SMS is authorized by this plan.

Confirmed causes and corrections:

- An uncertain provider result invalidated a possibly delivered OTP. Preserve its original hash/expiry and daily quota; bind the reserved challenge to the initiating session before provider I/O. Definitive rejections still revoke it.
- A lost send/verification response could strand the form. Bound headers and body reads, then reconcile through session-bound, read-only `/account/login-state`. Never automatically replay an SMS POST. A per-form request UUID prevents the same reserved send from being reissued; stale challenge submissions cannot consume another challenge's attempts.
- Customer bootstrap/history stopped after one transient error. Retry once immediately, then jittered exponential delays capped at 30 seconds, respecting longer `Retry-After`. Each episode stops after six failures. Offline state waits for connectivity; permanent authentication/contract failures require deliberate action. Operator defaults remain unchanged.
- Keep the same transcript, outbox UUIDs, receipt reconciliation, draft and scroll during transient recovery. Technical diagnostics contain only bounded operation/status/timing metadata, never phone numbers, OTPs, tokens, message text or URLs.

Validation sources: `CustomerLoginReliabilityTest`, `IpPanelOtpTest`, `CustomerAccountTest`; `tests/Frontend/customer-reconnect.test.mjs` plus existing send/bootstrap/sync tests; local Chromium fixtures `customer-login-reliability.js`, `support-customer-startup.js`, `support-customer-reconnect.js`, `customer-auth-controls.js`. The existing disposable MySQL/MariaDB concurrency harness now checks two competing OTP reservations with a fake provider. CI remains the single existing four-job workflow.

The deterministic recovery experiment runs 100 episodes (400 attempts), with zero overlapping operations. Its virtual-clock median/p95 are 2,198/12,995 ms across one to five injected failures: these are retry-policy timings, **not production performance**. Browser fixtures intercept application writes and use synthetic responses at desktop/mobile widths.

Security limit: if a browser loses the regenerated authentication cookie itself, reconciliation cannot invent an authenticated session. Normal secure authentication is still required. Challenge expiry, attempt budgets, resend cooldown, customer reset policy and provider selection remain authoritative server-side.

## Separate next implementation phase — not implemented here

1. Define phone/email verification and account-linking rules before adding identifiers; preserve existing account IDs and keep support/admin authentication independent. Possession of an email/phone string must never link accounts automatically.
2. Extend password/OTP recovery using the current transaction, session-revocation and throttling services. Add schema changes only for demonstrated requirements, with explicit migration review.
3. Prepare Google OIDC using verified issuer/audience, state, nonce and PKCE; require verified claims and explicit secure account linking. Obtain private client configuration through the existing secure workflow.
4. Research Bale's current official verified-delivery/identity capabilities and approval requirements before promising an authentication method. Treat unsupported capabilities as unavailable; no invented verification or SMS fallback.
5. Extend fake-provider and fault-injection tests for concurrent linking, replay, lost responses, revoked sessions and provider outages. Run the established CI on push; request separate local acceptance and production authorization before release.
