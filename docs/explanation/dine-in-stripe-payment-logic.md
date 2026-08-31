---
title: "Dine-in Stripe payment logic — current state (§1) + a per-guest split PROPOSAL (§2-§9) that was never built"
category: explanation
tags: [stripe, dine-in, payment, customer-web, proposal]
summary: "§1 describes how dine-in Stripe payment works on customer-web today. §2-§9 are a 2026-05 DESIGN PROPOSAL for per-guest split payment that was never implemented — none of its tables, endpoints or hooks exist."
related: [order-domain, api-payment-gateways, split-by-items]
---

# Dine-in Stripe payment logic

Last updated: 2026-05-11 · **nhãn phạm vi sửa 2026-08-07 (#2029)**

> **ĐỌC MỤC LỤC TRƯỚC — file này là HAI thứ dán vào nhau.**
>
> | Phần | Là gì | Tin được không |
> |---|---|---|
> | **§1** | Hiện trạng | **Có** — `createFullPaymentIntent`, `markOrderPaidFromIntent`, `OrderPaymentService::create`, `OrderClosingService::close`, `CustomerOrderService::splitBill` đều tồn tại (đo 2026-08-07) |
> | **§2-§9** | **ĐỀ XUẤT tháng 2026-05, CHƯA BAO GIỜ ĐƯỢC XÂY** | **Không** — đọc như đầu bài, không phải như API |
>
> Chính thân bài đã tự nhận là đề xuất ("New table", "Implementation order
> (suggested PRs)", "Open questions") — **cái sai là NHÃN**: frontmatter nói
> "today" và file nằm trong `explanation/`, nên một reader (hoặc một agent) mở
> giữa file rất dễ đọc §3-§5 như mô tả hệ thống. Đây không phải nội dung cần
> xoá; đề xuất có nhãn rõ ràng là thứ hợp lệ.
>
> **Đo 2026-08-07** — `order_split_sessions`, `order_split_shares`,
> `CustomerOrderSplitController`, `useSplitSession`, `markSplitSharePaid`:
> **zero hit** trên `backend/{app,database,routes}`. Chưa bao giờ có, không phải
> bị gỡ.
>
> **Cái ĐÃ ship cho chia bill dine-in thì khác hẳn hình dạng** — không có
> session/share nào được lưu, chỉ ghi lại *chế độ chia* mà khách chọn rồi để POS
> thu:
> ```
> POST /api/v1/customer/orders/{id}/split-mode     → CustomerOrderController::setSplitMode  (#377)
> GET  /api/v1/customer/orders/{id}/split-status   → CustomerOrderSplitStatusController::show
> GET  /api/v1/{pos,shops}/orders/{order}/split-bill → CustomerOrderController::splitBill   (máy tính chia, §1.3)
> ```
> Chia theo MÓN có doc riêng: `docs/explanation/split-by-items.md`.

## 1. Context and current state

### 1.1 What customer-web has today

Customer-web dine-in (`/[locale]/dine-in/[shop]/table/[qrToken]`) currently
supports two ways to pay for the whole table (a single payer):

| Method | Endpoint | Service | Handled by |
|---|---|---|---|
| Online (Stripe) | `POST /api/v1/customer/orders/{id}/full-payment-intent` | `StripePaymentService::createFullPaymentIntent` | The `payment_intent.succeeded` webhook → `markOrderPaidFromIntent` forces `paid_amount = total_amount` and closes the order |
| At the counter | (no API call) | The QR goes offline; staff collect payment on the POS | The POS uses `OrderPaymentService::create` |

Both paths collect the **entire total** in one go; there is no notion of an
outstanding balance — a decision settled in an earlier round (see the commit that
added `createFullPaymentIntent`).

### 1.2 The POS allows a balance owed; customer-web does not

The POS **does** allow an order to sit in an "amount still owed" state — staff
collect part of it (cash or card) through `OrderPaymentService::create`, and the
order only auto-closes once `paid_amount >= total_amount`
([`OrderPaymentService::isOrderPaidInFull`](../../backend/app/Services/Customer/OrderPaymentService.php) —
neo theo tên method, không theo số dòng: link `#L167` cũ đã trôi sang đoạn khác).
If staff stop halfway, the order stays in status `Paying` with
`paid_amount < total_amount` — a balance owed in the business sense.
[`CustomerOutstandingOrderService::listOutstanding`](../../backend/app/Services/Order/CustomerOutstandingOrderService.php)
lists these orders so that the POS PaymentDialog can remind staff when the
customer comes back. (Doc cũ trỏ `CustomerService::listOutstanding` — method
đó đã dời sang class riêng; `CustomerService` vẫn tồn tại nhưng không còn giữ nó.)

`OrderClosingService::close` refuses to close while paid < total — so the books
cannot be *closed* with a balance owed, but an order *existing* in that state is
perfectly valid.

Customer-web is the opposite: `StripePaymentService::createFullPaymentIntent`
forces `amount = order.total_amount` every time it creates an intent, and the
`markOrderPaidFromIntent` webhook forces `paid_amount = total_amount` and closes.
There is no partial-payment UI for the customer → **a customer can never create a
balance owed**.

The consequence on the admin order detail page
([web/admin/src/app/shop/[shopSlug]/orders/[id]/page.tsx](../../web/admin/src/app/shop/[shopSlug]/orders/[id]/page.tsx)):
once an order has one succeeded payment with `payment_method.code = 'stripe'`
(i.e. from customer-web), the "amount owed" block is suppressed even when
`remaining > 0` because staff added items afterwards. The reason: the customer's
payment obligation was fully discharged at the moment Stripe charged the full
amount; anything added later is on the staff side, not a debt of the customer.

### 1.3 Split bill today is an informational calculator

[`CustomerOrderService::splitBill(order, splitCount)`](../../backend/app/Services/Customer/CustomerOrderService.php)
only returns:

```json
{
  "total_amount": "3000.00",
  "remaining_amount": "3000.00",
  "split_count": 3,
  "per_person_amount": "1000.00",
  "per_person_amounts": ["1000.00", "1000.00", "1000.00"],
  "rounding_note": null
}
```

No state is stored in the database. The POS uses it to display "¥1000 each", and
then staff collect from each person and create three separate `OrderPayment`
rows.

---

# ĐỀ XUẤT (§2-§9) — CHƯA THI CÔNG

> Mọi thứ từ đây trở xuống là thiết kế tháng 2026-05 **chưa bao giờ được cài
> đặt**. Không bảng, endpoint, controller hay hook nào trong các mục dưới tồn
> tại trong repo (đo 2026-08-07, #2029). Giữ lại vì đầu bài và các quyết định
> race-condition ở §6 vẫn dùng được nếu ai đó thực sự làm — nhưng **đừng trích
> phần này như mô tả hệ thống**.

## 2. Goal

Let **several customers at one table** (all scanning the same qrToken on their own
devices) split the bill so that each pays their own share through Stripe on their
phone. The order only closes once **every share** is paid.

**Non-goals (v1):**
- Per-item assignment ("this dish is mine") — split evenly by headcount only
  (with a possible round-up remainder)
- Per-payer tipping
- Refunding an individual share (refund the whole session if needed)
- Partial payment / balance owed — not supported; if someone abandons a split
  midway, the session must be cancelled or the slot re-claimed

## 3. Data model

### 3.1 New table `order_split_sessions`

| Column | Type | Note |
|---|---|---|
| `id` | uuid | PK |
| `customer_order_id` | uuid | FK → `customer_orders.id` (unique on status `active` — only one active session per order) |
| `split_count` | tinyint | 2..10 |
| `total_amount_snapshot` | decimal(10,2) | The order total when the session was created — if the order changes (an item is added) → invalidate |
| `per_person_amounts` | json | `["1000.00","1000.00","1000.00"]` — returned by `splitBill()` |
| `status` | enum | `active` / `completed` / `cancelled` / `expired` |
| `created_by_qr_token` | string\|null | The qrToken of the creating device (debug) |
| `expires_at` | timestamp | created_at + 30 min; each paid share resets it by another 15 min |
| `closed_at` | timestamp\|null | Filled when `status` leaves `active` |
| `created_at`, `updated_at` | timestamp | |

Index: `unique (customer_order_id) WHERE status='active'` (Postgres), or a partial
index / application-level lock.

### 3.2 New table `order_split_shares`

| Column | Type | Note |
|---|---|---|
| `id` | uuid | PK |
| `order_split_session_id` | uuid | FK |
| `slot_index` | tinyint | 0..(N-1), unique within the session |
| `amount` | decimal(10,2) | Snapshot of `per_person_amounts[slot_index]` |
| `payer_name` | string\|null | Optional, e.g. "Anh A", "Em B" |
| `stripe_payment_intent_id` | string\|null | Filled when the intent is created |
| `order_payment_id` | uuid\|null | FK → `order_payments.id`, filled when the webhook records it |
| `status` | enum | `pending` / `processing` / `paid` / `expired` |
| `claimed_at` | timestamp\|null | When a user opens the intent (locks the slot for 10 min) |
| `paid_at` | timestamp\|null | When the webhook confirms |
| `created_at`, `updated_at` | timestamp | |

Index: `unique (order_split_session_id, slot_index)`.

### 3.3 Changes to `order_payments`

No new column is needed. When the webhook creates an `OrderPayment` for a share,
use `note = "split:{session_id}:{slot_index}"` or — better — link it through
`order_split_shares.order_payment_id`.

On the Stripe intent, use `metadata`:
```json
{
  "flow": "split",
  "order_id": "...",
  "split_session_id": "...",
  "slot_index": "0"
}
```

## 4. API endpoints (customer-facing)

All public (like the other dine-in endpoints), validated through the table's
`qrToken` to prevent access to another order.

### 4.1 Create a session

```
POST /api/v1/customer/tables/{qrToken}/split-session
Body: { split_count: 3, payer_names?: ["A","B","C"] }
```

**Logic:**
1. Lock the `customer_orders` row (`lockForUpdate`).
2. Reject with 409 when:
   - The order status is neither `Checkout` nor `Paying`
   - `paid_amount > 0` (a payment already exists) — to avoid mixing the full and
     split flows
   - An `active` session already exists for this order
3. Call `CustomerOrderService::splitBill($order, $splitCount)` to get
   `per_person_amounts`.
4. Create the `order_split_sessions` row plus N `order_split_shares` rows in one
   transaction.
5. Transition the order status to `Paying` if it is `Checkout`.
6. Return the session plus its shares.

**Response:**
```json
{
  "data": {
    "session": {
      "id": "...",
      "split_count": 3,
      "total_amount": "3000.00",
      "expires_at": "2026-05-11T10:30:00Z"
    },
    "shares": [
      { "id":"...", "slot_index":0, "amount":"1000.00", "status":"pending", "payer_name":"A" },
      ...
    ]
  }
}
```

### 4.2 Read the session

```
GET /api/v1/customer/tables/{qrToken}/split-session
```

Returns the active session (404 if there is none). The frontend polls every 3-5
seconds to see whether the others have paid.

### 4.3 Create a payment intent for one slot

```
POST /api/v1/customer/split-shares/{shareId}/payment-intent
Body: { payer_name?: "A" }
```

**Logic:**
1. Lock the share row.
2. Reject when:
   - The share has `status = paid` → 409
   - The share's `claimed_at` has not expired (10 min) and its
     `stripe_payment_intent_id` is `processing`/`requires_*` → 409 "somebody else
     is paying this slot"
3. If the share has an older `stripe_payment_intent_id` → cancel it at Stripe
   (same as `createFullPaymentIntent`).
4. Create a Stripe PaymentIntent with `amount = share.amount` and metadata
   `flow=split`, `split_session_id`, `slot_index`.
5. Store `stripe_payment_intent_id`, `claimed_at = now()`, `status = processing`
   and `payer_name`.
6. Return the `client_secret`.

### 4.4 Cancel the session

```
DELETE /api/v1/customer/tables/{qrToken}/split-session
```

Reject with 409 if any share is already `paid`. Cancel every pending intent at
Stripe, transition the session to `cancelled`, and return the order to `Checkout`.

## 5. Webhook routing

Extend `StripeWebhookController::handle` plus
`StripePaymentService::markOrderPaidFromIntent`:

```php
$flow = $intent->metadata->flow ?? 'full';

match ($flow) {
    'full'  => $this->markOrderPaidFromIntent($intent),  // current
    'split' => $this->markSplitSharePaid($intent),       // new
    default => null,
};
```

### `markSplitSharePaid(PaymentIntent $intent)`

1. Find the `order_split_shares` row by `stripe_payment_intent_id = $intent->id`.
2. If it is already `paid` (a replay) → return idempotently.
3. Inside a transaction:
   - Create an `OrderPayment` row (method=`stripe`, amount = share.amount,
     status = succeeded, reference_no = intent.id).
   - Update the share: `status=paid`, `paid_at=now()`,
     `order_payment_id = newPayment.id`.
   - Recompute `paid_amount = SUM(OrderPayment.amount WHERE succeeded)`.
   - Count the paid shares in the session. If it equals `split_count`:
     - Session `status=completed`, `closed_at=now()`.
     - Order: `paid_amount = total_amount`, `status=Closed`, `closed_at=now()`.

### Idempotency

- `reference_no = intent.id` is unique, so a replay cannot create a duplicate
  `OrderPayment`.
- The share status check prevents a double update.

## 6. Race conditions and invariants

| Situation | How it is handled |
|---|---|
| Two devices create a session at once | `unique (customer_order_id) WHERE active` plus a row lock → one wins, the other gets a 409 carrying the existing session |
| Two devices claim the same slot | `claimed_at` plus an intent status check; if the slot is processing, 409 "somebody else is paying" |
| A user claims a slot and closes the tab | `claimed_at` expires after 10 min, the slot returns to `pending`, and the old intent is cancelled at Stripe |
| An item is added while a session is active | The order service checks `active_split_session` before allowing an item to be added → 422 "a split is in progress, items cannot be added"; OR the session is automatically invalidated (cancelled) when `total_amount` changes |
| All shares are paid but an item is added immediately afterwards | The webhook locks the order row before closing → race-safe; if a race still occurs, the recompute at close rejects the close and resets the session |
| A paid slot is claimed again by someone else | The API returns 409 because status=paid |
| The session expires with nobody having paid | A cron job marks it `expired` and cancels the pending intents; the order returns to `Checkout` |
| The session expires after one share has been paid | **Do not expire it** — the unpaid slots simply return to `pending`, but the session stays active (until everyone pays or staff intervene through the POS) |

**The core invariant:** `paid_amount` always equals
`SUM(order_payments.amount WHERE status=succeeded)`. The share table is a
tracking layer for who paid which slot; it never bypasses OrderPayment.

## 7. Frontend flow (customer-web)

`payment-view.tsx` gains a third option beyond "Online" and "At the counter":

**The new option: "Split the bill"** → enters a sub-flow:

1. **Choose the headcount** (2-10). Show a preview of `per_person_amounts` (call
   `splitBill` for info only, without creating a session).
2. **Confirm** → POST split-session. Enter the share-list screen.
3. **Share-list screen** (poll GET split-session every 4s):
   - N cards, each showing: slot index, payer_name, amount, status badge.
   - A `pending` card: a "I'll pay this share" button → POST
     share/payment-intent → open the Stripe sheet (reusing `StripeCardSection`).
   - A card `processing` on another device: disabled, "Being paid…".
   - A `paid` card: green checkmark.
4. **When everything is `paid`** → polling returns session.status = completed →
   redirect to `/order-success`.

It is possible to start a split and then invite others to scan the table QR —
they land on dine-in/payment, see the active session, and join the share list.

**Cancelling a split:** a "Cancel split" button on the share-list screen, enabled
only while zero shares are paid.

## 8. Implementation order (suggested PRs)

> ⚠️ **Kế hoạch dưới đây CHƯA BAO GIỜ được dựng theo hình dạng này.** Không có
> bảng `order_split_sessions` / `order_split_shares` và không có
> `OrderSplitService`. Chia bill thực tế đi qua `split_mode` trên
> `customer_orders` + `OrderSplitBillTotals`, do `WritesCustomerOrders` ghi.
> Giữ lại làm hồ sơ thiết kế, đừng đọc như mô tả hệ thống đang chạy.

1. **PR 1 — backend data layer**
   - Migrations for `order_split_sessions` and `order_split_shares`
   - Models plus Eloquent relations
   - `OrderSplitService` with `createSession`, `getActiveSession`,
     `cancelSession`, `claimShare`, `markSharePaid`
   - Unit tests (Pest)

2. **PR 2 — backend API plus Stripe integration**
   - Routes plus a controller (`CustomerOrderSplitController`)
   - `StripePaymentService::createSplitShareIntent`
   - Webhook routing on `metadata.flow`
   - Feature tests covering create/claim/pay/cancel/race/all-paid-closes-order/expire

3. **PR 3 — split-bill frontend UI**
   - In `payment-view.tsx`: add the "Split the bill" option
   - A new `split-bill-view.tsx` component: headcount picker, share list, inline
     Stripe sheet
   - A `useSplitSession` polling hook
   - i18n (vi/en/ja)

4. **PR 4 — POS visibility (optional)**
   - The HQ/Shop order detail page shows "Splitting the bill: 2/3 paid"
   - A staff override: force-cancel the session to collect manually

## 9. Open questions

- **Round-up rule:** `splitBill()` currently pushes the remainder onto the first
  person. Should the payer of the remainder be selectable? → v1 keeps it as is
  and shows `rounding_note` to whoever performs the split.
- **Headcount limit:** 2-10 is proposed. Should the UI use free entry or a slider?
- **Authentication:** v1 requires no login — anyone with the qrToken can claim a
  slot. Later, `customer_id` could be linked to a share once the user is signed in.
- **Per-payer tipping:** none in v1. If added later, each share gains a
  `tip_amount` and the intent amount becomes share.amount + tip.
- **Refunding a whole session:** v1 refunds everything through the POS (each
  OrderPayment is refunded independently, as today). A refund-aware share UI comes
  later.
