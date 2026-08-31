---
title: Customer Domain
category: explanation
tags: [customer, order, order-item, payment, pos, workflow, stock]
summary: Explains the customer and order management domain -- customer records, order lifecycle, line items, payment, and the automatic stock deduction triggered on order completion.
related: [inventory-domain, product-domain, api-customer]
---

# Customer Domain

This document explains the customer and order management domain -- customer records, order creation, line-item pricing, order workflow, and the stock deduction that happens automatically when an order completes. Read this before implementing any POS or order-related feature.

## Overview

The customer domain covers everything that happens when a customer places an order at a shop:

1. Optionally look up or create a **Customer** record (walk-in customers are anonymous).
2. Create a **CustomerOrder** header tied to a branch, a table (dine-in), or no table (takeaway).
3. Add one or more **CustomerOrderItem** lines, each snapshotting the unit price at the time of the order.
4. Move the order through the workflow: `pending → confirmed → completed`.
5. On completion, the system automatically creates a `stock_out` transaction in the Inventory domain to deduct sold items from the warehouse.

---

## Customer

### What it is

A customer record stores contact and billing information for a known customer -- a returning guest or a business customer who needs a VAT invoice. Walk-in customers do **not** require a record; `CustomerOrder.customer_id` is nullable for this reason.

### Scope

Customers are scoped to an **Organization**. The same customer can be registered at multiple branches within the organization, but the record is shared at the org level.

### Key fields

| Field | Required | Purpose |
| ----- | -------- | ------- |
| `name` | Yes | Full name or company name |
| `phone` | No | Used to look up returning customers (BR-C01) |
| `email` | No | For sending VAT invoices |
| `address` | No | Delivery or invoice address |
| `tax_code` | No | Company tax ID for VAT invoices (BR-C02) |
| `note` | No | Internal notes (allergies, preferences) |

### Business rules

| Rule | Description |
| ---- | ----------- |
| BR-C01 | `phone` is optional but recommended -- it is the primary lookup key for returning customers |
| BR-C02 | `tax_code` is required only when the customer needs a VAT invoice for a company |
| BR-C03 | Customers are soft-deleted -- hard deletes are not allowed to preserve order history |

### Example

A coffee shop regular is registered when they first ask for a loyalty card:

```json
{
  "name": "Nguyen Van An",
  "phone": "0901234567",
  "email": "an@example.com",
  "note": "Allergic to dairy"
}
```

A corporate client for VAT invoicing:

```json
{
  "name": "Acme Co. Ltd",
  "tax_code": "0123456789",
  "address": "123 Tran Hung Dao, Ha Noi",
  "email": "accounting@acme.vn"
}
```

---

## CustomerOrder

### What it is

A customer order is the header record for a single purchase transaction at a branch. It holds totals, status, payment details, and links to the customer, table, and staff member who created it.

### Scope

Orders are scoped to a **Branch (Shop)**. Order codes are globally unique across the system.

### Key fields

| Field | Required | Purpose |
| ----- | -------- | ------- |
| `order_code` | Auto | Auto-generated: `ORD-YYYYMMDD-XXX`, sequential per day (BR-O01) |
| `order_type` | Yes | `dine_in` (table required) or `takeaway` (no table) |
| `status` | Auto | Workflow status, default `pending` |
| `subtotal` | Yes | Sum of all item subtotals before discount |
| `discount_amount` | Yes | Discount applied to the order, default `0` |
| `total_amount` | Yes | `subtotal - discount_amount` (BR-O04) |
| `payment_method` | No | Set when payment is made (BR-O05) |
| `paid_at` | No | Timestamp when payment was received (BR-O05) |
| `customer_id` | No | Nullable -- walk-in customers allowed (BR-O02) |
| `table_id` | No | Nullable -- takeaway orders allowed (BR-O03) |
| `created_by_id` | Yes | Soft FK to the SSO user (staff) who created the order |
| `stock_out_transaction_id` | No | Soft FK to the auto-created inventory transaction (BR-O06) |

### Business rules

