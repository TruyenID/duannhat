---
plan: 020
issue: 244
title: Split Bill Payment for Dine-in
slug: split-bill-payment
status: shipped
branch: feature/plan-020-split-bill-payment
created: 2026-05-11
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted). TASKS.md checkboxes are NOT the
  completion signal — plan-027 sits at 0/250 while godx-kds is a live shipping app
  (#1818). Verified by: no feature branch remains, plus a closed tracker or the
  plan's subject being present in the tree.
---

# Plan 020 — Split Bill Payment for Dine-in

**Status**: `implementing`
**Priority**: High
**Effort**: Medium (3-5 days)
**Owner**: Backend + Frontend team
**Created**: 2026-05-11

## Problem Statement

Hiện tại dine-in orders chỉ hỗ trợ 1 người thanh toán toàn bộ bill. Nhưng trong thực tế, nhiều trường hợp nhóm khách muốn **chia bill** (split bill) — mỗi người thanh toán 1 phần.

**Yêu cầu:**
- Nhiều người có thể thanh toán cùng 1 order
- Mỗi người trả số tiền tùy ý (không nhất thiết chia đều)
- Order chỉ chuyển sang `paid` khi tổng các payments ≥ total_amount
- Admin thấy được payment history (ai trả bao nhiêu, khi nào)

**User Stories:**

1. **US-1**: Là khách hàng, tôi muốn thanh toán 1 phần bill (ví dụ: ¥2000 / tổng ¥5000) thay vì phải thanh toán toàn bộ
2. **US-2**: Là khách hàng khác cùng bàn, tôi scan QR và thấy bill đã được thanh toán ¥2000, còn lại ¥3000
3. **US-3**: Là staff admin, tôi thấy được danh sách tất cả payments của 1 order (thời gian, số tiền, payment method)

## Scope

### In Scope
- ✅ Backend API: Hỗ trợ partial payment (amount < total_amount)
- ✅ Frontend (customer-web): UI cho phép chọn "Full payment" hoặc "Split bill"
- ✅ Validation: Reject payment nếu amount > remaining
- ✅ Race condition handling: Lock order khi xử lý payment
- ✅ Admin view: Hiển thị payment history + remaining amount
- ✅ Auto-update order status: `confirmed` → `paid` khi paid_amount ≥ total_amount

### Out of Scope
- ❌ Real-time WebSocket updates (dùng polling 5s)
- ❌ Refund/partial refund flow (future plan)
- ❌ Payment by person assignment (chỉ allow free-form amounts)
- ❌ POS integration (plan riêng)

## Success Metrics

1. **Functional:**
   - [ ] 1 order có thể nhận nhiều payments từ nhiều người
   - [ ] Order chuyển `paid` đúng khi paid_amount ≥ total_amount
   - [ ] Không có overpayment (validation reject)
   - [ ] Không có race condition (2 người submit cùng lúc)

2. **UX:**
   - [ ] User thấy rõ remaining amount trước khi thanh toán
   - [ ] Sau khi thanh toán partial, user thấy "Đã thanh toán ¥X. Còn lại ¥Y"
   - [ ] Admin thấy đầy đủ payment history

3. **Technical:**
   - [ ] API response time < 2s (Stripe payment latency)
   - [ ] No payment data loss (transaction isolation)
   - [ ] 100% test coverage cho split bill logic

## High-Level Design

### Architecture

```
┌─────────────────┐
│  Customer Web   │  Scan QR → View order → Choose payment mode
│  (Next.js)      │  - Full payment: amount = remaining
│                 │  - Split bill: custom amount input (max = remaining)
└────────┬────────┘
         │ POST /api/v1/customer/orders/{id}/checkout
         │ { amount: 2000, stripe_payment_method_id: "pm_xxx" }
         ↓
┌─────────────────┐
│  Laravel API    │  1. Validate amount ≤ remaining
│  Backend        │  2. Lock order (DB transaction)
│                 │  3. Create Stripe PaymentIntent
│                 │  4. Create OrderPayment record
│                 │  5. Update order.paid_amount += amount
│                 │  6. If paid_amount ≥ total_amount: set status='paid'
└────────┬────────┘
         │
         ↓
┌─────────────────┐
│   Database      │  - customer_orders (total_amount, paid_amount, status)
│   (MySQL)       │  - order_payments (amount, status, stripe_payment_intent_id)
└─────────────────┘
```

### Key Components

**Backend:**
- `CustomerOrderController@checkout` — Modified to accept partial amounts
- `StripePaymentService` — Create PaymentIntent with custom amount
- `OrderPayment` model — 1-to-many với CustomerOrder
- Database migration — Add `paid_amount` to `customer_orders` (already exists)

**Frontend:**
- `PaymentView` component — Radio buttons: "Full" vs "Split"
- Amount input với validation (max = remaining)
- Real-time remaining calculation
- Post-payment success screen với remaining info

**Admin:**
- Order detail page — Display payments list + remaining
- Logic: Hide "Còn nợ" nếu remaining ≤ 0

## Implementation Plan

See `TASKS.md` for detailed breakdown.

## Testing Strategy

See `TESTS.md` for test scenarios.

## Design Documentation

See [DESIGN.md](./DESIGN.md) for detailed technical design.

## Related Plans

- Plan-018: Stripe Payment Integration (base foundation)
- Plan-019: Menu Timeout & Settings (order management context)
- Future: POS split bill support

## References

- Stripe Payment Intents API: https://stripe.com/docs/api/payment_intents
- Original doc: `docs/explanation/dine-in-stripe-payment-logic.md`
