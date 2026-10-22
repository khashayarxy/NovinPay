# Final design assets and release preparation

This change follows Phase 7A main e05c42769e4c368f77545ad6e7e2624de69e869c and changes public presentation only. It does not implement Phase 7B, chat, SMS, payments, eNAMAD or any new business workflow. The owner explicitly confirmed the supplied horizontal duotone header lockup; the white lockup is used in the footer. Existing admin logos/favicons are preserved.

## Asset provenance

Inputs: owner-supplied D:/NovinPay/NovinPay-Final-Assets.zip and D:/NovinPay/NovinPay-Logo-Lockups.zip. Six approved designs are external QA references, not production backgrounds. Shipped assets: five optimized transparent mascot WebPs (blue-main, blue-header, orange-repair2, turquoise-sell2, everyone-aboutus2), three manufacturer SVGs and two NovinPay SVG lockups; 607,402 bytes in total. No PNG alternatives or screenshots are shipped. SVGs were checked for script, external href/image references, event handlers and foreignObject; none were found. Manufacturer viewBox margins were trimmed without changing descendant artwork. Lockups/WebPs preserve supplied bytes.

Fonts: retained existing OFL Vazirmatn, no new font weight or font dependency. Web-use licensing for optional supplied proprietary fonts is unverified, so they remain outside Git and deployment. Existing Tabler icons are reused; no icon/editor dependency was installed.

A focused search did not establish usable photo rights for the required actual device models. Generic stock or screenshot-cropped product imagery is not used. Product pages show genuine uploaded images when present and an intentional model/brand composition otherwise. Storefront/workshop photos in mockups were unavailable; those regions use approved mascots, actual contact information and existing external map links. No facility photograph was invented. See design-qa.md for source comparisons and intentional differences.

## Behavior and verification

Search/category semantics, published-only product access, stock/price/sample labels, repair/consultation actions and private payload handling remain unchanged. There are no model/controller/migration/authorization changes. Duplicate detail-page purchase actions were consolidated into distinct phone and consultation actions. Mobile navigation and FAQs use native details/summary without a JS dependency.

Full local SQLite and disposable MySQL suites: 428 tests / 2,943 assertions each; Pint, Vite and locked dependency audits pass. Browser evidence includes all six designs plus the existing contact page at four widths (28 captures), eight interaction checks and scoped accessibility checks. Synthetic catalog records are labeled as samples and were created only after the guarded runner verified the isolated canonical development target. They are not deployment data. No original migration, seed, key change, hosting operation or production deployment occurred.

## Deployment workflow

The updated docs/DEPLOYMENT.md supersedes old pre-administration instructions that permitted unsupported database versions or generic key/seeder operations. scripts/prepare-release.ps1 creates only a local committed-source + Vite artifact outside Git; it does not install, migrate, contact hosting or deploy. Use an approved protected main SHA with successful post-merge CI for an actual release. Feature-branch artifacts are rehearsals. The owner's original SQLite, active environment/key, lockfiles, recovery archive and Herd safety guards are preserved.

Before production authorization: verify provider PHP/MySQL/trigger capabilities using the actual deployment account on isolated staging, restoration rehearsal, production environment/database identity and existing owner bootstrap requirements, real inventory/pricing, photo rights, exact release SHA and maintenance/recovery plan. The historical private recovery archive is not an application dependency or release input. Do not reintroduce its credentials or .env into this repository.
