---
title: Product Domain API Reference
category: reference
tags: [api, brand, hq, product, product-option, product-option-value, product-sku, category, product-type, sku-unit, material, recipe, menu, menu-product, endpoint]
summary: Lists all API endpoints for the product domain — products, product types, categories, options, SKUs, units, materials, recipes, menus — all mounted under the HQ (brand-scoped) prefix /api/v1/hq/{brandSlug}/...
related: [product-domain, product-workflow, api-overview, api-shop]
---

# Product Domain API Reference

This document covers all API endpoints for the product domain: products, product types, categories, product options, product option values, product SKUs, SKU units, materials, recipes, and menus. They form the **HQ (brand headquarters)** surface — what brand-level operators use to manage their chain's catalog and master data.

> **Routing model:** Every endpoint listed here is mounted under `/api/v1/hq/{brandSlug}/...`. The `ResolveBrandFromSlug` middleware looks the brand up by slug, asserts it belongs to the caller's organization, and exposes `brand` / `brand_id` on the request for controllers to read. There is no org-scoped alias — `{brandSlug}` is always required. See [API Overview — Slug-Based URL Convention](api-overview.md#slug-based-url-convention).
>
> **Service reuse:** Controllers in `App\Http\Controllers\Api\V1\HQ` are thin — they only resolve the brand from the request, authorize, and delegate to a `ProductService` / `MenuService` / etc. The same service classes power any future shop-scoped variant of these resources. Business logic lives in the service, not the controller. See [Controller Rules](../contributing/controller.md) and [Service Rules](../contributing/service.md).

## Endpoints

