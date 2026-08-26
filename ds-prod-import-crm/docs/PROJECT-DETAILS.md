# China Import CRM — Full Project Documentation

**Plugin name:** Product stock and order management CRM  
**Plugin slug / text domain:** `ds-prod-import-crm`  
**Plugin version:** `0.18.0`  
**Frontend assets:** `0.30.1` (`CRM_Assets::VERSION`)  
**Database schema:** `0.9.9` (`CRM_Activator::DB_VERSION`)  
**Author:** Developer-S.com Team  
**Purpose:** Custom CRM for China–Bangladesh product import operations — orders, China export, warehouse receiving, delivery, billing, payments, reports, and multi-role portals  

This document describes the application as implemented in code. It does **not** include commercial pricing. Last reviewed against the codebase on 13 August 2026.

---

## 1. Product overview

### 1.1 What it is

A **WordPress plugin** that delivers a full branded CRM application on a public frontend page (shortcode). Day-to-day users work in the CRM shell, not in classic WordPress admin screens.

It is built for a specific business model:

- Clients place product orders (often **before** stock arrives in Bangladesh)
- The **China office** confirms supply quantities and unit prices
- Goods are **exported from China** (often in partial shipments)
- The **Bangladesh warehouse** receives cargo and updates stock
- Goods are **delivered** to clients (partial deliveries supported)
- **Client billing & payments** and **supplier billing & payments** are tracked in ledgers
- Staff, China office, and clients each see the right modules and data

### 1.2 Core business loop

```text
Client / staff creates order
        ↓
Awaiting acceptance (workflow blocked)
        ↓
China office / staff accepts lines (qty + unit price)
        ↓
Active order (e.g. Pending)
        ↓
China records export shipment(s)  ←── partial OK
        ↓
(optional) Per-product qty change request → supervisor/admin review
        ↓  (approved reductions free qty for another shipper)
BD warehouse receives cargo       ←── stock up + supplier shipping bill
        ↓
BD delivers to client             ←── partial OK + delivery shipping bill
        ↓
Client payments allocated by purpose (order vs delivery), FIFO within each pool
        ↓
Order may auto-complete when fully paid (configurable)
```

### 1.3 How users access it

| Surface | How it works |
|---------|----------------|
| **Public CRM app** | Shortcode `[ds_prod_import_crm]` on a WordPress page |
| **Login** | Custom CRM login gate for CRM roles |
| **WP Admin Settings** | Branding, portal, timezones, data tools (settings managers) |
| **Redirects** | Most CRM staff are redirected from `wp-admin` to the CRM frontend |

---

## 2. Technology stack

| Layer | Technology |
|-------|------------|
| Platform | WordPress plugin (PHP) |
| Data | Custom MySQL tables via `$wpdb` / `dbDelta` |
| Auth / roles | WordPress roles + custom capabilities + user-meta overrides |
| API style | Logged-in AJAX (`admin-ajax.php`), nonce-protected |
| Frontend UI | Vanilla JavaScript modules (no React/Vue build) |
| Styles | Custom CSS (`crm-main.css`, `crm-frontend.css`, `crm-print.css`, admin settings CSS) |
| Charts | Chart.js 4.4.1 (CDN) |
| Images | Custom canvas cropper (`crm-image-crop.js`) |

### 2.1 Approximate codebase scale

| Metric | Approximate size |
|--------|------------------|
| Custom database tables | 22 |
| Module controllers | 15 |
| PHP files | ~68 |
| JavaScript files | 24 |
| CSS lines | ~6,800 |
| AJAX action handlers | ~86 |
| CRM roles | 8 (+ WP Administrator) |

---

## 3. Architecture

### 3.1 High-level structure

```text
ds-prod-import-crm/
├── ds-prod-import-crm.php          # Bootstrap, hooks, module init
├── includes/                       # Shared engines (roles, access, ledger, stock, tracking…)
├── modules/                        # Feature modules (controller + views)
│   ├── dashboard/
│   ├── orders/
│   ├── shipments/                  # China Export
│   ├── warehouse/
│   ├── delivery/
│   ├── payments/
│   ├── clients/
│   ├── companies/
│   ├── products/
│   ├── product-categories/
│   ├── reports/
│   ├── team/
│   ├── activity/
│   ├── order-statuses/
│   └── settings/
├── assets/js/                      # Front-end module scripts
├── assets/css/
├── templates/                      # App shell, login, admin settings
└── docs/
```

