# Postex quote-only adapter

## Verified contract and live evidence

Primary source: owner-supplied `D:/NovinPay/API.json`, OpenAPI 3.1.1, SHA-256 `2fd7899c3048b1ad85cfd6bcf2f2bd6c27e8afa520233d73d175690699d0c0b7`.
Official docs: <https://api.postex.ir/docs/>. The supplied spec omits security schemes and response schemas. Authentication was established from Postex's published plugin `core/core.php`: <https://plugins.svn.wordpress.org/postex-advanced-shipping-method/trunk/core/core.php> (header `x-api-key`, HTTPS).
The provider's <https://postex.ir/service/api> describes the API as free and lists price inquiry separately from shipment/wallet operations. Only the documented catalogs and quote operation were exercised; the plugin was inspected as text, never installed or executed.

On 2026-11-03, five authenticated GETs returned HTTP 200:

- `/api/v1/common/boxes`: list of `id`, `box_type_name`, integer dimensions.
- `/api/v1/shipping-methods`: `isSuccess`, `data[]` with `courierCode`, `courierServiceCode`, `courierServiceId`, `isActive`.
- `/api/v1/locality/cities/from/all?keyword=…`: list with `city_id`, `province_name`, `city_name`.
- `/api/v1/locality/cities/to/all?keyword=…`: same locality structure.
- `/api/v1/common/payment-methods`: `value`, `name`; prepaid is `SENDER`.

One synthetic `POST /api/v1/shipping/quotes` returned HTTP 200. No customer name, address, phone, order or repair identifier was sent. Request: `courier_drop_off`, origin city 9 (Shahriar/Tehran), destination city 1 (Tehran/Tehran), verified box 7, dimensions 30×20×20 cm, 1000 g, declared value 10,000,000 IRR, `IR_POST/EXPRESS`, `SENDER`, all three documented optional-service requests false. These numbers describe the diagnostic only, never default parcel measurements or fallback prices.

Observed response:

- `currency = IRR`, `parcel_count = 1`, `total_cost = 1126400`.
- `pickup_price = 0`, `value_added_service_price = 0`, `excluded_services = []`.
- `shipping_prices[0].custom_parcel_id` echoes the synthetic identifier.
- `shipping_prices[0].total_shipping_price = 1126400`.
- Its single `service_price[0]` has `courierCode = IR_POST`, `serviceType = EXPRESS`, `totalPrice = 1126400`.
- Root fields also include `shipping_price`, `shipping_price_vat`, `shipping_price_discount`. Service-level `vat` was nonzero while root VAT was zero: do not add VAT again or reconstruct a price from guessed semantics.

**This proves API quotation support, not independent post-office counter-price applicability.** No shipment creation, pickup, label purchase, wallet or payment endpoint was called. Error bodies were not printed or retained; error-schema semantics remain unverified because the authenticated calls succeeded. Mock tests cover invalid JSON/HTTP/transport handling without manufacturing live errors.

## Application contract

`PostalQuotation::estimate($origin, $destination, $weightGrams, $properties)` returns a server-side `PostexQuote`. Origin/destination require exact city AND province names. Ambiguous, missing or mismatched catalog identifiers are rejected, never fuzzy-selected. Parcel properties require measured integer `length`, `width`, `height` (cm), `declared_value_rial` (IRR), `box_type_id` from the catalog; optional boolean `is_fragile`/`is_liquid` default false. No address/contact fields are transmitted. Optional services cannot be enabled by input.

The adapter uses only fixed allowlisted paths on `https://api.postex.ir`, TLS verification, no redirects or automatic retries, 5-second connection/15-second request timeouts, and one-hour public-catalog caching keyed by credential digest. Quotes themselves are never cached: each call recalculates. It accepts only the observed single-parcel, single-service, IRR response with matching parcel/courier and consistent total fields. Unknown fields, unavailable couriers, extras, invalid money, authentication/network errors or unfamiliar responses fail closed; logs contain only bounded diagnostic categories/status/types, never provider bodies or credentials. No database, financial entry, account, shipment or wallet is modified.

Quote lifetime is a conservative **local five-minute cap**, not a claimed provider guarantee. Preserve `requestDigest` and require the exact parcel/route binding when consuming a quote; never accept a client-supplied amount. Recalculate on expiry or any parcel, destination or delivery change. `billingMinor()` rejects expired/mismatched/unconfirmed quotes. Exact conversion is `IRR × 10` to existing hundredths-of-toman ledger units; no floating-point money calculation.

`PostalQuotation::quote()` retains the existing four-argument/int signature for future delivery billing, but requires independently verified tariff applicability. The customer workflow's postal confirmation gate intentionally remains closed: the broader delivery workflow is existing uncommitted work, and no new controller/checkout billing route was enabled by this adapter-only commit. Pickup remains free; Tipax/courier freight-collect charges remain excluded. The same server-side quote and existing financial correction/credit rules must be used for both orders and repairs when that gate is completed.

## Private configuration and remaining activation work

```dotenv
POSTEX_QUOTES_ENABLED=false
POSTEX_API_KEY=
POSTEX_BASE_URL=https://api.postex.ir
POSTEX_CATALOG_CACHE_STORE=file
POSTEX_INDEPENDENT_POSTAGE_VERIFIED=false
POSTEX_TARIFF_EVIDENCE=
```

The existing `.env` and credential were preserved, not copied into examples or Git. Quote estimation requires `POSTEX_QUOTES_ENABLED=true` in the separately approved runtime; having a credential alone never enables requests. No production settings were changed. Keep billing verification false until the provider supplies evidence that quotes apply to independently deposited packages without Postex shipment/label purchase. Record that evidence reference, confirm supported service/payment semantics and integrate staff-entered measurements and current quote binding into delivery confirmation and payment revalidation. Do not mark the customer postal checkout production-ready before those gates.

Run `./scripts/test.ps1 --filter=PostexQuotationTest` for mocked tests; no real provider calls are made by tests. No migrations or new dependencies are needed for this adapter. Existing customer/payment changes remain separately uncommitted and must not be inferred as included in its focused commit.