- [Products](#1-products)
- [Product Types](#2-product-types)
- [Categories](#3-categories)
- [Product Options](#4-product-options)
- [Product Option Values](#5-product-option-values)
- [Product SKUs](#6-product-skus)
- [SKU Units](#7-sku-units)
- [Materials](#8-materials)
- [Recipes](#9-recipes)
- [Menus](#10-menus)

---

## 1. Products

### Endpoints

| Method | Endpoint                                    | Description                              |
| ------ | ------------------------------------------- | ---------------------------------------- |
| GET    | `/api/v1/hq/{brandSlug}/products`                          | List products (paginated)                |
| POST   | `/api/v1/hq/{brandSlug}/products`                          | Create product (draft)                   |
| GET    | `/api/v1/hq/{brandSlug}/products/{id}`                     | Get product detail                       |
| PUT    | `/api/v1/hq/{brandSlug}/products/{id}`                     | Update product                           |
| DELETE | `/api/v1/hq/{brandSlug}/products/{id}`                     | Soft delete product                      |
| POST   | `/api/v1/hq/{brandSlug}/products/{id}/restore`             | Restore soft-deleted product             |
| POST   | `/api/v1/hq/{brandSlug}/products/bulk-delete`              | Bulk soft delete                         |
| GET    | `/api/v1/hq/{brandSlug}/products/lookup`                   | Lightweight list (id + name) for forms   |
| POST   | `/api/v1/hq/{brandSlug}/products/{id}/submit-for-approval` | draft -> pending                         |
| POST   | `/api/v1/hq/{brandSlug}/products/{id}/approve`             | pending -> approved                      |
| POST   | `/api/v1/hq/{brandSlug}/products/{id}/reject`              | pending -> rejected                      |
| POST   | `/api/v1/hq/{brandSlug}/products/{id}/activate`            | approved -> active                       |
| POST   | `/api/v1/hq/{brandSlug}/products/{id}/deactivate`          | active -> inactive                       |
| GET    | `/api/v1/hq/{brandSlug}/products/{id}/skus`                | List SKUs of product                     |
| POST   | `/api/v1/hq/{brandSlug}/products/{id}/skus`                | Create SKU under product                 |
| GET    | `/api/v1/hq/{brandSlug}/products/{id}/options`             | List options of product                  |
| POST   | `/api/v1/hq/{brandSlug}/products/{id}/options`             | Create option under product              |

> **Note:** `/dropdown` is a deprecated alias of `/lookup`. New code must use `/lookup`. See [API Overview](api-overview.md#dropdowns).

> **Workflow status:** The status-transition endpoints (`submit-for-approval`, `approve`, `reject`, `activate`, `deactivate`) are still wired in the controller, but the supporting columns (`approved_by_id`, `approved_at`, `rejected_by_id`, `rejected_at`, `rejection_reason`) have been removed from `Product.yaml`. Treat the workflow as **partially wired** until those fields are restored. See [Product Workflow](../explanation/product-workflow.md).

### Import / Export

| Method | Endpoint                              | Description           |
| ------ | ------------------------------------- | --------------------- |
| GET    | `/api/v1/hq/{brandSlug}/products/import/template`    | Download CSV template |
| POST   | `/api/v1/hq/{brandSlug}/products/import`             | Import products       |
| GET    | `/api/v1/hq/{brandSlug}/products/export`             | Export products       |

### List Filters

| Parameter        | Type    | Description                             |
| ---------------- | ------- | --------------------------------------- |
| `search`         | string  | Search by translated name or slug       |
| `status`         | string  | Filter by status                        |
| `product_type_id`| uuid    | Filter by product type                  |
| `category_id`    | uuid    | Filter by category                      |
| `is_hidden`      | boolean | Filter by visibility flag               |
| `with_trashed`   | boolean | Include soft-deleted                    |
| `sort`           | string  | Sort field: `name`, `slug`, `status`, `created_at` |
| `per_page`       | integer | Items per page                          |

### Translatable fields

`name` and `description` are translatable through `astrotomic/laravel-translatable`. Submit them as either:

- A string in the current locale: `"name": "Black Coffee"`
- A `{locale: value}` map: `"name": {"ja": "ブラックコーヒー", "en": "Black Coffee"}`

The same shape applies on responses; the API returns the locale resolved by the `Accept-Language` header (with `ja → en` fallback).

### Request Examples

#### Create Product

**POST /api/v1/hq/{brandSlug}/products**

```json
{
  "product_type_id": "019d4e6a-...",
  "name": {"ja": "ブラックコーヒー", "en": "Black Coffee"},
  "slug": "black-coffee",
  "description": {"en": "Traditional black coffee"},
  "category_ids": ["019d4e6a-...", "019d4e6b-..."]
}
```

> **Note:** The product is created in `draft` status. A default SKU is created automatically (`option_value{1,2,3}_id = NULL`, `option_signature = ""`). To attach options or per-combination SKUs, use the dedicated endpoints below.

#### Reject Product

**POST /api/v1/hq/{brandSlug}/products/{id}/reject**

```json
{ "rejection_reason": "Missing product image" }
```

### Product Status Workflow

```text
  draft --submit--> pending --approve--> approved --activate--> active
                       |                                          |
                       | reject                                   | deactivate
                       v                                          v
                   rejected --> edit --> draft                  inactive
```

- Rejected products return to an editable state and can be resubmitted
- Active products cannot be deleted
- The approver must differ from the creator

---

## 2. Product Types

### Endpoints

| Method | Endpoint                                   | Description        |
| ------ | ------------------------------------------ | ------------------ |
| GET    | `/api/v1/hq/{brandSlug}/product-types`                    | List (filters: search, is_active, product_form) |
| POST   | `/api/v1/hq/{brandSlug}/product-types`                    | Create             |
| GET    | `/api/v1/hq/{brandSlug}/product-types/{id}`               | Get detail         |
| PUT    | `/api/v1/hq/{brandSlug}/product-types/{id}`               | Update             |
| DELETE | `/api/v1/hq/{brandSlug}/product-types/{id}`               | Soft delete        |
| POST   | `/api/v1/hq/{brandSlug}/product-types/{id}/restore`       | Restore            |
| GET    | `/api/v1/hq/{brandSlug}/product-types/lookup`             | Lookup list        |
| POST   | `/api/v1/hq/{brandSlug}/product-types/{id}/toggle-status` | Toggle is_active   |
| POST   | `/api/v1/hq/{brandSlug}/product-types/bulk-delete`        | Bulk delete        |

### Import / Export

| Method | Endpoint                                | Description  |
| ------ | --------------------------------------- | ------------ |
| GET    | `/api/v1/hq/{brandSlug}/product-types/import/template` | CSV template |
| POST   | `/api/v1/hq/{brandSlug}/product-types/import`          | Import CSV   |
| GET    | `/api/v1/hq/{brandSlug}/product-types/export`          | Export CSV   |

### Fields

| Field                  | Type    | Required | Description                               |
| ---------------------- | ------- | -------- | ----------------------------------------- |
| `code`                 | string  | Yes      | Unique per organization (e.g. "BEVERAGE") |
| `name`                 | string  | Yes      | Display name                              |
| `description`          | text    | No       | Optional description                      |
| `product_form`         | enum    | Yes      | `physical` or `digital`                   |
| `has_recipe`           | boolean | No       | Whether SKUs of this type use recipes     |
| `is_inventory_tracked` | boolean | No       | Whether to track stock levels             |
| `icon`                 | string  | No       | Optional icon identifier                  |
| `is_active`            | boolean | No       | Active status                             |

---

## 3. Categories

### Endpoints

| Method | Endpoint                             | Description                                      |
| ------ | ------------------------------------ | ------------------------------------------------ |
| GET    | `/api/v1/hq/{brandSlug}/categories`                 | List (filters: search, parent_id, is_active, with_trashed) |
| POST   | `/api/v1/hq/{brandSlug}/categories`                 | Create                                           |
| GET    | `/api/v1/hq/{brandSlug}/categories/{id}`            | Get with parent and children                     |
| PUT    | `/api/v1/hq/{brandSlug}/categories/{id}`            | Update                                           |
| DELETE | `/api/v1/hq/{brandSlug}/categories/{id}`            | Soft delete                                      |
| POST   | `/api/v1/hq/{brandSlug}/categories/{id}/restore`    | Restore                                          |
| GET    | `/api/v1/hq/{brandSlug}/categories/lookup`          | Flat lookup list                                 |
| POST   | `/api/v1/hq/{brandSlug}/categories/bulk-delete`     | Bulk delete                                      |

### Import / Export

| Method | Endpoint                            | Description  |
| ------ | ----------------------------------- | ------------ |
| GET    | `/api/v1/hq/{brandSlug}/categories/import/template`| CSV template |
| POST   | `/api/v1/hq/{brandSlug}/categories/import`         | Import CSV   |
| GET    | `/api/v1/hq/{brandSlug}/categories/export`         | Export CSV   |

### Fields

| Field        | Type   | Required | Description                                  |
| ------------ | ------ | -------- | -------------------------------------------- |
| `name`       | string | Yes      | Category name                                |
| `sku`        | string | No       | Unique per org                               |
| `slug`       | string | No       | URL-friendly name                            |
| `description`| text   | No       | Optional description                         |
| `image_url`  | string | No       | Optional image URL                           |
| `is_active`  | bool   | No       | Default true                                 |
| `parent_id`  | uuid   | No       | Self-referencing FK for hierarchy (nullable) |

Categories support a parent/child tree structure via `parent_id`. Root categories have `parent_id = null`.

---

## 4. Product Options

A `ProductOption` defines one of up to three axes a product varies along (Size, Color, Material). See [Product Domain](../explanation/product-domain.md#product-option) for the model.

### Endpoints

| Method | Endpoint                                       | Description                  |
| ------ | ---------------------------------------------- | ---------------------------- |
| GET    | `/api/v1/hq/{brandSlug}/products/{productId}/options`         | List options for a product   |
| POST   | `/api/v1/hq/{brandSlug}/products/{productId}/options`         | Create option under product  |
| GET    | `/api/v1/hq/{brandSlug}/product-options/{id}`                 | Get option detail            |
| PUT    | `/api/v1/hq/{brandSlug}/product-options/{id}`                 | Update option                |
| DELETE | `/api/v1/hq/{brandSlug}/product-options/{id}`                 | Soft delete option           |

### Fields

| Field       | Type    | Required | Description                                                                           |
| ----------- | ------- | -------- | ------------------------------------------------------------------------------------- |
| `key`       | string  | Yes      | Internal slug, e.g. `size`. Immutable once any SKU references the option              |
| `name`      | string  | Yes      | Display label. Translatable                                                           |
| `position`  | integer | Yes      | `1`, `2`, or `3`. Immutable once any SKU references the option                        |
| `is_active` | boolean | No       | Default `true`                                                                        |

> **Important:** `position` and `key` are immutable once any `ProductSku` references the option, because both feed into the SKU's `option_value{N}_id` slot mapping. Reassigning them silently breaks data integrity.

### Request Example

#### Create Option

**POST /api/v1/hq/{brandSlug}/products/{productId}/options**

```json
{
  "key": "size",
  "name": {"ja": "サイズ", "en": "Size"},
  "position": 1,
  "is_active": true
}
```

---

## 5. Product Option Values

A `ProductOptionValue` is one concrete value along an option's axis (e.g. `S`, `M`, `L`). See [Product Domain](../explanation/product-domain.md#product-option-value).

### Endpoints

| Method | Endpoint                                            | Description               |
| ------ | --------------------------------------------------- | ------------------------- |
| GET    | `/api/v1/hq/{brandSlug}/product-options/{optionId}/values`         | List values for an option |
| POST   | `/api/v1/hq/{brandSlug}/product-options/{optionId}/values`         | Create value              |
| GET    | `/api/v1/hq/{brandSlug}/product-option-values/{id}`                | Get value detail          |
| PUT    | `/api/v1/hq/{brandSlug}/product-option-values/{id}`                | Update value              |
| DELETE | `/api/v1/hq/{brandSlug}/product-option-values/{id}`                | Soft delete value         |

### Fields

| Field       | Type    | Required | Description                                            |
| ----------- | ------- | -------- | ------------------------------------------------------ |
| `value`     | string  | Yes      | Internal slug, e.g. `s`, `red`. Avoid changing if SKUs reference it |
| `label`     | string  | Yes      | Display label. Translatable                            |
| `position`  | integer | No       | Display order (default `0`)                            |
| `is_active` | boolean | No       | Default `true`                                         |

> **Delete protection:** `DELETE` returns `409` (or `422`) if any active `ProductSku` references the value. Clean up SKUs first. The underlying constraint is `ON DELETE RESTRICT` at the database level.

---

## 6. Product SKUs

A `ProductSku` is the stockable unit. Inventory, recipes, costs, and selling units all live here. See [Product Domain](../explanation/product-domain.md#product-sku) for the full model.

### Endpoints

| Method | Endpoint                                  | Description                                    |
| ------ | ----------------------------------------- | ---------------------------------------------- |
| GET    | `/api/v1/hq/{brandSlug}/products/{productId}/skus`       | List SKUs for a product                        |
| POST   | `/api/v1/hq/{brandSlug}/products/{productId}/skus`       | Create SKU under a product                     |
| GET    | `/api/v1/hq/{brandSlug}/skus`                            | List all SKUs (org-wide)                       |
| GET    | `/api/v1/hq/{brandSlug}/skus/lookup`                     | Lightweight lookup (supports `include_ids`)    |
| GET    | `/api/v1/hq/{brandSlug}/skus/{id}`                       | Get SKU detail                                 |
| PUT    | `/api/v1/hq/{brandSlug}/skus/{id}`                       | Update SKU                                     |
| DELETE | `/api/v1/hq/{brandSlug}/skus/{id}`                       | Soft delete                                    |
| POST   | `/api/v1/hq/{brandSlug}/skus/{id}/restore`               | Restore                                        |
| POST   | `/api/v1/hq/{brandSlug}/skus/{id}/toggle-status`         | Toggle `is_active`                             |
| GET    | `/api/v1/hq/{brandSlug}/skus/{id}/check-usage`           | Check if used by materials, menus, or stock    |

### Import / Export

| Method | Endpoint                          | Description  |
| ------ | --------------------------------- | ------------ |
| GET    | `/api/v1/hq/{brandSlug}/skus/import/template`    | CSV template |
| POST   | `/api/v1/hq/{brandSlug}/skus/import`             | Import CSV   |
| GET    | `/api/v1/hq/{brandSlug}/skus/export`             | Export CSV   |

### Fields

| Field                | Type    | Required | Description                                                      |
| -------------------- | ------- | -------- | ---------------------------------------------------------------- |
| `sku`                | string  | No       | Unique stock-keeping code (nullable; default SKU may be `null`)  |
| `name`               | string  | No       | Variant name (UI falls back to product name when null)           |
| `option_value1_id`   | uuid    | No       | Value for the option at `position = 1`                           |
| `option_value2_id`   | uuid    | No       | Value for the option at `position = 2`                           |
| `option_value3_id`   | uuid    | No       | Value for the option at `position = 3`                           |
| `recipe_id`          | uuid    | No       | Link to recipe for cost calculation                              |
| `recipe_multiplier`  | decimal | No       | Scales recipe quantities (default `1.0`)                         |
| `cost_price`         | decimal | No       | Final cost price                                                 |
| `cost_price_auto`    | decimal | No       | Auto-calculated from recipe (read-only on input)                 |
| `is_cost_override`   | boolean | No       | When `true`, `cost_price` is preserved from auto-recompute       |
| `is_active`          | boolean | No       | Default `true`                                                   |

> **Service-managed fields:** `option_signature` is computed by `ProductSkuService` from the three `option_value{N}_id` fields. Never set it from a client. Validation also enforces that each `option_value{N}_id` belongs to the option at `position = N` of the same product.

### Cost Calculation Logic

```text
if recipe exists:
    cost_price_auto = recipe.material.calculated_cost * recipe_multiplier
else:
    cost_price_auto = 0

if is_cost_override:
    final cost = cost_price        (manually entered, persists)
else:
    final cost = cost_price_auto   (auto-tracked)
```

### Check Usage Response

**GET /api/v1/hq/{brandSlug}/skus/{id}/check-usage**

```json
{
  "in_use": true,
  "used_by": {
    "materials": [{ "id": "...", "name": "Wheat flour" }],
    "menu_products": [{ "id": "...", "menu_name": "Main menu" }],
    "stock_levels": [{ "warehouse_id": "...", "quantity_base": 12 }]
  }
}
```

### Request Example

#### Create per-combination SKU

**POST /api/v1/hq/{brandSlug}/products/{productId}/skus**

```json
{
  "sku": "TS-S-RED",
  "name": "T-Shirt Size S Red",
  "option_value1_id": "019e1234-...",
  "option_value2_id": "019e5678-...",
  "cost_price": 5000,
  "is_cost_override": true
}
```

The service computes `option_signature = "<value1>|<value2>|"` and validates the value-position mapping before insert. The unique index `(product_id, option_signature)` then guarantees no duplicate combination.

---

## 7. SKU Units

A `VariantUnit` (table `variant_units`) defines a selling unit for a SKU. See [Product Domain](../explanation/product-domain.md#variant-unit).

### Endpoints

| Method | Endpoint                          | Description           |
| ------ | --------------------------------- | --------------------- |
| GET    | `/api/v1/hq/{brandSlug}/skus/{skuId}/units`      | List units for a SKU  |
| POST   | `/api/v1/hq/{brandSlug}/skus/{skuId}/units`      | Create unit           |
| GET    | `/api/v1/hq/{brandSlug}/sku-units/{id}`          | Get unit detail       |
| PUT    | `/api/v1/hq/{brandSlug}/sku-units/{id}`          | Update unit           |
| DELETE | `/api/v1/hq/{brandSlug}/sku-units/{id}`          | Delete unit           |
| POST   | `/api/v1/hq/{brandSlug}/sku-units/{id}/set-base` | Set as the base unit  |

### Fields

| Field        | Type    | Required | Description                                          |
| ------------ | ------- | -------- | ---------------------------------------------------- |
| `unit`       | string  | Yes      | Unit name (e.g., "piece", "kg", "dozen")             |
| `ratio`      | decimal | Yes      | Conversion ratio to base unit (e.g., 12 for dozen)   |
| `sku`        | string  | No       | SKU code for this unit                               |
| `barcode`    | string  | No       | Optional barcode                                     |
| `price`      | decimal | No       | Selling price for this unit                          |
| `is_base`    | boolean | No       | Mark as base unit (exactly one per SKU)              |
| `is_sellable`| boolean | No       | Whether this unit can be sold                        |

---

## 8. Materials

### Endpoints

| Method | Endpoint                             | Description             |
| ------ | ------------------------------------ | ----------------------- |
| GET    | `/api/v1/hq/{brandSlug}/materials`                  | List materials          |
| POST   | `/api/v1/hq/{brandSlug}/materials`                  | Create material         |
| GET    | `/api/v1/hq/{brandSlug}/materials/{id}`             | Get detail              |
| PUT    | `/api/v1/hq/{brandSlug}/materials/{id}`             | Update                  |
| DELETE | `/api/v1/hq/{brandSlug}/materials/{id}`             | Delete                  |
| GET    | `/api/v1/hq/{brandSlug}/materials/lookup`           | Lookup list             |
| GET    | `/api/v1/hq/{brandSlug}/materials/{id}/check-usage` | Check usage             |
| POST   | `/api/v1/hq/{brandSlug}/materials/bulk-delete`      | Bulk delete             |

### Import / Export

| Method | Endpoint                             | Description  |
| ------ | ------------------------------------ | ------------ |
| GET    | `/api/v1/hq/{brandSlug}/materials/import/template`  | CSV template |
| POST   | `/api/v1/hq/{brandSlug}/materials/import`           | Import CSV   |
| GET    | `/api/v1/hq/{brandSlug}/materials/export`           | Export CSV   |

### Fields

| Field               | Type    | Required | Description                                       |
| ------------------- | ------- | -------- | ------------------------------------------------- |
| `name`              | string  | Yes      | Material name                                     |
| `sku`               | string  | No       | Unique per org                                    |
| `description`       | text    | No       | Optional description                              |
| `components`        | json    | No       | List of SKU/material components and quantities    |
| `yield_quantity`    | decimal | No       | How much one batch produces                       |
| `yield_unit`        | string  | No       | Unit of output                                    |
| `calculated_cost`   | decimal | No       | Auto-computed from component costs (read-only)    |
| `is_active`         | boolean | No       | Active status                                     |
| `output_sku_id`     | uuid    | No       | Which `ProductSku` this material produces         |

### Circular Reference Detection

Materials can reference other materials and SKUs as components. Create/update
returns `422` if a cycle is detected (A -> B -> C -> A) —
`App\Exceptions\CircularReferenceException`, thrown from
`Api/V1/HQ/MaterialController`.

> ⚠️ Dòng này từng quy hành vi cho `MaterialGraphService`. Class đó **chưa bao
> giờ tồn tại** (#2049) — hành vi 422 có thật, tên thì không.

---

## 9. Recipes

### Endpoints

| Method | Endpoint                         | Description                       |
| ------ | -------------------------------- | --------------------------------- |
| GET    | `/api/v1/hq/{brandSlug}/recipes`                | List recipes                      |
| POST   | `/api/v1/hq/{brandSlug}/recipes`                | Create recipe                     |
| GET    | `/api/v1/hq/{brandSlug}/recipes/{id}`           | Get detail                        |
| PUT    | `/api/v1/hq/{brandSlug}/recipes/{id}`           | Update                            |
| DELETE | `/api/v1/hq/{brandSlug}/recipes/{id}`           | Soft delete                       |
| POST   | `/api/v1/hq/{brandSlug}/recipes/{id}/restore`   | Restore                           |
| GET    | `/api/v1/hq/{brandSlug}/recipes/lookup`         | Lookup list (supports `include_ids`) |
| POST   | `/api/v1/hq/{brandSlug}/recipes/bulk-delete`    | Bulk delete                       |

### Import / Export

| Method | Endpoint                          | Description  |
| ------ | --------------------------------- | ------------ |
| GET    | `/api/v1/hq/{brandSlug}/recipes/import/template` | CSV template |
| POST   | `/api/v1/hq/{brandSlug}/recipes/import`          | Import CSV   |
| GET    | `/api/v1/hq/{brandSlug}/recipes/export`          | Export CSV   |

### Fields

| Field               | Type    | Required | Description                          |
| ------------------- | ------- | -------- | ------------------------------------ |
| `name`              | string  | Yes      | Recipe name                          |
| `sku`               | string  | No       | Unique per org                       |
| `description`       | text    | No       | Optional description                 |
| `material_id`       | uuid    | No       | Output material (nullable)           |
| `output_quantity`   | decimal | No       | Output quantity per batch            |
| `output_unit`       | string  | No       | Unit of output                       |
| `ingredients`       | json    | No       | Component list (SKU/material IDs and quantities) |
| `preparation_time`  | integer | No       | Time in minutes                      |
| `instructions`      | text    | No       | Free-text instructions               |
| `is_active`         | boolean | No       | Active status                        |

Circular reference detection also applies to recipes.

---

## 10. Menus

### Branch Menu Endpoints

| Method | Endpoint                            | Description                                   |
| ------ | ----------------------------------- | --------------------------------------------- |
| GET    | `/api/v1/hq/{brandSlug}/menus`                     | List branch menus                             |
| POST   | `/api/v1/hq/{brandSlug}/menus`                     | Create branch menu                            |
| GET    | `/api/v1/hq/{brandSlug}/menus/{id}`                | Get menu with products                        |
| PUT    | `/api/v1/hq/{brandSlug}/menus/{id}`                | Update menu                                   |
| DELETE | `/api/v1/hq/{brandSlug}/menus/{id}`                | Soft delete                                   |
| POST   | `/api/v1/hq/{brandSlug}/menus/{id}/restore`        | Restore                                       |
| GET    | `/api/v1/hq/{brandSlug}/menus/lookup`              | Lookup (optional `?branch_id=`)               |
| GET    | `/api/v1/hq/{brandSlug}/menus/current`             | Current active menu for branch (public)       |
| POST   | `/api/v1/hq/{brandSlug}/menus/bulk-delete`         | Bulk delete                                   |

### Menu Status Transitions

| Method | Endpoint                         | Description          |
| ------ | -------------------------------- | -------------------- |
| POST   | `/api/v1/hq/{brandSlug}/menus/{id}/submit`      | draft -> pending     |
| POST   | `/api/v1/hq/{brandSlug}/menus/{id}/approve`     | pending -> approved  |
| POST   | `/api/v1/hq/{brandSlug}/menus/{id}/reject`      | pending -> rejected  |
| POST   | `/api/v1/hq/{brandSlug}/menus/{id}/activate`    | approved -> active   |
| POST   | `/api/v1/hq/{brandSlug}/menus/{id}/deactivate`  | active -> inactive   |

### Master Menu Endpoints

| Method | Endpoint                              | Description                          |
| ------ | ------------------------------------- | ------------------------------------ |
| GET    | `/api/v1/hq/{brandSlug}/master-menus`                | List master menus                    |
| POST   | `/api/v1/hq/{brandSlug}/master-menus`                | Create master menu                   |
| GET    | `/api/v1/hq/{brandSlug}/master-menus/lookup`         | Lookup for forms                     |
| POST   | `/api/v1/hq/{brandSlug}/menus/{id}/clone-to-branch`  | Clone master to branch menu          |
| GET    | `/api/v1/hq/{brandSlug}/menus/{id}/check-sync`       | Check if new master products available |
| POST   | `/api/v1/hq/{brandSlug}/menus/{id}/sync-from-master` | Sync new products from master        |

### Menu Product Endpoints

| Method | Endpoint                                                    | Description                  |
| ------ | ----------------------------------------------------------- | ---------------------------- |
| POST   | `/api/v1/hq/{brandSlug}/menus/{menu}/products`              | Add products to menu         |
| DELETE | `/api/v1/hq/{brandSlug}/menus/{menu}/products/{menuProduct}`| Remove product from menu     |
| POST   | `/api/v1/hq/{brandSlug}/menus/{menu}/products/{menuProduct}/toggle` | Toggle product is_active |
| PUT    | `/api/v1/hq/{brandSlug}/menus/{menu}/products/reorder`      | Reorder products             |

### Menu List Filters

| Parameter        | Type    | Description                    |
| ---------------- | ------- | ------------------------------ |
| `search`         | string  | Search by menu name            |
| `status`         | string  | Filter by status               |
| `branch_id`      | uuid    | Filter by branch               |
| `master_menu_id` | uuid    | Filter clones of a master      |
| `with_trashed`   | boolean | Include soft-deleted           |

### Menu Fields

| Field            | Type     | Required | Description                                      |
| ---------------- | -------- | -------- | ------------------------------------------------ |
| `name`           | string   | Yes      | Menu name                                        |
| `description`    | text     | No       | Optional description                             |
| `branch_id`      | uuid     | Yes      | Branch this menu belongs to                      |
| `valid_from`     | datetime | No       | Start of valid period                            |
| `valid_to`       | datetime | No       | End of valid period                              |
| `priority`       | integer  | No       | Lower = higher priority. Unique per branch       |
| `status`         | enum     | No       | Draft, Pending, Approved, Active, Inactive, Rejected |
| `is_master`      | boolean  | No       | True for master menus                            |
| `master_menu_id` | uuid     | No       | Source master menu (for cloned menus)            |
| `last_synced_at` | datetime | No       | When last synced from master                     |

### MenuProduct Fields

| Field                 | Type    | Required | Description                                   |
| --------------------- | ------- | -------- | --------------------------------------------- |
| `product_sku_id`      | uuid    | Yes      | Which `ProductSku` this product represents    |
| `selling_price`       | decimal | Yes      | Price in this menu                            |
| `availability`        | enum    | No       | `Available`, `Unavailable`, `OutOfStock`      |
| `display_order`       | integer | No       | Sort order in menu                            |
| `is_price_overridden` | boolean | No       | True if price differs from master             |
| `master_price`        | decimal | No       | Original price from master menu               |
| `master_item_id`      | uuid    | No       | Reference to parent product in master menu    |

### Request Examples

#### Clone Master Menu

**POST /api/v1/hq/{brandSlug}/menus/{masterId}/clone-to-branch**

```json
{
  "branch_id": "019e8a3b-...",
  "name": "Branch SG Menu",
  "description": "Cloned from master"
}
```

Creates a branch menu with all products from the master. Status is set to Draft. Each product gets a `master_item_id` linking to its source.

#### Check Sync

**GET /api/v1/hq/{brandSlug}/menus/{cloneId}/check-sync**

```json
{
  "has_new_products": true,
  "new_products_count": 3,
  "new_products": [
    { "id": "...", "sku_name": "Milk Tea Size M", "selling_price": 45000 }
  ]
}
```

#### Get Current Menu (Public)

**GET /api/v1/hq/{brandSlug}/menus/current?branch_id=xxx**

Returns the active menu with the highest priority (lowest number) for the branch, within its valid date range. Used by POS and customer-facing applications.

### Menu Status Workflow

```text
  Draft --submit--> Pending --approve--> Approved --activate--> Active <--> Inactive
                       |
                       | reject
                       v
                    Rejected --> edit --> Draft
```

---

## Enums

### Product Status

| Value      | Description                     |
| ---------- | ------------------------------- |
| `draft`    | Initial state, editable         |
| `pending`  | Submitted, awaiting approval    |
| `approved` | Approved, ready to activate     |
| `active`   | Live and in use                 |
| `inactive` | Deactivated                     |
| `rejected` | Rejected, returns to editable   |

### Product Form (ProductType)

| Value      | Description      |
| ---------- | ---------------- |
| `physical` | Physical product |
| `digital`  | Digital product  |

### Menu Status

| Value      | Description                     |
| ---------- | ------------------------------- |
| `Draft`    | Initial state, editable         |
| `Pending`  | Submitted, awaiting approval    |
| `Approved` | Approved, ready to activate     |
| `Active`   | Live and in use                 |
| `Inactive` | Deactivated                     |
| `Rejected` | Rejected, returns to editable   |

### Menu Product Availability

| Value         | Description                                |
| ------------- | ------------------------------------------ |
| `Available`   | Product available for order                |
| `Unavailable` | Intentionally hidden (e.g., seasonal)      |
| `OutOfStock`  | Cannot fulfill due to stock shortage       |