| Rule | Description |
| ---- | ----------- |
| BR-O01 | `order_code` is auto-generated in the format `ORD-YYYYMMDD-XXX` with sequential numbering per day |
| BR-O02 | `customer_id` is nullable -- walk-in customers do not need a Customer record |
| BR-O03 | `table_id` is nullable -- takeaway orders are not linked to a table |
| BR-O04 | `total_amount = subtotal - discount_amount` |
| BR-O05 | `payment_method` and `paid_at` are set together when payment is recorded |
| BR-O06 | When an order moves to `completed`, the system automatically creates a `stock_out` transaction (sub_type `sales`) in the inventory domain |
| BR-O07 | Completed orders are immutable -- no edits or deletes are allowed after reaching `completed` |

### Workflow

```text
pending ──confirm──> confirmed ──complete──> completed (final)
    |                     |
    +──cancel──>    cancelled (final)
                          |
                    +──cancel──> cancelled (final)
```

| Status | Allowed actions | Stock impact |
| ------ | --------------- | ------------ |
| `pending` | Edit items, confirm, cancel | None |
| `confirmed` | Complete, cancel | None |
| `completed` | View only | `stock_out` (sales) created automatically |
| `cancelled` | View only | None |

### How total_amount is calculated

```text
subtotal      = sum of (item.quantity × item.unit_price) for all items
total_amount  = subtotal - discount_amount
```

The application is responsible for keeping `subtotal` and `total_amount` consistent. They are stored (not computed on the fly) to ensure immutability after the order completes.

### Example

A dine-in order for table 3:

```json
{
  "order_code": "ORD-20260410-001",
  "order_type": "dine_in",
  "status": "pending",
  "subtotal": 150000,
  "discount_amount": 0,
  "total_amount": 150000,
  "customer_id": null,
  "table_id": "uuid-of-table-3"
}
```

---

## CustomerOrderItem

### What it is

A customer order item is a single line in an order -- one product variant (SKU), with a quantity, a price snapshot, and an optional note.

### Key fields

| Field | Required | Purpose |
| ----- | -------- | ------- |
| `product_sku_id` | Yes | The variant ordered. RESTRICT -- cannot delete a SKU that has existing orders (BR-OI03) |
| `quantity` | Yes | Amount ordered (supports decimal for weight-based items) |
| `unit_price` | Yes | Price per unit **at time of order** -- immutable snapshot (BR-OI01) |
| `subtotal` | Yes | `quantity × unit_price` (BR-OI02) |
| `note` | No | Special instructions for this item (e.g., "no onion", "less spicy") |

### Business rules

| Rule | Description |
| ---- | ----------- |
| BR-OI01 | `unit_price` is a snapshot of the price at the moment the order was placed. It does not change when the menu price changes later |
| BR-OI02 | `subtotal = quantity × unit_price`. The application must store this value, not recompute it at runtime |
| BR-OI03 | `product_sku_id` uses a RESTRICT foreign key -- a SKU cannot be deleted while it is referenced by any order item |
| BR-OI04 | Order items are cascade-deleted when the parent `CustomerOrder` is deleted |
| BR-OI06 | `POST /orders/{id}/items` merges into an existing pending line when `(product_sku_id, unit_price, note)` all match; any difference (or a line past `pending`) creates a new row |

### Why unit_price is a snapshot

Menu prices change over time. If `unit_price` were a live reference to the SKU's current price, historical revenue reports would show incorrect values every time a price is updated. Storing the snapshot at order time ensures that order history is always accurate regardless of future price changes.

### Quick-tap merge (BR-OI06)

`POST /orders/{id}/items` is idempotent in the sense that tapping the same dish twice on the POS catalog does **not** produce two 1-qty rows. Inside the service transaction, each incoming entry looks for a line on the same order where:

- `product_sku_id` matches,
- the resolved `unit_price` matches (so a menu-override at a different price stays separate),
- `note` matches (both null, or identical strings — "extra spicy" ≠ "plain"),
- `status` is still `pending`.

