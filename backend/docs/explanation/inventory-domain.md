---
title: Inventory Domain
category: explanation
tags: [brand, shop, warehouse, stock, stock-level, transaction, transfer, count, disposal, movement, alert, production, material-batch, production-order]
summary: Explains the inventory management domain -- the Brand-Shop-Warehouse hierarchy, stock levels, stock transactions, transfers, stock counts, disposals, stock movements, and production -- including warehouse types, auto-approve settings, and the immutable audit trail.
related: [stock-management, production-flow, api-inventory, api-production, product-domain]
---

# Inventory Domain

This document explains the inventory management domain -- warehouses, stock levels, stock transactions, transfers, stock counts, disposals, stock movements, and production. Read this before implementing any inventory-related feature.

## Overview

The inventory system tracks the full lifecycle of goods in warehouses -- from receiving and issuing, through transfers and stock counts, to disposal. Every stock change is recorded through a stock transaction and an immutable stock movement (audit trail), ensuring full transparency and traceability.

---

## Brand, Shop, and Warehouse Hierarchy

Stock is scoped to a **Shop (Branch)**, not to a Brand. The full ownership chain is:

```text
Brand --> Shop (Branch) --> Warehouse --> Stock Level
```

- A Brand owns multiple Shops (Branches)
- Each Shop can have one or more Warehouses
- Each Warehouse holds Stock Levels for individual items
- Product-domain entities (Products, Recipes, Materials) are scoped to the Brand level
- Inventory-domain entities (Warehouses, Stock Levels, Transactions) are scoped to the Shop level

This means a Brand defines **what** can be sold (product catalog), while a Shop manages **how much** is in stock (inventory).

> **Note:** When querying inventory data, the system resolves the Shop from the authenticated user's context. A user assigned to Shop A cannot see stock in Shop B, even if both shops belong to the same Brand. Org Admin and Brand HQ users can see stock across all shops within their Brand.

---

## Warehouse

### What it is

A warehouse is a physical storage unit for goods. Each organization can have multiple warehouses, and each warehouse is linked to a branch.

### Warehouse types

| Type | Purpose | Example |
| ---- | ------- | ------- |
| `main` | Primary storage for goods | Hanoi central warehouse |
| `branch` | Storage at a branch location | Ba Trieu store warehouse |
| `production` | Storage supporting manufacturing | Factory raw material warehouse |

### Auto-approve settings

Each warehouse has four independent auto-approve flags. When enabled, the corresponding operations are automatically approved (skipping the pending-approval step), provided the creator holds the `manager` or `admin` role.

| Flag | Applies to |
| ---- | ---------- |
| `auto_approve_stock_in` | Stock-in transactions |
| `auto_approve_stock_out` | Stock-out transactions |
| `auto_approve_batch` | Material batch (production) orders |
| `auto_approve_disposal` | Disposal records |

### Disposal approval threshold

The `disposal_approval_threshold` field stores a monetary value. When the total value of a disposal exceeds this threshold, Org Admin approval is always required, regardless of the `auto_approve_disposal` flag.

Example: threshold = 5,000,000 VND. A disposal worth 3,000,000 VND can be auto-approved. A disposal worth 6,000,000 VND always requires Org Admin approval.

---

## Warehouse Member

### What it is

A warehouse member record assigns a user to a warehouse with a specific role, controlling per-warehouse access.

### Roles

| Role | Permissions |
| ---- | ----------- |
| `manager` | View, create, and approve stock transactions. Eligible for auto-approve when the warehouse allows it |
| `staff` | View and create stock transactions. Cannot approve |

- A non-Org-Admin user only sees warehouses they are assigned to.
- An Org Admin sees all warehouses in the organization.

---

## Stock Level

### What it is

A stock level represents the current quantity of a single item in a single warehouse. Each (warehouse + item) pair has exactly one stock level record.

**Item** refers to one of two entity types:

- **Product SKU** (`product_sku_id`) -- a finished good
- **Material** (`material_id`) -- a raw material or semi-finished good

Each record references exactly one of these two; never both at the same time.

### Unit of measure

