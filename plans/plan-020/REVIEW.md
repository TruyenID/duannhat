# Plan 020 — Split Bill Payment: Code Review

**Reviewer**: Claude Opus 4.6
**Date**: 2026-05-11
**Branch**: `feature/plan-020-split-bill-payment`
**Verdict**: **CRITICAL** -- 1 critical issue must be resolved before merge

---

## Summary

The implementation delivers the core split bill feature: a new `POST /split-payment-intent` endpoint, split-aware webhook handler, updated `formatOrder` with remaining/payment fields, customer-web payment mode selector (full/split), admin-web payment history display, and solid Pest test coverage for the new backend paths. The architecture (DB lock + webhook idempotency + metadata-based flow routing) is well-designed.

However, there is one critical overcharge bug when "Pay full" is selected after a prior split payment, plus several warnings that should be addressed.

---

## Issues

| # | Severity | File:Line | Description | Suggested Fix |
|---|----------|-----------|-------------|---------------|
| 1 | **CRITICAL** | `backend/app/Services/Customer/StripePaymentService.php:118` | **Overcharge bug**: `createFullPaymentIntent()` charges `$order->total_amount`, not the remaining balance. If Customer A makes a split payment of 2000 on a 5000 order, then Customer B selects "Pay full" (UI shows "Pay 3000"), the backend creates a PaymentIntent for 5000 -- charging 5000 instead of 3000. The frontend button text (`amountToPay = remaining`) does not match the actual Stripe charge. | Either (a) change `createFullPaymentIntent()` to charge `total_amount - paid_amount` instead of `total_amount`, or (b) when `hasPriorPayments` is true in `payment-view.tsx`, route "full" mode through `split-payment-intent` with `amount = remaining`. Option (b) is simpler and avoids breaking existing full-payment behavior for orders with no prior payments. |
| 2 | **WARNING** | `backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php:93-127` | **Authorization gap on split endpoint**: `createSplitPaymentIntent` is a public route (no auth middleware). Any client that knows an order UUID can create payment intents against it. While `createFullPaymentIntent` has the same exposure, split payments make this easier to exploit because arbitrary amounts can be specified. The customer routes file (`customer.php`) shows no rate-limiting on the payment-intent endpoints. | Add `throttle:10,1` middleware to `split-payment-intent` and `full-payment-intent` routes to limit abuse. Consider adding a lightweight token check (e.g., validating the QR token matches the order's table). |
| 3 | **WARNING** | `backend/app/Services/Customer/StripePaymentService.php:118`, `backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php:62-85` | **Docblock contradiction**: The `createFullPaymentIntent()` docblock says "charges the full remaining balance (total_amount - paid_amount)" but the code charges `total_amount`. Even if this is intentional pre-split-bill behavior (the test at `StripePaymentTest.php:137` confirms it), the docblock is now misleading in a split-bill context. | Update the docblock to accurately describe the current behavior, then fix issue #1. |
| 4 | **WARNING** | `plans/plan-020/DESIGN.md`, `plans/plan-020/TESTS.md` | **DESIGN.md references non-existent enum values**: The design doc uses `status = 'confirmed'` and `status = 'paid'` throughout, but the actual `CustomerOrderStatusEnum` has neither. The real statuses are `Open`, `Dining`, `Checkout`, `Paying`, `Closed`, `Voided`. The implementation correctly uses `Closed` in place of `paid`. TESTS.md test code samples also use the wrong enum values. | Update DESIGN.md and TESTS.md to use the correct enum values (`Open` instead of `confirmed`, `Closed` instead of `paid`). These docs will mislead future developers. |
| 5 | **WARNING** | `backend/check-branches.php`, `backend/check-menu-schedule.php` | **Debug scripts committed**: Two standalone PHP debug/diagnostic scripts were added in this branch. They are not tests, not artisan commands, and appear to be local debugging artifacts. They access the database directly and hardcode database names (`dxs_product`, `tempo`). | Remove these files from the branch. If the debugging functionality is needed, convert them to artisan commands. |
| 6 | **WARNING** | `customer-web/ISSUES.md`, `customer-web/docs/issue-cart-timeout.md`, `customer-web/docs/issue-cart-timeout-2026-05.md` | **Unrelated files committed**: Three documentation files about cart timeout (a separate feature) were added in this branch. `ISSUES.md` + two near-duplicate issue docs (`issue-cart-timeout.md` and `issue-cart-timeout-2026-05.md`) are out of scope for plan-020 and clutter the diff. | Move these to their own branch/commit, or at minimum remove the duplicate (`issue-cart-timeout-2026-05.md` is a near-exact copy of `issue-cart-timeout.md` with minor formatting changes). |
| 7 | **WARNING** | `backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php:100-102` | **Status check excludes valid payment states**: `createSplitPaymentIntent` rejects orders with status `Closed` or `Voided` but does not explicitly require any positive status. An order in `Pending` status (not yet confirmed by staff) can receive split payments. The DESIGN.md says "Check order.status === 'confirmed' (not 'paid' yet)" but the implementation allows any non-closed/voided status. | Consider restricting split payments to orders in `Open` or `Dining` status, which are the states where customers are actively at the table and the order has been accepted. |
| 8 | **INFO** | `backend/app/Services/Customer/StripePaymentService.php:401-405` | **`generatePaymentCode` uses `SUBSTR` + `CAST AS INTEGER`**: MySQL's `CAST(... AS INTEGER)` is not standard -- MySQL uses `CAST(... AS SIGNED)` or `CAST(... AS UNSIGNED)`. While MySQL may silently accept `INTEGER` in some versions, this is fragile. | Change to `CAST(SUBSTR(payment_code, ?) AS UNSIGNED)` for explicit MySQL compatibility. |
| 9 | **INFO** | `backend/app/Services/Customer/StripePaymentService.php:369` | **Sentinel UUID `00000000-0000-0000-0000-000000000000` for `received_by_id`**: This is a synthetic value for webhook-originated payments where no staff user exists. If `received_by_id` has a FK constraint to `users.id`, this will fail at insert time when no matching user row exists. | Verify that the `received_by_id` column allows this sentinel or is nullable. If it has a FK constraint, create a system user row or use `null`. |
| 10 | **INFO** | `customer-web/app/[locale]/dine-in/[shop]/table/[qrToken]/components/payment-view.tsx:172` | **Unsafe cast `(order as any).discount_amount`**: The `ActiveOrder` type does not include `discount_amount` or `tax_amount`, but the component accesses them via `as any` cast. This bypasses TypeScript safety. | Either add `discount_amount` and `tax_amount` to the `ActiveOrder` interface in `data/orders.ts`, or remove these fields if they are not returned by the API. |
| 11 | **INFO** | `customer-web/data/orders.ts:99` | **Hardcoded Vietnamese string in `minutesAgo()`**: The function returns `"vua xong"` (Vietnamese for "just now") regardless of the active locale. This should use the i18n system. | Use `useTranslations` or accept a translation function parameter to return localized strings. |

---

## Checklist Results

| Check | Result | Notes |
|-------|--------|-------|
| CORRECTNESS | FAIL | Critical overcharge bug (#1). DESIGN.md enum values do not match implementation (#4). |
| TESTS | PASS (with caveat) | Good coverage for split webhook, idempotency, validation, controller endpoints. The race condition test in TESTS.md (sequential `DB::transaction` calls) does not truly test concurrency, but the lock logic in the service is correct. Frontend tests (Task 4.2) not yet done per TASKS.md. |
| CONVENTIONS | PASS | Follows `docs/contributing/service.md` patterns: `DB::transaction` + `lockForUpdate` on state transitions, `whenLoaded` guard on relations (via `relationLoaded` check in controller), eager-load in `CustomerQrOrderService`. |
| SECURITY | WARNING | Public endpoints without rate limiting (#2). No auth check on payment intent creation. |
| PERFORMANCE | PASS | No N+1 issues found. `payments.paymentMethod` is eager-loaded in `getCurrentOrder` and `findById`. |
| OMNIFY | PASS | No edits to generated files (`app/Omnify/`, `database/migrations/omnify/`). |
| PINT | PASS | `vendor/bin/pint --test --format agent` passes on all changed backend files. |
| NO DEAD CODE | WARNING | Debug scripts `check-branches.php`, `check-menu-schedule.php` (#5). Duplicate issue docs (#6). No TODO/FIXME without issue links found. |
| OPENAPI | N/A | Customer controllers in this project do not use OA attributes (only `CouponController` and `CustomerMenuController` do). No action needed. |

---

## Recommendation

**Do not merge** until issue #1 (overcharge bug) is fixed. The remaining warnings should ideally be addressed in this PR but are not merge-blocking individually.

Priority order for fixes:
1. Fix the overcharge bug (critical)
2. Remove debug scripts from the commit
3. Remove out-of-scope cart-timeout docs (or move to separate PR)
4. Add rate limiting to payment endpoints
5. Update DESIGN.md/TESTS.md to use correct enum values
