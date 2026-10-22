# Option 2 homepage QA

final result: passed

This is an implementation QA result within the owner's explicit CSS/address-card fallback policy. **Owner visual approval is pending.** Technical checks do not constitute approval of the design.

Source: new `NovinPay-Option2-References.zip`, full-page Option 2 image and five section crops. References are conceptual AI composites, not real photographs. Implementation starts from stable main; rejected PR #4 is excluded.

## Comparison checklist

- Kept the existing hero headline, two hero actions, four trust items, two promotion cards, seven services, Why/brands, location and footer sequence.
- Matched the light-blue hero, white surfaces, mint sales/peach repair cards, blue accents, restrained borders/shadows and dark footer. Retained licensed Vazirmatn and existing Tabler icons.
- Hero uses three neutral model cards with a dominant PAX D230 card, Newland ME31 and NewPOS 7210. These are explicitly a CSS fallback; the reference's product photography was not reproduced with unverified or AI hardware images.
- Sales uses NewPOS 7210 identity; repairs uses PAX S915 identity and tool icon. Reoriented the repair card to put its visual left and copy right, matching the new reference. Preserved both original CTAs.
- Why uses a quiet technical mark. Genuine supplied manufacturer SVGs replace text approximations; source artwork is fingerprint-tested.
- The location is an address/directions card with all three providers; no map preview is claimed. Complete configured business information remains available.
- New owner-approved black/blue and white/blue lockups are visibly readable at their actual desktop/mobile sizes. Canvas trimming does not alter artwork. Admin logos and favicon are unchanged.
- Narrow 320px header initially wrapped its price action and left a single navigation item on another row. Removed only its decorative action arrow at that width and arranged the same six links in a deliberate three-column/two-row layout. Re-captured and inspected the result.

## Actual verification

Screenshots at 1440, 390 and 320px use the canonical isolated site, same homepage state and full-page CSS scale. Header and footer-logo closeups are also saved. Reference/current side-by-side comparison is normalized to the same displayed width without distorting image proportions. Evidence is outside Git under the task's `homepage-option2/` and `environment-setup/option2-*` paths.

All three widths: HTTP 200, one H1, zero horizontal overflow, zero broken images, zero empty placeholders, zero mascots and all three exact directions URLs. Scoped axe checks across header/main/footer returned zero WCAG 2 A/AA and 2.1 AA violations. Catalogue, product detail, about, repair, tracking, consultation and contact smoke checks returned 200 without broken images or overflow. Primary catalogue CTA, PAX filtering, required-field validation, form tokens and keyboard skip-link focus were exercised without submitting personal data.

Remaining visual differences: photographs are replaced with the authorized neutral CSS treatment; map imagery with an honest address card; real application labels/information replace inaccurate AI reference copy. Screenshot heights adapt to actual Persian text, the three mobile widths and preserved operational behavior. No percentage similarity claim or owner approval is inferred. See `docs/HOMEPAGE-OPTION2.md` for sources and unresolved imagery permissions.

---

# Historical Phase 3 QA record (superseded scope; retained for traceability)

# NovinPay Phase 3 — visual verification

final result: passed

Result applies to the owner's explicitly authorized placeholder-artwork scope.
It does not claim pixel-perfect artwork fidelity or approval to start Phase 4.

## Evidence and normalization

- Source visual truth: `C:/Users/Khashayar/Downloads/Telegram Desktop/file_00000000f4fc824697b11cf32261b7b3.png` (1024 × 1536).
- Local route: `http://127.0.0.1:8765/`, public/unauthenticated, light theme, Persian RTL.
- Reference-width implementation: `C:/Users/Khashayar/.codex/visualizations/2026/10/20/01a15969-8b3c-7783-9b3b-bade84862fc0/novinpay-home-reference-width.png` (1024 × 1554).
- Desktop: `C:/Users/Khashayar/.codex/visualizations/2026/10/20/01a15969-8b3c-7783-9b3b-bade84862fc0/novinpay-home-desktop.png` (1440 × 1590).
- Mobile: `C:/Users/Khashayar/.codex/visualizations/2026/10/20/01a15969-8b3c-7783-9b3b-bade84862fc0/novinpay-home-mobile.png` (390 × 3402).
- Responsive interaction checks used 390 × 844 and 768 × 1024 CSS viewports.
- Full-page captures used stable, document-height viewports at the same widths,
  then the browser's full-page screenshot API. Device density was approximately
  1 (reported 1.0000000149); no screenshot artwork was cropped into the site.
- The source and final 1024px implementation were opened together in one image
  comparison input; final desktop and mobile captures were inspected in that
  same comparison. These are real browser captures, not HTML mock images.
- The initial stitched capture showed a browser-capture alignment artifact and
  was discarded as evidence. Stable full-height captures corrected it without
  modifying page content for screenshots.
- Focused upper-page native captures were also inspected at 1024px. Separate
  source crops were unnecessary: the 1× 1024px pair exposes the headline, CTA,
  service-card, brand and footer details without extracting source artwork.