Stock levels are always stored in the **base unit**. For example, if the base unit is "box," the stock level shows 120 (boxes), not 5 (cartons of 24 boxes).

### Stock alerts

The system automatically monitors stock levels and raises alerts when:

- **`low_stock`**: quantity > 0 but <= `min_stock` (running low)
- **`out_of_stock`**: quantity = 0 (depleted)

Alerts are **created and resolved automatically** -- no manual action is needed:

- When stock drops below the threshold, the system creates an alert.
- When stock rises back above the threshold, the system resolves the alert.
- Each (warehouse + item) pair has at most one active alert.

Alerts can be disabled per item by setting `alert_enabled = false`.

### Key business rules

**Stock never goes negative (BR-S01).** Any stock-out transaction that would result in a negative quantity is rejected. Two exceptions:

1. **Stock count.** The physical count is the most accurate source of truth, so it can set stock to any value.
2. **Plan-024 — `Warehouse.allow_negative_sales` opt-in.** When this flag is `true` on a warehouse AND the failing stock_out has `sub_type IN {sales, sales_material_consumption}`, the transaction completes with a negative `StockLevel.quantity` and fires an `out_of_stock` `StockAlert` instead of throwing. Manual stock_out / disposal / adjustment_out / transfer_out remain strict regardless of the flag — those represent staff-initiated writes where knowing the real on-hand state is the whole point. Org Admin sets the flag via warehouse settings; backend default is `false`.

**Stock cannot be edited directly.** There is no API to assign `quantity = X`. Stock levels change only through:

1. Stock transactions
2. Stock transfers
3. Stock counts
4. Production (production orders / material batches)

---

## Stock Transaction

### What it is

A stock transaction is the primary record of goods entering or leaving a warehouse. Every stock level change passes through a stock transaction.

### Transaction types

| Type | Meaning | Effect on stock |
| ---- | ------- | --------------- |
| `stock_in` | Goods received into warehouse | Stock **increases** |
| `stock_out` | Goods issued from warehouse | Stock **decreases** |

### Sub-types

| Sub-type | Meaning | Created by |
| -------- | ------- | ---------- |
| `purchase` | Purchase from supplier | User |
| `sales` | Sale to customer (SKU-grain stock-out) | User / POS / `OrderClosingService` (auto, plan-024) |
| `sales_material_consumption` | Recipe-based material deduction on sale | `OrderClosingService` (auto, plan-024) |
| `production` | Production (receive finished goods / consume materials) | System (on production completion) |
| `transfer_in` | Receive goods from transfer | System (on transfer receipt) |
| `transfer_out` | Send goods via transfer | System (on transfer approval) |
| `return` | Return of goods | User |
| `disposal` | Disposal of goods | User (via Disposal) |
| `adjustment_in` | Upward adjustment | System (on stock count approval) |
| `adjustment_out` | Downward adjustment | System (on stock count approval) |
| `other` | Other | User |

### Transaction code

Auto-generated and immutable:

- Stock in: `SI-YYYYMMDD-XXX` (e.g., SI-20260403-001)
- Stock out: `SO-YYYYMMDD-XXX`
- Sequential numbering per day, per type.

### Status lifecycle

```text
draft ──submit──> pending ──approve──> completed
  |                  |
  +──cancel──>  cancelled
```

| Status | Meaning | Editable? | Deletable? |
| ------ | ------- | --------- | ---------- |
| `draft` | Being composed | Yes | Yes |
| `pending` | Awaiting approval | Yes (limited) | No |
| `completed` | Finalized; stock updated | No | No |
| `cancelled` | Cancelled | No | Yes |

### What happens on approval

This is the most critical step. When a transaction moves to `completed`:

1. **Row lock** is acquired on the stock level record to prevent race conditions.
2. **Stock is updated** for each item in the transaction:
   - `stock_in`: quantity += base_quantity
   - `stock_out`: quantity -= base_quantity (rejected if result < 0)
3. **A stock movement record** is created for each item, capturing the before/after quantities.
4. **Alerts are evaluated** -- new alerts are created or existing alerts are resolved based on threshold crossings.
5. **Approval metadata** is recorded: `approved_by_id`, `approved_at`, `completed_at`.

