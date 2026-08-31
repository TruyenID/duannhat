---
title: Inventory Domain API Reference
category: reference
tags: [api, shop, warehouse, stock-level, stock-transaction, stock-transfer, stock-count, stock-alert, disposal, production-calculator, endpoint]
summary: Lists all API endpoints for the inventory domain including warehouses, warehouse members, stock levels, stock transactions, stock transfers, stock counts, stock alerts, disposals, and production calculator. All endpoints are available under shop-scoped paths.
related: [inventory-domain, stock-management, api-overview]
---

# Inventory Domain API Reference

This document covers all API endpoints for the inventory domain: warehouses, warehouse members, stock levels, stock transactions, stock transfers, stock counts, stock alerts, and disposals.

> **Note:** All inventory-domain endpoints listed below are also available under the shop-scoped prefix `/api/v1/shops/{shopSlug}/...`. For example, `/api/v1/warehouses` becomes `/api/v1/shops/{shopSlug}/warehouses`. The `ResolveShopFromSlug` middleware resolves the shop (branch) from the URL slug. See [API Overview](api-overview.md) for details on the slug-based URL convention.

## Endpoints

- [Warehouses](#1-warehouses)
- [Warehouse Members](#2-warehouse-members)
- [Stock Levels](#3-stock-levels)
- [Stock Transactions](#4-stock-transactions)
- [Stock Transfers](#5-stock-transfers)
- [Stock Counts](#6-stock-counts-physical-inventory)
- [Stock Alerts](#7-stock-alerts)
- [Disposals](#8-disposals)
- [Production Calculator](#9-production-calculator)

---

## 1. Warehouses

### Endpoints

| Method | Endpoint                                | Description                       |
| ------ | --------------------------------------- | --------------------------------- |
| GET    | `/api/v1/warehouses`                    | List warehouses                   |
| POST   | `/api/v1/warehouses`                    | Create warehouse (Org Admin only) |
| GET    | `/api/v1/warehouses/{id}`               | Get warehouse detail              |
| PUT    | `/api/v1/warehouses/{id}`               | Update warehouse (Org Admin only) |
| DELETE | `/api/v1/warehouses/{id}`               | Soft delete (if no stock)         |
| POST   | `/api/v1/warehouses/{id}/restore`       | Restore                           |
| PUT    | `/api/v1/warehouses/{id}/settings`      | Update auto-approve settings      |
| POST   | `/api/v1/warehouses/{id}/toggle-active` | Toggle is_active                  |
| GET    | `/api/v1/warehouses/branches`           | Get branches that have warehouses |

### List Filters

| Parameter      | Type    | Description              |
| -------------- | ------- | ------------------------ |
| `search`       | string  | Search by name or code   |
| `type`         | string  | Filter by warehouse type |
| `branch_id`    | uuid    | Filter by branch         |
| `is_active`    | boolean | Filter by active status  |
| `with_trashed` | boolean | Include soft-deleted     |

### Fields

| Field                         | Type    | Required | Description                                        |
| ----------------------------- | ------- | -------- | -------------------------------------------------- |
| `code`                        | string  | Yes      | Unique per org (e.g., "WH-01")                     |
| `name`                        | string  | Yes      | Warehouse name                                     |
| `type`                        | enum    | Yes      | main, branch, production                           |
| `branch_id`                   | uuid    | No       | Associated branch (nullable)                       |
| `address`                     | text    | No       | Physical address                                   |
| `is_active`                   | boolean | No       | Active status                                      |
| `auto_approve_stock_in`       | boolean | No       | Auto-approve stock-in transactions                 |
| `auto_approve_stock_out`      | boolean | No       | Auto-approve stock-out transactions                |
| `auto_approve_batch`          | boolean | No       | Auto-approve material batches                      |
| `auto_approve_disposal`       | boolean | No       | Auto-approve disposals                             |
| `disposal_approval_threshold` | decimal | No       | Cost threshold for disposal approval (nullable)    |

### Access Control

- Non-admin users see only warehouses they are assigned to (via `warehouse_members`)
- Org Admin sees all warehouses
- Only Org Admin can create, update, or delete warehouses and change settings

---

## 2. Warehouse Members

### Endpoints

| Method | Endpoint                                     | Description                    |
| ------ | -------------------------------------------- | ------------------------------ |
| GET    | `/api/v1/warehouses/{id}/members`            | List members                   |
| POST   | `/api/v1/warehouses/{id}/members`            | Add member                     |
| PUT    | `/api/v1/warehouses/{id}/members/{userId}`   | Update member role             |
| DELETE | `/api/v1/warehouses/{id}/members/{userId}`   | Remove member                  |
| GET    | `/api/v1/warehouses/{id}/available-users`    | Get users not yet in warehouse |

### Fields

| Field     | Type | Required | Description          |
| --------- | ---- | -------- | -------------------- |
| `user_id` | uuid | Yes      | User to add          |
| `role`    | enum | Yes      | `manager` or `staff` |

---

## 3. Stock Levels

### Endpoints

| Method | Endpoint                                    | Description                           |
| ------ | ------------------------------------------- | ------------------------------------- |
| GET    | `/api/v1/warehouses/{id}/stock-levels`      | List stock levels in warehouse        |
| GET    | `/api/v1/stock-levels`                      | List all stock levels (cross-warehouse) |
| GET    | `/api/v1/stock-levels/{id}`                 | Get one stock level                   |
| PUT    | `/api/v1/stock-levels/{id}`                 | Update alert settings only            |
| GET    | `/api/v1/stock-levels/{id}/movements`       | Get movement history for item         |

### List Filters

| Parameter      | Type   | Description                            |
| -------------- | ------ | -------------------------------------- |
| `warehouse_id` | uuid   | Filter by warehouse                    |
| `search`       | string | Search by variant/material name or SKU |
| `stock_status` | string | `low_stock`, `out_of_stock`, `normal`  |
| `sort`         | string | Sort field (e.g., `-quantity`)         |

### Request Examples

#### Update Alert Settings

**PUT /api/v1/stock-levels/{id}**

```json
{
  "min_stock": 20.0,
  "max_stock": 500.0,
  "alert_enabled": true
}
```

> **Note:** Stock level `quantity` cannot be edited directly. It changes only through stock transactions, transfers, counts, or production.

#### Movement History Response

```json
{
  "data": [
    {
      "id": "...",
      "movement_type": "in",
      "quantity": 100.0,
      "quantity_before": 50.0,
      "quantity_after": 150.0,
      "unit": "pcs",
      "stock_transaction": {
        "id": "...",
        "transaction_code": "SI-20260403-001",
        "type": "stock_in",
        "sub_type": "purchase"
      },
      "created_at": "2026-04-03T08:00:00Z"
    }
  ]
}
```

---

## 4. Stock Transactions

### Endpoints

| Method | Endpoint                                    | Description                        |
| ------ | ------------------------------------------- | ---------------------------------- |
| GET    | `/api/v1/stock-transactions`                | List transactions                  |
| POST   | `/api/v1/stock-transactions`                | Create transaction (draft)         |
| GET    | `/api/v1/stock-transactions/{id}`           | Get transaction detail             |
| PUT    | `/api/v1/stock-transactions/{id}`           | Update (draft/pending only)        |
| DELETE | `/api/v1/stock-transactions/{id}`           | Soft delete (draft/cancelled only) |
| POST   | `/api/v1/stock-transactions/{id}/submit`    | draft -> pending                   |
| POST   | `/api/v1/stock-transactions/{id}/approve`   | pending -> completed (applies stock) |
| POST   | `/api/v1/stock-transactions/{id}/cancel`    | draft/pending -> cancelled         |

### List Filters

| Parameter      | Type   | Description                                 |
| -------------- | ------ | ------------------------------------------- |
| `warehouse_id` | uuid   | Filter by warehouse                         |
| `type`         | string | `stock_in` or `stock_out`                   |
| `sub_type`     | string | purchase, sales, production, disposal, etc. |
| `status`       | string | draft, pending, completed, cancelled        |
| `date_from`    | date   | Start date filter                           |
| `date_to`      | date   | End date filter                             |
| `search`       | string | Search by transaction code                  |

### Request Examples

#### Create Transaction

**POST /api/v1/stock-transactions**

```json
{
  "type": "stock_in",
  "sub_type": "purchase",
  "warehouse_id": "019d5286-...",
  "note": "Purchase from supplier ABC",
  "items": [
    {
      "product_sku_id": "019d5287-...",
      "quantity": 100,
      "base_quantity": 100,
      "unit": "pcs",
      "unit_price": 15000
    }
  ]
}
```

**Auto-generated fields:**
- `transaction_code`: `SI-YYYYMMDD-XXX` (stock_in) or `SO-YYYYMMDD-XXX` (stock_out)
- `created_by_id`: from the authenticated user

**Auto-approval:** If the warehouse has a matching auto-approve flag AND the creator is Manager or OrgAdmin, the transaction is auto-approved on create (skips draft/pending, goes directly to completed).

### Transaction Status Workflow

```text
  draft --submit--> pending --approve--> completed
    |                  |
    | cancel           | cancel
    v                  v
  cancelled          cancelled
```

### What Happens on Approve (to Completed)

1. Row-lock stock levels (`lockForUpdate()`)
2. For each item: adjust stock level (+ for stock_in, - for stock_out)
3. Reject if any stock_out would result in negative quantity
4. Create immutable `StockMovement` records (audit trail)
5. Check, create, or resolve stock alerts
6. Set `approved_by_id`, `approved_at`, `completed_at`

---

## 5. Stock Transfers

### Endpoints

| Method | Endpoint                                   | Description                            |
| ------ | ------------------------------------------ | -------------------------------------- |
| GET    | `/api/v1/stock-transfers`                  | List transfers                         |
| POST   | `/api/v1/stock-transfers`                  | Create transfer (draft)                |
| GET    | `/api/v1/stock-transfers/{id}`             | Get transfer detail                    |
| PUT    | `/api/v1/stock-transfers/{id}`             | Update (draft only)                    |
| DELETE | `/api/v1/stock-transfers/{id}`             | Soft delete (draft/cancelled)          |
| POST   | `/api/v1/stock-transfers/{id}/submit`      | draft -> pending                       |
| POST   | `/api/v1/stock-transfers/{id}/approve`     | pending -> in_transit (deducts stock)  |
| POST   | `/api/v1/stock-transfers/{id}/receive`     | in_transit -> completed (adds stock)   |
| POST   | `/api/v1/stock-transfers/{id}/cancel`      | Cancel (reverses if in_transit)        |

### List Filters

| Parameter                  | Type   | Description                                          |
| -------------------------- | ------ | ---------------------------------------------------- |
| `source_warehouse_id`      | uuid   | Filter by source                                     |
| `destination_warehouse_id` | uuid   | Filter by destination                                |
| `status`                   | string | draft, pending, in_transit, completed, cancelled     |
| `date_from` / `date_to`   | date   | Date range                                           |
| `search`                   | string | Search by transfer code                              |

### Transfer Status Workflow

```text
  draft --submit--> pending --approve--> in_transit --receive--> completed
                       |
                       | cancel (reverses stock if in_transit)
                       v
                    cancelled
```

### Approve (to InTransit)

1. Check stock availability at source warehouse (row-locked)
2. Create `stock_out` transaction at source (type=stock_out, sub_type=transfer_out, status=completed)
3. Deduct stock levels at source
4. Create stock movements at source
5. Set `approved_by_id`, `approved_at`, link `stock_out_transaction_id`

### Receive (to Completed)

```json
{
  "items": [
    {
      "id": "item-uuid",
      "received_quantity": 48,
      "received_base_quantity": 48,
      "note": "2 units damaged in transit"
    }
  ]
}
```

1. Create `stock_in` transaction at destination (type=stock_in, sub_type=transfer_in, status=completed)
2. Add stock at destination using `received_quantity` (can differ from `sent_quantity`)
3. Create stock movements at destination
4. Set `received_by_id`, `received_at`, `completed_at`, link `stock_in_transaction_id`

### Cancel (from InTransit)

If the transfer is in_transit (stock already deducted from source):
1. Create reversal `stock_in` transaction at source to restore deducted stock
2. Create stock movements for the reversal
3. Re-check alerts at source

### Fields

| Field                      | Type     | Required | Description                             |
| -------------------------- | -------- | -------- | --------------------------------------- |
| `transfer_code`            | string   | No       | Auto-generated: `TR-YYYYMMDD-XXX`       |
| `source_warehouse_id`      | uuid     | Yes      | Source warehouse                        |
| `destination_warehouse_id` | uuid     | Yes      | Destination warehouse (must differ)     |
| `status`                   | string   | No       | draft, pending, in_transit, completed, cancelled |
| `stock_out_transaction_id` | uuid     | No       | Linked stock-out (after approve)        |
| `stock_in_transaction_id`  | uuid     | No       | Linked stock-in (after receive)         |
| `received_by_id`           | uuid     | No       | Who received at destination             |
| `received_at`              | datetime | No       | When received                           |

---

## 6. Stock Counts (Physical Inventory)

### Endpoints

| Method | Endpoint                                    | Description                              |
| ------ | ------------------------------------------- | ---------------------------------------- |
| GET    | `/api/v1/stock-counts`                      | List stock counts                        |
| POST   | `/api/v1/stock-counts`                      | Create count session                     |
| GET    | `/api/v1/stock-counts/{id}`                 | Get count with items                     |
| POST   | `/api/v1/stock-counts/{id}/add-items`       | Add items (partial scope, draft only)    |
| POST   | `/api/v1/stock-counts/{id}/start`           | draft -> in_progress                     |
| POST   | `/api/v1/stock-counts/{id}/update-items`    | Update counted quantities                |
| POST   | `/api/v1/stock-counts/{id}/submit`          | in_progress -> pending_approval          |
| POST   | `/api/v1/stock-counts/{id}/approve`         | pending -> approved (creates adjustments)|
| POST   | `/api/v1/stock-counts/{id}/cancel`          | Cancel (except from approved)            |

> **Note:** Stock counts cannot be deleted. History is preserved permanently.

### List Filters

| Parameter      | Type   | Description                                                     |
| -------------- | ------ | --------------------------------------------------------------- |
| `warehouse_id` | uuid   | Filter by warehouse                                             |
| `status`       | string | draft, in_progress, pending_approval, approved, cancelled       |
| `scope`        | string | `full` or `partial`                                             |
| `date_from/to` | date   | Date range                                                      |
| `search`       | string | Search by count code                                            |

### Request Examples

#### Create Count

**POST /api/v1/stock-counts**

```json
{
  "warehouse_id": "019d5286-...",
  "scope": "full",
  "note": "Q2 2026 inventory count"
}
```

- `scope=full`: auto-snapshots all items with qty > 0 from the warehouse
- `scope=partial`: empty session, add items later via `add-items`

#### Update Counted Quantities

**POST /api/v1/stock-counts/{id}/update-items** (in_progress only)

```json
{
  "items": [
    { "id": "item-uuid", "counted_quantity": 148.0, "note": "2 units damaged" }
  ]
}
```

`difference` is auto-calculated: `counted_quantity - system_quantity`

### Count Status Workflow

```text
  draft --start_counting--> in_progress --submit--> pending_approval --approve--> approved
                                                                     --cancel--> cancelled
```

### What Happens on Approve

1. Group items by difference direction:
   - Surplus (counted > system) -> 1 `stock_in/adjustment_in` transaction
   - Shortage (counted < system) -> 1 `stock_out/adjustment_out` transaction
2. For each item with non-zero difference: adjust stock level
3. `system_quantity` is immutable (snapshot at time of count creation)
4. Stock counts allow negative results (counted quantity is authoritative)
5. Maximum 2 adjustment transactions created
6. Reference: `reference_type='StockCount'`, `reference_id=stock_count.id`

> **Warning:** The warehouse is NOT locked during counting. Other operations (receive, ship) can occur in parallel. The snapshot reflects the moment the count was created.

---

## 7. Stock Alerts

### Endpoints

| Method | Endpoint                        | Description              |
| ------ | ------------------------------- | ------------------------ |
| GET    | `/api/v1/stock-alerts`          | List active alerts       |
| GET    | `/api/v1/stock-alerts/summary`  | Dashboard summary counts |

> **Note:** Alerts are fully automated. There are no manual create, update, or delete operations.

### List Filters

| Parameter      | Type   | Description                   |
| -------------- | ------ | ----------------------------- |
| `status`       | string | `active` or `resolved`        |
| `warehouse_id` | uuid   | Filter by warehouse           |
| `alert_type`   | string | `low_stock` or `out_of_stock` |
| `search`       | string | Search by item name           |

### Summary Response

```json
{
  "data": {
    "total_active": 12,
    "low_stock": 8,
    "out_of_stock": 4
  }
}
```

### Auto-trigger Rules

Checked after every stock level change:
- `qty = 0` -> `out_of_stock` alert
- `0 < qty <= min_stock` -> `low_stock` alert
- `qty > min_stock` -> resolve existing alert
- Only 1 active alert per (warehouse + item)
- If `alert_enabled = false` on the stock level, no alerts are created

---

## 8. Disposals

### Endpoints

| Method | Endpoint                             | Description                   |
| ------ | ------------------------------------ | ----------------------------- |
| GET    | `/api/v1/disposals`                  | List disposal transactions    |
| POST   | `/api/v1/disposals`                  | Create disposal               |
| GET    | `/api/v1/disposals/{id}`             | Get disposal detail           |
| PUT    | `/api/v1/disposals/{id}`             | Update (draft only)           |
| DELETE | `/api/v1/disposals/{id}`             | Delete (draft/cancelled only) |
| POST   | `/api/v1/disposals/{id}/submit`      | draft -> pending              |
| POST   | `/api/v1/disposals/{id}/approve`     | pending -> completed          |
| POST   | `/api/v1/disposals/{id}/cancel`      | Cancel                        |
| GET    | `/api/v1/disposals/waste-report`     | Waste statistics report       |

### List Filters

| Parameter        | Type   | Description                              |
| ---------------- | ------ | ---------------------------------------- |
| `warehouse_id`   | uuid   | Filter by warehouse                      |
| `status`         | string | draft, pending, completed, cancelled     |
| `disposal_reason`| string | Filter by reason                         |
| `date_from/to`   | date   | Date range                               |

### Request Examples

#### Create Disposal

A disposal is a `stock_out` transaction with `sub_type=disposal`. Each item gets a `DisposalRecord`.

```json
{
  "warehouse_id": "019d5286-...",
  "note": "Expired items disposal April 2026",
  "items": [
    {
      "product_sku_id": "019d5287-...",
      "quantity": 10,
      "base_quantity": 10,
      "unit": "pcs",
      "disposal_reason": "expired",
      "cost_at_disposal": 150000,
      "note": "Expired on April 1"
    }
  ]
}
```

### Disposal Approval

- Normal approval flow (checks stock like any stock_out)
- **Threshold check:** If the warehouse has `disposal_approval_threshold` set:
  - Total cost below threshold -> follows `auto_approve_disposal` setting
  - Total cost at or above threshold -> requires OrgAdmin approval regardless

### Waste Report

**GET /api/v1/disposals/waste-report?warehouse_id=xxx&date_from=2026-01-01&date_to=2026-04-01**

```json
{
  "data": {
    "summary": {
      "total_disposals": 45,
      "total_cost": 2500000
    },
    "by_reason": [
      { "reason": "expired", "count": 20, "cost": 1200000 },
      { "reason": "damaged", "count": 15, "cost": 800000 }
    ],
    "top_items": [
      { "variant_name": "Fresh Milk 500ml", "count": 12, "total_quantity": 120, "total_cost": 600000 }
    ],
    "daily_trend": [
      { "date": "2026-03-01", "count": 3, "cost": 150000 }
    ]
  }
}
```

---

## 9. Production Calculator

### Endpoints

| Method | Endpoint                                | Description                             |
| ------ | --------------------------------------- | --------------------------------------- |
| POST   | `/api/v1/production-calculator/preview` | Preview what can be produced from stock |

### Request

```json
{
  "warehouse_id": "019d5286-...",
  "output_variant_id": "019d5287-...",
  "planned_quantity": 100
}
```

### Response

```json
{
  "data": {
    "feasible": false,
    "max_producible": 80,
    "components": [
      {
        "material_id": "019d5288-...",
        "name": "Coffee pre-mix",
        "required_quantity": 10.0,
        "available_quantity": 8.0,
        "unit": "kg",
        "sufficient": false
      }
    ]
  }
}
```

---

## Enums

### Warehouse Type

| Value        | Description                           |
| ------------ | ------------------------------------- |
| `main`       | Main warehouse                        |
| `branch`     | Branch-level warehouse                |
| `production` | Production/manufacturing warehouse    |

### Warehouse Member Role

| Value     | Description      |
| --------- | ---------------- |
| `manager` | Warehouse manager |
| `staff`   | Warehouse staff  |

### Stock Transaction Type

| Value       | Description |
| ----------- | ----------- |
| `stock_in`  | Stock in    |
| `stock_out` | Stock out   |

### Stock Transaction Sub Type

| Value            | Description                          |
| ---------------- | ------------------------------------ |
| `purchase`       | Purchase from supplier               |
| `sales`          | Sales to customer                    |
| `production`     | Production input/output              |
| `transfer_in`    | Incoming transfer from another warehouse |
| `transfer_out`   | Outgoing transfer to another warehouse |
| `return`         | Customer or supplier return          |
| `disposal`       | Disposal of damaged/expired items    |
| `adjustment_in`  | Stock count surplus adjustment       |
| `adjustment_out` | Stock count shortage adjustment      |
| `other`          | Other                                |

### Stock Transaction Status

| Value       | Description                  |
| ----------- | ---------------------------- |
| `draft`     | Initial state, editable      |
| `pending`   | Submitted, awaiting approval |
| `completed` | Approved and stock applied   |
| `cancelled` | Cancelled                    |

### Stock Transfer Status

| Value        | Description                        |
| ------------ | ---------------------------------- |
| `draft`      | Initial state, editable            |
| `pending`    | Submitted, awaiting approval       |
| `in_transit` | Approved, stock deducted at source |
| `completed`  | Received at destination            |
| `cancelled`  | Cancelled                          |

### Stock Count Status

| Value              | Description                         |
| ------------------ | ----------------------------------- |
| `draft`            | Initial state, items being selected |
| `in_progress`      | Counting in progress                |
| `pending_approval` | Submitted, awaiting approval        |
| `approved`         | Approved, adjustments applied       |
| `cancelled`        | Cancelled                           |

### Stock Count Scope

| Value     | Description                        |
| --------- | ---------------------------------- |
| `full`    | All items in warehouse             |
| `partial` | Manually selected items only       |

### Stock Alert Type

| Value          | Description          |
| -------------- | -------------------- |
| `low_stock`    | Below minimum stock  |
| `out_of_stock` | Zero quantity        |

### Stock Alert Status

| Value      | Description                    |
| ---------- | ------------------------------ |
| `active`   | Alert is active                |
| `resolved` | Stock restored above threshold |

### Movement Type

| Value | Description    |
| ----- | -------------- |
| `in`  | Stock increase |
| `out` | Stock decrease |

### Disposal Reason

| Value            | Description                    |
| ---------------- | ------------------------------ |
| `expired`        | Item past expiration date      |
| `overproduction` | Excess production              |
| `damaged`        | Physical damage                |
| `quality`        | Quality control failure        |
| `contaminated`   | Contaminated item              |
| `other`          | Other reason                   |
