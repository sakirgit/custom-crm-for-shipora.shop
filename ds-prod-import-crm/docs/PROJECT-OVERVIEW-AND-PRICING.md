# China Import CRM — Project Overview & Pricing Guide

**Product:** Product stock and order management CRM (`ds-prod-import-crm`)  
**Developer:** Developer-S.com Team  
**Document purpose:** Full feature inventory + recommended client pricing for the completed custom CRM  
**Audience:** Project owner (you) when presenting scope and commercial terms to the client  

---

## 1. What this application is

This is a **custom, production-ready China-import CRM** built as a WordPress plugin. It is **not** a simple contact CRM or a generic WooCommerce add-on.

It manages the full import business loop:

> **Client order → China approval & pricing → China export → Bangladesh warehouse receive → Client delivery → Billing & payments → Reports**

Day-to-day work runs on a **branded public frontend CRM** (shortcode page), not inside classic WordPress admin. Staff, China office, and clients each get a role-scoped experience with their own permissions, menus, and data visibility.

### Why it is valuable to the client

| Business problem | What the CRM solves |
|------------------|---------------------|
| Orders placed before stock arrives | Orders do not require stock on hand |
| China must confirm qty & price | Acceptance + pricing workflow before supply |
| Partial exports / partial deliveries | Line-level remaining quantities |
| BD vs China clocks | Dual timezone display (Dhaka / Shanghai) |
| Money confusion | Client ledger + supplier ledger + FIFO payment allocation |
| Who can do what | 7 CRM roles + per-user Team permission overrides |
| Accountability | Full activity / audit log |

---

## 2. Technical snapshot (for credibility)

| Item | Detail |
|------|--------|
| Platform | Custom WordPress plugin |
| Frontend | SPA-style CRM shell (`[ds_prod_import_crm]`) |
| Backend | PHP, WordPress roles/caps, `admin-ajax.php` |
| UI | Vanilla JavaScript modules + custom CSS |
| Charts | Chart.js 4.4.1 |
| Custom DB tables | **20** (`wp_crm_*`) |
| AJAX business endpoints | **~83** |
| PHP files | **~68** (~22k+ lines) |
| JS files | **24** (~12k lines) |
| CSS | **~6,500** lines |
| Controllers / modules | **15** module controllers + shared engines |
| Roles | **7** CRM roles (+ WP Administrator) |
| Asset / schema maturity | Assets `0.28.x`, DB schema upgrades through `0.9.x` |

This is mid-to-advanced custom software: multi-role portals, financial ledger, inventory, logistics workflows, and audit — not a brochure site or a “CRUD plugin.”

---

## 3. Modules & features (complete inventory)

### 3.1 Dashboard
- Role-aware KPI cards (orders, warehouse, finance)
- Period filters: today, yesterday, 7 / 15 / 30 days, custom range
- Chart.js payment / activity visualization
- Default landing for staff (not client / China office)

### 3.2 Orders (core)
- Create / edit / view / list with search, filters, pagination, sorting
- Line items: product, color, size, qty, weight, unit price, delivery priority (normal / 2nd / urgent)
- **Awaiting acceptance** gate before export / delivery / billing flow
- China/staff **accept** with accepted quantity + unit prices
- Order bill based on **China-approved qty × price**
- Rich single-order page: tracking, China export history, deliveries, payments, billing, activity
- Print order
- Cancel with business guards (e.g. blocked when deliveries exist)
- Client can create orders and edit **own** orders only while awaiting acceptance

### 3.3 China Export (Shipments)
- Record export shipments from China to BD warehouse
- Cargo/supplier company, ship date, line qty/weight
- Partial exports per order line
- Ready-to-ship / history tabs and workflows
- Void export with permission
- Primary workspace for **CRM China Office**

### 3.4 Warehouse / Receive
- Receive stock from cargo companies by weight
- Per-line shipping rate (BDT/kg) → supplier shipping bill
- Stock increments by product name / color / size
- Void receive (permission + stock business rules)
- Primary workspace for **CRM Warehouse**

### 3.5 Delivery
- Partial / full delivery against accepted quantities
- Receiver details, weights, shipping rates, shipping bill
- Auto status sync: pending → partial_delivered → completed
- Void delivery with stock/billing reversal
- Client portal can view own deliveries

### 3.6 Payments (customer)
- Record client payments (pooled at client level; optional order link)
- Balance preview before save
- FIFO allocation: oldest orders first; product bill then delivery bill
- Client “My payments” read-only portal view

### 3.7 Clients
- Customer CRUD + active/inactive
- Link WordPress portal user
- **Client ledger** (orders, bills, paid, due)
- Auto-create client row when CRM Client role is assigned

### 3.8 Companies / suppliers
- Cargo / local supplier CRUD
- **Supplier ledger**: receive shipping, manual bills, supplier payments
- View-only users see ledger; billing write requires billing permission

### 3.9 Products & categories
- Catalog with SKU, prices (single or dual sell/purchase mode), stock qty
- Image upload with **canvas crop** (full + thumbnail)
- Category management (system Uncategorized)

