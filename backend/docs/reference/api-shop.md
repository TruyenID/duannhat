---
title: Shop Domain API Reference
category: reference
tags: [api, shop, branch, zone, table, qr-token, status, runtime, shop-menu, menu-item, availability, price-override, shop-scoped, endpoint]
summary: Lists API endpoints for the shop domain — zones, tables, and the shop-side menu façade — all mounted under the shop-scoped prefix /api/v1/shops/{shopSlug}/...
related: [api-overview, api-product, api-inventory, controller, service]
---

# Shop Domain API Reference

This document covers the API endpoints for the shop domain: zones (areas inside a shop) and tables (the seating units customers occupy). Inventory-domain endpoints (warehouses, stock, disposals) live under the same `/api/v1/shops/{shopSlug}/...` prefix and are documented in [API: Inventory](api-inventory.md).

> **Routing model:** Every endpoint listed here is mounted under `/api/v1/shops/{shopSlug}/...`. The `ResolveShopFromSlug` middleware looks the shop up by slug, asserts it belongs to the caller's organization, and exposes `shop` / `shop_id` / `brand_id` on the request for controllers to read. There is no org-scoped alias — `{shopSlug}` is always required. See [API Overview — Slug-Based URL Convention](api-overview.md#slug-based-url-convention).
>
> **Service reuse:** Controllers in `App\Http\Controllers\Api\V1\Shop` are thin — they only resolve the shop from the request, authorize, and delegate to a `ZoneService` / `TableService` / `TableStatusService`. Business logic (cascading soft deletes, QR token rotation, runtime status transitions, validation) lives in the service, not the controller. The same service classes can be reused under any other route prefix that exposes the same domain. See [Controller Rules](../contributing/controller.md) and [Service Rules](../contributing/service.md).

## Endpoints

