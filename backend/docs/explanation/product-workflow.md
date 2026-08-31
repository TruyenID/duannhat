---
title: Product Workflow
category: explanation
tags: [approval, status, workflow, draft, pending, approved, active, rejected, menu-sync, recipe-cost, sku]
summary: Explains the product status lifecycle (draft, pending, approved, active, rejected, cancelled), menu management rules, and recipe/material cost calculation mechanics with all valid transitions and business rules. Reflects the option/SKU model that replaced ProductVariant.
related: [product-domain, authorization]
---

# Product Workflow

This document explains the product lifecycle, menu management rules, and recipe/material cost mechanics. Read this before implementing any product status transition, menu synchronization, or cost calculation feature.

## Product Status Lifecycle

A product moves through a defined set of statuses from creation to active use. The following diagram shows all valid transitions:

```text
draft ──submit──> pending ──approve──> approved ──activate──> active ──deactivate──> inactive
  ^                 │                                           │                       │
  │                 │                                        activate <──────────────────┘
  │               reject
  │                 │
  └── (resubmit) ──┘

draft|pending ──cancel──> cancelled
```

> **Note:** The approval workflow is **transitionally disabled in the schema**. The `ProductStatus` enum still carries every value, and `ProductController` still exposes the transition routes (`submit-for-approval`, `approve`, `reject`, `activate`, `deactivate`), but the supporting columns (`approved_by_id`, `approved_at`, `rejected_by_id`, `rejected_at`, `rejection_reason`) have been removed from `Product.yaml`. The rules below describe the **intended** behavior. Until the columns are restored, the runtime accepts the calls but cannot persist approver/rejecter metadata.

---

## Product Status Rules

### BR-P01: Draft

- The system creates every product in `draft` status.
- Only `draft` and `rejected` products can be edited (translatable name, slug, description, options, SKUs, categories).
- The system sets `created_by_id` to the authenticated user at creation time (Omnify audit observer).

### BR-P02: Submit (draft to pending)

- Requires at least one active `ProductSku`. Default-SKU products always satisfy this.
- Requires `product_type_id` to be set.
- Translations for `name` should exist for the locales the brand uses.

### BR-P03: Approve (pending to approved)

- Only users with the `org-manager` or `org-admin` role can approve.
- The system sets `approved_by_id` and `approved_at` (when columns are restored).
- A user cannot approve their own product (approver must differ from creator).

### BR-P04: Reject (pending to rejected)

- `rejection_reason` is required.
- The system sets `rejected_by_id` and `rejected_at` (when columns are restored).
- The product returns to an editable state, allowing the creator to fix issues and resubmit.

### BR-P05: Activate (approved to active)

- Makes the product available for sales, menus, and stock transactions.
- SKU code uniqueness is enforced per organization where `sku` is non-null: a partial unique index on `(organization_id, sku) WHERE sku IS NOT NULL` allows multiple default SKUs to coexist.

### BR-P06: Deactivate (active to inactive)

- Removes the product from active use.
- Existing stock levels remain untouched.
- Active menu items referencing this product's SKUs become `Unavailable`.

### BR-P07: Cancel (draft or pending to cancelled)

- Cancellation is only allowed from `draft` or `pending` status.
- The system rejects cancellation for `approved`, `active`, or `completed` products.

### BR-P08: Soft Delete

- Products with `active` status cannot be deleted.
- Products referenced by stock levels or transactions cannot be hard-deleted. The system applies soft delete only.
- Soft-deleting a product cascades a soft delete to its `ProductSku` rows; restoring the product does **not** auto-restore SKUs (a manual restore is required if needed).

### BR-P09: SKU Code Uniqueness

| Scope | Uniqueness Constraint |
| ----- | --------------------- |
| Product slug | Unique per brand: `(brand_id, slug)` |
| SKU code (`product_skus.sku`) | Unique per organization where non-null |

