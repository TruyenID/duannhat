---
title: Share-bill propagation from customer-web to kiosk
category: explanation
tags: [split-bill, kiosk, customer-web, counter-pay]
summary: "How a customer's split-bill choice made on customer-web reaches the kiosk without a manual hand-off, in both the pay-online and counter-pay flows."
related: [split-by-items]
---

# Share-bill propagation: customer-web → kiosk

> How a customer's split-bill choice on customer-web gets to the kiosk
> without a manual hand-off. Covers both pay-online and counter-pay
> flows.

Cross-references:
- Backend: `App\Http\Controllers\Api\V1\Customer\CustomerOrderController::setSplitMode`
- Backend resources: `App\Http\Resources\CustomerOrderResource`, `App\Http\Resources\KioskOrderResource`
- Customer-web (counter-pay UI): `web/customer/app/[locale]/order-success/`
- Customer-web (pay-online UI): `web/customer/app/[locale]/dine-in/[shop]/table/[qrToken]/components/payment-view.tsx`
- Kiosk: `app/kiosk/app/bill.tsx`
- Plan: plan-039 (đã archive — xem git history)
- Related: `docs/explanation/split-by-items.md`

## The problem this solves

A dine-in customer who already decided "we'll split equally between the
3 of us" on customer-web shouldn't have to re-declare that intent at
the kiosk. Before this contract existed, the kiosk always showed its
`/split-options` chooser regardless — the cashier had to ask "how do
you want to split?" again, the customer had to repeat themselves, and
operator feedback was that bills got printed in the wrong allocation,
voided, and reprinted.

The fix is a single column on `customer_orders` plus two surfaces
reading it:

```
┌──────────────┐   POST split-mode    ┌──────────────────────┐
│ customer-web │ ───────────────────► │ customer_orders      │
│  (web side)  │                      │  .split_mode column  │
└──────────────┘                      └──────────┬───────────┘
                                                 │
                                                 │ GET via KioskOrderResource
                                                 ▼
                                       ┌─────────────────────┐
                                       │ kiosk app/bill.tsx  │
                                       │ skips chooser if    │
                                       │ split_mode != null  │
                                       └─────────────────────┘
```

## State machine

```
[null] ─pay-online (payment-view)──► [by_people | by_items | custom]
   ▲                                          │
   │ counter-pay only                         │ paid_amount = 0
   │                                          │
   └─ unset (re-edit, idempotent overwrite) ──┘
                                              │ paid_amount > 0
                                              ▼
                                         [LOCKED]
                                              │
                                              ▼
                                  (kiosk allocation finalizes)
                                              │
                                              ▼
                                       [bill printed]
```

`custom` is only reachable through the pay-online flow (counter-pay
rejects it; see ADR-1 below).

## Two entry points, one column

The `split_mode` column gets populated through two distinct UX paths;
both fire the same `POST /api/v1/customer/orders/{id}/split-mode`
endpoint.

### Pay-online (shipped in PR #377)

`web/customer/.../components/payment-view.tsx` calls the endpoint right
before creating the Stripe payment intent. The customer chose a tab
(`Chia đều` / `Theo món` / `Tùy ý`); we record the choice before the
payment so the kiosk has it even if the customer never returns to the
counter (rare but possible — fully online-paid orders may still want a
kiosk physical receipt).

### Counter-pay (plan-039)

`web/customer/app/[locale]/order-success/components/split-bill-sheet.tsx`
opens from a "Chia hóa đơn?" card on the order-success / "Đang chờ
thanh toán" screen, between the QR code and the navigation buttons.
This screen is shown after the customer placed a counter-pay order but
before they reach the kiosk; tapping the card lets them declare the
split mode without going through `payment-view` (which they never see
in the counter-pay flow).

The card is gated on `orderData.is_counter_pay === true`, a derived
field on `CustomerOrderResource` that returns
`stripe_payment_intent_id === null`. Pay-online orders skip the card
entirely (the existing payment-view already handles them).

## API contract

### POST `/api/v1/customer/orders/{id}/split-mode`

Auth: guest path — opaque UUIDv7 order id from the customer's
localStorage / draft. No Sanctum guard. Same pattern as the rest of
`/customer/orders/*`.

Body:
```json
{ "split_mode": "by_people" | "by_items" | "custom" }
```

Response 200:
```json
{
  "data": {
    "split_mode": "by_people",
    "split_mode_locked": false
  }
}
```

Error responses (4 distinct error codes for FE branching):

| HTTP | error code | Trigger |
|---|---|---|
| 404 | _(none)_ | Order id not found |
| 422 | `ORDER_FINALIZED` | Order is closed or voided |
| 422 | `SPLIT_MODE_INVALID_FOR_COUNTER` | `custom` mode + no Stripe intent (ADR-1) |
| 422 | validation `split_mode` | Missing or out-of-enum value |
| 409 | `SPLIT_MODE_LOCKED` | `paid_amount > 0` |