### 3.10 Reports
- Client ledger report
- Supplier ledger report
- Stock report
- Date filters, opening balance, Print (PDF via browser), CSV export

### 3.11 Team & access
- List users with CRM roles
- Per-user permission matrix (overrides on top of role defaults)
- Reset to role defaults
- Critical for production multi-office security

### 3.12 Activity log
- Audit of create / update / delete / void across modules
- Filters, pagination, order-related timeline enrichment

### 3.13 Order statuses
- Configurable statuses: color, closed, blocks workflow, auto-on-paid, sort
- Seeded system statuses: awaiting_acceptance, pending, partial_delivered, completed, cancelled

### 3.14 Settings (wp-admin)
- Branding: company name, tagline, logo, favicon, theme colors
- Frontend CRM page / shortcode
- Currency symbol, low-stock threshold
- Client portal order scope (own / all)
- China & Bangladesh timezones + dual timezone display
- Portal user sync tools
- Admin data-reset tools

### 3.15 Client portal (role experience)
- Default modules: Orders, Delivery, My payments
- Linked to one client record; data scoped
- Extra modules only if Team grants custom caps
- Audience-friendly labels (e.g. “on the way” instead of staff jargon)

### 3.16 China office portal (role experience)
- Default modules: China Export, Orders, Companies
- Lands on Shipments
- China time as primary display; BD secondary when dual TZ on
- Accept / price / export workflows

---

## 4. End-to-end business workflow

```text
1. Client (or staff) creates order
        ↓
2. Status: Awaiting acceptance (workflow blocked)
        ↓
3. China office / staff accepts lines
   - accepted quantity (≤ ordered)
   - unit price confirmed
        ↓
4. Status becomes active (e.g. Pending)
        ↓
5. China records export shipment(s) — partial OK
        ↓
6. BD warehouse receives cargo — stock up, supplier shipping bill
        ↓
7. BD delivers to client — partial OK, delivery shipping bill
        ↓
8. Client pays — payments allocated FIFO across orders
        ↓
9. Status may auto-complete when paid (configurable)
```

**Guards built in:** blocked statuses, cancel rules, void flows, ownership rules, capability checks on every AJAX action.

---

## 5. Roles & permissions (summary)

| Role | Purpose |
|------|---------|
| **CRM Admin** | Full access including Team & Settings |
| **CRM Manager** | Full operations without settings/team admin |
| **CRM Warehouse** | Stock receive / warehouse focus |
| **CRM Accountant** | Payments, billing, reports |
| **CRM Viewer** | Dashboard + reports (read-oriented) |
| **CRM Client** | Portal: own orders, deliveries, payments |
| **CRM China Office** | Orders accept/price + China export + companies view |

Plus:
- Granular caps (view / create / edit / delete / void / accept / status…)
- Per-user **custom overrides** in Team & access
- Scoped portals so clients/China cannot wander into admin modules unless explicitly granted

---

## 6. Notable production features

1. **Financial ledger engine** — client FIFO allocation; company payables  
2. **Order tracking timeline** — review → supplying → to Bangladesh → received → delivered  
3. **Dual timezone** — Asia/Dhaka default; Asia/Shanghai for China office  
4. **Audit trail** — who did what, when  
5. **Void / reverse** — deliveries, warehouse receives, export shipments  
6. **Branded CRM shell** — logo, colors, login gate, mobile nav  
7. **Print + CSV** — operational and accounting use  
8. **Image cropper** — product catalog quality  
9. **Schema upgrades** — versioned DB migrations for safe production updates  
10. **Ownership rules** — clients edit only their own awaiting orders  

---

## 7. Effort estimate (development reality)

Rough comparable effort if rebuilt from scratch by a competent team:

| Workstream | Effort band |
|------------|-------------|
| Discovery, UX, data model | 40–60 hours |
| Core modules (orders, products, clients, companies) | 120–160 hours |
| China export + accept/pricing workflow | 80–120 hours |
| Warehouse + stock + voids | 60–80 hours |
| Delivery + shipping bills | 50–70 hours |
| Payments + dual ledger + reports | 80–110 hours |
| Roles, portals, Team permissions | 60–90 hours |
| Tracking, dual TZ, print, branding, settings | 50–70 hours |
| Hardening, QA, production fixes | 60–100 hours |
| **Total** | **~600–860 hours** |

At realistic professional rates this is a **multi-month** specialty CRM, not a weekend plugin.

---

## 8. How much you can charge the client

### 8.1 Market context (2025–2026)

| Market | Typical custom CRM / similar app |
|--------|----------------------------------|
| US / Western EU agency | USD **$40,000 – $150,000+** |
| South Asia / India mid CRM | USD **$12,000 – $45,000** |
| Bangladesh mid web app / ERP-lite | BDT **৳4,00,000 – ৳15,00,000+** (varies widely) |

Your product sits in the **vertical / logistics CRM** band (China–BD import), which prices **above** a simple lead CRM and **below** a full ERP.

