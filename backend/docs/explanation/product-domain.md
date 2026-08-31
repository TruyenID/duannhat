---
title: Product Domain
category: explanation
tags: [brand, product, option, sku, variant-unit, category, material, recipe, menu, menu-product, menu-product-sku, cost, pricing, translatable, i18n]
summary: Explains the product management domain -- brands, product types, products, options, SKUs, selling units, categories, materials, recipes, and menus -- including the option/SKU model that replaced the legacy ProductVariant entity, the two-level menu structure (MenuProduct + MenuProductSku), translatable fields, lifecycle, and business rules for each entity.
related: [product-workflow, api-product, inventory-domain, authorization]
---

# Product Domain

This document explains the product management domain -- brands, products, options, SKUs, categories, materials, recipes, and menus. Read this before implementing any product-related feature.

## Overview

The product management system handles the full product lifecycle, from drafting to active sales. A `Product` is a conceptual catalog entry; the actual stockable units are `ProductSku` rows generated from up to three `ProductOption` axes (Size, Color, Material, …). Each SKU can have multiple selling units (single, pack, carton) and an optional production recipe that links materials to finished goods. All product-domain entities are scoped to a Brand, which represents a business identity within an Organization.

> **Note:** This model replaced the legacy `ProductVariant` entity. There is no longer a `product_variants` table -- everywhere the old model said "variant", the system now uses `ProductSku` for the stockable unit and `ProductOption`/`ProductOptionValue` for the axes that generate it.

---

## Brand

### What it is

A Brand is a business identity within an Organization. One Organization owns multiple Brands. All product-domain entities -- Products, Materials, Recipes, Menus, and Categories -- are scoped to a specific Brand via `brand_id`.

Brand is a tenant-scoped domain entity keyed to Platform identifiers. Platform owns workforce service access; Tempo owns its local operational brand data.

### How it works

**Organization-Brand-Branch hierarchy:**

```text
Organization (DXS Corp)
+-- Brand (Coffee House)
|   +-- Branch / Shop (Shibuya Store)
|   +-- Branch / Shop (Shinjuku Store)
+-- Brand (Tea Garden)
    +-- Branch / Shop (Ginza Store)
```

- One Organization owns multiple Brands (1:N)
- One Brand owns multiple Branches (1:N). A Branch is also called a Shop
- Products, Materials, Recipes, Categories, and Menus belong to a Brand
- Menus are further scoped to a Branch (Shop) within the Brand

**Scoping rules:**

| Entity | Scoped to |
| ------ | --------- |
| Product, ProductType | Brand |
| ProductOption, ProductOptionValue, ProductSku | Brand (via Product) |
| Category | Brand |
| Material | Brand |
| Recipe | Brand |
| Menu (master) | Brand |
| Menu (branch) | Brand + Branch (Shop) |

### Example

Organization "DXS Corp" has two brands:
- "Coffee House" -- manages coffee products, recipes, and menus for its 5 shops
- "Tea Garden" -- manages tea products, recipes, and menus for its 3 shops

Each brand maintains its own catalog. A product in "Coffee House" is invisible to "Tea Garden."

---

## Product Type

### What it is

A product type classifies products by business characteristics. Each type determines which product group an item belongs to and how the system processes it.

**Examples:** Beverages, Prepared Food, Raw Materials, Digital Products

### How it works

Each product type defines key behavioral flags:

| Attribute | Field | Values | Description |
| --------- | ----- | ------ | ----------- |
| Product form | `product_form` | `physical`, `digital` | Physical items track inventory; digital items do not |
| Has recipe | `has_recipe` | `true`, `false` | Whether SKUs of this type require a production recipe |
| Inventory tracked | `is_inventory_tracked` | `true`, `false` | Whether the system manages stock for this type |

### Example

- Type "Beverages": `has_recipe = true`, `is_inventory_tracked = true`
- Type "Voucher": `product_form = digital`, `is_inventory_tracked = false`

---

## Product

### What it is

A product is the conceptual catalog entry -- it represents an item in the business catalog.

**Examples:** "Black Coffee", "Bubble Milk Tea", "Banh Mi Sandwich"

A product is **not directly sellable**. Customers buy a `ProductSku` of a product. Even a product with no options has at least one default SKU.

### How it works