### Unit conversion

Each line item in a transaction carries two quantity fields:

- `quantity`: the amount in the user-selected unit (e.g., 5 cartons)
- `base_quantity`: the equivalent in the base unit (e.g., 120 boxes, if 1 carton = 24 boxes)

Stock levels always update using `base_quantity`.

---

## Stock Transfer

### What it is

A stock transfer moves goods from one warehouse to another within the same organization.

### Status lifecycle

```text
draft --> pending --> in_transit --> completed
                  --> cancelled (if in_transit: stock is returned)
```

| Status | Meaning |
| ------ | ------- |
| `draft` | Transfer is being composed |
| `pending` | Awaiting approval |
| `in_transit` | Approved; goods are in transit. Stock has been deducted from the source warehouse |
| `completed` | Destination warehouse has received the goods. Stock has been added at the destination |
| `cancelled` | Cancelled. If cancelled while `in_transit`, stock is returned to the source warehouse |

### How it works

**Step 1: Create (draft)**

- Select a source warehouse, a destination warehouse (must be different), and a list of items.
- Record `sent_quantity` for each item.

**Step 2: Approve (pending to in_transit)**

- Check stock availability at the source warehouse (row lock).
- Create a stock-out transaction (`stock_out`, sub_type = `transfer_out`) at the source warehouse with status = completed.
- Deduct stock at the source warehouse.
- Link via `stock_out_transaction_id`.

**Step 3: Receive (in_transit to completed)**

- The destination warehouse confirms the actual quantity received (`received_quantity`).
- `received_quantity` may differ from `sent_quantity` (e.g., damage during transit).
- Create a stock-in transaction (`stock_in`, sub_type = `transfer_in`) at the destination warehouse with status = completed.
- Add stock at the destination warehouse.
- Link via `stock_in_transaction_id`.

**Cancellation while in_transit:**

- A reverse stock-in transaction is created at the source warehouse to restore the deducted quantity.
- Stock alerts are re-evaluated.

---

## Stock Count

### What it is

A stock count is the process of physically counting goods in a warehouse and reconciling the result with system records. When discrepancies exist, the system automatically creates adjustment transactions.

### Count scope

| Scope | Meaning |
| ----- | ------- |
| `full` | Count the entire warehouse -- the system snapshots all items with quantity > 0 |
| `partial` | Count selected items -- the user chooses which items to count |

### Status lifecycle

```text
draft --> in_progress --> pending_approval --> approved
                                           --> cancelled
```

| Status | Meaning |
| ------ | ------- |
| `draft` | Just created; items can be added (partial scope) |
| `in_progress` | Counting is underway; actual quantities are being entered |
| `pending_approval` | Counting is complete; awaiting approval |
| `approved` | Approved -- the system creates adjustment transactions |
| `cancelled` | Cancelled (cannot cancel after approval) |

### Count item fields

| Field | Meaning |
| ----- | ------- |
| `system_quantity` | System quantity at the time the count was created (immutable snapshot) |
| `counted_quantity` | Actual quantity counted by staff |
| `difference` | `counted_quantity - system_quantity` (computed automatically) |

### What happens on approval

1. Items are grouped by discrepancy direction:
   - Surplus (counted > system): creates one `stock_in` / `adjustment_in` transaction
   - Shortage (counted < system): creates one `stock_out` / `adjustment_out` transaction
2. Stock is updated for each item where difference is not 0.
3. At most two adjustment transactions are created.
4. Stock counts allow negative results (the physical count is the most accurate source of truth).
5. The adjustment transactions reference the count via `reference_type = 'StockCount'`.

> **Note:** The warehouse is NOT locked during a stock count. Normal stock-in and stock-out operations continue in parallel. The `system_quantity` is a snapshot taken at creation time and does not change afterward.

### Stock counts are never deleted

Unlike other records, stock counts are **never deleted** -- history is preserved permanently to ensure full traceability.

---

## Disposal

### What it is

A disposal records the removal of goods from a warehouse due to quality issues, expiration, damage, or other reasons. Under the hood, a disposal is a stock-out transaction (`stock_out`, sub_type = `disposal`) with additional detail about the reason.

