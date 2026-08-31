---
title: ProductSku Inventory Mode Reference
category: reference
tags: [plan-024, inventory, product-sku, recipe, order-close]
summary: Reference for the `ProductSku.inventory_mode` enum introduced by plan-024 — controls whether order close triggers SKU stock-out and recipe-based material deduction.
related: [inventory-domain, stock-management, api-inventory]
---

# ProductSku Inventory Mode Reference

`ProductSku.inventory_mode` (added by plan-024) is the per-SKU policy that determines whether `OrderClosingService::close()` produces stock movements for that SKU at order-close time.

## Enum values

| Value | Behaviour |
| ----- | --------- |
| `made_to_order` *(default)* | Order close skips this SKU entirely — no Phase 1 SKU stock-out, no Phase 2 recipe-based material deduction. The SKU is treated as ephemeral; raw materials (if any) are the inventory of record and they're consumed upstream via `MaterialBatch`. Correct for restaurant menu items that the kitchen replenishes on demand. |
| `track_stock` | Order close emits one combined `stock_out / sales` `StockTransaction` for the SKU itself AND, if the linked Recipe has non-empty `ingredients`, a second combined `stock_out / sales_material_consumption` `StockTransaction` aggregating recipe-derived material quantities. |

## Migration default

Existing `ProductSku` rows after the plan-024 migration default to `made_to_order` — conservative, no behaviour change. Shops that relied on implicit stock tracking should mass-set the relevant catalog subset to `track_stock` via HQ admin.

## Recipe-based deduction formula

For each `track_stock` SKU in the order, the service walks `Recipe.ingredients` (a JSON array shaped `[{material_id, quantity, unit}, ...]`) and computes per-material consumption:

```
material_qty = (ingredient.quantity / recipe.output_quantity) × order_item.quantity
```

Quantities are aggregated across all order items sharing the same material, so an order with two `track_stock` SKUs both using "rice" produces one combined material item, not two.

## Skip cases

`OrderClosingService::emitMaterialConsumptionTransaction()` skips and logs `Log::warning` when:

- `productSku.recipe_id` is `null`
- `Recipe.ingredients` is empty
- An ingredient's `material_id` references a soft-deleted Material (filtered before submit)

In every skip case the order still closes — the warn is purely an observability signal for back-office data hygiene.

## Operational requirement

Both Phase 1 and Phase 2 call `StockTransactionService::submit()`. The transaction auto-completes only when the warehouse has `auto_approve_stock_out = true`. If `false`, the transaction lands in `pending` and waits for a manager — **the order itself still closes**. Shops that want immediate inventory drains on close must keep this flag on for the warehouse(s) reachable by `OrderClosingService::getDefaultWarehouse()`.

## API surface

- `GET /api/v1/hq/{brandSlug}/products/{productId}/skus/{skuId}` — response includes `inventory_mode`
- `PATCH /api/v1/hq/{brandSlug}/products/{productId}/skus/{skuId}` — accepts `inventory_mode` (`in:made_to_order,track_stock`)
- `POST /api/v1/hq/{brandSlug}/products/{productId}/skus` — accepts `inventory_mode` (optional, defaults to `made_to_order`)

## Admin UI

`/hq/[brandSlug]/products/[id]/skus/[skuId]` — the "Variant details" card now includes an `Inventory mode` `<Select>` alongside SKU code + selling price. Default selected value reflects the persisted column; hint copy below the select explains the two modes.

## Related decisions

See [plan-024 DESIGN.md](../../../plans/plan-024/DESIGN.md) Decision 1 (enum vs boolean), Decision 4 (combined material transaction), Decision 7 (plain `qty/output_quantity × order_qty` formula), Decision 8 (auto_approve operational requirement).