If a match is found, the existing line's `quantity` and `subtotal` are bumped in place. Otherwise a new line is inserted as before. The lookup uses `lockForUpdate()` so two concurrent `addItems` calls on the same order can't both create parallel duplicate rows.

Items that have moved past `pending` (kitchen picked them up — `preparing`, `ready`, `served`) are never merged into: they represent a batch that is already in motion, and bumping their quantity would silently change what the kitchen has already committed to. In that case a fresh `pending` line is created instead.

### Example

Two items in one order:

```json
[
  {
    "product_sku_id": "uuid-cafe-sua-m",
    "quantity": 2,
    "unit_price": 45000,
    "subtotal": 90000,
    "note": "Less sugar"
  },
  {
    "product_sku_id": "uuid-banh-mi",
    "quantity": 1,
    "unit_price": 30000,
    "subtotal": 30000,
    "note": null
  }
]
```

---

## Payment

Payment is recorded on the `CustomerOrder` by setting two fields together:

| Field | Description |
| ----- | ----------- |
| `payment_method` | How the customer paid |
| `paid_at` | When payment was received |

### Payment methods

| Value | Label |
| ----- | ----- |
| `cash` | Cash |
| `card` | Card (credit/debit) |
| `transfer` | Bank transfer |
| `other` | Other method |

Both fields are null until payment is made. Recording payment does not automatically change the order status -- the order must still be explicitly moved to `completed` by the staff.

---

## Stock Impact on Completion

When an order moves to `completed`, the system creates a `stock_out` transaction automatically:

- **Transaction type:** `stock_out`
- **Sub-type:** `sales`
- **Status:** auto-completed (skips the pending step)
- **Items:** one transaction item per `CustomerOrderItem`, deducting the sold quantity from the branch's active warehouse
- **Link:** `CustomerOrder.stock_out_transaction_id` stores the UUID of the created transaction (soft FK -- no database constraint)

> **Note:** `stock_out_transaction_id` is a soft foreign key. There is no database-level FK constraint between `customer_orders` and `stock_transactions` because these two tables belong to different domains. The soft FK enables cross-domain lookup without introducing schema coupling.

---

## Relationships

```text
Organization
+-- Brand
    +-- Branch (Shop)
        +-- CustomerOrder
        |   +-- customer_id --> Customer (nullable, walk-in allowed)
        |   +-- table_id --> Table (nullable, takeaway allowed)
        |   +-- created_by_id --> SSO User (soft FK)
        |   +-- stock_out_transaction_id --> StockTransaction (soft FK)
        |   +-- CustomerOrderItem (1..n)
        |       +-- product_sku_id --> ProductSku (RESTRICT)
        +-- Customer (registered customers)
```

---

## Business Rules Summary

| Rule | Applies to | Description |
| ---- | ---------- | ----------- |
| BR-C01 | Customer | Phone is the primary lookup key for returning customers |
| BR-C02 | Customer | tax_code required for VAT invoice customers |
| BR-C03 | Customer | Soft-delete only -- preserve order history |
| BR-O01 | CustomerOrder | Order code auto-generated: ORD-YYYYMMDD-XXX |
| BR-O02 | CustomerOrder | customer_id nullable -- walk-in customers allowed |
| BR-O03 | CustomerOrder | table_id nullable -- takeaway orders allowed |
| BR-O04 | CustomerOrder | total_amount = subtotal - discount_amount |
| BR-O05 | CustomerOrder | payment_method and paid_at set together on payment |
| BR-O06 | CustomerOrder | Completion auto-creates stock_out (sales) transaction |
| BR-O07 | CustomerOrder | Completed orders are immutable |
| BR-OI01 | CustomerOrderItem | unit_price is a snapshot at time of order |
| BR-OI02 | CustomerOrderItem | subtotal = quantity × unit_price |
| BR-OI03 | CustomerOrderItem | RESTRICT FK on product_sku_id |
| BR-OI04 | CustomerOrderItem | Cascade delete when parent order is deleted |
| BR-OI06 | CustomerOrderItem | addItems merges into an existing pending line when (product_sku_id, unit_price, note) match |
