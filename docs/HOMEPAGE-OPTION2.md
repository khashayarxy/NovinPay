# Minimal homepage polish — PR #5

Built independently from main `e05c42769e4c368f77545ad6e7e2624de69e869c`. No rejected PR #4 files, commits or assets were restored. Scope is the existing homepage and its shared public header/footer lockups. Operational application logic, product data, administration, forms and dependency locks remain unchanged. The latest owner request retires only the general About and Services routes and updates the central map configuration.

## Owner-supplied assets

Sources: `NovinPay-Final-Handoff/NovinPayLogo.zip`, `BrandLogos.zip` and `NovinPay-Option2-References.zip`. The last archive contains conceptual AI reference images, used only for layout comparison and never shipped as photography or map tiles.

Two approved horizontal lockups are under `public/brand/lockups/`. PAX, NewPOS and Newland originals are under `public/brand/manufacturers/`. SVGs were inspected for scripts, event handlers, embedded images and external references; none are present. Only outer width, height and viewBox were changed, using browser-measured bounds with padding. All descendant paths, glyphs, fills, transforms, styles and proportions remain intact. Regression tests fingerprint canonical source XML excluding only those three approved outer attributes. Existing symbol logos and favicon retain their original bytes and continue to serve the admin interface.

## Photography and map limitations

**No product photographs were published.** Exact model identity and publication permission are separate checks. The focused source review did not establish a reusable commercial image license for the required models. No permission was inferred from a manufacturer download link, and no external images are hotlinked. References inspected:

| Model | Primary evidence inspected | Decision |
| --- | --- | --- |
| PAX D230 | [Official product page](https://www.paxtechnology.com/d230) | Model identified; publication permission not established. |
| Newland ME31 | [Official manufacturer brochure](https://download.newlandpayment.com/root/website-en/ME31.pdf) | Model identified; no reusable image license established. |
| NewPOS 7210 | [Manufacturer-authored PCI security guidance](https://listings.pcisecuritystandards.org/ptsdocs/4-90031Security_Policy_v5.1-1537562614.18447.pdf) and [current manufacturer site](https://www.newpostech.com/) | No exact-model photograph with verified publication rights found. NEW7220 was not substituted. |
| PAX S915 | [Manufacturer-authored PCI security policy](https://listings.pcisecuritystandards.org/ptsdocs/4-40308%20S915_Security_Policy-1706902857.98444.pdf) | Model identified; no publication permission for a photograph established. |

The revised homepage uses the owner's definitive minimal direction: a low-weight typographic hero, compact benefits, common services, manufacturer logos, location and footer. Both middle promotional banners and the entire Why section are removed. Public-header search and price controls are removed while the four retained navigation links remain (Home, Products, Repairs, Contact), including a native keyboard-accessible mobile menu. All existing product, repair, tracking and consultation routes remain. Only the three genuine manufacturer SVGs are displayed in the dedicated brand section; the hero no longer repeats them. No stock/model claim, device mockup or unlicensed photograph fills the removed regions.

The homepage now embeds the exact official Neshan iframe URL supplied by the owner, with a titled, lazy-loaded responsive frame and all three explicit Balad/Neshan/Google directions links. No fake map tiles, screenshots or reconstructed map are shipped. Browser verification confirms actual map rendering. **Owner decision remains:** the supplied place identifier labels its marker `شهربازی ماکوان / خانه بازی`, not NovinPay. The exact URL was preserved; geographic/business identity is not certified. Confirm or replace the supplied place URL before release. Loading the embed contacts Neshan; the privacy draft discloses this external provider.

`/about` and `/services` are retired with normal Persian 404 responses, without misleading redirects. Their controller methods, dedicated views/metadata and live navigation references are removed. No individual service-detail routes exist in this codebase: the ownership/account/other-service cards now reach the existing Contact page; the four repair cards retain `/repair`. Only destinations changed inside those seven cards; icons, descriptions, markup and service/trust CSS remain unchanged. The auxiliary “all services” control is now “service guidance” linking to Contact. Footer removes the redundant services column and About link; catalogue empty-state guidance also reaches Contact.

## Verification and production decisions

Focused guarded in-memory SQLite checks: 86 tests, 606 assertions. Pint and Vite pass. No dependencies added. Browser review: homepage at 1440, 390 and 320px; no broken images, overflow, empty image placeholders or mascots. Scoped axe checks include public header, main and footer; zero WCAG A/AA violations. Six operational public routes returned 200; all ten unique homepage internal destinations returned 200, and both retired routes returned 404 with both lockups. Catalogue/search navigation, native required-field validation, retained CSRF/submission tokens and keyboard skip-link focus were checked; no browser form was submitted.

Original SQLite SHA-256 remains `466981e70c0fcce755ab4399d40e0c54609c1a995e3ad9a48e33608953ad0eab`. Environment, keys, Herd guards, dependency lockfiles and recovery material are preserved. No migration, seed, production operation or deployment occurred.

Owner visual approval is pending. Genuine owner-owned product photos or explicit manufacturer permission would allow a separately approved photographic treatment later. The supplied Neshan place identity must be confirmed before release. Neither missing input is silently represented as completed photography/map work. No numeric visual similarity score is claimed. Merge and deployment require separate approval.
