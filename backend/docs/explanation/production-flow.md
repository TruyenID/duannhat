---
title: Production Flow
category: explanation
tags: [production, material-batch, production-order, recipe, yield, stock-impact, component]
summary: Explains how the production system converts raw materials into finished products through material batches and production orders, including the workflow lifecycle, recipe-based component calculation, yield tracking, and stock impact rules.
related: [inventory-domain, stock-management, product-domain]
---

# Production Flow

This document explains how the production system converts raw materials into finished products through material batches and production orders. Read this before implementing any production workflow, recipe calculation, or yield tracking feature.

## Overview

Production converts raw materials into finished products through two mechanisms:

| Mechanism | Output | Example |
| --------- | ------ | ------- |
| **Material Batch** | Semi-finished material | Dough, sauce, pre-mix |
| **Production Order** | Finished product SKU | Packaged coffee, bottled drink |

Both follow the same workflow and stock impact pattern.

---

## Workflow

```text
draft ──submit──> pending ──approve──> approved ──start──> in_progress ──complete──> completed
                    │
                    └── cancel (from draft or pending) ──> cancelled
```

### BR-PD01: Draft

- The system creates the batch or order with `planned_yield`/`planned_quantity` and component items.
- The component list is derived from the recipe: each item has a `planned_quantity`.
- `stock_available` on each item shows current warehouse stock for reference.

### BR-PD02: Submit (draft to pending)

- The system validates that all component items have valid references.
- The system validates that `warehouse_id` exists and is active.

### BR-PD03: Approve (pending to approved)

- Only users with `org-manager` or `org-admin` role can approve.
- The system sets `approved_by_id` and `approved_at`.
- Auto-approval: if the warehouse has `auto_approve_batch = true`, the system skips `pending` and goes directly to `approved`.

### BR-PD04: Start (approved to in_progress)

- The system sets `started_at`.
- This is an informational state with no stock impact.
- Physical production begins at this point.

### BR-PD05: Complete (in_progress to completed)

This is the critical step that triggers stock changes:

1. **Record actuals**: `actual_yield` (for batches) or `actual_quantity` (for orders), and `actual_quantity` per component item.
2. **Create Stock Out transaction**: consumes components from the warehouse.
3. **Create Stock In transaction**: adds the output to the warehouse.
4. The system sets `completed_at`.

### BR-PD06: Cancel

- Cancellation is only allowed from `draft` or `pending` status.
- The system rejects cancellation for `in_progress` or `completed` batches/orders.

---

## Stock Impact on Completion

### Step 1: Stock Out (consume components)

The system creates a stock-out transaction to deduct consumed materials:

```text
StockTransaction:
  type: stock_out
  sub_type: production
  warehouse_id: {batch warehouse}
  reference_type: MaterialBatch (or ProductionOrder)
  reference_id: {batch_id}
  status: completed (auto)

Items (one per component):
  - material_id or product_sku_id (based on component_type)
  - quantity: actual_quantity
  - base_quantity: converted to base unit
```

### Step 2: Stock In (produce output)

The system creates a stock-in transaction to add the produced output:

```text
StockTransaction:
  type: stock_in
  sub_type: production
  warehouse_id: {batch warehouse}
  reference_type: MaterialBatch (or ProductionOrder)
  reference_id: {batch_id}
  status: completed (auto)

Items:
  For MaterialBatch:
    - material_id: {output material}
    - quantity: actual_yield
  For ProductionOrder:
    - product_sku_id: {output SKU}
    - quantity: actual_quantity
```

### Step 3: Update Stock Levels

Both transactions update StockLevel and create StockMovement records. See [Stock Management -- Completion Side Effects](../explanation/stock-management.md#br-st04-completion-side-effects) for details.

---

## Material Batch vs Production Order

| Aspect | Material Batch | Production Order |
| ------ | -------------- | ---------------- |
| **Output** | Material (semi-finished) | `ProductSku` (finished) |
| **Code format** | `MB-YYYYMMDD-XXX` | `PO-YYYYMMDD-XXX` |
| **Use case** | Dough, sauce, pre-mix, compound | Packaged product, bottled drink |
| **Yield fields** | `planned_yield` / `actual_yield` | `planned_quantity` / `actual_quantity` |
| **Expiry** | Has `expiry_date` | No expiry tracking |
| **Multiplier** | `multiplier` (batch size) | `recipe_multiplier` (from `ProductSku`) |

---

## Recipe Integration

### BR-PD07: Component Calculation

When creating a batch or order, the system calculates components from the recipe:

```text
For each recipe ingredient:
  planned_quantity = ingredient.quantity x multiplier (or recipe_multiplier)
```

### BR-PD08: Yield Variance

- `actual_yield` may differ from `planned_yield` due to waste or efficiency.
- Yield variance = `(actual_yield - planned_yield) / planned_yield x 100%`
- The system takes no automatic action on variance. It is reported for analysis.

### BR-PD09: Component Availability Check

- `stock_available` on each item is populated at creation time.
- If any component's `stock_available < planned_quantity`, the system flags that item.
- This is a warning only and does not block creation.
- The actual stock sufficiency check happens at completion time (BR-S01: non-negative stock level).

---

## Examples

### Scenario: Produce a coffee pre-mix (Material Batch)

1. **Recipe:** "Coffee Pre-mix" = 5 kg coffee beans + 2 kg sugar, yielding 6.5 kg pre-mix.
2. **Create batch:** multiplier = 3 (triple batch).
   - Planned components: 15 kg beans, 6 kg sugar, yielding 19.5 kg pre-mix.
3. **Start production.**
4. **Complete:** actual_yield = 19.2 kg (slight waste).
   - Stock Out: 15 kg beans + 6 kg sugar from warehouse.
   - Stock In: 19.2 kg coffee pre-mix to warehouse.

### Scenario: Produce packaged coffee (Production Order)

1. **SKU:** "Black Coffee - Size S" with a recipe referencing coffee pre-mix.
2. **Create order:** planned_quantity = 100 pieces.
   - Components: 2 kg coffee pre-mix (calculated from recipe multiplied by recipe_multiplier).
3. **Start production.**
4. **Complete:** actual_quantity = 98 pieces.
   - Stock Out: 2 kg coffee pre-mix from warehouse.
   - Stock In: 98 pieces "Black Coffee - Size S" to warehouse.

---

## Relationships

```text
Recipe ──1:N──> Ingredients (materials / SKUs)
  │
  └──referenced by──> MaterialBatch (via material.recipe)
  └──referenced by──> ProductionOrder (via product_sku.recipe)

MaterialBatch ──creates──> StockTransaction (stock_out + stock_in)
ProductionOrder ──creates──> StockTransaction (stock_out + stock_in)
```

See also [Stock Management](../explanation/stock-management.md) for transaction completion rules, and [Product Workflow](../explanation/product-workflow.md) for product and recipe relationships.