### Disposal reasons

| Value | Meaning |
| ----- | ------- |
| `expired` | Past expiration date |
| `overproduction` | Excess production |
| `damaged` | Physical damage |
| `quality` | Failed quality standards |
| `contaminated` | Contamination |
| `other` | Other reason (note is required) |

### Disposal cost

The `cost_at_disposal` field records the value of goods at the time of disposal, used for waste reporting.

### Approval threshold

If the warehouse has a `disposal_approval_threshold`:

- Total value < threshold: follows the `auto_approve_disposal` flag.
- Total value >= threshold: Org Admin approval is mandatory.

### Waste report

Aggregated disposal data for analysis:

- **By reason**: quantity and value grouped by disposal reason
- **Top disposed items**: ranked by count or value
- **Daily trend**: disposal trend chart over time
- **Summary**: total number of disposals, total value

---

## Stock Movement

### What it is

A stock movement is an **immutable** record created each time a stock level changes. It serves as the ledger for the warehouse.

### How it works

Each record contains:

- Which item changed
- Movement direction: `in` (received) or `out` (issued)
- Quantity changed
- **Quantity before** (`quantity_before`) and **quantity after** (`quantity_after`)
- Which stock transaction caused the change

Stock movements are **never edited or deleted**. They form a complete audit trail that allows tracing back from any point in time.

---

## Production

### Material Batch

A material batch produces **materials or semi-finished goods** from components. For example: mixing coffee beans + sugar to produce coffee powder.

Transaction code: `MB-YYYYMMDD-XXX`

### Production Order

A production order produces **finished goods** (product SKUs) from materials. For example: using coffee powder to package 100 cups of black coffee size S.

Transaction code: `PO-YYYYMMDD-XXX`

### Status lifecycle

```text
draft --> pending --> approved --> in_progress --> completed
                  --> cancelled
```

| Status | Meaning |
| ------ | ------- |
| `draft` | Planning; selecting components |
| `pending` | Awaiting approval |
| `approved` | Approved; ready for production |
| `in_progress` | Production underway -- no stock impact yet |
| `completed` | Finished -- **two stock transactions are created automatically** |
| `cancelled` | Cancelled (only from draft, pending, or approved) |

### What happens on completion

The system automatically creates **two stock transactions**:

1. **Stock-out transaction** (consume materials):
   - type = stock_out, sub_type = production
   - Deducts stock for each component based on `actual_quantity`

2. **Stock-in transaction** (receive finished goods):
   - type = stock_in, sub_type = production
   - Adds stock for the output item based on `actual_yield` / `actual_quantity`

Both transactions are auto-completed (they skip the pending step).

### Planned vs. actual

| Field | Meaning |
| ----- | ------- |
| `planned_quantity` / `planned_yield` | Expected production output |
| `actual_quantity` / `actual_yield` | Actual production output (entered on completion) |
| `stock_available` | Current stock of the component (for reference) |

Actual yield may differ from planned yield due to waste, efficiency variations, or other factors.

### Production calculator

A tool to check **feasibility** before creating a production order:

- Input: warehouse, output SKU, planned quantity
- Output: Is production feasible? What is the maximum producible quantity? Which components are insufficient?

---

## Relationships

```text
Warehouse
+-- WarehouseMember (user + role)
+-- StockLevel (1 per item per warehouse)
|   +-- StockAlert (auto-generated)
+-- StockTransaction --> StockMovement (audit trail)
|   +-- StockTransactionItem
|       +-- DisposalRecord (if sub_type = disposal)
+-- StockTransfer
|   +-- source_warehouse
|   +-- destination_warehouse
|   +-- stock_out_transaction (auto-created on approve)
|   +-- stock_in_transaction (auto-created on receive)
+-- StockCount
|   +-- StockCountItem
|       +-- adjustment transactions (auto-created on approve)
+-- MaterialBatch
|   +-- MaterialBatchItem (components)
|   +-- stock_out_transaction (auto on complete)
|   +-- stock_in_transaction (auto on complete)
+-- ProductionOrder
    +-- ProductionOrderItem (components)
    +-- stock_out_transaction (auto on complete)
    +-- stock_in_transaction (auto on complete)
```

