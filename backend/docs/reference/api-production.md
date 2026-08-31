---
title: Production Domain API Reference
category: reference
tags: [api, material-batch, production-order, endpoint, workflow]
summary: Lists all API endpoints for the production domain covering material batches (with submit/approve/start/complete/cancel workflow) and production orders.
related: [production-flow, inventory-domain, api-overview]
---

# Production Domain API Reference

This document covers all API endpoints for the production domain: material batches and production orders.

## Endpoints

- [Material Batches](#material-batches)
- [Production Orders](#production-orders)

---

## Material Batches

### Endpoints

| Method | Endpoint                                    | Description                    |
| ------ | ------------------------------------------- | ------------------------------ |
| GET    | `/api/v1/material-batches`                  | List material batches          |
| POST   | `/api/v1/material-batches`                  | Create batch (draft)           |
| GET    | `/api/v1/material-batches/{id}`             | Get batch detail               |
| PUT    | `/api/v1/material-batches/{id}`             | Update batch (draft only)      |
| DELETE | `/api/v1/material-batches/{id}`             | Soft delete (draft only)       |
| POST   | `/api/v1/material-batches/{id}/submit`      | Submit for approval            |
| POST   | `/api/v1/material-batches/{id}/approve`     | Approve batch                  |
| POST   | `/api/v1/material-batches/{id}/start`       | Start production               |
| POST   | `/api/v1/material-batches/{id}/complete`    | Complete (record actual yield) |
| POST   | `/api/v1/material-batches/{id}/cancel`      | Cancel batch                   |

---

## Production Orders

### Endpoints

| Method | Endpoint                                     | Description                       |
| ------ | -------------------------------------------- | --------------------------------- |
| GET    | `/api/v1/production-orders`                  | List production orders            |
| POST   | `/api/v1/production-orders`                  | Create order (draft)              |
| GET    | `/api/v1/production-orders/{id}`             | Get order detail                  |
| PUT    | `/api/v1/production-orders/{id}`             | Update order (draft only)         |
| DELETE | `/api/v1/production-orders/{id}`             | Soft delete (draft only)          |
| POST   | `/api/v1/production-orders/{id}/submit`      | Submit for approval               |
| POST   | `/api/v1/production-orders/{id}/approve`     | Approve order                     |
| POST   | `/api/v1/production-orders/{id}/start`       | Start production                  |
| POST   | `/api/v1/production-orders/{id}/complete`    | Complete (record actual quantity) |
| POST   | `/api/v1/production-orders/{id}/cancel`      | Cancel order                      |

---

## Request Examples

### Create Material Batch

**POST /api/v1/material-batches**

**Request:**

```json
{
  "warehouse_id": "019d5286-...",
  "material_id": "019d5288-...",
  "multiplier": 2.0,
  "planned_yield": 10.0000,
  "yield_unit": "kg",
  "expiry_date": "2026-07-01",
  "note": "Flour batch round 2",
  "items": [
    {
      "component_type": "material",
      "material_id": "019d528a-...",
      "planned_quantity": 5.0000,
      "unit": "kg"
    },
    {
      "component_type": "sku",
      "product_sku_id": "019d5287-...",
      "planned_quantity": 20,
      "unit": "pcs"
    }
  ]
}
```

**Response (201):**

```json
{
  "data": {
    "id": "019d5295-...",
    "batch_code": "MB-20260403-001",
    "warehouse_id": "019d5286-...",
    "material_id": "019d5288-...",
    "multiplier": 2.0,
    "planned_yield": 10.0000,
    "actual_yield": null,
    "yield_unit": "kg",
    "status": "draft",
    "stock_out_transaction_id": null,
    "stock_in_transaction_id": null,
    "note": "Flour batch round 2",
    "created_by_id": "019d5286-...",
    "items": [
      {
        "id": "019d5296-...",
        "component_type": "material",
        "material_id": "019d528a-...",
        "planned_quantity": 5.0000,
        "actual_quantity": null,
        "unit": "kg",
        "stock_available": 25.0000
      }
    ],
    "created_at": "2026-04-03T08:00:00.000000Z"
  }
}
```

### Complete Material Batch

**POST /api/v1/material-batches/{id}/complete**

Records actual yield and triggers automatic stock transactions.

**Request:**

```json
{
  "actual_yield": 9.5000,
  "items": [
    {
      "id": "019d5296-...",
      "actual_quantity": 4.8000
    }
  ]
}
```

**Response (200):**

```json
{
  "data": {
    "id": "019d5295-...",
    "status": "completed",
    "actual_yield": 9.5000,
    "completed_at": "2026-04-03T12:00:00.000000Z",
    "stock_out_transaction_id": "019d529a-...",
    "stock_in_transaction_id": "019d529b-..."
  }
}
```

### Create Production Order

**POST /api/v1/production-orders**

**Request:**

```json
{
  "warehouse_id": "019d5286-...",
  "output_sku_id": "019d5287-...",
  "planned_quantity": 100.0000,
  "output_unit": "pcs",
  "recipe_multiplier": 1.0,
  "note": "Black coffee size S production - April batch",
  "items": [
    {
      "component_type": "material",
      "material_id": "019d5288-...",
      "planned_quantity": 10.0000,
      "unit": "kg"
    }
  ]
}
```

**Response (201):**

```json
{
  "data": {
    "id": "019d5298-...",
    "order_code": "PO-20260403-001",
    "warehouse_id": "019d5286-...",
    "output_sku_id": "019d5287-...",
    "planned_quantity": 100.0000,
    "actual_quantity": null,
    "output_unit": "pcs",
    "status": "draft",
    "items": [],
    "created_at": "2026-04-03T08:00:00.000000Z"
  }
}
```

---

## Production Workflow

```text
  draft --submit--> pending --approve--> approved --start--> in_progress --complete--> completed
    |                  |
    | cancel           | cancel
    v                  v
  cancelled          cancelled
```

**Auto-approval:** If the warehouse has `auto_approve_batch = true`, material batches skip the `pending` step.

---

## Stock Impact

When a batch or order is **completed**, the system auto-creates 2 stock transactions:

### 1. Stock Out Transaction (components consumed)

```text
type: stock_out
sub_type: production
reference_type: MaterialBatch (or ProductionOrder)
reference_id: {batch_id}
items: [each component with actual_quantity]
```

### 2. Stock In Transaction (output produced)

```text
type: stock_in
sub_type: production
reference_type: MaterialBatch (or ProductionOrder)
reference_id: {batch_id}
items: [{output material or product_sku with actual_yield}]
```

Both transactions are auto-approved and completed immediately.

---

## Enums

### Material Batch Status / Production Order Status

| Value         | Description              |
| ------------- | ------------------------ |
| `draft`       | Initial state, editable  |
| `pending`     | Awaiting approval        |
| `approved`    | Approved                 |
| `in_progress` | Production in progress   |
| `completed`   | Production completed     |
| `cancelled`   | Cancelled                |

### Component Type

| Value      | Description     |
| ---------- | --------------- |
| `material` | Raw material    |
| `sku`      | Product SKU     |