The endpoint is idempotent for in-flight overwrites (e.g., customer
changes `by_people` → `by_items` before kiosk pays). Last-write-wins.

### GET `/api/v1/customer/orders/{id}` — new resource fields

`CustomerOrderResource` exposes (mirroring `KioskOrderResource`):

```json
{
  "data": {
    "split_mode": "by_people" | "by_items" | "custom" | null,
    "split_mode_locked": false,
    "is_counter_pay": true,
    ...
  }
}
```

`is_counter_pay` is derived (`stripe_payment_intent_id === null`); the
column doesn't exist. It's an FE convenience so customer-web can
conditional-render the card without re-deriving the logic on every
render.

### GET `/api/v1/kiosk/orders/...` — unchanged

`KioskOrderResource` already exposed `split_mode` + `split_mode_locked`
from PR #377. Kiosk reads it via the existing endpoint chain — no
contract change in this plan.

## Counter-pay detection: why `stripe_payment_intent_id IS NULL`

`payment_method` is request-only — it's a field on
`CustomerOrderStoreRequest`, not a column on `customer_orders`. So
after order creation, there's no first-class way to query "is this a
counter-pay order".

The Stripe intent gets minted at the start of the pay-online flow
(`payment-view` calls `/create-payment-intent`). Counter-pay never
mints one. So `stripe_payment_intent_id IS NULL` is a reliable proxy:

- ✅ Counter-pay order before payment: `stripe_payment_intent_id IS NULL`
- ✅ Pay-online order at any stage: `stripe_payment_intent_id IS NOT NULL`
- ✅ Order paid via kiosk cash: `paid_amount > 0` (locked branch takes over before this matters)

The alternative — adding a `payment_method` column — was rejected as
scope creep for a single derived check. If a future flow legitimately
needs both "counter-pay" and "Stripe intent created" to coexist, the
column may earn its place; until then, the derived flag is enough.

## Design decisions (recorded as ADRs in plan-039)

### ADR-1: Counter-pay rejects `custom`

The backend returns 422 `SPLIT_MODE_INVALID_FOR_COUNTER` when a
counter-pay order tries to set `split_mode = "custom"`. Defense at the
API layer means external clients (curl, tests, alternate UIs) can't
put the order into an invalid state — not just the customer-web UI.

Why: "custom" UX requires per-person amount entry. Pay-online has the
input surface (payment-view's third tab); counter-pay doesn't (the
customer never types money on web — they bring the QR to the cashier).
Adding a per-person amount input to customer-web's counter-pay path
would double the UX complexity for marginal benefit; the typical
counter-pay flow only needs by_people / by_items.

### ADR-2: by_items skips chooser, no preselect

When a counter-pay customer chose `by_items`, the kiosk routes to
`/split/items` and shows the **full** item picker. We do not currently
persist the specific items the customer picked.

Why: persisting items would require a `split_items` array column on
`customer_orders` plus a new picker UI on the counter-pay path
(essentially porting half of `payment-view`'s item-pick UI). Cashier
always confirms allocation with the customer at the counter anyway, so
"customer indicated by_items mode" is enough information to route past
the chooser. Bookkeeping savings don't justify the engineering cost.

### ADR-3: Card placement below QR

The "Chia hóa đơn?" card sits between the QR code and the action
buttons on `/order-success`. It is NOT an auto-prompt dialog and NOT a
compact button on the action row.

Why: auto-prompt interrupts the customer who just placed their order
and wants to read the QR; compact button doesn't communicate purpose
(easy to misread as "Pay split now"). Card with title + hint copy +
button is discoverable when needed and ignorable when not.

## What the kiosk reads (zero change)

`app/kiosk/app/bill.tsx`:

```ts
const order = await fetchOrder(code);
if (order.split_mode === "by_people") {
  return router.replace("/split/people");
}
if (order.split_mode === "by_items") {
  return router.replace("/split/items");
}
if (order.split_mode === "custom") {
  return router.replace("/custom/amount");
}
// fall through — show /split-options chooser
return router.replace("/split-options");
```

This branching shipped in PR #377. Plan-039 only adds the counter-pay
entry point; the kiosk side is unchanged.

## Out of scope

- **By-items preselect (split_items[] column)** — deferred per ADR-2.
- **Custom amount in counter-pay** — deferred per ADR-1.
- **Multi-device same-order conflict resolution** — last-write-wins is
  acceptable for the launch window. If multi-device editing becomes
  common, consider `If-Match: <etag>` / optimistic locking.
- **Analytics event for "card tapped" conversion** — deferred 1 week
  post-go-live; needs data to know whether to invest in alternative
  UI placements.