The `sku` column on `product_skus` is **nullable** (the auto-generated default SKU may have `sku = NULL`). Code that looks up an SKU by code must also scope by brand or organization.

### BR-P10: Option and SKU Requirements

- Each product has at least one `ProductSku`. `ProductService::create` automatically creates a default SKU when none is supplied.
- A product can have up to three `ProductOption` rows (positions `1`, `2`, `3`).
- `ProductOption.position` and `ProductOption.key` are **immutable** once any SKU references the option. Reordering or renaming requires a migration that also recomputes `option_signature` for every affected SKU.
- `ProductSku.cost_price` can be manually set (`is_cost_override = true`) or auto-calculated from the recipe. See [BR-R02](#br-r02-material-cost-calculation).

---

## Menu Rules

### BR-M01: Master Menu Pattern

- Brand-level menus can be marked as `is_master = true`.
- Branch menus are cloned from a master menu via `POST /menus/{id}/clone-to-branch` and resynced via `POST /menus/{id}/sync-from-master`.
- Each branch can override `selling_price` (sets `is_price_overridden = true`), `availability`, and `display_order`.

### BR-M02: Menu Priority

- `priority` is unique per branch: `(branch_id, priority) UNIQUE`.
- A lower number means higher priority.
- The active menu with the highest priority (lowest number) is the "current" menu.

### BR-M03: Menu Valid Period

- `valid_from` and `valid_to` define when a menu is applicable.
- Menus outside their valid period are treated as `Inactive` even if their status is `Active`.

### BR-M04: Menu Item Price

| Field | Purpose |
| ----- | ------- |
| `selling_price` | The active price used for sales. Required, no fallback. |
| `master_price` | The original price from the master menu, stored for reference. |
| `is_price_overridden` | `true` when the branch has customized the price. |

`MenuItem` links to `ProductSku` via `product_sku_id` (`ON DELETE CASCADE`). Removing an SKU therefore removes its menu items.

---

## Recipe and Material Rules

### BR-R01: Recipe Components

- `ingredients` (JSON) defines the component list: materials and/or SKUs with quantities.
- `output_quantity` and `output_unit` define what one batch of this recipe produces.
- `preparation_time` is measured in minutes.

### BR-R02: Material Cost Calculation

The system computes costs through a chain:

1. `Material.calculated_cost` is auto-computed from component costs (sum of each component's `price × quantity`).
2. `ProductSku.cost_price_auto` is derived from its recipe's material cost: `recipe.material.calculated_cost × ProductSku.recipe_multiplier`.
3. If `ProductSku.is_cost_override = true`, the manual `cost_price` is preserved and auto-recompute is suppressed. Otherwise `cost_price` tracks `cost_price_auto`.

`RecipeObserver::recompute` runs whenever a recipe or its material changes and updates every `ProductSku` whose `is_cost_override` is `false`.

### BR-R03: Recipe Multiplier

- `ProductSku.recipe_multiplier` scales the recipe quantities for that specific SKU.
- Example: a recipe produces 1 unit. An SKU representing the "Large" size uses `recipe_multiplier = 2.0`, doubling all ingredient quantities and the resulting `cost_price_auto`.

---

## Relationships

```text
Product ──1:N──> ProductOption ──1:N──> ProductOptionValue
   │                                          │
   │                                          │ RESTRICT
   │                                          v
   ├──1:N──> ProductSku ──N:1──> Recipe       option_value{1,2,3}_id
   │           │                   │
   │           │                   ingredients (JSON)
   │           │                          │
   │           │                          v
   │           │                  Material / ProductSku
   │           │
   │           └──1:N──> VariantUnit
   │
   └──N:N──> Category

Menu ──1:N──> MenuItem ──N:1──> ProductSku
  │
  └── is_master ──clone/sync──> Branch Menus
```

See also [Inventory Domain](inventory-domain.md) for how SKUs interact with stock levels.
