---
title: Stock Management
category: explanation
tags: [stock, transaction, transfer, count, disposal, alert, auto-approve, unit-conversion, row-locking]
summary: Explains stock-level tracking, transaction workflows (transfers, physical counts, disposals), non-negative enforcement, unit conversion, automated threshold alerts, and approval rules across warehouses.
related: [inventory-domain, production-flow, authorization]
---

# Stock Management

This document explains the inventory management domain -- stock levels, transactions, transfers, physical counts, disposals, and alerts. Read this before implementing any stock-related feature or modifying transaction workflows.

## Overview

The stock management system tracks inventory quantities across warehouses using a transaction-based model. Every quantity change flows through a stock transaction, creating an auditable trail of movements. The system enforces non-negative stock, supports unit conversion, and provides automated alerting when stock falls below configured thresholds.

---

## Core Principles

### BR-S01: Stock Level Non-Negative (with plan-024 opt-in exception)

Stock level `quantity` must never go below zero. The system rejects any transaction that would result in a negative stock level at approval or completion time.

**Plan-024 opt-in exception.** A warehouse with `allow_negative_sales = true` allows stock_out transactions with `sub_type IN {sales, sales_material_consumption}` to drive `StockLevel.quantity` below zero. Instead of throwing `InsufficientStockException`, the transaction completes with the negative quantity and fires an `out_of_stock` `StockAlert` (which in turn dispatches a notification via `StockAlertNotificationObserver`). Manual stock_out / disposal / adjustment_out / transfer_out remain strict regardless of the flag — staff-initiated writes should require knowing the real on-hand state.

The flag is per-warehouse (org-admin sets it via warehouse settings). Default is `false`, preserving the historical strict behaviour for warehouses that have not opted in.

### BR-S02: Mutually Exclusive Item Type

Each stock-related item (StockLevel, StockTransactionItem, etc.) references exactly one of:

| Reference | Meaning |
| --------- | ------- |
| `product_sku_id` | Finished product (a `ProductSku` row) |
| `material_id` | Raw material |

One must be set and the other must be null. Both cannot be set simultaneously.

### BR-S03: Unit Conversion

The system maintains two quantity representations for every stock item:

| Field | Description |
| ----- | ----------- |
| `quantity` | User-facing quantity in the selected `unit` |
| `base_quantity` | Converted to the base unit using `VariantUnit`/`MaterialUnit` ratios |

StockLevel always stores quantities in the base unit.

**Example:** A user enters 5 boxes. The conversion ratio for boxes is 24 pieces per box. The system calculates `base_quantity = 5 x 24 = 120 pieces`.

---

## Stock Transaction Rules

### BR-ST01: Transaction Code Format

Transaction codes are auto-generated and non-editable:

| Transaction Type | Format | Example |
| ---------------- | ------ | ------- |
| Stock In | `SI-YYYYMMDD-XXX` | `SI-20260403-001` |
| Stock Out | `SO-YYYYMMDD-XXX` | `SO-20260403-001` |

The sequential counter (`XXX`) resets daily and increments per transaction type.

### BR-ST02: Draft Mutability

- Transactions in `draft` status can be edited (items added/removed, quantities changed).
- Once submitted to `pending`, the transaction becomes immutable.

### BR-ST03: Auto-Approval

Per-warehouse settings control whether transactions skip the `pending` state and go directly to `approved`:

| Setting | Applies to |
| ------- | ---------- |
| `auto_approve_stock_in` | Stock-in transactions |
| `auto_approve_stock_out` | Stock-out transactions |
| `auto_approve_batch` | Material batches |
| `auto_approve_disposal` | Disposal sub-type only |

When enabled, submitting a transaction skips `pending` and moves directly to `approved` status.

### BR-ST04: Completion Side Effects

When a transaction reaches `completed` status, the system performs three actions:

**1. Update StockLevel for each item:**

| Transaction Type | Stock Level Change |
| ---------------- | ------------------ |
| `stock_in` | `quantity += base_quantity` |
| `stock_out` | `quantity -= base_quantity` (rejected if result < 0, per BR-S01) |

