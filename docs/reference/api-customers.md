---
title: Customers API
category: reference
tags: [customers, api, find-or-create, outstanding, debt, pos]
summary: Reference for the shop-scoped customer endpoints, including POS convenience flows — exact-phone find-or-create upsert and outstanding-debt listing for the payment-reminder prompt.
related: [api-orders, order-domain]
---

# Customers API

Reference doc for the shop-scoped customer endpoints. All routes are mounted under `/api/v1/shops/{shopSlug}/customers` and require Sanctum authentication. Customers are org-scoped and branch-scoped — the resolved shop's `organization_id`, `brand_id`, and `branch_id` are injected on write.

## Endpoints

| Method | Path | Purpose | Auth |
|--------|------|---------|------|
| GET | `/customers` | List branch customers (paginated) | sanctum |
| POST | `/customers` | Create customer | sanctum |
| POST | `/customers/find-or-create` | Upsert by exact phone (POS create-order flow) | sanctum |
| GET | `/customers/{id}` | Show customer detail | sanctum |
| PUT | `/customers/{id}` | Update customer | sanctum |
| DELETE | `/customers/{id}` | Soft-delete customer | sanctum |
| POST | `/customers/{id}/restore` | Restore a soft-deleted customer | sanctum |
| GET | `/customers/{id}/outstanding` | List customer's unpaid orders at this shop | sanctum |

---

## POST `/customers/find-or-create` — Upsert by phone

Convenience endpoint for the POS create-order flow, where staff usually captures only a phone number when a customer wants to be remembered. If a customer with the same `phone` already exists in this `organization_id` + `branch_id`, the existing record is returned. Otherwise a new customer is created with `first_name = "Khách"` (placeholder) and the given phone — staff can fill in full details later via the customer admin screen.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `phone` | string | yes | Length 1–20. Trimmed before lookup. |

### Response

| Status | When | Body |
|--------|------|------|
| 200 | Existing customer matched | `{ data: CustomerResource, created: false }` |
| 201 | New customer created | `{ data: CustomerResource, created: true }` |
| 422 | Invalid phone | — |

The `created` flag lets POS clients decide whether to show a "new customer" confirmation toast or silently reuse the existing record.

---

## GET `/customers/{id}/outstanding` — List unpaid orders

Returns every order attached to this customer at the current shop that is still in the `paying` lifecycle with `paid_amount < total_amount`. Ordered by most-recent `checkout_at` first. Used by POS `PaymentDialog` to remind staff when a returning customer owes money from a previous visit.

### Response

- `200` — payload below.

```json
{
  "data": [
    { "id": "uuid", "order_code": "ORD-2026-0042", "total_amount": "850.00", "paid_amount": "500.00", "remaining_amount": "350.00", "...": "..." }
  ],
  "total_owed": "350.00"
}
```

### Fields

| Field | Type | Notes |
|-------|------|-------|
| `data[]` | array of `CustomerOrderResource` | Each row carries a computed `remaining_amount = max(0, total_amount - paid_amount)`. |
| `total_owed` | string | Sum of `remaining_amount` across the returned orders. String-typed to avoid float rounding (matches the decimal cast on `total_amount`). |

### Errors

| Status | When |
|--------|------|
| 403 | Customer belongs to a different organization |
| 404 | Customer does not exist or is not visible from this shop |

See [Partial payment and outstanding debt](../explanation/order-domain.md#partial-payment-and-outstanding-debt) for the lifecycle that produces these rows.

---

## Related

- [Orders API](api-orders.md) — taking a payment against an outstanding order.
- [Order Domain](../explanation/order-domain.md) — why the `paying` status is what parks a partially paid order.