### 3.2 Request flow

1. User opens the CRM page → `CRM_Frontend` / `CRM_App` renders the shell  
2. Sidebar modules are filtered by `CRM_Access::user_can_access_module()`  
3. Each module’s JS loads lists/forms via `postAjax()` → `wp_ajax_crm_*`  
4. Controllers verify nonce + capability, then read/write custom tables  
5. Important mutations write to `crm_activity_log` via `CRM_Audit`

### 3.3 Shared engines (includes)

| Class / helper | Responsibility |
|----------------|----------------|
| `CRM_Roles` | Register/sync CRM roles and default caps |
| `CRM_Capability_Registry` | Module actions, legacy bundles, order edit rules |
| `CRM_Capabilities` | Per-user overrides (`ds_crm_cap_overrides`) |
| `CRM_Access` | Module access, login redirects, admin bar, **Additional CRM roles** on WP user profile |
| `CRM_Client_Portal` | Client linking, scoping, portal modules |
| `CRM_China_Office` | China portal roles (officer + supervisor) + baseline modules |
| `CRM_Ledger` | Client/supplier financial summaries & payment allocation |
| `CRM_Stock` | Increment/decrement stock by product/color/size |
| `CRM_Order_Status` | Status map, workflow blocking, accept, sync |
| `CRM_Order_Tracking` | Tracking steps, list badges, timeline events |
| `CRM_Order_Item_Priority` | Line priority (normal / 2nd / urgent) |
| `CRM_Audit` | Activity log writer / readers |
| `CRM_Ownership` | Own-record create/edit/cancel helpers |
| Helpers | Dates, amounts, weights, URLs, timezone formatting, settings |

---

## 4. Database model

All tables use the WordPress prefix + `crm_` (example: `wp_crm_orders`).

| Table | Purpose |
|-------|---------|
| `companies` | Cargo / local suppliers |
| `clients` | Customers; optional `wp_user_id` portal link |
| `product_categories` | Product categories |
| `products` | Catalog (SKU, prices, image, color/size defaults) |
| `orders` | Order header (client, dates, status, accept meta, creators) |
| `order_items` | Lines (qty, accepted qty, weight, unit price, priority, notes) |
| `export_shipments` | China → BD export documents |
| `export_shipment_items` | Export line quantities / weights |
| `export_shipment_amendments` | Pending/approved/declined qty change requests on exports |
| `export_shipment_amendment_items` | Per-product old/new qty on a change request |
| `warehouse_receives` | BD warehouse receive documents (linked to China `shipment_id` when from export) |
| `receive_items` | Receive lines — received qty, optional `missing_quantity`, optional `export_shipment_item_id` |
| `stock` | On-hand stock by product name / color / size |
| `deliveries` | Client delivery documents |
| `delivery_items` | Delivery line quantities / weights / shipping |
| `payments` | Client payments (purpose: order_bill / delivery_bill / auto; optional order reference) |
| `client_bills` | Order bills and shipping/delivery bills |
| `company_bills` | Manual supplier bills |
| `company_payments` | Payments to suppliers |
| `order_statuses` | Configurable status definitions |
| `activity_log` | Audit trail |
| `settings` | Key/value settings store (also uses WP options for main settings) |

Schema upgrades are versioned in `CRM_Activator::maybe_upgrade()` so production sites can migrate safely.

---

## 5. Modules (detailed)

### 5.1 Dashboard

**Who uses it:** Staff roles with dashboard access (default landing for most staff).

**Features:**
- KPI cards filtered by user capabilities (orders, warehouse, finance)
- Period filters: today, yesterday, last 7 / 15 / 30 days, custom dates
- Chart.js series for payments / insights where permitted
- Compact operational overview for managers and accountants

---

### 5.2 Orders

**Who uses it:** Staff, China office, clients (scoped).

