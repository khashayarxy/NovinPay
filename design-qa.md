# Final public design QA

final result: passed

## Source, implementation and evidence

Six owner-approved 1024 × 1536 PNG references from NovinPay-Final-Assets.zip: homepage, products, product-page, about-us, repair and consultation. Implementation uses the canonical isolated site https://novinpaypos.test. The supplied consultation image visually depicts contact/message entry; the existing consultation form and contact page use its information-card/form treatment while preserving their separate routes and validated fields.

Evidence lives outside Git under the task visualization folder: final-design/compare-{page}.png contains approved reference and rendered implementation in the same image; environment-setup/final-design/{page}-{width}.png contains full-page captures. Screenshots use deviceScaleFactor 1 and CSS screenshot scaling at viewport widths 1440, 1024, 390 and 320; the desktop browser scrollbar slightly reduces full-page content width. Side-by-side boards normalize both columns to 640px without changing image proportions. All six boards and focused homepage hero comparison were opened and visually reviewed. The extra contact page was captured too: 28 distinct page/viewport combinations.

## Findings, fixes and retest

- P1 accessibility: initial orange repair buttons failed text contrast. Darkened the action surface to #bd3b00; repeated main-content axe WCAG A/AA checks passed at all four widths.
- P2 layout: intrinsic square hero artwork exceeded bounded grid frames and could overlap the following category/trust content. Replaced the image wrapper's implicit grid sizing with explicit block bounds and contained image dimensions. Subsequent desktop and mobile captures show bounded artwork.
- P2 visual hierarchy: initial 1024px catalogue used three columns and loose homepage rhythm. Matched the four-column desktop catalogue, reduced homepage hero/card/section spacing at the reference width and aligned the reference's service/brand/location sequence. Mobile adapts to two columns at 390px and one at 320px.
- P2 asset presentation: manufacturer SVG canvases contained large empty margins. Adjusted only the outer viewBox/size metadata; XML comparison verifies all descendant geometry, styles and fills are unchanged. NovinPay duotone/white SVG lockups and optimized mascot WebPs retain supplied bytes.
- P2 icons: a broad hero-fact span selector also styled nested icon spans. Restricted it to direct fact children; revised captures show visible, consistent existing Tabler icons.

## Required fidelity surfaces

Typography: Vazirmatn remains the single licensed public family, with existing 400–800 weights and no font payload additions. Approved optional variable fonts lack confirmed web-use licenses and were not integrated. Heavy navy headings, blue emphasis, readable line heights and RTL labels preserve reference hierarchy; exact glyph shapes differ from the reference typography.

Spacing/layout: matched hero copy/art split, rounded tinted sales/repair cards, service grid, brand cards, contact cards and dark five-column footer. Header/footer use the supplied official horizontal lockups without separately duplicated brand text. Desktop and narrow layouts have no horizontal overflow, clipped controls or overlapping imagery. Native mobile navigation and FAQ disclosures work with Enter; filter and product-anchor navigation were exercised.

Colors/tokens: public-only blue/navy, mint and peach surfaces follow the references; contrast-safe orange actions intentionally differ from the brighter reference. Admin tokens and operational styling remain separate.

Image quality: supplied transparent WebPs are contained and sharp; primary heroes use high priority while supporting assets load lazily. No screenshot crops, invented product photographs, fabricated storefront/workshop/staff photographs, new generated images or unapproved archived mascot are used. The actual supplied mascot variants differ from figures inside the reference screenshots and are the approved production assets. Supplied manufacturer identity variants are used as provided.

Copy/content: existing configured contacts/hours/address, sample-data flags, real database stock/price values, form fields, CSRF/idempotency tokens, privacy notices and online-request/physical-intake distinction remain. Unsupported guarantees, fabricated ratings, new stock/sort controls, comments, checkout and messenger/live-chat actions from the mockups were not introduced. Product photos without verifiable reuse rights use an intentional model/brand identity card; actual uploaded product images continue to render normally. Missing storefront/map photography uses an approved mascot/contact composition with genuine external map links. These are explicit owner-policy fallbacks, not fidelity defects or empty placeholders.

## Acceptance and limits

All 28 captures: HTTP 200, one H1, no broken images, no horizontal overflow, zero main-content axe WCAG A/AA violations and no browser console/request errors. A separate mobile pass includes header/main/footer axe (zero violations) and eight successful checks for keyboard menu, catalogue search, detail anchor, absent checkout, FAQ, required-field validation and retained repair/consultation fields/tokens. No customer form was submitted during this visual pass.

The first long QA script waited indefinitely for off-screen lazy images to decode. The test harness now scrolls through each page before capture, bounds decode waiting, and runs short viewport batches. No environment or browser security setting was changed.

Known differences are accepted constraints under the owner's missing-image and functional-preservation instructions: licensed font fallback, unavailable genuine photos, existing additional required/private form fields, dynamic catalogue content and supplied logo/mascot variants. These prevent pixel-identical full-page heights. Remaining P3: owner-provided genuine device/store photography and licensed optional typography can be evaluated later. No unresolved P0/P1/P2 implementation findings remain within this approved fallback scope.