### 8.2 Recommended commercial range for *this* project

Use these bands depending on how you sell and where the client is:

#### Option A — Bangladesh / regional client (most common)

| Package | Amount (BDT) | Amount (USD approx.) | When to use |
|---------|--------------|----------------------|-------------|
| **Floor (don’t go below)** | ৳ **4,50,000 – 5,50,000** | ~$3,700 – $4,500 | Existing relationship, long retainer ahead |
| **Recommended ask** | ৳ **7,00,000 – 9,50,000** | ~$5,700 – $7,800 | Fair value for delivered production CRM |
| **Strong ask** | ৳ **10,00,000 – 12,50,000** | ~$8,200 – $10,200 | Client has money, multi-office ops, ongoing support included lightly |

**Best default quote to start negotiation:** **৳ 8,00,000 – ৳ 9,00,000** (≈ **USD $6,500 – $7,400**).

#### Option B — International / diaspora client (USD invoice)

| Package | Amount (USD) | Notes |
|---------|--------------|-------|
| **Floor** | **$6,000 – $8,000** | Only if support is paid separately |
| **Recommended** | **$10,000 – $15,000** | Matches South-Asia “advanced vertical CRM” |
| **Premium positioning** | **$18,000 – $25,000** | If you include training, 3–6 months support, and ownership docs |

### 8.3 How to present the price (module value breakdown)

Use a **value breakdown**, not hourly confession. Example for a **৳ 8,50,000** quote:

| Deliverable | Value share | Example allocation |
|-------------|-------------|--------------------|
| Order + China acceptance + tracking | 22% | ৳ 1,87,000 |
| China export + BD warehouse + stock | 20% | ৳ 1,70,000 |
| Delivery + client/supplier billing + payments | 18% | ৳ 1,53,000 |
| Client portal + China portal + Team permissions | 15% | ৳ 1,27,500 |
| Products, companies, clients, branding shell | 10% | ৳ 85,000 |
| Reports, print/CSV, activity log, settings | 10% | ৳ 85,000 |
| QA, production hardening, dual timezone, voids | 5% | ৳ 42,500 |
| **Total** | **100%** | **৳ 8,50,000** |

### 8.4 What to charge *extra* (do not include free)

| Extra | Suggested add-on |
|-------|------------------|
| Monthly support / bugfix / small changes | ৳ **15,000 – 40,000**/month or **15–20%** of project/year |
| Training (staff + China office + client) | ৳ **25,000 – 60,000** |
| New modules (SMS, accounting sync, mobile app) | Separate SOW |
| Data migration from old Excel/sheets | ৳ **30,000 – 1,00,000** depending on mess |
| Custom reports beyond the 3 built-in | per report |

### 8.5 Negotiation tips

1. **Anchor high** (৳ 10–11L or $15k), then settle in your recommended band.  
2. Emphasize **replacement cost**: rebuilding this from scratch is 600–800+ hours.  
3. Emphasize **business specificity**: China accept → export → BD receive → delivery → dual ledger is rare off-the-shelf.  
4. Emphasize **production maturity**: roles, voids, audit, portals, timezone — not an MVP demo.  
5. If client pushes hard on price, **remove scope** (e.g. reports later, Team UI later) — don’t silently undercharge the full system.  
6. Keep **source code ownership** clear in the contract (transfer on full payment is standard).

### 8.6 Honest positioning (for you)

| If you charge… | Interpretation |
|----------------|----------------|
| Below ৳ 4,50,000 / $4,000 | You are undercharging a production vertical CRM |
| ৳ 7–9.5L / $6.5–8k | Fair BD-market professional price |
| ৳ 10–12.5L / $10–15k | Strong but defendable with support + docs |
| $18k+ | Only if international rate positioning + extras |

**Practical recommendation:** Quote **৳ 8,50,000** (or **USD $12,000** for foreign clients), with optional **3 months support** at ৳ 25,000/month or $200/month.

---

## 9. One-page pitch you can send the client

> We delivered a **custom China–Bangladesh import CRM** on WordPress that covers your full operation: client orders, China office approval & pricing, China export shipments, Bangladesh warehouse receiving, client delivery, supplier & client billing, payments, reports, branded portals, role-based security, audit log, and dual Bangladesh/China timezone display.  
>  
> It includes **20 custom database tables**, **7 operational roles**, client portal, China office portal, Team permission overrides, void/reverse flows, and print/CSV reporting — production software, not a template.  
>  
> **Investment for the completed system: ৳ 8,50,000** (or USD $12,000), plus optional monthly support.

---

## 10. Suggested next commercial steps

1. Finalize this quote number for your market (BD vs international).  
2. Attach a short SOW: modules included, excluded, support terms, payment milestones (e.g. 40 / 40 / 20).  
3. Offer a **support retainer** so ongoing requests are paid, not endless free changes.  
4. Keep this document as your **scope of record** when the client asks “what did we get?”

---

*Generated from the actual `ds-prod-import-crm` codebase for commercial planning. Adjust currency and final number to your client relationship and support commitment.*