**List features:**
- Search (order # / client), status filter, tracking filter, client filter, date range
- Pagination and column sorting
- Product preview thumbnails, urgent priority indicators
- **Order Bill** column uses China-approved amount: `COALESCE(accepted_quantity, quantity) × unit_price`
- Actions: View, Print, Edit (when permitted)

**Create / edit:**
- Client selection (portal clients are forced to their linked client)
- Line items with product picker, color, size, quantity, weight, unit price, delivery priority, notes
- Optional unit prices at create time (China confirms later)
- Clients may edit/cancel **orders they created** only while status still blocks workflow (awaiting acceptance)

**Acceptance workflow:**
- Requires `crm_orders_accept`
- Per line: accepted quantity (≤ ordered) and unit price (> 0 for accepted lines)
- Writes acceptance metadata and moves order out of blocked status
- Creates / updates the order bill from accepted totals

**Single order view:**
- Audience-aware tracking timeline (client vs staff wording)
- Line table with accepted / exported (“on the way”) / delivered / weight context
- China export history section
- Deliveries list
- Payments section (clients: read-friendly; staff: record payment when allowed)
- Billing summary (order bill, delivery bill, paid, due)
- Activity history (order + related payment / delivery / shipment events)
- Print

---

### 5.3 China Export (Shipments)

**Who uses it:** China office (primary), China office supervisor (review), managers/admins with shipment caps.

**List views:**
- **Orders** — ready-to-ship / needs pricing / blocked workflow (hidden for supervisor-only users)
- **Export history** — recorded China shipments
- **Qty change requests / Review board** — product-level change requests (default tab for China Office Supervisor)

**Order workspace** (`shipment_action=new&order_id=…`):
- Step 1: approve accepted qty + unit prices
- Step 2: confirm supply (ship now qty, cargo company, ship date, notes)
- **Supply history** per batch: company, products, supplied qty, weight
- **Change** on a product row (officer): request a qty and/or weight update for that product only; reason required. Qty may be reduced to **0** to remove the product from that shipment. If it is the last remaining product, approval voids the shipment so the qty can be exported again.
- Mobile: Change opens a stacked full-width amend panel (qty, weight, reason, Submit/Cancel) instead of cramped table cells
- After supervisor approval, freed qty can be supplied again in a later batch (another shipper)

**Record export (standalone new shipment):**
- Search/select order, ship date, cargo company, notes, per-line ship qty/weight
- Export company dropdown is filled from **active cargo companies** on page load (AJAX refresh as fallback)

**Other features:**
- Partial exports tracked per order line (`qty_exported` vs accepted)
- Reassign company on in-transit exports (where permitted)
- Multiple pending change requests allowed on one shipment, but **not** two pending requests on the same product line
- **Supervisor / admin review**: Review board lists all pending product changes together (grouped by shipment), with **Accept / Decline per product** — supervisors cannot edit quantities or record shipments
- Void export shipments (blocked while a change request is pending, or after warehouse receives exist)
- Status after BD receive: `partially_received` / `received` (synced from Warehouse)
- Dual-timezone timestamps on supply/tracking events
- Module label configurable (default “China Export”)

---

### 5.4 Warehouse / Receive

**Who uses it:** Warehouse staff and managers.

**Features:**
- Two tabs:
  - **Awaiting arrival** — China export shipments (`in_transit` / `partially_received`) with remaining qty to receive; filter by client and company
  - **Receive history** — saved receive documents with client, company, linked shipment, kg, shipping bill
- Primary flow: open a China shipment → receive against its product lines
  - Per product: shipped / already received / already missing / remaining
  - Enter **Receive now** (into stock) and/or **Missing now** (short / lost — closes that qty without stock)
  - Partial receives allowed; leftover remaining stays on Awaiting until fully accounted
  - Weight + shipping rate required only for qty received into stock
- Shipment status syncs: `in_transit` → `partially_received` → `received` when remaining is zero
- Manual receive (no China shipment) still available for exceptions
- Filter receive history by client, company, dates
- Void receive reverses stock and reopens shipment remaining qty when linked

**Business note:** Warehouse receive is driven by China Export shipments. Orders may still exist before stock arrives; stock increases only when BD records a receive.

---

### 5.5 Delivery

**Who uses it:** BD operations staff; clients can view scoped deliveries.

**Features:**
- Create delivery against an order’s remaining accepted quantities
- Receiver info, line qty/weight, shipping rate/kg, shipping share
- Creates delivery shipping bills for the client
- Decrements stock
- Syncs order status to `partial_delivered` or `completed` based on delivered vs accepted qty
- List with filters and period KPIs
- Void delivery (reverses effects when allowed)

---

### 5.6 Payments

**Who uses it:** Accountants / managers; clients see “My payments”.

**Features:**
- Staff page has two tabs:
  - **From clients** — money received from customers
  - **To suppliers** — money paid to cargo companies / suppliers
- Client payments: client, **payment purpose** (Order bill / Delivery bill), amount, date, method, reference, optional order link, notes; client/order balance preview
- New client payments **must** choose purpose — money reduces only that due type (product or shipping)
- Purpose is stored on each payment and shown in lists, client ledger, and reports
- Within a purpose pool, allocation is oldest-order-first across the client’s open orders
- Legacy payments without purpose stay `auto` (waterfall: remaining order bill, then delivery)
- Optional order reference is for notes only (does not pin allocation to that order)
- Supplier payments: company, amount, date, method, reference, notes; company bill/paid/due preview
- Supplier payments update the company ledger (receive shipping + manual bills − payments)
- List/search/date filters on both tabs; clients are scoped to their own (incoming) payments
- Clients cannot create/edit/delete payments and do not see the supplier tab
- Staff KPI cards:
  - **From clients:** outstanding due, total billed, total collected, today, this month, in-this-list count/amount, plus clients paid (or order/delivery due when one client is filtered). Today / this month cards filter the list.
  - **To suppliers:** outstanding payable, total billed, total paid out, today, this month, in-this-list, plus companies paid (or receive shipping / manual bills when one company is filtered)
- Deep links: `payments_tab=suppliers` and optional `company_id` to open the supplier form; order page can deep-link a client payment
- Manual bills stay on the company ledger page (not on Payments)

---

### 5.7 Clients

**Features:**
- CRUD: name, phone, email, address, notes, status
- Link / unlink WordPress portal user (`crm_client` role users)
- List shows bill / paid / due summary columns
- **Client ledger** modal: totals, order/delivery breakdown, order-wise allocated share, payment history with purpose
- “Record payment” action shown only when user can create payments
- Auto-provision client row when CRM Client users are synced

---

### 5.8 Companies / suppliers

**Features:**
- CRUD for cargo companies and local suppliers
- List financial columns (bill / paid / due)
- **Supplier ledger** is a **full page** (`company_action=ledger`) — not a popup — with:
  - Company totals and bill breakdown (receive shipping + manual bills)
  - Paginated, searchable, date-filtered tables: payments, warehouse receives, manual bills
- **Record payment to supplier** lives on Payments → To suppliers (ledger links there)
- **Record manual bill** remains on the ledger page when user has billing manage permission
- View-only roles (e.g. China office with companies view only) can open ledger read-only

---

### 5.9 Products

**Features:**
- Catalog CRUD with SKU (unique), category, description
- Pricing modes from settings:
  - **Single:** one catalog price
  - **Dual:** sell price + purchase rate
- Default color / size
- Image upload with square crop → full image + thumbnail
- Stock quantity display (maintained by warehouse/delivery flows)
- Search pickers used by orders and warehouse forms

---

### 5.10 Product categories

**Features:**
- Category CRUD (name, description, status)
- System “Uncategorized” ensured by upgrades
- Shares product module capabilities

---

### 5.11 Reports

**Requires:** `crm_view_reports`

**Report types:**
1. **Full client report** — account summary, order/delivery bill–paid–due, payments with purpose, plus chronological ledger; primary report for staff and client portal (**My report**)
2. **Client ledger** — receivables over a date range (opening balance when from-date set); payments labeled by purpose
3. **Client billing statement** — order-wise billing only (subset of the full report)
4. **Supplier ledger** — payables for a company over a date range (staff only)
5. **Stock report** — inventory snapshot (staff only)

**Outputs:**
- On-screen report view
- **Download PDF** (browser Print → Save as PDF; branded header)
- CSV export

**Client portal:** Clients see only their own full account report (`crm_view_reports` + reports module). Supplier/stock endpoints are blocked server-side.

---

### 5.12 Team & access

**Requires:** `crm_manage_settings`

**Features:**
- Lists users who have a CRM role (shows all CRM roles when a user has more than one)
- Opens permission matrix per user (defaults merge capabilities from every CRM role on the account)
- Shows role defaults vs custom overrides vs off-by-role
- Saves only differences from role defaults into user meta
- Reset to role defaults
- Supports multi-role users by merging role capabilities for effective defaults
- Assign multiple CRM roles in **WordPress → Users → Edit user** via primary Role + **Additional CRM roles** (e.g. China Office + China Office Supervisor)

---

### 5.13 Activity log

**Requires:** settings/manage capability for the module

**Features:**
- Paginated activity feed across modules
- Filters (module, action, search)
- Stores action, module, record id, description, meta JSON, user, timestamp
- Order view timeline can include related payment / delivery / shipment events

---

### 5.14 Order statuses

**Requires:** settings capability

**Features:**
- Configurable status list with label, color, sort order
- Flags:
  - System status
  - Closed
  - **Blocks workflow** (e.g. awaiting acceptance)
  - **Auto on paid** (e.g. completed when fully paid)
- Seeded defaults include awaiting_acceptance, pending, partial_delivered, completed, cancelled

---

### 5.15 Settings (WordPress admin)

Accessible to users with CRM settings capability (and WP administrators).

**Areas include:**
- Company branding (name, tagline, logo, favicon)
- Theme colors (sidebar / accent)
- Frontend CRM page selection and shortcode helper
- Currency symbol (default ৳)
- Low stock threshold
- Pricing mode (single / dual)
- Client portal order scope (`own` / `all`)
- Shipments module label
- China timezone / Bangladesh timezone / dual timezone display toggle
- Portal user sync helpers
- Admin data reset / maintenance tools

---

## 6. Portals & audiences

### 6.1 Client portal

| Item | Behavior |
|------|----------|
| Role | `crm_client` |
| Default modules | Orders, Delivery, Payments, My report |
| Data scope | Linked `clients` row via `wp_user_id` / user meta |
| Order visibility | Setting `client_orders_scope`: `own` (default) or `all` |
| Create orders | Yes (for linked client) |
| Edit / cancel | Only own created orders while awaiting acceptance |
| Payments | View history; cannot record/edit/delete |
| Reports | Own full account report only; Download PDF; no supplier/stock |
| Extra modules | Only if Team permissions explicitly grant the view cap |

Client-facing copy is simplified (e.g. “on the way”, “My payments”, “My report”).

### 6.2 China office portal

| Item | Behavior |
|------|----------|
| Roles | `crm_china_office` (officer), `crm_china_supervisor` (supervisor) |
| Default modules | Shipments (China Export), Orders, Companies |
| Landing module | Shipments |
| Officer powers | View/edit/accept orders, create exports, request per-product qty/weight changes on submitted exports, view companies |
| Supervisor powers | Lands on **Review board**; view exports/orders/companies; **accept or decline** each product change (cannot create exports, cannot change quantities, cannot accept orders) |
| Combined roles | A user may hold both officer + supervisor (WP primary Role + Additional CRM roles); capabilities merge |
| Time display | China timezone primary; Bangladesh secondary when dual TZ enabled |
| Extra modules | Only via explicit Team custom grants |

### 6.3 Staff portals

Managers, warehouse, accountants, viewers, and admins use capability-based menus without the hard portal whitelist (except admin-only modules like Team / Activity / Order Statuses for non-settings users).

---

## 7. Roles & permissions

### 7.1 CRM roles

| Role slug | Label | Default focus |
|-----------|-------|---------------|
| `crm_admin` | CRM Admin | All capabilities including settings/team |
| `crm_manager` | CRM Manager | All operational caps except settings/users |
| `crm_warehouse` | CRM Warehouse | Dashboard + stock view/receive |
| `crm_accountant` | CRM Accountant | Payments, billing, reports |
| `crm_viewer` | CRM Viewer | Dashboard + reports |
| `crm_client` | CRM Client | Portal orders/delivery/payments view+create orders |
| `crm_china_office` | CRM China Office | Orders accept/edit + shipments create/amend + companies view |
| `crm_china_supervisor` | CRM China Office Supervisor | Shipments view/review + orders/companies view (no qty edits) |

WordPress **Administrator** receives all CRM capabilities on role sync.

A user may hold **more than one CRM role**. WordPress’s Role dropdown is still the primary role; extra CRM roles are assigned under **Additional CRM roles** on **Users → Add/Edit user**. Effective capabilities are the union of every CRM role on the account (Team permission overrides still apply on top).

### 7.2 Capability model

Permissions are organized by module actions, for example:

- Orders: view, create, edit, status, accept, cancel  
- Shipments: view, create, amend (request qty changes), review (accept/decline), void  
- Warehouse: stock view, receive, void  
- Delivery: view, create, edit, void  
- Payments: view, create, edit, delete  
- Billing: view, edit (supplier ledger writes)  
- Companies / clients / products: view, create, edit, delete  
- General: dashboard, settings, reports  

Legacy “bundle” caps (e.g. `crm_manage_orders`) still expand to granular actions for compatibility.

### 7.3 Effective permission resolution

```text
Role defaults
    + per-user overrides (Team UI)
    → effective caps via user_has_cap filter
    → current_user_can() checks in PHP
    → UI flags (can_create / can_edit / …) hide unauthorized chrome
```

### 7.4 Ownership rules (orders)

Even without “edit any orders”:

- Users with **create** can edit/cancel **their own** order
- Only while status still **blocks workflow** (awaiting acceptance)
- After acceptance, clients lose edit rights

### 7.5 Important security behaviors

- AJAX endpoints verify nonce + capability
- View caps do **not** grant write on supplier payments/bills (requires billing edit)
- Warehouse void requires explicit void cap
- Client/China extra modules require custom override, not only a checked baseline elsewhere
- Staff CRM roles are kept out of general wp-admin (except settings screens when allowed)

---

## 8. Financial model

### 8.1 Client side

| Bill type | Source |
|-----------|--------|
| Order bill | Accepted quantity × unit price (China-approved) |
| Delivery / shipping bill | Delivery documents (weight × rate allocation) |
| Payments | Recorded against client with purpose (order bill or delivery bill); optional order reference |

**Allocation rule (purpose + FIFO):**  
- **Order bill** payments reduce product dues only (oldest open orders first)  
- **Delivery bill** payments reduce shipping dues only (oldest open orders first)  
- Legacy **auto** payments use waterfall: remaining order bill, then delivery, per order (oldest first)  

Ledgers expose:

- Total bill, total paid (applied), total due  
- Order bill / order paid / order due  
- Delivery bill / delivery paid / delivery due  
- Per-order payment status: unpaid / partial / paid
### 8.2 Supplier side

| Item | Source |
|------|--------|
| Receive shipping bills | Warehouse receives (rate × kg) |
| Manual bills | Entered on the company ledger page |
| Supplier payments | Entered under Payments → To suppliers |

Company ledger summarizes bill, paid, due, receive counts, and breakdowns.

---

## 9. Order tracking

Tracking is computed from order state + exports + receives + deliveries.

Typical steps:

1. Review / awaiting acceptance  
2. Supplying / prices confirmed  
3. To Bangladesh (exported / in transit)  
4. Received in BD  
5. Delivered  

**Audience differences:**
- Clients see friendlier labels (“submitted to BD”, “on the way”)
- Staff/China see operational labels (“exported”, “needs pricing”)

List tables show short tracking badges; order view shows full timeline with dual-timezone timestamps when enabled.

---

## 10. Timezones

| Setting | Default |
|---------|---------|
| Bangladesh timezone | `Asia/Dhaka` |
| China timezone | `Asia/Shanghai` |
| Dual timezone display | On by default |

**Display rules:**
- Admin, staff, clients → Bangladesh time primary  
- China office → China time primary  
- When dual display is enabled, the other zone is shown as secondary on tracking/supply times  

Stored datetimes are interpreted in the WordPress site timezone and converted for display.

---

## 11. Stock & inventory

- Stock is keyed by **product name + color + size**
- Warehouse receive **increases** stock  
- Delivery **decreases** stock  
- Void flows reverse stock when business rules allow  
- Product list shows current stock quantity  
- Low-stock threshold is configurable in settings  

Orders intentionally do **not** require available stock at order time.

---

## 12. UI / UX features

- Branded shell (logo, colors, company name)
- Responsive sidebar / mobile nav
- Module summary KPI cards on list pages
- Icon action buttons (view, edit, delete, print, ledger, export…)
- Selectable order numbers with separate open icon (easy copy)
- Product thumbnail previews; thumbnails can link to full image without eager-loading full files
- Urgent delivery priority beacons
- Modal forms for many CRUD entities
- Full-page forms for orders, shipment workspace, receive, delivery
- Print styles for orders and reports
- Toast notifications and loading states
- 12-hour AM/PM time formatting in UI

---

## 13. Printing & exports

| Feature | Behavior |
|---------|----------|
| Order print | Printable order document; filename includes date+time+seconds; Priority is an inline product label (not a table column); thumbnails use absolute URLs and wait for load (Safari-friendly) |
| Report print | Browser print / Save as PDF |
| Report CSV | Download spreadsheet-friendly export |
| Print CSS | Dedicated `crm-print.css` |

---

## 14. Audit & accountability

Almost all create/update/delete/void operations can write:

- Actor (user id)
- Module
- Action
- Record id
- Human description
- Optional structured meta (often includes `order_id` for related timelines)

Used by:

- Activity log module  
- Order activity history  
- Operational troubleshooting  

---

## 15. Configuration reference (key settings)

| Setting key / concept | Effect |
|-----------------------|--------|
| Frontend page | Which WP page hosts the CRM shortcode |
| Currency symbol | Amount formatting (default ৳) |
| Pricing mode | Single catalog price vs sell + purchase |
| Low stock threshold | Inventory warnings |
| Client orders scope | Portal sees own linked client vs all clients |
| Shipments module label | Sidebar/title text for China Export |
| China / BD timezones | Display conversion |
| Dual timezone toggle | Show second zone on tracking times |
| Branding fields | Logo, favicon, colors, company name/tagline |

---

## 16. Installation & operations (high level)

1. Install/activate the plugin on WordPress  
2. Ensure CRM roles are registered (automatic on `init`)  
3. Create/assign WordPress users to CRM roles (primary **Role** + optional **Additional CRM roles** on the user profile, e.g. China Office + China Office Supervisor)  
4. Set frontend page + shortcode in CRM Settings  
5. Configure branding, currency, timezones, portal scope  
6. Link client portal users to client records  
7. Train China office on accept → export → (optional) per-product Change → supervisor Review board; BD staff on receive → delivery → payments  

**Upgrades:** Plugin runs `CRM_Activator::maybe_upgrade()` to apply schema/data migrations.

**Uninstall:** Plugin includes uninstall handling for cleanup when removed (see `uninstall.php`).

---

## 17. Production-oriented safeguards

Present in the system:

- Capability checks on every business AJAX action  
- Nonce verification  
- Client/China data and module scoping  
- Workflow-blocking statuses until acceptance  
- Cancel blocked when deliveries exist  
- Void paths for exports, receives, deliveries  
- Billing write separated from company view  
- Per-user permission overrides without changing global roles  
- Audit log for accountability  
- Versioned DB upgrades for live sites  

---

## 18. File map (major)

### Controllers
- `modules/*/class-*-controller.php` — AJAX + business logic per module  

### Views
- `modules/*/views/*.php` — list/form/view markup and permission flags  

### Front-end scripts
- `assets/js/crm-main.js` — shared AJAX, formatting, UI helpers  
- `assets/js/crm-*.js` — one script per major screen  

### Templates
- `templates/crm-page-wrapper.php` — app shell / routing  
- `templates/crm-login-gate.php` — login  
- `templates/crm-admin-settings.php` — settings UI  

### Docs
- `docs/PROJECT-OVERVIEW-AND-PRICING.md` — commercial overview (separate)  
- `docs/PROJECT-DETAILS.md` — this file  

---

## 19. Glossary

| Term | Meaning |
|------|---------|
| Accepted quantity | Qty China/staff approved for supply (may be less than ordered) |
| Order bill | Accepted qty × unit price |
| China Export | Shipment recorded from China toward BD warehouse |
| Export qty change request | Per-product qty/weight change on a submitted export (officer **Change**); supervisor/admin Accept or Decline on Review board |
| Review board | China supervisor list of pending product changes, grouped by shipment, with separate Accept/Decline per product |
| Additional CRM roles | Extra WordPress CRM roles on a user besides the primary Role; capabilities are merged |
| Receive | BD warehouse inbound document from a cargo/supplier |
| Delivery | Outbound delivery to the customer |
| Blocks workflow | Status that prevents export/delivery/billing progress until cleared |
| Dual timezone | Show both BD and China local times for the same event |
| Team overrides | Per-user capability exceptions on top of role defaults |
| FIFO allocation | Purpose-tagged payments applied to oldest matching dues first |

---

## 20. Summary

This project is a **domain-specific import operations CRM** covering:

- Multi-party workflows (client, China office / supervisor, BD warehouse/ops, finance)
- Partial logistics (export and delivery), including post-submit per-product export qty corrections
- Dual financial ledgers (clients and suppliers)
- Strong role/permission and portal isolation
- Tracking, branding, reporting, and audit for production use

It is delivered as a maintainable WordPress plugin with a frontend application shell, custom schema, and modular controllers.

---

*Document generated from the `ds-prod-import-crm` codebase (plugin 0.18.0, assets 0.30.1, DB 0.9.9). Update this file when major modules or workflows change.*
