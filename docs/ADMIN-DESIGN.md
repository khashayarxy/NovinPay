# NovinPayPos admin design

## Research

This review uses public product documentation and product images. Private merchant and operator dashboards were outside the review. The linked references show the vendors’ own interfaces.

| Reference | What transfers to NovinPayPos | What to leave out |
| --- | --- | --- |
| [Linear refresh and comparison screenshots](https://linear.app/now/behind-the-latest-design-refresh) | Separate location from actions. Keep the rail quieter than the active work, reduce icon weight, and use restrained borders to show structure. | Custom themes, command menus and keyboard conventions that would add a new interaction model. |
| [Stripe Dashboard search](https://docs.stripe.com/dashboard/search) | Keep precise identifiers and filters close to the results. Use readable numeric columns and concise status text to support financial comparisons. | Query syntax, cross-entity search, and charts without a staff decision attached. |
| [Shopify admin refresh](https://shopify.dev/changelog/posts/prepare-your-app-for-the-shopify-admins-new-look) and [order workflow walkthrough](https://help.shopify.com/en/manual/fulfillment/managing-orders) | A collapsible frame gives wide tables more room. A short list toolbar keeps filters, actions and fulfillment state close. | App-wide search and bulk actions for workflows with distinct permission and confirmation requirements. |
| [Intercom inbox setup](https://www.intercom.com/help/en/articles/10223008-setting-up-the-inbox) and [Inbox screenshot](https://www.intercom.com/blog/announcing-intercoms-next-gen-inbox/) | Show the active task alongside its reference context. Keep independent support one shortcut away. | A permanent third column or embedded conversations on the admin dashboard. |
| [Vercel Geist table](https://vercel.com/geist/table), [empty states](https://vercel.com/geist/empty-state) and [interface guidelines](https://vercel.com/design/guidelines) | Use semantic columns, tabular digits, explanatory empty states and distinct hover, focus and press states. Use progress indicators when async work has a known waiting state. | Skeletons for server-rendered Blade pages or JavaScript virtualization for small paginated lists. |
| [RepairDesk ticket walkthrough](https://help.repairdesk.co/portal/en/kb/articles/ticket-management) | Keep stage and device identity visible beside the tools for changing them. Give staff a direct path from list to case. | Additional ticket states or assignment rules for an interface release. |

At db30250, the NovinPay dashboard counts sat far from their labels, the repair record stacked reference details and work across a wide canvas, and the product editor displayed optional specification rows during routine entry. Similar blue outlines also made actions and content compete for attention.

## Three directions

| Direction | Overview | Repair and commerce surfaces | Assessment |
| --- | --- | --- | --- |
| Refined SaaS | Bright shell with a spacious primary workspace and one accent | Single-column edit pages and airy tables | Readable and familiar, but close to the prior uniform white-panel layout. |
| Precision Workspace | Ink navigation rail with a narrow location bar and a work queue | A focused repair editor beside device context and compact native list filters | Best fit for frequent switching between repairs and orders. A neutral work area balances the darker rail. |
| Commerce Operations | Product and inventory lead the hierarchy | Strong totals, detailed catalog and ledger rows, and compact filter controls | Useful density and numeric alignment. The overview would make repairs compete with commerce metrics. |

**Selected direction — Precision Workspace.** The active route and pending workload provide the color cues. The commerce direction contributes table and filter density. Device intake and active repair work anchor the dashboard.

## Design system

- **Surfaces.** Ink rail #142337, canvas #f2f5f8, white work surface, and pale blue selection #edf4ff. Use #dce3ec for separators and a darker #a7b4c5 for controls. Use a small panel shadow on raised work surfaces and a deeper shadow for overlays.
- **Type.** Use the existing Vazirmatn files. Page headings use 22–30px, section headings use 15–17px, labels and content use 13–14px, and secondary metadata uses 11–12px. Isolate mixed-direction identifiers. The existing Persian digit and Jalali adapters continue to send canonical values.
- **Spacing and shape.** Use a 4px rhythm for related items, 8–16px between controls, and 16–24px between content groups. Inputs and buttons use an 8px radius, panels use 14px, and states use pills. Give mobile buttons and inputs a 44px minimum height.
- **Actions and states.** Blue fills identify primary submissions. Bordered buttons identify secondary actions. Existing Tabler icons provide a consistent weight. Pills combine color and text. Form errors stay adjacent to the original controls. Destructive actions retain their confirmation and authorization.
- **Tables and forms.** Put filters next to the table. Keep semantic headers and use a labelled keyboard-scrollable region for wide data. The compact repair list reflows all fields at small widths. Group fields by task, separate form actions with a footer, and disclose optional product specifications on demand.
- **Motion and feedback.** Use short color transitions for direct interactions and disable them for reduced motion. The native calendar dialog shares the overlay treatment. Empty states explain what happened and what the operator can do next. Retain native form submission, password-manager semantics, and status and alert announcements.

Core tokens live in resources/css/admin.css. Blade owns semantic structure. The existing Vite entry point manages desktop rail visibility and the mobile disclosure. Browser evidence uses the local Herd fixture and stays outside Git.
