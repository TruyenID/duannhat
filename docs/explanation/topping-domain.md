---
title: Topping Domain
category: explanation
tags: [topping, topping-group, product, catalog, brand-scoped, soft-delete, phase-1, phase-2]
summary: Explains the five-entity topping domain — how topping groups are structured, scoped, and priced per-SKU, the guard preventing topping products from appearing on menus, and what is deferred to Phase 2.
related: [order-domain]
---

# Topping Domain

This document explains the topping management domain introduced in plan-013. For API endpoints, see the [Topping Groups API](../reference/api-topping-groups.md) reference.

## Core concept

A topping is an ordinary `Product` whose `product_type.code = 'topping'`. Toppings are not sold stand-alone — they are organized into **ToppingGroups** that define selection rules (minimum and maximum choices) and are assigned to orderable products.

Phase 1 (plan-013) delivers the admin configuration layer: schema, CRUD API, and admin-web UI. The runtime order layer (recording customer selections, rolling up topping cost into `subtotal`, deducting topping SKU stock) is deferred to Phase 2.

---

## Entity model

```text
Brand
└── ToppingGroup  (brand-scoped, translatable name, soft-deletable)
      ├── ProductToppingGroup  (pivot → Products that offer this group)
      └── ToppingGroupItem  (→ Product with type=topping, soft-deletable)
            └── ToppingGroupItemSku  (→ ProductSku, extra_price override)

[Phase 2 — deferred]
CustomerOrderItem
└── OrderItemTopping  (snapshot: group_item + sku + unit_price)
```

---

## Entities

### ToppingGroup

Brand-scoped configuration entity. Represents one selection panel shown to the customer (e.g. "Choose a sauce" or "Choose a size").

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID | |
| `name` | string | Translatable — stored in `topping_group_translations` |
| `min_select` | int | `0` = optional, `1+` = required. Replaces a boolean `is_required`. |
| `max_select` | int, nullable | `NULL` = no limit on distinct topping types. |
| `max_qty_per_item` | int | Maximum quantity of any single topping per order item. Default `1`. |
| `sort_order` | int | Display order within the product's topping panel list. |
| `is_active` | bool | Inactive groups are hidden from customer-facing views. |
| `brand_id` | UUID FK | Org-scoped. All items and assigned products must share the same brand. |
| `deleted_at` | timestamp | Soft delete. Deleted groups remain referenced by historic `order_item_toppings`. |

### ProductToppingGroup

M-N pivot linking `products` → `topping_groups`. Both must belong to the same brand. No soft delete — unlinking removes the row.

| Column | Notes |
|--------|-------|
| `product_id` | FK to `products`. `onDelete: CASCADE`. |
| `topping_group_id` | FK to `topping_groups`. `onDelete: CASCADE`. |
| `sort_order` | Display order of this group's panel within the product's topping picker. |

Uniqueness: `(product_id, topping_group_id)`.

### ToppingGroupItem

One entry per topping product inside a group. Soft-deletable so Phase 2 `OrderItemTopping` rows that reference a removed topping keep their history.

| Column | Notes |
|--------|-------|
| `topping_group_id` | FK to `topping_groups`. `onDelete: CASCADE`. |
| `product_id` | FK to `products`. `onDelete: RESTRICT` — cannot delete a product while it appears in a topping group. |
| `sort_order` | Display order within the group. |

Uniqueness: `(topping_group_id, product_id)` — one product appears at most once per group.

**Observer:** `ToppingGroupItemObserver` — when an item is soft-deleted, it hard-deletes all child `ToppingGroupItemSku` rows (they have no independent value once the item is gone).

### ToppingGroupItemSku

Per-SKU price override for a topping item. Allows different prices depending on which variant of the topping the customer selects.

| Column | Notes |
|--------|-------|
| `topping_group_item_id` | FK to `topping_group_items`. `onDelete: CASCADE`. |
| `product_sku_id` | FK to `product_skus`, **nullable**. `NULL` means "this topping has no variants". |
| `extra_price` | `DECIMAL(15,2)`, default `0`. Added on top of the base product price. |

**Price lookup logic:**

```text
Topping with no variants  → extra_price WHERE product_sku_id IS NULL
Topping with variants     → extra_price WHERE product_sku_id = {selected SKU}
                            Fallback: product_skus.selling_price when no override row exists
```

**NULL uniqueness caveat:** MySQL `UNIQUE(topping_group_item_id, product_sku_id)` does not block multiple `NULL` rows. The service layer checks for an existing `WHERE product_sku_id IS NULL` row before inserting a no-variant price row.

**Auto-create:** when adding a simple (no-variant) topping product to a group, `ToppingGroupItemService::addItem()` automatically creates one `ToppingGroupItemSku(product_sku_id=null, extra_price=0)` so the price lookup always has a row.

### OrderItemTopping (schema only — Phase 2)

Append-only snapshot of the topping selections made inside a single `CustomerOrderItem`. The table is created in Phase 1 but no API or service writes to it.

| Column | Notes |
|--------|-------|
| `customer_order_item_id` | FK to `customer_order_items`. `onDelete: CASCADE`. |
| `topping_group_item_id` | FK to `topping_group_items`. `onDelete: RESTRICT`. |
| `product_sku_id` | FK to `product_skus`, nullable. Phase 2 service must resolve to an actual SKU ID — never store `NULL` in this table (unlike `ToppingGroupItemSku`). |
| `quantity` | Int, default `1`. |
| `unit_price` | `DECIMAL(15,2)`. Price snapshot at order time. |
| `note` | string(500), nullable. |

---

## Menu guard

Topping products are intentionally blocked from appearing in `menu_products`. The guard lives in `MenuAddProductsRequest::withValidator()`. Attempting `POST /hq/{brand}/menus/{menu}/products` with a product whose type is `topping` returns `422 Topping products cannot be added to a menu.`

This is enforced at the request layer, not the service layer, so the service remains unaware of the menu concept.

---

## Soft delete and deletion constraints

| Entity | Soft delete | Hard delete blocked by |
|--------|-------------|------------------------|
| `ToppingGroup` | Yes | — (cascade orphans groups' items when group is hard-deleted) |
| `ToppingGroupItem` | Yes | `product_id RESTRICT` — cannot delete a product while it is referenced |
| `ToppingGroupItemSku` | No | Cascade-deleted by observer when parent item is soft-deleted |
| `ProductToppingGroup` | No | Removed via sync endpoint |
| `OrderItemTopping` | No | `RESTRICT` on both FK columns — order history is immutable |

---

## Phase 2 deferred work

| Item | What's needed |
|------|---------------|
| `customer_order_items.topping_subtotal` | New `DECIMAL(15,2) DEFAULT 0` column + update BR-OI02 comment |
| `CustomerOrderService::addItem()` | Accept `toppings[]` payload, write `OrderItemTopping` snapshot, compute `topping_subtotal` |
| `CustomerOrderService::close()` | Stock-out topping SKUs for served items (filter `product_sku_id IS NOT NULL`) |
| POS / customer-web UI | Topping picker shown when adding an item to an order |

> **Important:** `OrderItemTopping.product_sku_id` must always be a real SKU ID (not `NULL`) when written in Phase 2. Every topping product has at least one default SKU. The nullable column in `ToppingGroupItemSku` means something different — it marks a no-variant config entry, not a missing SKU reference.
