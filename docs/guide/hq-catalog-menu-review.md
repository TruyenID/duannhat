---
title: HQ Catalog and Menu workflow
category: guide
tags: [hq, catalog, product, sku, topping-group, menu]
summary: "End-to-end HQ workflow for product types, categories, products and SKUs, topping groups, and menu assembly at both HQ and shop level."
related: [sku-expand-workflow, topping-domain]
---

# HQ Catalog & Menu — workflow guide

> **Scope**: Product Type · Categories · Product & SKU · Topping Groups · Menu (HQ + Shop)
> **Updated**: 2026-05-08 v1.2

---

## Changelog

| Version | Date | Change |
|---------|------|---------|
| v1.2 | 2026-05-08 | Redrew the Product state machine clearly, added the Inactive vs Rejected rules |
| v1.1 | 2026-05-08 | Fixed the Product state machine, added verified permissions, HTTP errors, payload examples, override scope |
| v1.0 | 2026-05-08 | Created |

---

## Quick cheatsheet

| Entity | Approval workflow | Soft delete | Translatable (ja/en/vi) | Notes |
|--------|:-----------------:|:-----------:|:-----------------------:|---------|
| Product Type | — (toggle) | ✅ | ✅ | Decides `product_form` and `has_recipe` |
| Category | — | ✅ | ✅ | A hierarchy tree, M:N with Product |
| Product | ✅ 6 states | ✅ | ✅ | Must be approved before it can join a menu |
| ProductSku | — (toggle) | ✅ | — | Unique by `option_signature` |
| ToppingGroup | — (toggle) | ✅ | ✅ | Assignable to many products |
| Menu | ✅ 6 states | ✅ | — | An active menu cannot be deleted |
| MenuSection | — | — | — | Attached to a menu through a pivot |
| MenuSchedule | — (toggle) | ✅ | — | The time windows in which the menu is live |

---

## Contents