## Comparison history

1. Initial implementation: source-width page height about 1699px. Findings:
   **P2** header/hero and service spacing too tall; **P2** repair promotion used
   the same composition as sales; **P2** all-services action was on the wrong
   desktop side; **P2** heading weight did not reflect the source hierarchy.
   Corrected header/hero heights, section spacing, mirrored repair layout,
   action alignment, and used bundled Vazirmatn ExtraBold for display headings.
2. Refined pass: page height reduced first to about 1606px, then 1554px. The
   service row, promotion dimensions, location and footer align closely with
   the reference. Mobile supporting copy was increased for readability.
3. Final composition pass: restored the tall hero-art column alongside the
   four compact trust benefits. A **P2** placeholder-clearance/text-wrapping
   issue was found and corrected by narrowing the art slot and shortening two
   benefit captions. Final screenshots show clear, readable benefit content
   and no overlap with links. Hero artwork is 350px high on desktop and returns
   to normal flow on tablet/mobile. Final source-width page height is 1554px.

## Section-by-section review

| Surface | Result and deliberate differences |
| --- | --- |
| Header | Official square logo, RTL navigation, inquiry CTA and functional catalogue search link. Missing About/pricing routes were not invented. |
| Hero | Right headline/copy/actions; tall left artwork slot extends into the benefits region. Original mascot/background remains unavailable. |
| Trust | Four compact benefits to the right of the art column on desktop; 2×2 mobile layout. Unverified guarantees were replaced with factual wording. |
| Promotions | Right sales card in turquoise; left repair card in peach/orange with mirrored composition. Original product/workbench artwork remains absent. |
| Services | Seven outlined icon cards in one desktop row; four-column tablet and two-column mobile grids. Last mobile card spans both columns. |
| Brands / why | Three neutral brand-name links beside the blue why-section on desktop; stacked on mobile. No recreated manufacturer logos or human character. |
| Location | Official address/hours and three working map destinations. No fabricated map or unverified storefront photograph. |
| Footer | Dark navy information/navigation footer with original white logo. No invented social destinations or dead icon buttons. |

## Required fidelity surfaces

- **Typography:** required Vazirmatn, locally bundled; 400/500/600/700 and new
  800 display weight. Final hero is approximately 38px at 1024 and 49px at 1440;
  mobile uses 33px. Strong heading hierarchy, intentional line breaks, natural
  RTL alignment and readable wrapping were checked. Source font metadata is
  unavailable, so exact typeface/antialiasing equivalence is not asserted.
- **Spacing/layout:** matching 32px source-width side margins, compact header,
  293px hero, 234px promotion cards, seven-card service row and 192px location
  band. The final page is 18px taller than the 1536px reference. Tablet/mobile
  stacking, card dimensions, corner radii and clear space were inspected.
- **Colors/tokens:** official #098CF7 for primary decorative emphasis, darker
  accessible blue for small text/actions, pale blue hero/why panels, turquoise
  sales and warm repair panels, dark navy footer. Original logo colors and
  geometry were preserved. Reference photographic/gradient depth is absent.
- **Imagery:** five explicitly labelled art slots, with local approved-asset
  configuration and safe missing-file fallback. No screenshot crops, generated
  characters, fake store photos or code-drawn replacement illustrations.
  Standard UI icons are unmodified, locally served Tabler outline SVGs with
  pinned provenance and MIT license; no icon framework/runtime dependency.
- **Copy:** approved business address, phone and hours; supported brands only.
  Unsupported authenticity/delivery guarantees, prices and social claims were
  omitted. All visible controls have relevant existing destinations.

## Functional/accessibility checks

- Hero product CTA opened the existing catalogue and its real sample records.
- Hero repair CTA opened the existing repair information/disabled preview page.
- Brand search destinations return successful responses in automated tests.
- Phone and map hrefs match approved configuration. External maps announce new
  tabs and include noopener/noreferrer; no call or external form was submitted.
- Skip link received keyboard focus with a visible solid outline.
- One H1, semantic sections/headings, decorative icons hidden from accessibility,
  original-logo alt text, and labelled missing-artwork states.
- No horizontal overflow at 1440, 1024, 768 or 390px checks. Fonts and original
  logo images load; final browser error/warning log was empty.
- 149 PHP tests passed (769 assertions), no failures/skips. Standard Vite build
  passed and emitted all five referenced font files. PHP formatting passed.

## Remaining differences / acceptance boundary

Original hero mascot and backdrop, sales/repair artwork, approved support
illustration, verified storefront image, and separate manufacturer logo files
are still missing. They are the main barrier to full visual fidelity; their
labelled placeholders are expressly allowed by the user for this phase.
There is no source mobile/tablet screenshot, so those layouts are responsive
adaptations rather than verified reproductions of a supplied mobile design.

No actionable P0/P1/P2 implementation findings remain within that scope. Minor
P3 optical refinements should be revisited after original artwork is supplied.
Owner visual approval is still required before any next phase.
