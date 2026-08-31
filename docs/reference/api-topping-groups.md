---
title: Topping Groups API
category: reference
tags: [topping, topping-group, api, hq, catalog, crud, brand-scoped]
summary: Reference for the 17 HQ topping group endpoints — group CRUD, soft delete, restore, lookup, item management, per-SKU price overrides, and product assignment sync.
related: [topping-domain]
---

# Topping Groups API

Reference for the HQ topping group endpoints. All routes are mounted under `/api/v1/hq/{brandSlug}/` and require Sanctum SSO authentication. Authorization uses `ToppingGroupPolicy` with brand-scoped checks.

For domain concepts (entities, price logic, soft delete rules, Phase 2 deferred work) see the [Topping Domain](../explanation/topping-domain.md) explanation.

## Endpoints

| # | Method | Path | Purpose |
|---|--------|------|---------|
| 1 | GET | `/topping-groups` | List topping groups |
| 2 | POST | `/topping-groups` | Create group |
| 3 | GET | `/topping-groups/{group}` | Show group |
| 4 | PUT | `/topping-groups/{group}` | Update group |
| 5 | DELETE | `/topping-groups/{group}` | Soft delete group |
| 6 | POST | `/topping-groups/{group}/restore` | Restore soft-deleted group |
| 7 | GET | `/topping-groups/lookup` | Lightweight list for dropdowns |
| 8 | GET | `/topping-groups/{group}/items` | List items in group |
| 9 | POST | `/topping-groups/{group}/items` | Add item to group |
| 10 | PUT | `/topping-groups/{group}/items/{item}` | Update item (sort order) |
| 11 | DELETE | `/topping-groups/{group}/items/{item}` | Remove item from group |
| 12 | GET | `/topping-groups/{group}/items/{item}/skus` | List price overrides |
| 13 | POST | `/topping-groups/{group}/items/{item}/skus` | Add price override |
| 14 | PUT | `/topping-groups/{group}/items/{item}/skus/{itemSku}` | Update price override |
| 15 | DELETE | `/topping-groups/{group}/items/{item}/skus/{itemSku}` | Delete price override |
| 16 | GET | `/products/{product}/topping-groups` | List groups assigned to a product |
| 17 | POST | `/products/{product}/topping-groups/sync` | Sync groups assigned to a product |

---

## GET `/topping-groups` — List topping groups

Returns paginated list of topping groups for the brand.

### Query parameters

| Field | Type | Notes |
|-------|------|-------|
| `search` | string | Free-text match on `name`. |
| `is_active` | bool | Filter by active status. |
| `with_trashed` | bool | Include soft-deleted groups when `true`. |
| `sort` | string | Sort column, prefix `-` for descending. Default `sort_order`. |
| `per_page` | integer | Default 15. |

### Response

- `200` — paginated `ToppingGroupResource` collection.

---

## POST `/topping-groups` — Create group

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `name` | string | yes | Top-level mirror (locale-priority value). |
| `ja` / `en` / `vi` | object | at least one required | `{ name: string }`. At least one locale must have a non-empty `name`. |
| `min_select` | integer | no | Default `0` (optional). |
| `max_select` | integer, nullable | no | `null` = no limit. Must be ≥ `min_select` when both provided. |
| `max_qty_per_item` | integer | no | Default `1`. |
| `sort_order` | integer | no | Default `0`. |
| `is_active` | bool | no | Default `true`. |

### Response

- `201` — created `ToppingGroupResource`.

### Errors

| Status | When |
|--------|------|
| 422 | `max_select < min_select`; no locale has a non-empty `name`; validation failure |

---

## GET `/topping-groups/{group}` — Show group

Returns a single group with translations and items eager-loaded.

### Response

- `200` — `ToppingGroupResource` with `items` included.
- `404` — group not found (or soft-deleted without `with_trashed`).

---

## PUT `/topping-groups/{group}` — Update group

All fields are optional. Sends only the fields to change.

### Request body

Same fields as POST, all optional. When updating `min_select` or `max_select`, send both fields together — cross-field validation only fires when both are present.

### Response

- `200` — updated `ToppingGroupResource`.

---

## DELETE `/topping-groups/{group}` — Soft delete group

Sets `deleted_at`. The group no longer appears in the default list but historic `order_item_toppings` (Phase 2) remain intact.

### Response

- `200` — soft-deleted `ToppingGroupResource`.
- `404` — group not found.

---

## POST `/topping-groups/{group}/restore` — Restore group

