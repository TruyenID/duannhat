# Plan 020 — Notes

> Working log for [Split Bill Payment for Dine-in](README.md). Append-only. Newest entries on top.

---

## 2026-05-11 — Implementation complete

**Branch**: `feature/plan-020-split-bill-payment`
**Commits**: 12 (T1.1 through T4.1 + review fixes)
**Tests**: 31 payment tests passing (15 SplitPaymentTest + 16 StripePaymentTest)

### Bugs found and fixed during implementation

1. **Enum comparison bug** — `CustomerOrderController` used `in_array($order->status, ['closed', 'voided'], true)` but `$order->status` is a `CustomerOrderStatusEnum` enum instance, not a string. Strict comparison always returned false, so closed/voided orders could still receive payments. Fixed by comparing against enum instances.

2. **SQLite test incompatibility** — `generatePaymentCode()` used MySQL-only `SUBSTRING()` and `CAST AS UNSIGNED`. Fixed to `SUBSTR()` and `CAST AS INTEGER` which work on both MySQL and SQLite.

3. **Idempotency bug** — `handleSplitPaymentWebhook()` incremented `paid_amount` even on duplicate webhook replays. `recordStripePayment()` silently returned on duplicate but didn't signal to the caller. Fixed by returning `bool` from `recordStripePayment()` and skipping `paid_amount` increment when false.

4. **`received_by_id` NOT NULL** — Omnify migration defines `received_by_id` as NOT NULL. Service was passing `null`, which failed on SQLite. Fixed with sentinel UUID `00000000-...`.

5. **CRITICAL: Overcharge bug** (found in code review) — `createFullPaymentIntent()` charged `total_amount` instead of `total_amount - paid_amount`. After a 2000 split payment on a 5000 order, "Pay full" would charge 5000 instead of the remaining 3000. Fixed to charge `max(0, total_amount - paid_amount)`.

### Accepted warnings from review

- Debug scripts (`check-branches.php`, `check-menu-schedule.php`) and cart-timeout docs are from earlier commits on this branch, not from plan-020. Should be cleaned up separately.
- DESIGN.md/TESTS.md reference wrong enum values (`confirmed`/`paid` vs actual `Open`/`Closed`). Plan docs are ephemeral — not worth a commit to fix.
- No rate limiting on payment-intent endpoints. Acceptable for MVP; can be added later.

---

## 2026-05-11 — Discovery

### Existing integration points

- **CustomerOrder** — `customer_orders` table has `total_amount`, `paid_amount`, `status`, `checkout_at`.
- **OrderPayment** — `order_payments` table with `customer_order_id` FK, `amount`, `status`, `stripe_payment_intent_id`, `paid_at`.
- **StripePaymentService** — `backend/app/Services/Customer/StripePaymentService.php` handles PaymentIntent creation.
- **StripeWebhookController** — `backend/app/Http/Controllers/StripeWebhookController.php` handles `payment_intent.succeeded`.
- **CustomerOrderController** — checkout at `POST /api/v1/customer/orders/{id}/checkout`.

### Key design decisions

- No WebSocket — polling every 5s for remaining amount updates.
- No per-person assignment — free-form amounts only.
- Race condition via DB lock — `lockForUpdate()` inside `DB::transaction()`.
- Webhook-driven DB update — Stripe confirms async; frontend polls.

### Reference docs

- `docs/explanation/dine-in-stripe-payment-logic.md`
- Stripe PaymentIntents API

---

## 2026-05-11 — Plan created

Initial scaffold. Builds on existing Stripe integration.
