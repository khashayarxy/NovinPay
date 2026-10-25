# NovinPay standalone maintenance — design QA

final result: passed

## Source, captures and comparison

- Source visual truth: `C:/Users/Khashayar/Downloads/NovinPay Under Maintenance Illustration.png`, 1536 × 1024 pixels, owner-approved.
- Evidence directory: `C:/Users/Khashayar/.codex/visualizations/2026/10/20/01a15969-8b3c-7783-9b3b-bade84862fc0/maintenance-page/`.
- Implementation: `maintenance-1536-final.png`, captured at the same 1536 × 1024 CSS viewport; full-page captures at 320, 390, 430, 768 and 1440 CSS px are also retained.
- Full-view comparison: `comparison-desktop.png`, source on left, implementation on right. Both are downsampled equally from 1536 × 1024 to 768 × 512 for the comparison board, without changing their aspect ratios.
- Focused comparison: `comparison-details.png`, actual 1:1 typography/features regions from both source and implementation. Both the full board and detail board were opened and inspected.
- State: public standalone maintenance message, no authenticated user, no navigation or normal site footer. The actual 503 response is tested separately from the visual HTML preview.
- Browser: Codex in-app Chromium. Explicit responsive viewport overrides, no intentional density multiplier. Native screenshot output is normalized to PNG encoding, not creatively edited.

## Iteration history

1. Initial desktop capture was too tall (1071 px at the 1440 × 960 viewport). [P2] Section spacing and image scale were reduced while preserving the reference order and central illustration. Updated capture fits approximately one desktop viewport (963 px content at 960 px height), with no clipped content.
2. Initial mobile feature icons had inconsistent vertical positions because descriptions differed in height. [P2] Mobile feature columns now align at their start. Browser measurement confirms equal icon Y positions in all three columns at every tested width.
3. The desktop supporting paragraph wrapped differently from the source. [P2] A desktop-only line grouping, matching type size and illustration proportions now follow the reference. Mobile text remains fluid and naturally wrapped. The updated same-size comparison was inspected after the correction.
4. Small subtitle text had borderline contrast in a sampled desktop region. [P2] Its blue-white color was raised while retaining the reference palette. Body text, contact links and visible focus treatment were checked; this is a targeted manual accessibility review, not a claim of an exhaustive WCAG certification.

## Required fidelity surfaces

- Fonts/typography: local licensed Vazirmatn Regular/Bold, embedded WOFF2; real Persian text, diacritics, semantic H1/H2 hierarchy, RTL and isolated LTR phone digits. No rasterized body copy. The headline, labels and paragraph match the approved copy; both business phone values come from the actual config rather than the reference raster.
- Spacing/layout rhythm: centered logo/subtitle, large POS/tools illustration, white headline, supporting paragraph, three horizontal desktop feature highlights and separated contact block. Tablet/mobile preserve the hierarchy with scaled illustration, wrapped heading and icons above labels. No horizontal scrolling at 320/390/430/768/1440 or comparison width 1536.
- Colors/tokens: deep-blue full-viewport gradient, blue-white supporting text, white headings, blue icons and subtle divider. Background lighting is recreated in CSS as explicitly requested, not a substitute for the source illustration.
- Image quality: actual approved central artwork and feature icons, lossily optimized as WebP at quality 92 (phone crop 95), fully visible subject and responsive aspect ratio. No generated replacement artwork, fabricated photographs, custom SVG illustrations or new brand mark. Existing official white/blue SVG lockup is embedded intact.
- Copy/content: exact requested H1 and feature labels, approved supporting message and thank-you line; verified configured telephone numbers 09362896373 and 09384144021 in Persian display digits with matching tel URLs. No unrelated CTA, product shortcut, normal header, menu or footer.

## Browser and interaction checks

- All images load, and the embedded bold font is available at every viewport.
- Both contact links can receive keyboard focus, with a visible light outline; touch targets are at least 44 CSS px high. No phone call was initiated during QA.
- One H1 and a labelled main landmark; illustration has descriptive alt text, redundant decorative feature icons have empty alt attributes.
- No console warning/error was observed in the final preview.
- No JavaScript, external font, remote API, Vite reference or additional image request is present in the production page source. The earlier Herd toolbar is local developer injection; it was hidden only for the browser session before final captures.
- The temporary public visual-preview file was removed after captures. A self-contained HTML preview and gallery remain outside the repository for owner review; they are explicitly not evidence of HTTP availability.

## Remaining P3 differences and limits

- Small differences in gradient lighting, font rasterization and the official lockup's proportions remain compared with the raster mockup. The background and copy are responsive HTML/CSS, not a flattened screenshot. No material visual direction change was introduced.
- The supplied reference has no mobile frame. Mobile adapts the same desktop hierarchy; there is no invented bottom bar, navigation or additional content.
- Production LiteSpeed, provider cache behavior and server-level static emergency routing have not been activated or tested in this task. Those are deployment gates, not reasons to claim the local HTTP fixture is production proof.
- No unresolved P0/P1/P2 visual finding remains in the inspected states. Owner visual approval and separate authorization are still required before push or deployment.