- [Zones](#1-zones)
- [Tables](#2-tables)
- [Table Runtime Status](#3-table-runtime-status)
- [Table QR Token](#4-table-qr-token)
- [Shop Menus](#5-shop-menus)

---

## 1. Zones

A `Zone` is a logical area inside a shop (Terrace, Indoor, VIP). Zones own tables. Zone soft-delete cascades to tables in the zone, but restoring a zone does **not** auto-restore its tables (BR-Z02 / BR-Z03).

### Endpoints

| Method | Endpoint                                                       | Description                                       |
| ------ | -------------------------------------------------------------- | ------------------------------------------------- |
| GET    | `/api/v1/shops/{shopSlug}/zones`                               | List zones (paginated)                            |
| POST   | `/api/v1/shops/{shopSlug}/zones`                               | Create zone (Manager+ only)                       |
| GET    | `/api/v1/shops/{shopSlug}/zones/{zone}`                        | Get zone detail (with `table_count`)              |
| PUT    | `/api/v1/shops/{shopSlug}/zones/{zone}`                        | Update zone                                       |
| DELETE | `/api/v1/shops/{shopSlug}/zones/{zone}`                        | Soft delete (cascades to tables)                  |
| POST   | `/api/v1/shops/{shopSlug}/zones/{zone}/restore`                | Restore soft-deleted zone (does not restore tables) |
| POST   | `/api/v1/shops/{shopSlug}/zones/{zone}/toggle-active`          | Toggle `is_active` flag                           |
| GET    | `/api/v1/shops/{shopSlug}/zones/lookup`                        | Lightweight lookup `[{id, code, name, display_order}]` |

### List Filters

| Parameter      | Type    | Description                                |
| -------------- | ------- | ------------------------------------------ |
| `search`       | string  | Search by `code` or `name`                 |
| `is_active`    | boolean | Filter by active state                     |
| `with_trashed` | boolean | Include soft-deleted                       |
| `sort`         | string  | Sort field (default `display_order`)       |
| `per_page`     | integer | Items per page (default 25, max 100)       |

### Fields

| Field           | Type    | Required | Description                                                |
| --------------- | ------- | -------- | ---------------------------------------------------------- |
| `code`          | string  | Yes      | Unique per shop. Alphanumeric + hyphens only (e.g. `TER`)  |
| `name`          | string  | Yes      | Display name (e.g. `Terrace`)                              |
| `description`   | text    | No       | Optional description                                       |
| `display_order` | integer | No       | Sort order (default `0`)                                   |
| `is_active`     | boolean | No       | Default `true`                                             |

> **Note:** `organization_id` and `branch_id` are injected server-side from the resolved shop — clients never send them.

### Request Example — Create Zone

**POST /api/v1/shops/{shopSlug}/zones**

```json
{
  "code": "TER",
  "name": "Terrace",
  "description": "Outdoor seating",
  "display_order": 10
}
```

---

## 2. Tables

A `Table` is a seating unit inside a Zone. Each table has a unique `code`, a `seat_count`, an `is_active` flag, and a runtime `status`. Every table also has an opaque `qr_token` that lets a customer scan into the ordering flow; the QR image is rendered on the frontend from the token (no `/qr` image endpoint exists).

### Endpoints

| Method | Endpoint                                                         | Description                                     |
| ------ | ---------------------------------------------------------------- | ----------------------------------------------- |
| GET    | `/api/v1/shops/{shopSlug}/tables`                                | List tables (paginated)                         |
| POST   | `/api/v1/shops/{shopSlug}/tables`                                | Create table (Manager+ only)                    |
| GET    | `/api/v1/shops/{shopSlug}/tables/{table}`                        | Get table detail                                |
| PUT    | `/api/v1/shops/{shopSlug}/tables/{table}`                        | Update table (cannot change `status` or `qr_token`) |
| DELETE | `/api/v1/shops/{shopSlug}/tables/{table}`                        | Soft delete                                     |
| POST   | `/api/v1/shops/{shopSlug}/tables/{table}/restore`                | Restore soft-deleted table                      |
| POST   | `/api/v1/shops/{shopSlug}/tables/{table}/toggle-active`          | Toggle `is_active` flag                         |

### List Filters

| Parameter      | Type    | Description                                                       |
| -------------- | ------- | ----------------------------------------------------------------- |
| `zone_id`      | uuid    | Filter by zone                                                    |
| `status`       | string  | `free`, `occupied`, `reserved`, `cleaning`, `out_of_service`      |
| `is_active`    | boolean | Filter by active state                                            |
| `search`       | string  | Search by `code` or `name`                                        |
| `with_trashed` | boolean | Include soft-deleted                                              |
| `sort`         | string  | Sort field (default `code`)                                       |
| `per_page`     | integer | Items per page (default 25, max 100)                              |

### Fields

| Field        | Type    | Required | Description                                                       |
| ------------ | ------- | -------- | ----------------------------------------------------------------- |
| `code`       | string  | Yes      | Unique per shop. Alphanumeric + hyphens only (e.g. `T-01`)        |
| `name`       | string  | No       | Optional display label (e.g. `Window seat`)                       |
| `seat_count` | integer | Yes      | Capacity, 1–1000                                                  |
| `zone_id`    | uuid    | Yes      | Must belong to the resolved shop                                  |
| `status`     | enum    | —        | Runtime status. **Not editable through `PUT`** — use `/status`    |
| `qr_token`   | string  | —        | Auto-minted server-side. **Not editable** — use `/regenerate-qr`  |
| `is_active`  | boolean | —        | Defaults to `true` on create                                      |

> **Note:** `organization_id` and `branch_id` are injected server-side from the resolved shop.

### Request Example — Create Table

**POST /api/v1/shops/{shopSlug}/tables**

```json
{
  "code": "T-01",
  "name": "Window seat",
  "seat_count": 4,
  "zone_id": "019e1234-..."
}
```

---

## 3. Table Runtime Status

Runtime status is the only mutation Shop Staff are allowed to perform on a table. Every transition writes one row in `table_status_changes` (append-only audit log). v1 uses **free transitions** — any status can move to any other — but inactive tables (`is_active = false`) are blocked (BR-T03).

### Endpoints

| Method | Endpoint                                                            | Description                                                  |
| ------ | ------------------------------------------------------------------- | ------------------------------------------------------------ |
| POST   | `/api/v1/shops/{shopSlug}/tables/{table}/status`                    | Change runtime status (Staff allowed)                        |
| GET    | `/api/v1/shops/{shopSlug}/tables/{table}/status-history`            | List status transitions newest-first (paginated)             |

### Change Status Body

| Field    | Type   | Required | Description                                                    |
| -------- | ------ | -------- | -------------------------------------------------------------- |
| `status` | enum   | Yes      | `free`, `occupied`, `reserved`, `cleaning`, `out_of_service`   |
| `note`   | string | No       | Free-text note, max 1000 chars                                 |

### Request Example — Change Status

**POST /api/v1/shops/{shopSlug}/tables/{table}/status**

```json
{
  "status": "occupied",
  "note": "Walk-in 2 pax"
}
```

### Status History Filters

| Parameter  | Type    | Description                          |
| ---------- | ------- | ------------------------------------ |
| `per_page` | integer | Items per page (default 25, max 100) |

---

## 4. Table QR Token

Each table has an opaque `qr_token` that drives the customer-side QR ordering flow. Rotating the token invalidates the previous one immediately. The frontend renders the QR image client-side from the returned token; there is no server-rendered QR image endpoint. Manager+Admin only.

### Endpoint

| Method | Endpoint                                                           | Description                       |
| ------ | ------------------------------------------------------------------ | --------------------------------- |
| POST   | `/api/v1/shops/{shopSlug}/tables/{table}/regenerate-qr`            | Issue a new `qr_token`            |

---

---

## 5. Shop Menus

A shop sees branch menus that HQ has cloned from a master menu (`menu.master_menu_id IS NOT NULL`). The shop can read those menus and toggle product/SKU availability, override per-shop SKU selling prices, or sync new products from the master — but cannot create, delete, or move menus through the approval workflow. Those remain HQ-only ([API: Product → Menus](api-product.md)). The underlying `Menu`, `MenuProduct`, and `MenuProductSku` models are used; the shop façade reuses the existing `MenuService`. See [Product Domain — Menu](../explanation/product-domain.md#menu) for the entity model.

### Endpoints

| Method | Endpoint                                                                                                       | Description                                          |
| ------ | -------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| GET    | `/api/v1/shops/{shopSlug}/menus`                                                                               | List branch menus visible to the shop                |
| GET    | `/api/v1/shops/{shopSlug}/menus/{menu}`                                                                        | Show one branch menu with eager-loaded products/SKUs |
| GET    | `/api/v1/shops/{shopSlug}/menus/{menu}/products`                                                               | List products in a branch menu (paginated)           |
| POST   | `/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/toggle`                                          | Toggle product `is_active` (Staff+)                  |
| POST   | `/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/skus/{menuProductSku}/toggle`                    | Toggle SKU `is_active` (Staff+)                      |
| POST   | `/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/skus/{menuProductSku}/price`                     | Override SKU selling price (Manager+ only)            |
| POST   | `/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/skus/{menuProductSku}/reset-price`               | Reset SKU price to canonical (Manager+ only)         |
| POST   | `/api/v1/shops/{shopSlug}/menus/{menu}/sync`                                                                   | Sync new products from master menu (Manager+ only)   |

### List Filters (menus)

| Parameter   | Type    | Description                                                          |
| ----------- | ------- | -------------------------------------------------------------------- |
| `status`    | string  | Filter by menu status (default `Active`)                             |
| `per_page`  | integer | Items per page (default 20, max 100)                                 |

### List Filters (products)

| Parameter      | Type    | Description                                                       |
| -------------- | ------- | ----------------------------------------------------------------- |
| `is_active`    | boolean | Filter by product active state                                    |
| `search`       | string  | Free-text search on the related product name                      |
| `per_page`     | integer | Items per page (default 50, max 100)                              |

### Menu Product Fields

| Field                  | Type     | Notes                                                               |
| ---------------------- | -------- | ------------------------------------------------------------------- |
| `id`                   | uuid     |                                                                     |
| `menu_id`              | uuid     | Parent menu                                                         |
| `product_id`           | uuid     | The product on this menu                                            |
| `is_active`            | boolean  | Whether the product is active on this branch menu                   |
| `display_order`        | integer  | Read-only on the shop side (HQ owns ordering)                       |

### Menu Product SKU Fields

| Field                  | Type     | Notes                                                               |
| ---------------------- | -------- | ------------------------------------------------------------------- |
| `id`                   | uuid     |                                                                     |
| `menu_product_id`      | uuid     | Parent `MenuProduct`                                                |
| `product_sku_id`       | uuid     | The SKU being sold (NOT mutable from the shop side)                 |
| `selling_price`        | decimal  | Per-branch price; set by the override endpoint                      |
| `is_price_overridden`  | boolean  | `false` at clone/reset; `true` after the override endpoint runs     |
| `is_active`            | boolean  | Whether this SKU is active on the branch menu                       |

> **No `master_price` column.** The canonical price is always `product_skus.selling_price`, queried live. The reset-price endpoint copies that value into `menu_product_skus.selling_price` and clears `is_price_overridden`.

### Request Example — Override SKU Price

```http
POST /api/v1/shops/main-shop/menus/0193cf3a.../products/0193cf3b.../skus/0193cf3c.../price
Content-Type: application/json
Authorization: Bearer <token>

{
  "selling_price": 125.00
}
```

The response includes the updated `MenuProductSku` with `is_price_overridden: true`. Use the `reset-price` endpoint to revert to the canonical `product_skus.selling_price`.

### Request Example — Sync from Master

```http
POST /api/v1/shops/main-shop/menus/0193cf3a.../sync
Content-Type: application/json
Authorization: Bearer <token>
```

Pulls products from the master menu that are not yet in the branch clone, creating `MenuProduct` and `MenuProductSku` rows. SKU prices are snapshotted from `product_skus.selling_price` with `is_price_overridden = false`.

### Authorization

All endpoints run through `MenuPolicy@shop*`:

| Method                   | Required role                          | Notes                                                                 |
| ------------------------ | -------------------------------------- | --------------------------------------------------------------------- |
| `shopView`               | Any authenticated shop user (Staff+)   | Used by index, show, listProducts                                     |
| `shopToggle`             | Shop Staff and above                   | Daily-operations 86'd toggle for products and SKUs                    |
| `shopUpdatePrice`        | Shop Manager and above                 | Pricing is a managerial decision; Staff is denied                     |
| `shopSync`               | Shop Manager and above                 | Sync pulls new products from master                                   |

Every method additionally requires `menu.organization_id == user.console_organization_id`, `menu.branch_id == resolved shop.id`, and `menu.master_menu_id IS NOT NULL`. See [Authorization → Menu](../explanation/authorization.md#menu-management).

### Errors

| Status | Code                          | Meaning                                                                  |
| ------ | ----------------------------- | ------------------------------------------------------------------------ |
| 401    | `unauthenticated`             | Missing or invalid Sanctum token                                         |
| 403    | `forbidden`                   | Caller is in the right org but not the right role for the action        |
| 404    | `not_found`                   | Menu, product, or SKU not found within the resolved shop                 |
| 422    | `validation_failed`           | Field validation errors (price range, etc.)                              |

---

## Enums

### Table Status

| Value            | Description                                       |
| ---------------- | ------------------------------------------------- |
| `free`           | Available, no party seated                        |
| `occupied`       | A party is currently seated                       |
| `reserved`       | Held for an upcoming reservation                  |
| `cleaning`       | Being cleaned between parties                     |
| `out_of_service` | Unavailable (broken, maintenance, ...)            |

## Errors

| Status | Code                          | Meaning                                                            |
| ------ | ----------------------------- | ------------------------------------------------------------------ |
| 401    | `unauthenticated`             | Missing or invalid Sanctum token                                   |
| 403    | `forbidden`                   | Caller lacks role required for the action (e.g. Staff trying CRUD) |
| 404    | `not_found`                   | Shop, zone, or table not found in the resolved shop                |
| 422    | `validation_failed`           | Field validation errors                                            |
| 500    | `inactive_table`              | Cannot change runtime status on an inactive table (BR-T03)         |