---

## How Stock Changes

| Source | Effect |
| ------ | ------ |
| Stock-in transaction | + base_quantity |
| Stock-out transaction | - base_quantity |
| Transfer (approve) | Deduct at source warehouse |
| Transfer (receive) | Add at destination warehouse |
| Transfer (cancel while in_transit) | Restore at source warehouse |
| Stock count (approve) | Adjust by difference |
| Production (complete) | Deduct materials + add finished goods |
| Disposal (approve) | Deduct disposed goods |
| **Plan-024 — Order close (paid → closed)** | Auto-deduct gated by `ProductSku.inventory_mode` — see below |
| **Never** | Edit quantity directly |

---

## Plan-024 — Auto-deduct on order close

`OrderClosingService::close()` runs synchronously when a `CustomerOrder` transitions to `paid → closed`. It writes inventory in two phases:

### Phase 1 — SKU stock-out (gated by `inventory_mode`)

For each non-voided order item, the service inspects `productSku.inventory_mode`:

| Mode | Phase 1 effect |
| ---- | -------------- |
| `made_to_order` (default) | **Skip.** The SKU is treated as ephemeral; raw materials are the inventory of record. |
| `track_stock` | Emit a single combined `stock_out / sales` `StockTransaction` aggregating all track-stock items in the order. Calls `submit()` so the warehouse `auto_approve_stock_out` flag drives `completeTransaction` synchronously. |

The default `made_to_order` is conservative — SKUs in this mode produce no stock movement on close. Shops that require explicit stock tracking should set `inventory_mode = track_stock` on the relevant catalog subset via HQ admin.

### Phase 2 — Recipe → Material deduction (only for `track_stock`)

For every `track_stock` SKU with a non-empty Recipe, the service walks `recipe.ingredients` (a JSON array shaped `[{material_id, quantity, unit}, ...]`) and aggregates per material across all order items:

```
material_qty = (ingredient.quantity / recipe.output_quantity) × order_item.quantity
```

Then it emits one combined `stock_out / sales_material_consumption` `StockTransaction` with one item per material. The FEFO pre-pass in `StockTransactionService::completeTransaction` splits material consumption across the active lot pool automatically.

On the **strict** path a positive `material_lot_id=NULL` stock level is not an allocation source — FEFO walks active lots only — and if those plus any configured substitutions cannot cover the demand, the whole transaction fails with `InsufficientStockException`.

The opt-in `allow_negative_sales` path reads the opposite way, and the distinction matters when reconciling a count. There the unmet residual is emitted as a material-level line and **decrements** the `material_lot_id=NULL` row like any other line. That row goes negative — and only then forces an `out_of_stock` alert regardless of configured thresholds — when the residual exceeds what it already holds. A warehouse carrying a positive NULL-lot balance simply draws it down and completes with no alert. Both halves are pinned in `StockTransactionAllowNegativeTest`.

Skip cases (logged as `Log::warning`, order still closes):

- `track_stock` SKU has `recipe_id = null`
- Recipe exists but `ingredients` is empty
- An ingredient references a soft-deleted Material (the ghost reference is filtered out before submit)

### Operational requirement — `auto_approve_stock_out`

Both phases call `StockTransactionService::submit()`. The transaction is auto-completed only when the target warehouse has `auto_approve_stock_out = true`. If the flag is `false`, the transaction lands in `pending` and waits for a manager — the order itself still closes. Shops that want immediate inventory drains on close MUST keep this flag on for the warehouse(s) reachable by `getDefaultWarehouse()`.

### Stock alert notifications (plan-023 M1)

`StockAlert::created` triggers `StockAlertNotificationObserver`, which dispatches a notification via `NotificationService` to all users with the `warehouse_manager` role scoped to the affected warehouse. The dispatch is idempotent per `{type}:{alert_id}` and runs inside the same DB transaction as the alert write — a notification failure is logged but does NOT abort the order close. No additional wiring needed in plan-024.
