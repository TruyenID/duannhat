---
title: Payment Methods API
category: reference
tags: [payment-methods, payments, api, branch-scope, translations, deprecation, plan-047]
summary: Legacy shop-scoped payment-methods list and HQ CRUD — deprecated in favor of effective-payment-options and gateway admin APIs.
related: [api-payment-gateways, api-orders, order-domain]
---

# Payment Methods API

> **Deprecated (plan-047 T7.7).** These routes remain readable during rollout but must not be used in new code.
>
> | Legacy surface | Successor | Removal target |
> |----------------|-----------|----------------|
> | `GET /api/v1/pos/payment-methods` | `GET /api/v1/pos/effective-payment-options` | **TempoFast 2.0 — 2027-01-01** |
> | `GET /api/v1/shops/{shopSlug}/payment-methods` | `GET /api/v1/shops/{shopSlug}/effective-payment-options` | **TempoFast 2.0 — 2027-01-01** |
> | HQ `/payment-methods` CRUD | `GET/PATCH /api/v1/hq/{brandSlug}/payment-options` + `/payment-gateways` | **TempoFast 2.0 — 2027-01-01** |
>
> Removal is gated on plan-047 Gate 7 zero-drift observation and T4.14 allowlist zero (plan-047 T7.6 — `TASKS.md` đã xoá ở #2336, xem git history). Historical ledger rows keep their `payment_method_id` references after route removal.
>
> See [Payment Gateways API](api-payment-gateways.md) and [plan-047 DESIGN](../../plans/plan-047/DESIGN.md).

Deprecated responses include RFC 8594-style headers:

| Header | Value |
|--------|-------|
| `Deprecation` | `true` |
| `Sunset` | `Sat, 01 Jan 2027 00:00:00 GMT` |
| `Link` | `<successor-url>; rel="successor-version"` |

---

## Shop / POS list

| Method | Path | Purpose | Auth |
|--------|------|---------|------|
| GET | `/api/v1/shops/{shopSlug}/payment-methods` | List active methods (admin) | sanctum |
| GET | `/api/v1/pos/payment-methods` | Same handler, POS namespace | device + `X-Shop-Slug` |

Cross-org access is blocked by shop resolution middleware; `PaymentMethodPolicy::viewAny()` gates authorization.

### Scoping rules

1. Always filter by `organization_id` of the resolved shop.
2. Return rows where `branch_id = shop.branch_id` **OR** `branch_id IS NULL`.
3. Exclude inactive rows unless `include_inactive=true`.
4. Order by `sort_order ASC`, then `code ASC`.

### Query parameters

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `include_inactive` | boolean | no | Include rows with `is_active=false`. Defaults to `false`. |

### Response

- `200` — `data[]` of `PaymentMethodResource`.

```json
{
  "data": [
    {
      "id": "uuid",
      "code": "cash",
      "name": "Tiền mặt",
      "type": "cash",
      "is_auto_confirm": true,
      "requires_tendered": true,
      "is_active": true,
      "sort_order": 10,
      "branch_id": null,
      "organization_id": "uuid",
      "translations": { "ja": "現金", "en": "Cash", "vi": "Tiền mặt" },
      "created_at": "2026-04-18T10:00:00Z",
      "updated_at": "2026-04-18T10:00:00Z"
    }
  ]
}
```

### Fields

| Field | Type | Notes |
|-------|------|-------|
| `code` | string | Stable machine identifier (`cash`, `card`, `qr`, …). |
| `type` | string | Tender kind (`cash`, `card`, `on_account`, …). Required by POS debt UI during rollout. |
| `name` | string | Flat localized display name for the current locale. |
| `is_auto_confirm` | boolean | Cash-like immediate settlement when `true`. |
| `requires_tendered` | boolean | When `true`, cash change requires `tendered_amount` on payment POST. |
| `branch_id` | UUID \| null | `null` = organization-wide row. |

### Errors

| Status | When |
|--------|------|
| 401 | Missing or invalid token |
| 403 | User has no access to this shop |
| 404 | Shop slug does not resolve |

---

## HQ CRUD (deprecated)

Base: `/api/v1/hq/{brandSlug}/payment-methods`

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/payment-methods` | Paginated list |
| POST | `/payment-methods` | Create tender row |
| GET | `/payment-methods/{id}` | Show |
| PUT | `/payment-methods/{id}` | Update |
| DELETE | `/payment-methods/{id}` | Soft delete |
| POST | `/payment-methods/{id}/restore` | Restore |
| PUT | `/payment-methods/reorder` | Reorder `sort_order` |
| POST | `/payment-methods/bulk-delete` | Bulk soft delete |

All HQ responses include the same deprecation headers; `Link` points to `/api/v1/hq/{brandSlug}/payment-options`.

Configure provider connections, environment isolation, and option policies via [Payment Gateways API](api-payment-gateways.md) instead.

---

## Related

- [Payment Gateways API](api-payment-gateways.md) — replacement admin + effective + runtime surfaces
- [Orders API](api-orders.md) — uses method UUID in `POST /orders/{id}/payments` during rollout
- [Order Domain](../explanation/order-domain.md) — partial-payment / debt behavior