`name` and `description` are translatable through `astrotomic/laravel-translatable`. The translations live in the `product_translations` table; the locale fallback chain is `ja → en` (configured in `omnify.yaml`). See [Translatable fields](#translatable-fields).

**Status lifecycle:**

| Status | Meaning | Editable? |
| ------ | ------- | --------- |
| `draft` | Being drafted, not yet submitted for approval | Yes |
| `pending` | Submitted, awaiting approval from an authorized person | No |
| `approved` | Approved, ready for business use | No |
| `active` | Currently in business, visible on menu/POS | No |
| `inactive` | Temporarily suspended (data retained) | No |
| `rejected` | Rejected with a reason, needs revision | Yes |

```text
draft ──submit──> pending ──approve──> approved ──activate──> active
                    |                                           |
                    v                                           v
                 rejected                                   inactive
                    |
                    v
                  draft (revise and resubmit)
```

> **Note:** The approval workflow is **transitionally disabled in the schema**. The status enum still carries all values, and `ProductController` still exposes `submit-for-approval`, `approve`, `reject`, `activate`, and `deactivate` routes, but the supporting columns (`approved_by_id`, `approved_at`, `rejected_by_id`, `rejected_at`, `rejection_reason`) have been removed from `Product.yaml`. Treat the workflow as **partially wired** until those fields are restored. See [Product Workflow](product-workflow.md).

**Approval rules (when re-enabled):**

- Only users with the `org-manager` or `org-admin` role can approve products
- The creator cannot approve their own product
- Rejection requires a reason
- A rejected product returns to an editable state and can be resubmitted

---

## Category

### What it is

A category groups products by business function. Categories support a parent-child tree hierarchy.

### How it works

- A product can belong to **multiple categories** (many-to-many relationship)
- Root categories have `parent_id = null`
- Child categories inherit from their parent
- When **all** categories assigned to a product become inactive, the product's `is_hidden` flag flips to `true` (`CategoryObserver`)

### Example

```text
Beverages
+-- Coffee
|   +-- Hot Coffee
|   +-- Iced Coffee
+-- Tea
+-- Juice

Food
+-- Pastries
+-- Main Dishes
```

---

## Product Option

### What it is

A `ProductOption` is one **axis** along which a product varies (Size, Color, Material). Each product can have **at most three** options. The cap is intentional -- it keeps `ProductSku` flat (three FK columns instead of a pivot table), following Shopify's option model.

### How it works

| Field | Purpose |
| ----- | ------- |
| `key` | Internal slug, e.g. `size`, `color`. Used by code, not shown to users. **Immutable** once any SKU references the option. |
| `name` | Display label (translatable, lives in `product_option_translations`). |
| `position` | `1`, `2`, or `3`. Maps 1-to-1 to `ProductSku.option_value{N}_id`. **Immutable** once any SKU references the option. |
| `is_active` | Soft-disable an option without deleting it. |

**Position rules (critical):**

- `position` is unique per product. If `Size` is at position 1, `Color` cannot also be at position 1.
- Once any `ProductSku` references the option, **never reassign `position`** -- doing so silently breaks the `option_value{N}_id` slot mapping. Migration is the only safe way to reorder.
- Adding a new option later (e.g. introducing `Material` at position 3) is safe. Existing SKUs keep `option_value3_id = NULL` and remain valid.

### Example

Product "T-Shirt" has two options:

| Position | Key | Display name |
| -------- | --- | ------------ |
| 1 | `size` | Size |
| 2 | `color` | Color |

---

## Product Option Value

### What it is

A `ProductOptionValue` is one concrete value along an option's axis -- e.g. `S`, `M`, `L` for the `size` option, or `red`, `blue`, `green` for `color`.

### How it works

| Field | Purpose |
| ----- | ------- |
| `value` | Internal slug, e.g. `s`, `red`. Avoid changing once any SKU references the value. |
| `label` | Display label (translatable, lives in `product_option_value_translations`). |
| `position` | Display order in the option's value list (e.g. `S → M → L`). |
| `is_active` | Soft-disable a value. |

**Delete protection:** `ProductSku.option_value{N}` uses `ON DELETE RESTRICT`, so the database refuses to delete a value while any SKU still references it. The application layer additionally uses soft delete and a usage check.

The reason RESTRICT is preferred over `SET NULL`: nulling out a value would silently turn a "Red XL Shirt" into a "NULL XL Shirt" while inventory and order rows still reference the SKU. RESTRICT forces the operator to clean up SKUs first, keeping data consistent.

### Example

Option `Size` has three values:

| Position | Value | Label |
| -------- | ----- | ----- |
| 1 | `s` | S |
| 2 | `m` | M |
| 3 | `l` | L |

---

## Product SKU

### What it is

A `ProductSku` is the **stockable unit**. Inventory, recipes, costs, and selling units all live on the SKU, not the product. Every product has at least one SKU; products without options get an automatically created **default SKU** at creation time.

### How it works

| Field | Purpose |
| ----- | ------- |
| `sku` | Stock-keeping code (nullable). The default SKU may have `sku = NULL`. |
| `name` | Variant name (nullable; UI falls back to the parent product's name). |
| `option_value1_id` / `_2_id` / `_3_id` | FKs into `ProductOptionValue`. Each `option_value{N}_id` must reference a value belonging to the option at `position = N` of the same product. Enforced in `ProductSkuService`, not in YAML. |
| `option_signature` | Computed canonical string `"<val1_id>|<val2_id>|<val3_id>"` (NULL → ""). Used for the NULL-safe unique index `(product_id, option_signature)`. Always set by the service before save -- never write it from a controller or factory. |
| `recipe_id` | Optional link to a `Recipe` for cost calculation. `ON DELETE SET NULL`. |
| `recipe_multiplier` | Scales recipe quantities (e.g. Size S = 0.8, Size L = 1.2). |
| `cost_price_auto` | Auto-calculated by `RecipeObserver::recompute` from recipe materials. |
| `cost_price` | Effective cost used by reports and P&L. |
| `is_cost_override` | When `false`, `cost_price = cost_price_auto`. When `true`, the manual `cost_price` is preserved and auto-recompute is suppressed. |
| `selling_price` | The canonical selling price for this SKU. Used as the source of truth when cloning menus to branches. Brand menus read this price directly; branch menus snapshot it into `menu_product_skus.selling_price` at clone time. |
| `is_active` | Soft-disable an SKU without deleting it. |

**SKU creation paths:**

1. **Default SKU.** `ProductService::create()` automatically creates one SKU with all `option_value{N}_id = NULL` and `option_signature = ""`. Used for products with no options.
2. **Per-combination SKU.** `ProductSkuService::create([...])` builds an SKU from one specific combination of option values. The service computes `option_signature` and validates that each `option_value{N}_id` belongs to the option at `position = N` of the same product.
3. **Cartesian generation.** `ProductSkuService::generateMissingCombinations($product)` fills in every option combination that does not yet exist as an SKU. Used by the UI when an option is added or a value is created.

**Adding a new option after SKUs already exist:** Existing SKUs keep `option_value3_id = NULL` and remain valid. New SKUs can supply all three values. The signatures `"S|red|"` and `"S|red|cotton"` are distinct, so the unique index does not conflict.

**Common pitfalls:**

- Never insert into `product_skus` directly bypassing `ProductSkuService` -- the missing `option_signature` will either fail the unique index or silently allow a duplicate.
- Never use the `sku` code as a business key. It is nullable and not globally unique. The primary key is always `id` (UUID).
- Never edit `cost_price` while `is_cost_override = false` and expect the change to persist -- the next recipe recompute will overwrite it. Set `is_cost_override = true` first.

### Example

Product "T-Shirt" with options `Size [S, M, L]` × `Color [Red, Blue]` produces six SKUs:

| SKU code | option_value1 (Size) | option_value2 (Color) | cost_price | is_active |
| -------- | --------------------- | ---------------------- | ---------- | --------- |
| TS-S-RED | S | Red | 5,000 | true |
| TS-S-BLUE | S | Blue | 5,000 | true |
| TS-M-RED | M | Red | 5,500 | true |
| TS-M-BLUE | M | Blue | 5,500 | true |
| TS-L-RED | L | Red | 6,000 | true |
| TS-L-BLUE | L | Blue | 6,000 | true |

---

## Variant Unit

### What it is

A `VariantUnit` defines a selling unit for a SKU. Each SKU can be sold in multiple units (single, pack, carton) at different prices. Inventory is always tracked in the **base unit**.

### How it works

- **Base unit** (`is_base = true`): The reference unit for inventory. Each SKU has exactly one base unit. `StockLevel.quantity_base` is always expressed in this unit.
- **Conversion ratio** (`ratio`): The number of base units in one of this unit. `1 carton = 24 boxes` means `ratio = 24`. Selling one carton subtracts `24 × base` from stock.

### Example

SKU "Fresh Milk 500ml" can be sold as:

| Unit | Is base | Ratio | Selling price |
| ---- | ------- | ----- | ------------- |
| Box | Yes | 1 | 25,000 |
| Carton (24 boxes) | No | 24 | 550,000 |
| Pack (4 boxes) | No | 4 | 95,000 |

---

## Material

### What it is

A material is an input ingredient used to produce SKUs. Materials can be raw ingredients or semi-finished goods.

### How it works

**Components** (`components` JSON field): A material can be composed of other SKUs or materials. For example: "Coffee Mix" = 500g Coffee Beans + 100g Sugar.

**Auto-calculated cost** (`calculated_cost`): Automatically computed from component prices. If 500g Coffee Beans = 50,000 and 100g Sugar = 5,000, then `calculated_cost = 55,000`.

**Yield** (`yield_quantity` + `yield_unit`): How much one production batch produces. For example, `yield_quantity = 600`, `yield_unit = "g"` means one batch yields 600g.

**Output SKU** (`output_sku_id`): Optional link to the SKU produced by this material. Used by production orders to know which SKU to credit when a batch finishes.

**Circular reference prevention:** if A contains B, B contains C, and C
references A, create/update returns `422` with a cycle error
(`App\Exceptions\CircularReferenceException`, ném từ
`Api/V1/HQ/MaterialController`).

> ⚠️ Từng ghi là `MaterialGraphService` — class đó **chưa bao giờ tồn tại** (#2049).

### Example

| Material | Type | Components |
| -------- | ---- | ---------- |
| Coffee Beans | Raw ingredient | None |
| Sugar | Raw ingredient | None |
| Ground Coffee | Semi-finished | 500g Coffee Beans |
| Spice Blend | Semi-finished | Multiple raw ingredients |

---

## Recipe

### What it is

A recipe is a production instruction -- it connects materials to product SKUs. It defines what inputs are needed to produce a given output.

### How it works

Each recipe specifies:
- Input materials and quantities (`ingredients` JSON)
- Output quantity and unit (`output_quantity`, `output_unit`)
- Preparation time in minutes

**Recipe multiplier** (`recipe_multiplier` on `ProductSku`): When a SKU references a recipe, the multiplier scales the ingredient quantities. This allows different sizes to share the same base recipe.

### Example

Recipe "Black Coffee":
- Input: 15g ground coffee + 200ml hot water
- Output: 1 cup (`output_quantity = 1`, `output_unit = "cup"`)
- Preparation time: 3 minutes

Size scaling using `recipe_multiplier`:

| SKU | Multiplier | Ground Coffee Needed |
| --- | ---------- | --------------------- |
| Size S | 1.0 | 15g |
| Size M | 1.5 | 22.5g |
| Size L | 2.0 | 30g |

---

## Product-Option-SKU-Recipe-Material Relationships

The following diagram shows how a product links through its options and SKUs to recipes and materials:

```text
Product (Black Coffee)
+-- ProductOption (Size, position=1)
|   +-- ProductOptionValue (S)
|   +-- ProductOptionValue (M)
|   +-- ProductOptionValue (L)
+-- ProductSku (TS-S, option_value1 = S)
|   +-- recipe_id ───> Recipe (Black Coffee Recipe)
|   |                   +-- ingredients (JSON) ───> Material (Ground Coffee)
|   |                                                  +-- components (JSON) [Coffee Beans 500g]
|   +-- recipe_multiplier: 1.0
|   +-- cost_price_auto = material.calculated_cost x 1.0
+-- ProductSku (TS-M, option_value1 = M)
|   +-- recipe_id ───> (same recipe)
|   +-- recipe_multiplier: 1.5
|   +-- cost_price_auto = material.calculated_cost x 1.5
+-- ProductSku (TS-L, option_value1 = L)
    +-- recipe_multiplier: 2.0
    +-- cost_price_auto = material.calculated_cost x 2.0
```

### Cost price calculation

```text
Material.calculated_cost = sum(component.price x component.quantity)

ProductSku.cost_price_auto = recipe.material.calculated_cost x recipe_multiplier

If is_cost_override = true:
    Final cost = cost_price (manually entered)
Else:
    Final cost = cost_price_auto (auto-calculated)
```

---

## Translatable fields

TempoFast uses `astrotomic/laravel-translatable` for end-user-visible labels. Three product-domain models have translation tables today:

| Model | Translation table | Translatable fields |
| ----- | ----------------- | ------------------- |
| `Product` | `product_translations` | `name`, `description` |
| `ProductOption` | `product_option_translations` | `name` |
| `ProductOptionValue` | `product_option_value_translations` | `label` |

Each translation row is keyed by `(model_id, locale)`. Locale fallback follows the chain configured in `omnify.yaml` (currently `ja → en`).

When you write Eloquent code that updates a translatable model, use the `PreservesTranslatableColumns` trait. It avoids accidentally wiping translations from non-current locales when calling `$model->update([...])` with non-translation columns.

---

## Menu

### What it is

A menu is a collection of products available for sale at a branch, including selling prices and availability status. Each branch can have multiple menus (seasonal, time-based, etc.).

**Examples:**

- "Summer Menu 2026" (`valid_from`: Jun 1, `valid_to`: Aug 31)
- "Regular Menu" (no time restriction)

### How it works

#### Master menu vs branch menu

The system supports a **master-branch** model (one master, many branch clones):

**Master menu** (`is_master = true`):

- Created at the brand level
- Contains `MenuProduct` rows linking products to the menu
- Does **not** have `MenuProductSku` rows -- prices come from `product_skus.selling_price` live
- Not tied to a specific branch

**Branch menu** (`is_master = false`):

- Cloned from a master menu
- Tied to a specific branch (`branch_id`)
- Contains both `MenuProduct` rows **and** `MenuProductSku` rows
- SKU-level prices are snapshotted from `product_skus.selling_price` at clone time
- Can customize: SKU selling price, product/SKU availability, display order
- When the master adds new products, the branch can sync to pull them in

#### Two-level menu structure

The menu uses a two-level model instead of the legacy flat `MenuItem`:

| Table | Model | Level | Purpose |
| ----- | ----- | ----- | ------- |
| `menu_products` | `MenuProduct` | Product | Links a `Product` to a `Menu`. Carries `is_active` and `display_order`. |
| `menu_product_skus` | `MenuProductSku` | SKU | Links a `ProductSku` to a `MenuProduct`. Carries `selling_price`, `is_price_overridden`, and `is_active`. Only present in **branch menus**. |

**Brand menus** have only `menu_products` -- no SKU rows. The selling price shown to the UI is queried live from `product_skus.selling_price`.

**Branch menus** have both `menu_products` and `menu_product_skus`. The `selling_price` on `MenuProductSku` is the branch's effective price. When `is_price_overridden = false`, the price matches the canonical `product_skus.selling_price`; when `true`, the branch has overridden it.

There is no `master_price` column. To determine whether a branch price differs from the canonical price, the system queries `product_skus.selling_price` live.

#### Master-to-branch clone and sync

```text
Master Menu (brand-level)              Branch Menu (clone)
+--------------------+                 +--------------------+
| MenuProduct:       |---- clone ----> | MenuProduct:       |
|   Black Coffee     |                 |   Black Coffee     |
|   (no SKU rows)    |                 | MenuProductSku:    |
|                    |                 |   Size S  ¥300     | (from product_skus.selling_price)
|                    |                 |   Size M  ¥400     |
+--------------------+                 +--------------------+
| MenuProduct:       |---- clone ----> | MenuProduct:       |
|   Milk Tea         |                 |   Milk Tea         |
+--------------------+                 | MenuProductSku:    |
                                       |   Size M  ¥450     |
                                       |   Size L  ¥550     | (overridden, is_price_overridden=true)
                                       +--------------------+
| * Juice (new)      |--- sync ------> | Juice              | <-- syncFromMaster() adds
|                    |                 | MenuProductSku:    |
|                    |                 |   Size M  ¥400     | (from product_skus.selling_price)
+--------------------+                 +--------------------+
```

- **Clone flow:** For each `MenuProduct` in the master, create a `MenuProduct` in the branch. Then for each `ProductSku` belonging to that product, create a `MenuProductSku` row with `selling_price` copied from `product_skus.selling_price` and `is_price_overridden = false`.
- **Sync (`syncFromMaster()`):** Adds new products from the master that are not yet in the branch, along with their SKU entries.
- **Reset SKU price:** Copies `product_skus.selling_price` back into `menu_product_skus.selling_price` and sets `is_price_overridden = false`.

### Menu Product

Each `MenuProduct` represents one `Product` available within a menu. The link is `MenuProduct.product_id` (`ON DELETE CASCADE`).

| Field | Purpose |
| ----- | ------- |
| `product_id` | The product on this menu |
| `is_active` | Whether this product is currently available on the menu |
| `display_order` | Sort order within the menu |

### Menu Product SKU

Each `MenuProductSku` represents one `ProductSku` available within a `MenuProduct`. Only present in branch menus. The link is `MenuProductSku.product_sku_id` (`ON DELETE CASCADE`).

| Field | Purpose |
| ----- | ------- |
| `menu_product_id` | Parent `MenuProduct` |
| `product_sku_id` | The SKU being sold |
| `selling_price` | Per-branch selling price for this SKU |
| `is_price_overridden` | `false` at clone/reset time; `true` when the branch overrides the price |
| `is_active` | Whether this SKU is currently available on the menu |

### Menu priority

When a branch has multiple active menus, the system selects the menu by `priority` (lower number = higher priority). Priority is unique per branch.

**Example:**

- Lunar New Year Menu (`priority = 1`, valid Jan 1-15) -- displayed during the holiday
- Regular Menu (`priority = 10`, valid always) -- displayed when no higher-priority menu applies

### Menu approval

Menus follow the same approval workflow as products:

```text
draft ──submit──> pending ──approve──> approved ──activate──> active
                                                                |
                                                                v
                                                            inactive
```

A menu is only visible to customers when its status is `active` AND the current date falls within `valid_from` to `valid_to`.

### Shop-side menu management

Once HQ has cloned a master menu down to a branch (`menu.master_menu_id IS NOT NULL`), the shop can operate on that branch menu through a strict subset of write actions, without going back to HQ for routine changes:

- **Toggle product availability** — flip a `MenuProduct.is_active` on or off. This controls whether the entire product (all its SKUs) appears on the branch menu.
- **Toggle SKU availability** — flip a `MenuProductSku.is_active` on or off. This controls whether a specific SKU within a product is orderable. This is the daily-operations 86'd workflow and is allowed for any shop user including Shop Staff.
- **Override per-shop SKU selling price** — replace `MenuProductSku.selling_price` and set `is_price_overridden = true`. Restricted to Shop Manager and above.
- **Reset SKU price to canonical** — copy `product_skus.selling_price` back into `MenuProductSku.selling_price` and set `is_price_overridden = false`. Restricted to Shop Manager and above.
- **Sync from master** — pull new products (and their SKUs) from the master menu that are not yet in the branch clone. Restricted to Shop Manager and above.

What the shop **cannot** do from this surface: create new branch menus, add or remove products manually, change `display_order`, edit the master menu, or step through the approval workflow. Those actions remain on the HQ controller because they cross the brand boundary.

The implementation is a thin façade — `Shop\MenuController` reuses the existing `MenuService` rather than introducing a parallel domain object. See [API: Shop → Shop Menus](../reference/api-shop.md#5-shop-menus) for the endpoints and [Authorization → Menu Management](authorization.md#menu-management) for the role matrix.

---

## Import / Export

The system supports bulk import and export via CSV files for: Products, SKUs, Categories, Product Types, Materials, and Recipes.

**Workflow:**

1. Download a CSV template (sample file with correctly formatted headers)
2. Fill in the data
3. Upload the file (max 10MB)
4. The system validates each row
5. Valid rows are created/updated; invalid rows return an error file with messages

The system supports **partial success**: some rows can succeed while others fail.

---

## Overall relationship diagram

```text
Organization --1:N--> Brand --1:N--> Branch (Shop)
                        |
                        +-- ProductType --1:N--> Product --1:N--> ProductOption --1:N--> ProductOptionValue
                        |                           |                                          |
                        |                           |                                          | RESTRICT
                        |                           |                                          v
                        |                           +--1:N--> ProductSku ──────────────────> option_value{1,2,3}_id
                        |                                       |
                        |                                       +--1:N--> VariantUnit
                        |                                       +--N:1--> Recipe --N:1--> Material
                        |                                                                   |
                        |                                                                   | components (JSON)
                        |                                                                   v
                        |                                                          ProductSku / Material (nested)
                        |
                        +-- Category
                        |
                        +-- Menu (master) --clone--> Menu (branch, scoped to Branch/Shop)
                              |
                              +--1:N--> MenuProduct --N:1--> Product
                                            |
                                            +--1:N--> MenuProductSku --N:1--> ProductSku
                                                       (branch menus only)
```

See [Product Domain API Reference](../reference/api-product.md) for endpoint details and request/response formats.
