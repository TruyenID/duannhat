---
title: Order Domain
category: explanation
tags: [order, customer-order, spot, dine-in, takeaway, table-assignment, lifecycle]
summary: Explains the customer order domain — order types (spot, dine_in, takeaway), status lifecycle, table assignment flow, and the two-step create pattern (header first, items later).
related: [api-orders]
---

# Order Domain

This document explains the customer order domain and its core concepts. For endpoint details, see the [Orders API](../reference/api-orders.md) reference.

## Core concept

A `CustomerOrder` represents a single transaction at a branch — from the moment a staff member opens an order until payment is collected and the order is closed. Orders are branch-scoped and org-scoped.

## Order types

Orders have a `order_type` field that describes the service mode:

| Type | Description | Tables |
|------|-------------|--------|
| `spot` | Default. Quick/flexible order. | Optional — can be assigned later. |
| `dine_in` | Sit-down meal at a table. | Can be assigned at creation or later via init/merge. |
| `takeaway` | Customer takes food home. | Not expected. |

The `order_type` defaults to `spot` when not specified. Unlike a strict dine-in order, a spot order does not require table assignment at any point — it supports walk-up counter orders, quick sales, or orders where the table is determined after the fact.

## Status lifecycle

Dine-in and spot orders start at `open`; takeaway orders skip the table-side lifecycle and start at `pending` (the kitchen queue):

```text
open → dining → checkout → paying → closed
  │                          │
  │                          └── (partial payment parks here)
  │
  └──────────── voided ──────────────────
```

| Status | Meaning |
|--------|---------|
| `open` | Order created. Items can be added, tables can be assigned. Initial state for `spot` and `dine_in`. |
| `pending` | Takeaway order sitting in the kitchen queue; no table-side interaction. |
| `dining` | Guests are eating. Items can still be modified. |
| `checkout` | Staff initiated checkout. Items are locked. |
| `paying` | Payment in progress. An order stays in `paying` while any `remaining_amount` is owed (see [partial payment](#partial-payment-and-outstanding-debt)). |
| `closed` | Fully paid. Triggers stock-out transaction. Immutable. |
| `voided` | Cancelled. All items voided, tables released. |

## Two-step create pattern

Orders are created as header-only (no items). This supports the standard restaurant workflow:

1. **Create order header** — `POST /orders` with optional type, customer, tables, guest count
2. **Add items incrementally** — `POST /orders/{id}/items` as the customer decides

This matches the Toast/Square POS pattern where a table is opened first, then orders are taken over time.

## Table assignment

Tables are linked to orders via the `Table.current_order_id` reverse foreign key. Multiple tables sharing the same `current_order_id` represent merged tables (group seating).

Tables can be assigned in three ways:

1. **At creation** — pass `table_ids` in the create request
2. **Via init** — `PUT /orders/{id}/init` assigns tables using first-write-wins semantics (only sets if order has no tables yet)
3. **Via merge** — `POST /orders/{id}/merge-table` adds one additional table to an existing order

When an order is closed, tables transition to `cleaning` status. When voided, tables return to `free`.

## Init endpoint (first-write-wins)

The init endpoint (`PUT /orders/{id}/init`) supports deferred assignment with idempotent semantics:

- **`table_ids`**: only assigned if the order currently has no tables. If tables are already assigned, the field is silently ignored.
- **`guest_count`**: only saved if the current DB value is null. If already set, the field is silently ignored.

This makes the endpoint safe to retry from multiple devices — the first successful call wins, subsequent calls are no-ops for already-set fields.

## Financial fields

| Field | Description |
|-------|-------------|
| `subtotal` | Sum of non-voided item subtotals |
| `discount_amount` | Set at checkout |
| `service_charge` | Set at checkout |
| `tax_amount` | Set at checkout |
| `total_amount` | `subtotal - discount + service_charge + tax` |
| `paid_amount` | Cached sum of succeeded payments |
| `remaining_amount` | Computed accessor: `max(0, total_amount - paid_amount)` (not stored) |

## Item voiding (soft-void)

Items are never hard-deleted in response to a void. `POST /orders/{id}/items/{item}/void` sets:

- `status = voided`
- `voided_at = <timestamp>`
- `void_reason = <captured reason>`

The row stays in `customer_order_items` forever. `subtotal` and `total_amount` exclude voided items so totals remain honest, but the row is still returned inside `data.items[]` so clients can render an audit toggle ("▸ Show N voided items") and so reconciliation reports (daily void ratio, void-by-staff, void-by-SKU) can run against persistent data.

A reason is required — the API rejects void requests without `void_reason`. "Removed by staff" placeholders are not acceptable: the audit log must capture real staff intent (customer changed their mind, kitchen ran out of an ingredient, wrong SKU, etc.) for end-of-shift reconciliation to be useful.

Only items currently in `pending` status can be voided through this endpoint. Items that the kitchen has acknowledged (`preparing`, `ready`, `served`) go through the KDS workflow; POS surfaces a 409 preemptively by hiding the void button once status leaves `pending`.

## Partial payment and outstanding debt

A payment's `amount` does not have to cover the order's `remaining_amount`. When it doesn't:

1. The payment is recorded normally; `paid_amount` advances by the paid figure.
2. The order stays in `paying` (it does **not** advance to `closed`).
3. `remaining_amount = total_amount - paid_amount` is recomputed on every read.
4. The order becomes an **outstanding debt** against the attached customer.

No separate "debt" column exists — the shortfall is always derived from `total_amount - paid_amount` on the order itself. The POS surfaces outstanding debts on the customer's next visit via `GET /shops/{shop}/customers/{id}/outstanding`, which returns every non-closed, non-voided order with a positive `remaining_amount` for that customer plus the `total_owed` sum. Staff can opt-in to apply new-order funds toward past debt at checkout.

A partially paid order is kept off the active tab bar (filter `status=open,dining,checkout`) until the remaining balance is paid — it reappears in the debt reminder flow, not the floor view.