**2. Create StockMovement records for audit trail:**

- `movement_type`: `in` or `out`
- `quantity_before` / `quantity_after`: snapshot of the stock level before and after the change

**3. Check stock alerts:**

| Condition | Action |
| --------- | ------ |
| `quantity < min_stock` and `alert_enabled` | Create or update StockAlert with type `low_stock` |
| `quantity = 0` | Create StockAlert with type `out_of_stock` |
| Alert was `active` and quantity is now above threshold | Mark alert as `resolved` |

### BR-ST05: Reference Tracking

`reference_type` and `reference_id` link a transaction to its source:

| reference_type | Source |
| -------------- | ------ |
| `MaterialBatch` | Production batch |
| `ProductionOrder` | Production order |
| `StockTransfer` | Inter-warehouse transfer |
| `DisposalRecord` | Disposal |
| `null` | User-initiated (manual) |

### BR-ST06: Completed Transactions Are Immutable

Once `completed`, a transaction cannot be edited or deleted. To reverse a completed transaction, create a new opposite transaction (for example, an `adjustment_in` to correct an incorrect `stock_out`).

---

## Stock Transfer Rules

### BR-TR01: Transfer Creates Two Transactions

When completed, a transfer automatically creates two transactions:

1. **Stock Out** at the source warehouse (sub_type: `transfer_out`)
2. **Stock In** at the destination warehouse (sub_type: `transfer_in`)

### BR-TR02: Receiving

- The destination warehouse must explicitly receive the transfer.
- `received_quantity` can differ from `sent_quantity` (for example, due to damage in transit).
- The system records `received_by_id` and `received_at`.

### BR-TR03: Same Organization Only

Transfers can only happen between warehouses within the same organization.

---

## Stock Count (Physical Inventory) Rules

### BR-SC01: System vs Counted

| Field | Description |
| ----- | ----------- |
| `system_quantity` | Current stock level at the time of count creation |
| `counted_quantity` | Physical count entered by staff |
| `difference` | `counted_quantity - system_quantity` (auto-calculated) |

### BR-SC02: Reconciliation on Approval

When a stock count is approved, the system creates adjustment transactions for each item where `difference` is not zero:

| Condition | Action |
| --------- | ------ |
| `difference > 0` | Create `adjustment_in` stock transaction |
| `difference < 0` | Create `adjustment_out` stock transaction |

### BR-SC03: Count Scope

| Scope | Description |
| ----- | ----------- |
| `full` | All items in the warehouse |
| `partial` | Selected items only |

---

## Disposal Rules

### BR-D01: Disposal Record

Each disposal creates a `DisposalRecord` linked to a `StockTransactionItem`:

- `disposal_reason`: why the item was disposed.
- `cost_at_disposal`: the monetary value of the disposed stock.

### BR-D02: Disposal Approval Threshold

If a warehouse has `disposal_approval_threshold` set (for example, 5,000,000):

| Condition | Behavior |
| --------- | -------- |
| `cost_at_disposal` below threshold | Follows the `auto_approve_disposal` setting |
| `cost_at_disposal` at or above threshold | Always requires manual approval, regardless of the auto-approve setting |

---

## Stock Alert Rules

### BR-A01: Alert Trigger

The system checks alerts after every stock level change (transaction completion):

| Alert Type | Trigger Condition |
| ---------- | ----------------- |
| `low_stock` | `quantity < min_stock` |
| `out_of_stock` | `quantity = 0` |

### BR-A02: Alert Resolution

- Alerts auto-resolve when stock is replenished above `min_stock`.
- Alerts can also be manually resolved via `PATCH /stock-alerts/{id}/resolve`.

---

## Relationships

```text
Warehouse ──1:N──> StockLevel ──1:N──> StockMovement
    │                  │
    │                  └── product_sku_id XOR material_id
    │
    └──1:N──> StockTransaction ──1:N──> StockTransactionItem
                    │
                    └── reference_type/reference_id ──> Source Entity
```

See also [Production Flow](../explanation/production-flow.md) for how production creates stock transactions, and [Product Workflow](../explanation/product-workflow.md) for product status rules.