Clears `deleted_at`. The `{group}` UUID must resolve with `withTrashed()`.

### Response

- `200` — restored `ToppingGroupResource`.
- `404` — group not found (even with trashed).

---

## GET `/topping-groups/lookup` — Lightweight list

Returns a minimal list for populating Combobox / select dropdowns. Only active, non-deleted groups. No pagination.

### Response

- `200` — array of `{ id, name }` objects.

> **Route order:** `lookup` is registered before the `apiResource` call so Laravel does not match it as `{group}=lookup` and call `show()` instead.

---

## Group item endpoints

All item routes are nested under `/topping-groups/{group}/items`.

---

## GET `/topping-groups/{group}/items` — List items

Returns all items in the group with their `product` and `skus` eager-loaded.

### Response

- `200` — array of `ToppingGroupItemResource`.

---

## POST `/topping-groups/{group}/items` — Add item

Adds a product to the group as a topping item.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `product_id` | UUID | yes | Must belong to the same brand as the group. Must have `product_type.code = 'topping'`. Must not already be in this group. |

### Response

- `201` — created `ToppingGroupItemResource`.

### Errors

| Status | When |
|--------|------|
| 422 | Product not found; wrong brand; wrong product type; already in group |

### Side effects

If the product has no variants (single default SKU), one `ToppingGroupItemSku(product_sku_id=null, extra_price=0)` is created automatically.

---

## PUT `/topping-groups/{group}/items/{item}` — Update item

Updates the item's `sort_order`. Other fields are not editable.

### Request body

| Field | Type | Notes |
|-------|------|-------|
| `sort_order` | integer | Display order within the group. |

### Response

- `200` — updated `ToppingGroupItemResource`.

---

## DELETE `/topping-groups/{group}/items/{item}` — Remove item

Soft-deletes the item. Observer cascade hard-deletes all child `ToppingGroupItemSku` rows.

### Response

- `200` — soft-deleted `ToppingGroupItemResource`.
- `404` — item not found, or item belongs to a different group.

---

## Price override endpoints

All SKU routes are nested under `/topping-groups/{group}/items/{item}/skus`.

---

## GET `/topping-groups/{group}/items/{item}/skus` — List price overrides

Returns all `ToppingGroupItemSku` rows for the item.

### Response

- `200` — array of `ToppingGroupItemSkuResource`.

---

## POST `/topping-groups/{group}/items/{item}/skus` — Add price override

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `product_sku_id` | UUID, nullable | no | `null` for no-variant toppings. If provided, must belong to the item's product. |
| `extra_price` | decimal | yes | Min `0`. Added on top of base product price. |

### Response

- `201` — created `ToppingGroupItemSkuResource`.

### Errors

| Status | When |
|--------|------|
| 422 | `product_sku_id` belongs to a different product; duplicate `null` entry already exists |

---

## PUT `/topping-groups/{group}/items/{item}/skus/{itemSku}` — Update price

Updates `extra_price` only. `product_sku_id` is immutable after creation.

### Request body

| Field | Type | Notes |
|-------|------|-------|
| `extra_price` | decimal | Min `0`. |

### Response

- `200` — updated `ToppingGroupItemSkuResource`.

---

## DELETE `/topping-groups/{group}/items/{item}/skus/{itemSku}` — Delete price override

Hard-deletes the price override row.

### Response

- `200` — deleted `ToppingGroupItemSkuResource`.

---

## Product assignment endpoints

Routes are mounted under `/products/{product}/topping-groups` (still within the HQ `{brandSlug}` prefix).

---

## GET `/products/{product}/topping-groups` — List assigned groups

Returns all topping groups assigned to the product, with `items` eager-loaded.

### Response

- `200` — array of `ToppingGroupResource` (with items).

---

## POST `/products/{product}/topping-groups/sync` — Sync assigned groups

Replaces the full set of topping groups assigned to a product. Send an empty array to detach all groups.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `topping_group_ids` | array of UUID | yes (present) | Can be empty `[]` to detach all. All IDs must belong to the same brand as the product. |
| `sort_orders` | object | no | Map of `{ [groupId]: sortOrder }` for display ordering. |

### Response

- `200` — array of `ToppingGroupResource` reflecting the new assignment.

### Errors

| Status | When |
|--------|------|
| 422 | Any `topping_group_id` belongs to a different brand than the product |

### Side effects

All previous `product_topping_groups` rows for this product are replaced with the provided list. This is a full replace — not an additive operation.
