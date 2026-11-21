# Zibal routes and connection check

| Method and name | Canonical path | Compatibility |
| --- | --- | --- |
| `POST payments.start` | `/payments/documents/{document}/zibal` | `/account/documents/{document}/zibal` stays as `payments.legacy.start` |
| `GET payments.order` | `/shop/orders/{order}/payment` | Shared financial-document view |
| `GET payments.repair` | `/repairs/{repair}/payment` | Shared financial-document view, existing invoice required |
| `GET payments.callback` | `/payments/zibal/callback` | `/bot/payment_verify.php` remains a direct alias to the same verifier |
| `GET payments.connect` | `/payments/{payment}/connect` | `/pay/index.html` resolves the exact former URL shape to a session-owned attempt |
| `GET payments.gateway` | `/payments/{payment}/gateway` | Manual continue opens the same-origin handoff using the stored track ID |
| `GET payments.result` | `/payments/{payment}/result` | Former `/pay/*.html` result pages remain available for historical links |
| `GET payments.status` | `/payments/{payment}/status` | `/bot/check_payment_status.php` retains its old response for legacy result pages |
| `GET payments.document` | `/account/documents/{document}` | Existing document and receipt workflows |

The old `/bot/receipt_info.php` contract remains for the historical result pages. The cart and `/cart/checkout` keep their existing routes. Both order and repair payments start from the authorized financial document and use one provider callback. The result derives the business context from the stored document and links back to it. Browser parameters do not select the context, settlement state or gateway host.

Before retiring aliases, review outstanding attempts, old return links and the merchant's callback configuration. Both exact callback paths are admitted by the existing maintenance corridor for GET only. The existing legacy HTML and assets retain their original bytes. New flows render native Blade.

## Reference review and connectivity data

The ZizoLearn ZIP supplied `bot/geo_check.php`, `bot/ir_ranges.php`, `pay/index.html`, `pay/assets/pay.css` and an integration note. `payment_start.php` was absent. The warning reuses the reference card, copy, animations, four-second polling and single-button retry. The official NovinPay wordmark sits above the card and its light blue replaces the purple palette. A domestic or unknown estimate at entry opens the gateway handoff. While the warning is visible, only a domestic estimate continues automatically, and a manual retry can also continue on an unknown estimate. The JSON check reads the same authenticated attempt through `payments.connect`. The subsequent gateway handoff remains the existing same-origin document.

The bundled data is a derivative of [DB-IP Country Lite CSV](https://db-ip.com/db/download/ip-to-country-lite), November 2026, licensed [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). It collapses adjacent ranges with the same Iran/outside-Iran classification and omits unknown records. Source uncompressed SHA-1 is `e2061015c43ec91c94a12191a6a613279eb94c0c`. The derived file is 212,280 bytes, SHA-256 `71bb01d7e219646e48fe2c6743068f2b2c3f612a21c452bf802bf027bf1fb7f4`. The application performs no remote lookup and does not store or output addresses. Attribution is visible in the site's privacy page and linked from the warning's document metadata.

Refresh from a newly downloaded monthly source and its separately published checksum. Review the generated snapshot and run the focused tests before replacing it through source control. Data older than the current and two preceding calendar months yields unknown.

```powershell
python scripts/update-payment-country-ranges.py <csv.gz> <YYYY-MM> <published-csv-sha1> <new-snapshot.json>
./scripts/test.ps1 --filter=PaymentConnectivityTest
./scripts/test.ps1 --filter=SharedPaymentsTest
```

By default the lookup uses the observed remote peer. For a verified reverse-proxy deployment, set optional `PAYMENTS_TRUSTED_PROXY_IPS` to comma-separated exact peer addresses. Ingress must overwrite X-Forwarded-For or append its observed hop. The lookup uses the rightmost untrusted hop, ignores other forwarding headers and declines wildcard or CIDR trust. This setting does not change global Laravel proxy policy. Host access logs remain subject to existing hosting policy.

## Release and rollback

Set the private `ZIBAL_CALLBACK_URL=https://novinpaypos.com/payments/zibal/callback` before enabling new payments. Keep `ZIBAL_ENABLED`, `ZIBAL_LIVE_REQUESTS` and `OPERATIONS_SCHEDULED_PAYMENTS` false for code deployment. Release validation checks the canonical and alias routes and the configured callback when present. The private readiness probe must require the exact callback before a separate activation.

The supplied Zibal standard IPG OpenAPI 3.1.1 contract specifies merchant-code authentication, a callback on the merchant-registered domain, and an obligatory browser `Referer` on `GET /start/{trackId}`. The authorized launch document uses `Referrer-Policy: origin`, immediate browser navigation and a manual link with the same policy. This sends the NovinPay origin without the attempt identifier or query. The advisory screen, callback and result retain `no-referrer`. Browser privacy settings can still suppress the header.

The normal IPG endpoints are `/v1/request`, `/v1/verify` and `/v1/inquiry`. Request sends a JSON integer amount in IRR with a minimum of 1,000 IRR. The existing store cap of 499,999,999 IRR is conservative relative to the provider-wide 4,000,000,000 IRR cap. Confirm the applicable account limit and any server-IP restriction in the merchant panel. Verification and inquiry send positive JSON int64 track IDs. The callback is GET and client-provided `success` and `status` do not authorize settlement. A bound verify result 100 or 201 with verified status can settle. An incomplete response needs bound inquiry evidence with status 1. Bound inquiry status -1 retains resumable pending state within the reconciliation window. Status 2 does not settle. Confirmed non-payment and refund/reversal states are recorded or escalated without mutating already-settled entries.

The contract requires the callback and browser Referer domains to match the registered website. It does not specify an exact callback path allowlist. Confirm `novinpaypos.com` and any account-specific restrictions privately before activation.

Qualify the actual release SHA with the four CI jobs and a reviewed cPanel application-release manifest covering configuration, both callbacks, the maintenance corridor, the local country data and frontend files. Use the established [deployment safeguards](DEPLOYMENT.md) and verified persistent-cart prerequisite. This route/connectivity change has zero schema migrations or new dependencies.

Stop new attempts before rollback. Keep the merchant binding and, where safe, use the existing callback and worker for outstanding attempts. Before reverting to code that only understands the old path, reconcile outstanding attempts issued with the canonical callback or retain a reviewed same-verifier handler at that path. Preserve all payment and cart records if code rolls back.
