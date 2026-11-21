# Product experience rollback

The local implementation uses the existing Herd site at `https://novinpaypos.test`.
The five storefront migrations add columns on `products` and create `product_feedback` and
`product_feedback_reports`. Product URLs, product IDs, inventory associations and
original images are retained.

## Application rollback

1. For a future separately approved release, package the exact pre-release
   application files and route/view assets using the existing release procedure.
   Verify that recovery artifact before activation. Source rollback restores
   those files together, including routes and views. Keep prior local media work.
2. Leave the additive schema and all submissions in place during application
   rollback. Preserve the `ProductFeedbackModerated` enum case and label if its
   value exists in `admin_activities`, so the earlier application can render history.
3. Check the previous application's product page, catalog, admin editing,
   authorized activity history and the existing cart checks. Verify the three ME31
   originals still return their recorded hashes. The former checkout ignores the
   new online-purchasing flag, so any inquiry-only item must also have a legacy
   stock status that prevents checkout before the old application serves traffic.
   The previous StockStatus enum cannot hydrate the value `unverified`. If any row
   uses it, retain a compatible enum in the rollback package and verify the public
   catalog, admin list and checkout denial before reopening traffic. Check all
   paused or offline offers against the rollback checkout rules as well.

## Local database recovery

The pre-migration SQLite backup was verified with `PRAGMA quick_check` and the
ME31 record at `%LOCALAPPDATA%\NovinPay-Local\development\before-product-experience-20261117.sqlite`.
Use it for comparison or open it as a separate recovery database. Before any
recovery, verify the active SQLite path with the existing Herd environment guard.
For an ME31-only reversal, restore only the new fields on the existing slug
`newland-me31-black` after taking a fresh local copy. The earlier schema had
no brand, model, color, warranty months, or structured content fields. Clear
those fields and retain the unverified stock status and original image metadata.

The final ME31 copy refinement uses the existing schema. Its before-edit
product row was saved privately as
`%LOCALAPPDATA%\NovinPay-Local\development\me31-before-final-content.json`.
To reverse just that copy edit, restore `name`, `summary`, `description`, and
`product_details` for product ID 2 with slug `newland-me31-black`. Prefer the
existing admin editor so it also regenerates `search_text`. Verify the current
record identity first and retain media, price, publication and stock fields.

The color-variant migration adds nullable `variant_family` and `color_swatch`
fields with a unique family/color pair. Restore the previous application
without dropping these fields so its individual product pages, product IDs
and order links continue to work. The pre-variant local copy is
`%LOCALAPPDATA%\NovinPay-Local\development\before-product-color-variants.sqlite`.
It contains the prior title if the ME31 display-title edit also needs reversal.

The price-date migration adds nullable `products.price_changed_at` in UTC.
Keep it when rolling back the application so recorded price dates survive.
Retain the `ProductPriceChanged` and `ProductPriceDateRecorded` enum cases if there
are matching audit entries. Legacy products without evidence keep a null date.
The private local before-change copy is
`%LOCALAPPDATA%\NovinPay-Local\development\before-product-price-timestamp.sqlite`.
Use its ME31 row to recover the previous editorial copy without replacing
unrelated records or discarding later product price events.

Use the migrations' `down` methods only on a disposable database or after
preserving all newer product metadata, feedback and reports and confirming they
can be recovered. Replacing the active SQLite file would also revert unrelated
local writes. The pre-migration backup is local recovery material.