1. [Relationships between the entities](#1-relationships-between-the-entities)
2. [Product Type — workflow](#2-product-type--workflow)
3. [Categories — workflow](#3-categories--workflow)
4. [Product & SKU — workflow](#4-product--sku--workflow)
5. [Topping Groups — workflow](#5-topping-groups--workflow)
6. [Menu at HQ — workflow](#6-menu-at-hq--workflow)
7. [Menu at the Shop — workflow](#7-menu-at-the-shop--workflow)
8. [Approval workflow — permissions and errors](#8-approval-workflow--permissions-and-errors)

---

## 1. Relationships between the entities

```
ProductType
    │
    │ 1:N
    ▼
Product ──────── M:N ──────► Category (a hierarchy tree)
    │
    │ 1:N
    ▼
ProductOption (at most 3)
    │ 1:N
    ▼
ProductOptionValue
    │
    │ (a combination of 3 values → 1 SKU)
    ▼
ProductSku ◄──────────────── ToppingGroupItemSku (extra_price per variant)
    │                                  ▲
    │                                  │
    └──── product_topping_groups ──► ToppingGroup
              (pivot: sort_order,          │
               min/max override)           │ 1:N
                                           ▼
                                    ToppingGroupItem
                                    (a product used as a topping)

Menu ──────────────────────────────────────────────────────────────────────────────────
  │ is_master=true → Master Menu (an HQ template)
  │ is_master=false + master_menu_id → Branch Menu (cloned from a master)
  │
  ├── MenuSection (groups: Starters, Mains, Desserts…)
  ├── MenuProduct (a product selected into the menu)
  │       └── MenuProductSku (a per-menu SKU price)
  └── MenuSchedule (time windows: Mon-Fri 11:00-14:00…)
```

---

## 2. Product Type — workflow

### Purpose

Defines a product's **behaviour**, not its category. For example, "Drinks" has
`product_form=variant` (sizes S/M/L) and is not stock-tracked. "Raw materials" has
`has_recipe=true` and `is_inventory_tracked=true`.

### The important fields

| Field | Meaning |
|--------|---------|
| `product_form` | `simple` = no options, `variant` = has SKU combinations, `combo` = a bundle |
| `has_recipe` | Allows a recipe to be attached → cost price is computed automatically |
| `is_inventory_tracked` | A shop may track stock for this type |

### User workflow

```
1. An admin goes to HQ → Product Types
2. Create: enter the name (ja/en/vi), a code, pick product_form, tick has_recipe / is_inventory_tracked
3. Save → Active automatically
4. To turn it off temporarily: Toggle Status → Inactive (not deleted)
5. To remove it for good: Soft Delete (only while no product uses it)
```

**Note**: Product Type has no approval — an admin's change takes effect
immediately. Do not delete a type that products are using (it fails on the FK
constraint).

---

## 3. Categories — workflow

### Purpose

Classifies products for display to customers (on the menu and the POS). A
multi-level hierarchy. One product may belong to several categories.

### User workflow

```
1. Create a root category (parent_id = null): "Drinks", "Food"
2. Create a subcategory: pick a parent → "Drinks > Coffee", "Drinks > Tea"
3. Assign categories to a Product when creating or editing it (M:N, multi-select)
4. Toggle Active/Inactive to show or hide it on the POS
```

**Hierarchy display**: the frontend uses `category-tree-row.tsx` with
expand/collapse. The API supports filtering by `parent_id` to lazy-load one
branch at a time.

**Note**: `sku` on a Category is an internal code (unique per org) and is **not**
a product SKU. It is used for CSV import/export by reference code.

---

## 4. Product & SKU — workflow

### Purpose

The products actually sold. They must pass through the approval workflow before
they can be put on a menu and sold.

### State machine (Product)

```
  Draft ──[submit]──► Pending ──[approve]──► Approved ──[activate]──► Active
                         │                                                │
                       [reject]                                     [deactivate]
                         │                                                │
                         ▼                                                ▼
                      Rejected                                         Inactive
                         │                                                │
                       [submit]                                      [activate]
                         │                                                │
                         └────────────────────► Pending ◄────────────────┘
                                        (back to the start, needs approval again)
```

**Important rules**:
- `Rejected → Pending`: resubmit — it must go through approval again from the
  start
- `Inactive → Active`: activate directly, **no re-approval needed**
- `Draft` is the initial state on creation — nothing can return to Draft from any
  other state

**Transition rules**:
- `Draft` → `Pending`: any staff member (no special permission)
- `Pending` → `Approved`: requires the **`catalog.approve`** permission (Org
  Manager / Org Owner)
- `Pending` → `Rejected`: requires **`catalog.approve`**, and a reason is
  mandatory
- `Approved` → `Active`: any HQ staff (org ownership)
- `Active` ↔ `Inactive`: any HQ staff (org ownership)
- `Rejected` → `Pending`: resubmission is allowed — but it still needs approval
  again from the start

**HTTP error on an illegal transition**:
```json
HTTP 422
{
  "error": "INVALID_STATUS_TRANSITION",
  "from": "Draft",
  "to": "Active",
  "action": "activate",
  "allowed": ["Approved", "Inactive"],
  "message": "Cannot activate: product status is Draft, expected one of: [Approved, Inactive]."
}
```

### Workflow for a simple product (no variants)

```
1. Create the Product: pick a Product Type, enter the name and description (ja/en/vi)
2. Assign Categories (M:N)
3. Upload the image gallery
4. Enter the selling price for the default SKU
5. Submit for approval → a manager approves → Activate
6. The Product is Active → it can be added to a Menu
```

### Workflow for a product with variants (SKU combinations)

```
1. Create the Product: pick a Product Type with product_form=variant
2. Add Options (at most 3):
   - Option 1: "Size"  → values: [small, medium, large]
   - Option 2: "Milk"  → values: [full_fat, skim, oat]
3. Press "Generate Combinations" → a 3×3 = 9 SKU grid is created automatically
4. Enter the selling price and cost price for each SKU
   - The cost price can be overridden manually (is_cost_override=true)
   - With has_recipe=true: attach a recipe → cost_price is computed automatically
5. Upload a separate image per SKU (optional)
6. Submit → Approve → Activate
```

**How `option_signature` works**:

It is a string (not a hash) built from the three option value IDs, **sorted
alphabetically** and joined with `|`:

```
option_value1_id = "uuid-A"
option_value2_id = "uuid-C"
option_value3_id = null

→ option_signature = "uuid-A|uuid-C"   (sorted, nulls dropped)
```

The unique constraint is `[product_id, option_signature]` — which guarantees no
two SKUs share the same option combination.

### Example payload: create a Product with nested options and SKUs

```json
POST /api/v1/hq/{brandSlug}/products
{
  "name": "Cà phê sữa",
  "translations": {
    "ja": { "name": "ミルクコーヒー" },
    "en": { "name": "Milk Coffee" },
    "vi": { "name": "Cà phê sữa" }
  },
  "product_type_id": "uuid-product-type",
  "category_ids": ["uuid-cat-1"],
  "options": [
    {
      "key": "size",
      "name": "Size",
      "position": 1,
      "values": [
        { "value": "s", "label": "Small", "position": 1 },
        { "value": "m", "label": "Medium", "position": 2 }
      ]
    }
  ],
  "skus": [
    {
      "value_indices": [0],
      "sku": "CAFE-S",
      "selling_price": 35000
    },
    {
      "value_indices": [1],
      "sku": "CAFE-M",
      "selling_price": 45000
    }
  ]
}
```

`value_indices` is an array of indices, one per option (the index of the value
inside `options[i].values`).

---

## 5. Topping Groups — workflow

### Purpose

Flexible modifier groups: add a topping, substitute an ingredient, choose a
special size. They are defined independently and attached to many products.

### The three-level structure

```
ToppingGroup  "Choose toppings"
  ├── min_select=0, max_select=3, max_qty_per_item=2
  ├── selection_type=multiple, modifier_type=add
  │
  ├── Item: "Black pearls"   (product_id = uuid-pearl-product)
  │     └── ItemSku: product_sku_id=null,  extra_price=5000   (one price for the whole item)
  │
  ├── Item: "Jelly"          (product_id = uuid-jelly-product)
  │     └── ItemSku: product_sku_id=null,  extra_price=5000
  │
  └── Item: "Pudding"        (product_id = uuid-pudding-product)
        ├── ItemSku: product_sku_id=uuid-small-sku, extra_price=8000
        └── ItemSku: product_sku_id=uuid-large-sku, extra_price=12000
```

### Workflow: create a Topping Group and attach it to a Product

```
Step 1 — Create the Topping Group
  ├── Name it (ja/en/vi)
  ├── Configure the selection rules: min_select, max_select, max_qty_per_item
  ├── Pick modifier_type (add/substitute) and selection_type (single/multiple)
  └── Optional: available_from/to plus available_days (time or day restrictions)

Step 2 — Add Items to the Group
  ├── Pick the product to use as a topping (it must be Active)
  ├── If the product has no variants: create one ItemSku with an extra_price
  └── If it has variants: create an ItemSku per SKU, each with its own price

Step 3 — Attach the Group to a Product
  ├── Go to the Product detail → the Topping Groups tab
  ├── Pick the group(s) to attach (several are allowed; drag to reorder)
  └── Optional: override min/max for this product only
        (e.g. the group defaults to max=3, but this product allows only max=2)

Step 4 — Per-product overrides (optional, advanced)
  ├── Hide a specific item from this product (is_hidden=true)
  └── Override an item's price for this product (override_price)
```

### The scope of per-product overrides

> **Important**: the overrides (is_hidden, override_price) only take effect in the
> **HQ admin view** on the product detail page. They are **not yet** propagated
> into the Menu response or the workstation/POS sync (the infrastructure exists,
> but the application logic is not wired up). This is a **known gap — planned**
> for the next sprint.

### Example payload: sync overrides

```json
PUT /api/v1/hq/{brandSlug}/products/{pid}/topping-groups/{gid}/overrides/sync
{
  "overrides": [
    {
      "topping_group_item_id": "uuid-item-pearls",
      "product_sku_id": null,
      "is_hidden": false,
      "override_price": 3000
    },
    {
      "topping_group_item_id": "uuid-item-pudding",
      "product_sku_id": "uuid-large-sku",
      "is_hidden": true,
      "override_price": null
    }
  ]
}
```

**Validation rules**:
- `is_hidden=true` → `override_price` must be `null` (an item cannot be hidden and
  priced at the same time)
- The payload must not contain two overrides with the same
  `(topping_group_item_id, product_sku_id)` pair

---

## 6. Menu at HQ — workflow

### Purpose

Menu management, with an approval workflow. It supports a Master Menu (an HQ
template) → cloned down to a Branch Menu → which a shop can view and price-override.

### State machine (Menu)

```
   ┌───────────┐  submit*  ┌─────────┐  approve  ┌──────────┐
   │   Draft   │ ────────► │ Pending │ ─────────► │ Approved │
   └───────────┘           └─────────┘            └──────────┘
                                │                      │ activate
                                │ reject               ▼
                                ▼              ┌──────────────┐
                         ┌──────────┐          │    Active    │
                         │ Rejected │          └──────────────┘
                         └──────────┘               │ deactivate
                              │ submit*              ▼
                              └─────────────► ┌──────────────┐
                                              │   Inactive   │
                                              └──────────────┘
                                                    │ activate
                                                    └──────────► Active
```

`*` submit requires the menu to hold **at least one product**; otherwise:
```json
HTTP 422 { "error": "MENU_OPERATION_NOT_ALLOWED",
           "message": "Menu must have at least one product before submitting." }
```

**Menu permissions**: every workflow action
(submit/approve/reject/activate/deactivate) requires **org ownership** — there is
no separate role. Any HQ staff member in the org may perform them. This differs
from Product, which has its own `catalog.approve` for approve and reject.

### Full workflow — create and activate a new menu

```
Step 1 — Create the Master Menu (if there is none)
  ├── is_master=true, brand_id, no branch_id
  └── This is the shared template for every branch

Step 2 — Structure the menu: add sections
  ├── Create or pick sections: "Starters", "Mains", "Desserts"
  └── Attach the sections to the menu with a display_order

Step 3 — Add products to the menu
  ├── Pick Active products (only Active products are selectable)
  ├── Assign each product to its section
  ├── Drag to reorder within a section
  └── Toggle each product on or off inside the menu (is_active)

Step 4 — Set SKU prices in the menu (optional)
  ├── By default: use selling_price from the ProductSku
  └── Override: set a menu-specific price (is_price_overridden=true)

Step 5 — Configure schedules (optional)
  ├── Create a schedule: "Lunch" → Mon-Fri, 11:00-14:00
  ├── Create a schedule: "Dinner" → Mon-Sun, 17:00-22:00
  └── The system turns the menu on and off per the schedule; GET /menus/current resolves the right one

Step 6 — Submit → Approve → Activate
  └── The menu is Active → ready to be cloned to branches and read by shops

Step 7 — Clone down to a branch (for a master)
  ├── POST /menus/{id}/clone-to-branch → creates a copy for the branch
  ├── The branch menu inherits every section, product and price
  └── The branch menu needs its own approval before it can go Active

Step 8 — Sync when the master changes
  ├── GET /menus/{id}/check-sync → check whether the master has new products
  └── POST /menus/{id}/sync-from-master → pull updates (manually triggered)
       ⚠ Auto-sync does not exist — a known gap, status: planned
```

### Example payload: bulk-sync the layout (sections + products)

```json
PUT /api/v1/hq/{brandSlug}/menus/{menuId}/layout
{
  "menu_items": [
    {
      "section_name": "Starters",
      "product_ids": ["uuid-product-1", "uuid-product-2"]
    },
    {
      "section_name": "Mains",
      "product_ids": ["uuid-product-3", "uuid-product-4", "uuid-product-5"]
    }
  ]
}
```

`section_name` is the section's name (it is found or created). `product_ids` is an
ordered list — the array order is the display_order.

**Priority conflict**: each branch has only one menu per priority. Activating a
menu whose priority is taken:
```json
HTTP 422 { "error": "INVALID_STATUS_TRANSITION",
           "message": "Cannot activate: priority 10 already taken by another active menu." }
```

---

## 7. Menu at the Shop — workflow

### Purpose

A shop only **reads and adjusts** a menu HQ has approved — it never creates or
edits the structure. It may override SKU prices and toggle products and SKUs to
suit the shop.

### Shop permissions

| Action | Staff | Manager |
|-----------|:-----:|:-------:|
| View the menu (Active only) | ✅ | ✅ |
| Toggle a product in the menu | ✅ | ✅ |
| Toggle a SKU in the menu | ✅ | ✅ |
| Override a SKU price | ❌ | ✅ |
| Reset a SKU price to the original | ❌ | ✅ |
| Sync from the master | ✅ | ✅ |

### User workflow (shop manager)

```
1. Go to Shop Admin → Menus (only Active menus are visible)

2. Browse the menu by section and product
   ├── The HQ default price is shown (MenuProductSku.selling_price)
   └── If overridden: the shop's price plus an "Overridden" badge

3. Override a SKU price (managers only)
   ├── Click the SKU's price cell → enter a new price
   ├── is_price_overridden = true
   └── To reset: press "Reset" → is_price_overridden = false

4. Toggle a product
   └── For example: out of an ingredient → turn the product off → it disappears from this shop's POS

5. Toggle a SKU variant
   └── For example: out of Large → turn off the "size=large" SKU → S and M are still sold

6. Sync when HQ adds a new product to the master
   └── Press "Sync" → POST /shops/{slug}/menus/{id}/sync
```

**Note**: a shop's price override is per shop. It affects neither the HQ menu nor
any other shop.

---

## 8. Approval workflow — permissions and errors

### Consolidated permissions (verified against the Policy files)

| Action | Product | Menu |
|--------|---------|------|
| Submit (Draft/Rejected → Pending) | Org ownership | Org ownership |
| Approve (Pending → Approved) | The **`catalog.approve`** permission | Org ownership |
| Reject (Pending → Rejected) | The **`catalog.approve`** permission | Org ownership |
| Activate / Deactivate | Org ownership | Org ownership |

**`catalog.approve`** is granted to Org Manager and Org Owner (see IamSeeder).
Menu has **no** dedicated approval permission — any HQ staff member in the org can
approve a menu.

### The standard HTTP errors

| Situation | HTTP | Error code |
|-----------|------|-----------|
| An illegal state transition | 422 | `INVALID_STATUS_TRANSITION` |
| Submitting a menu with no products | 422 | `MENU_OPERATION_NOT_ALLOWED` |
| No permission to approve | 403 | Forbidden |
| A priority conflict when activating a menu | 422 | `INVALID_STATUS_TRANSITION` |
| An override with is_hidden=true and a price at once | 422 | Validation error |

### The standard response for a transition error

```json
HTTP 422
{
  "message": "Cannot activate: product status is Draft, expected one of: [Approved, Inactive].",
  "error": "INVALID_STATUS_TRANSITION",
  "from": "Draft",
  "to": "Active",
  "action": "activate",
  "allowed": ["Approved", "Inactive"]
}
```