## Implementation checklist

- [x] Same-reference and focused visual comparison completed after corrections.
- [x] Desktop/tablet/mobile captures and browser metrics retained.
- [x] Real HTTP 503 / cache / Retry-After / missing-Composer fixture tested.
- [x] Relevant tests, full SQLite regressions, Pint and frontend build passed.
- [x] Original database and environment preserved; no push, PR, merge or deployment.


---

## Preserved previous project QA record (unchanged content)

# Final footer — PR #5

final result: passed

Three RTL desktop columns (brand, four useful links, contact), stacked mobile layout, #102B45 background and original supplied white horizontal SVG. Redundant product/brand/tracking/directions links removed from footer only. Unapproved legal draft links omitted; the actual legal routes remain drafts/noindex. Dynamic Persian copyright stays centered. Header/hero/services/trust/brands/map/contact-panel files unchanged.

Actual footer/full-page screenshots reviewed at 1440/1024/390/320: no overflow, zero scoped axe WCAG A/AA violations; keyboard mobile menu unchanged. Evidence under task artifacts footer-final/gallery.html and environment-setup/footer-final-*. Full guarded SQLite 425 passed / 2923 assertions; Pint/Vite pass. Production-readiness report is a local review artifact (footer-final/PRODUCTION-READINESS.fa.md), not permission to deploy. Hosting capabilities/trigger policy/legal text/Neshan place identity remain NO-GO gates.

Existing unrelated deletion of public/images/home/novinpay-hero-terminal.webp was present on entry, untouched and excluded from this footer commit. Original DB/environment/keys/lockfiles/Herd preserved. No server writes, production migrations, merge or deploy.

---

## Previous records

# Final visual polish — PR #5

final result: passed

- Old WebP had baked-in partially opaque gray/blue edge pixels, not CSS smoke/filter. Replaced by supplied Object.png (853×933), lossless WebP at public/images/home/novinpay-terminal-clean.webp. Four corners alpha zero; alpha identical and visible composite pixels identical to source. WebP normalizes irrelevant RGB in fully transparent pixels. No device/logo generation, image cropping or masks.
- Exact section gradient 90deg #E0F0FF → #F8FBFF; transparent figure, restrained CSS blue arch and soft elliptical ground shadow. Supplied wave background not overlaid because the brief explicitly requires this gradient. Complete supplied device/card retained (new source has no receipt); illustrative alt, no authenticated-photo claim.
- True header-centered desktop navigation with one light-blue rounded outline; compact 1024px layout without logo collision. Existing keyboard mobile menu intact. Copyright spans its own centered full-width row with dynamic Persian year and exact approved wording.
- Central enum labels آکبند / استوک, internal new/used unchanged. Cards/details/product forms reuse mapping; inventory/order labels/options centralized too. Static homepage/meta/footer/future sample fixture copy updated. Trust item condition wording changes only; seven service cards unchanged. No new filter/features.
- Exceptions: existing persisted free-text product names/descriptions, historical documents and snapshots are not rewritten. Owner must review any old free-text wording and verify unopened condition before calling physical stock آکبند; relabel does not certify stock grade/warranty/refurbishment. No database records changed.
- Browser: 1440/1024/390/320, no overlap/horizontal overflow/broken hero image, zero scoped header/main/footer WCAG A/AA axe violations. Nav and copyright midpoints match half page width; mobile Enter/menu works. Catalogue/PAX search/product detail return 200 with updated labels.
- Full guarded SQLite: 424 tests / 2952 assertions. Pint and Vite pass. CI/MySQL outcome is checked independently on the pushed head.
- Seven protected fingerprints unchanged; original SQLite SHA-256 466981e70c0fcce755ab4399d40e0c54609c1a995e3ad9a48e33608953ad0eab. No migration/seed/dependency/environment/Herd/production operations.
- Evidence: environment-setup/final-polish-home-{1440,1024,390,320}.png and final-polish-viewport-{1440,1024,390,320}.png. Compared with supplied preview: existing seven service cards, actual logo/menu retained instead of preview's three cards/device chrome. Neshan place identity remains pending owner confirmation. Draft, no merge/deploy.

---

## Prior records (hero superseded above)

# Final hero upgrade — PR #5

final result: passed

