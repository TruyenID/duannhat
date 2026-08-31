---
title: Split-by-items bill division
category: explanation
tags: [split-bill, order-payment, plan-033, rounding]
summary: "Server-side contract for by-items check splitting — the metadata shape, the four structured 422 codes, the rounding contract, the multi-surface preview endpoint, and the defensive bypass."
related: [api-orders, share-bill-dine-in]
---

# Split-by-items Bill Division

> Server-side contract for the by-items check-splitting feature on `OrderPayment`. Plan-033 (plan đã xoá khỏi cây #2188 — git history) added the validator, the preview endpoint, and the shared calculator. This doc explains the shape, the structured 422 codes, the rounding contract, and the defensive bypass.

## Domain

A by-items split decomposes a single `CustomerOrder` into N sub-checks, each carrying a discrete subset of the order's `CustomerOrderItem` rows. Each sub-check is one `OrderPayment` row tagged with `metadata.split_mode = 'by_items'`. The order's `paid_amount` cache (BR-O08) sums the sub-checks; when it equals `total_amount`, `OrderClosingService` closes the order. There is no `split_pending` lifecycle state — the cashier "rolls back" by simply not tendering the next sub-check (matches plan-021's flat model decision).

## Metadata payload

The `OrderPayment.metadata` JSON column carries the by-items audit shape:

```json
{
  "split_mode": "by_items",
  "bill_index": 0,
  "label": "Người 1",
  "item_allocations": [
    { "item_id": "01HX…", "units": 1 }
  ],
  "expected_sub_total": "1000.00"
}
```

- `split_mode` — discriminator. Validator only fires when this equals `'by_items'`.
- `bill_index` — 0-based index identifying the sub-check among the N bills.
- `label` — display string; informational only.
- `item_allocations[]` — `{item_id: uuid, units: int ≥ 1}`. Each unit is a single quantity of a line item.
- `expected_sub_total` — FE-computed pre-tax/pre-charge subtotal for this bill. Persisted for audit/reprint; **the validator does NOT consume this field for business logic.** The arch test pins that no code reads it for guard logic.

## Structured 422 error codes

The validator at `App\Services\Customer\OrderPaymentService::create()` runs **inside the per-order `lockForUpdate` transaction**, between the existing `split_bill_total_drift` guard and the existing overpayment guard. It returns one of:

| Code | When |
|------|------|
| `split_by_items_unknown_item` | `item_id` not on the order |
| `split_by_items_voided_item` | allocation targets an item whose status is `voided` |
| `split_by_items_double_claim` | cumulative units across `pending + succeeded` payments + the new payment exceeds the item's `quantity` |
| `split_by_items_total_mismatch` | `amount` disagrees with the calculator-recomputed sub-check total beyond a 1-minor-unit tolerance |

All error payloads include the offending `item_id` (or `expected_amount` / `given_amount` for the mismatch). Clients render the code as a structured toast and refetch the preview endpoint.

The existing `split_bill_total_drift` (plan-007) fires first — it catches stale snapshots from `expected_total_amount` before the by-items recomputation runs. The overpayment guard fires last — it's the numeric net of "this payment + existing payments must not exceed `order.total_amount`".

## Defensive bypass

The validator is intentionally a no-op for three call shapes:

1. `metadata.split_mode !== 'by_items'` — legacy single-payment + equal-split paths.
2. `metadata.item_allocations` is missing or `[]` — customer-web's deferred QR by-items path can carry the field for forward-compatibility without tripping a 422.
3. `refund_of_id` is set — refund records re-use the `OrderPayment` shape but never claim items.

Pest tests pin all three bypasses so the customer-web Stripe split-intent path and the refund flow continue to work unchanged.

## Calculator + rounding

`App\Services\Customer\SplitByItemsCalculator` is a final readonly class with no database access. It mirrors `web/pos/src/app/pos/lib/split-by-items.ts:78` bit-for-bit so the shared fixture set at `backend/tests/Fixtures/split_by_items_cases.json` produces identical per-bill totals on both sides.

Reconciliation: when `reconcile = true` (default; preview endpoint), the calculator absorbs Σ bills.total ≠ order.total_amount drift on the **last non-empty bill**; if that would push the bill below zero, the overshoot is forwarded to the **first non-empty bill** (negative clamp). When `reconcile = false` (validator path; one bill at a time), each bill stands alone and the natural proportional total is returned.

Rounding step comes from `ShopOrderSetting.split_bill_rounding_mode` (plan-029) via `App\Support\RoundingMode::step($mode, $currencyCode)`:

| Mode | Step |
|------|------|
| `auto` | derived from `currency_code` — `1` for zero-decimal (VND, JPY, KRW, …), `0.001` for three-decimal (KWD, BHD, …), `0.01` otherwise |
| `integer` | `1` regardless of currency |
| `two_decimals` | `0.01` |
| `none` | `0` — exact division; one designated bill absorbs the remainder |

## Preview endpoint

The `GET /split-by-items/preview` endpoint is **read-only and stateless** — no DB writes. It's mounted on three surfaces under the same controller method shape:

| Surface | Path | Auth |
|---------|------|------|
| POS | `/api/v1/pos/orders/{customerOrder}/split-by-items/preview` | sanctum + `ResolvePosShop` (X-Shop-Slug header) |
| Kiosk | `/api/v1/kiosk/orders/{customerOrder}/split-by-items/preview` | `device.auth:kiosk` (bearer device token); controller asserts `order.branch_id === device.branch_id` |
| Customer-web QR | `/api/v1/customer/orders/{id}/split-by-items/preview` | public — order id is the opaque token (same pattern as `/split-status`) |

Optional `?allocations=<url-encoded JSON>` carries a candidate allocation. Payloads above 4 KB return the base shape (no `preview_bills` block). Response keys: `order_id`, `total_amount`, `allocated_amount`, `remaining_amount`, `rounding_mode`, `rounding_step`, `currency_code`, `items[]` (each with `units_claimed`, `units_remaining`, `claims[]`), and optionally `preview_bills[]` (per-bill `subtotal/discount/tax/service/total/is_empty`).

## Deferred surfaces

The pos-web client UI continues to render the existing `SplitBillByItemsTab` from plan-021. The deferred follow-up plans pick up:

1. **pos-web split-by-items completeness** — wires `useSplitByItemsPreview`, extends the TS calculator with `roundingMode + currencyCode`, mirrors the shared `split_by_items_cases.json` fixture, enforces parity via Vitest.
2. **workstation per-bill receipt** — adds `FormatSplitBillReceipt`, the `POST /print/split-bill-receipt` LAN endpoint, and the `split_bill_receipt_mode` workstation setting.

Both inherit plan-033's BE contract via the OpenAPI doc (`storage/api-docs/api-docs.json`) and the fixture file.