- Visual check against the supplied standalone artwork and explicit owner layout: image left/content right at 1440px; image first above welcome badge/content at 390/320px. Exact headline/paragraph and two unchanged CTAs; mobile buttons stack full-width. No new visual cards, overlays or navigation.
- Original supplied WebP is unchanged, RGBA 1360×941, 314,798 bytes; CSS object-fit contain, transparent container and intrinsic dimensions/aspect ratio preserve complete receipt/arcs/terminal and reserve image space. It is labelled as an AI illustration, not authenticated product photography.
- At height 900, hero heights: about 443/580/531px at 1440/390/320. Image decoded successfully at all widths; no overlap/overflow or scoped header/main/footer WCAG A/AA axe violations. Native mobile keyboard navigation and catalogue destination verified.
- Full-page screenshots and viewport captures reviewed: `environment-setup/hero-upgrade-home-{1440,390,320}.png` and `hero-upgrade-viewport-{1440,390,320}.png`; gallery in `hero-upgrade/gallery.html` under task artifacts. Third-party map tiles can load independently; iframe/source/directions unchanged. Herd's development toolbar remains visible.
- 87 focused guarded SQLite tests / 616 assertions, Pint and Vite pass. Post-hero markup confirmed identical to prior HEAD; no route, form, map, footer, data, environment, key, lockfile or Herd changes.
- Seven protected fingerprints unchanged; original SQLite SHA-256 `466981e70c0fcce755ab4399d40e0c54609c1a995e3ad9a48e33608953ad0eab`.
- Pending owner visual review; previous Neshan place-label confirmation remains outstanding. No merge or deployment.

---

## Prior iteration records

# Navigation and redundant-page cleanup — PR #5

Local technical/visual verification: passed; owner visual approval and Neshan place identity confirmation remain pending.

- Homepage service cards preserve their seven titles, descriptions, icons, markup and layout; four trust items and minimal hero unchanged. Only three retired Services destinations change to existing Contact; no individual service-detail routes exist. Four repair destinations unchanged.
- Header desktop/mobile retain Home, Products, Repairs, Contact in order. Enter opens the native mobile menu and Products navigation works.
- General About and Services routes/controller methods/dedicated views and metadata removed; proper 404 without unrelated redirects. Footer redundant services column/About link removed; all ten unique homepage internal destinations return 200. Catalogue empty-state guidance corrected.
- Exact owner-supplied responsive official Neshan iframe visibly renders; all three exact directions URLs preserved. **Release decision:** the supplied iframe marker is labelled `شهربازی ماکوان / خانه بازی`; owner must verify its identity/location. No coordinates were guessed or substituted. External-map loading disclosed in privacy draft.
- Reviewed actual 1440/390/320 captures: seven service cards, four trust items, three authentic manufacturer images; no horizontal overflow, missing images or scoped WCAG A/AA axe violations. Six operational public route smoke checks return 200. Two console 404s correspond to deliberate retired-URL tests. Cross-origin frame internals are outside the scoped axe audit.
- 86 focused guarded in-memory SQLite tests / 606 assertions; Pint and Vite pass. No migrations, seeds, submissions, dependency changes or environment changes.
- Original SQLite SHA-256: `466981e70c0fcce755ab4399d40e0c54609c1a995e3ad9a48e33608953ad0eab`. Seven preservation fingerprints (including environment, original database, lockfiles and Herd configuration) unchanged.
- Evidence: `navigation-cleanup/gallery.html` and `environment-setup/navigation-*` in the task visualization directory. Full-page captures expand the viewport to include Chromium's cross-origin map frame; responsive/keyboard checks use height 900. Herd's local developer toolbar remains visible; no tool UI was modified.

---

## Earlier revision record (superseded for navigation and maps)

# Minimal homepage revision — PR #5

final result: passed

Owner visual approval is pending. This latest direction supersedes the initial Option 2 composition below.

- Removed public-header search/price controls, both promotion banners, the complete Why section and the three oversized hero model cards. Removed their genuinely unused CSS and artwork branches; shared routes and operational pages remain.
- Preserved the approved lockups, all six navigation links, both hero CTAs, four compact benefits, seven service links, three genuine manufacturer SVGs, accurate address/hours and all three map links. Manufacturer logos now appear only in their own homepage section.
- Replaced heavy hero cards with restrained service typography on light blue; no unverified product photos, mock devices, mascots, map tiles or new sections. Mobile navigation uses native details/summary with keyboard support and no JavaScript dependency.
- Actual 1440/390/320 captures reviewed. Full-page image heights decreased respectively from 1954/3767/4319 to 1584/2651/2739 pixels. These are measured screenshot heights, not a similarity score.
- All three widths: HTTP 200, one H1, no overflow/broken images/empty placeholders/mascots; zero scoped header/main/footer WCAG A/AA axe violations. The removed sections/controls are absent and exactly three manufacturer images remain.
- Seven public-route smoke checks returned 200. Enter opens the mobile menu; all six links and catalogue/PAX search work. Repair/consultation required-field validation and CSRF/submission tokens remain intact. No browser form submission occurred.
- 83 focused guarded in-memory SQLite tests / 518 assertions, Pint and Vite pass. No dependency, business logic, original database, environment or Herd configuration changes.
- Evidence: task visualization folder `homepage-minimal/gallery.html`, `homepage-minimal/compare-{1440,390,320}.png` and `environment-setup/minimal-*` captures. Prior screenshots remain unchanged. No merge/deployment authorized.

---

## Initial Option 2 implementation record (superseded by owner feedback)

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
