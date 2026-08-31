---
title: Takeaway payment policy, email and phone validation
category: guide
tags: [takeaway, payment-policy, email, phone, plan-035]
summary: "Payment-before-prep policy with an HQ default and per-shop override, checkout email collection, and phone validation — the three plan-035 features that share the takeaway checkout surface."
related: [order-domain]
---

# Takeaway payment policy + email + phone validation (plan-035)

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

Three takeaway-flow features shipped together because they touch the same
checkout surface:

1. **Payment-before-prep policy** — HQ-default (brand) + per-shop override.
2. **Customer email** — collected at checkout; triggers OrderPlacedMail
   (with QR code) on placement and OrderPaidInvoiceMail (with PDF) on
   payment.
3. **Phone validation by country** — derived from `branches.locale` and
   enforced by `libphonenumber-js` on the FE.

Plan: plan-035 (đã archive — xem git history). Issue:
[godx-jp/godx-tempo#363](https://github.com/godx-jp/godx-tempo/issues/363).

---

## Payment policy

### Mental model

| Setting | Behaviour |
|---|---|
| `prep_before_payment = true` (default) | Takeaway order born as `pending`. KDS / workstation hide pending. Staff must `POST /api/v1/shops/{slug}/orders/{id}/confirm` to flip to `open` after seeing payment at the counter. Existing flow — nothing changes for unconfigured brands. |
| `prep_before_payment = false` | Order born as `open` immediately. KDS sees it, kitchen starts cooking. Risk: customer walks before paying — accept that as the explicit tradeoff. |
| Stripe online prepay (`payment_method ∈ ['stripe', 'online']`) | Always `open` — Stripe webhook already takes care of `closed`. The policy flag is bypassed here. |

### Resolution

`App\Services\Shop\EffectiveOrderPolicyService::resolve(Branch $branch)`
merges the brand default + shop override:

```
ShopOrderSetting.prep_before_payment IS NULL ──► fallback ──► BrandOrderPolicy.default_prep_before_payment IS NULL ──► fallback ──► true (legacy)
```

Same pattern for `customer_email_required`. The resolved payload is
cached 5 minutes per branch (`branch:{id}:effective_order_policy`) and
busted by:
- `ShopOrderSetting::saved` / `deleted` — single-branch forget.
- `BrandOrderPolicy::saved` / `deleted` — fan-out forget across every
  branch in the brand.

### Where the FE reads it

| Endpoint | Field surfaced |
|---|---|
| `GET /api/v1/customer/branches` | `branch.effective_order_policy` + `branch.locale` |
| `GET /api/v1/customer/branches/{slug}/cart-config` | `data.effective_order_policy` (same shape) |

Customer-web's `brand-context.tsx` hydrates `Branch.effective_order_policy`
and `Branch.locale` from those payloads. `checkout-page.tsx` reads:

```ts
const branchCountry = currentBranch.effective_order_policy?.phone_country
  ?? deriveCountry(currentBranch.locale ?? null);
const prepBeforePayment = currentBranch.effective_order_policy?.prep_before_payment ?? true;
const emailRequired = currentBranch.effective_order_policy?.customer_email_required ?? false;
```

### Admin UI

`/shop/{slug}/settings → "Đơn hàng" tab` exposes a tri-state radio:

- **Theo HQ (mặc định toàn brand)** → `prep_before_payment: null`
- **Phải thanh toán trước khi chuẩn bị món** → `prep_before_payment: true`
- **Chuẩn bị món ngay, không chờ thanh toán** → `prep_before_payment: false`

Plus a Switch for `customer_email_required`. Both PATCH'd to
`/api/v1/shops/{slug}/settings/order`.

### Brand-level (HQ) UI

Deferred — plan-035 ships brand defaults via the existing
`brand_order_policies` table. To bulk-set:

```sh
docker compose exec app php artisan tinker --execute \
  '\App\Models\BrandOrderPolicy::updateOrCreate(
    ["brand_id" => \App\Models\Brand::where("slug","beto-coffee")->value("id")],
    ["organization_id" => \App\Models\Organization::first()->id, "default_prep_before_payment" => false]
  );'
```

HQ admin-web page comes in a follow-up — track in `plan-036` if scheduled.

---

## Customer email + transactional mail

### Triggers

| Mail | Trigger | Class |
|---|---|---|
| OrderPlacedMail (+ QR) | `CustomerOrderController::storeByBranch` save success, email present. Fires for BOTH `status=open` (paid online) AND `status=pending` (counter pay-first) — copy switches via `subject_pending` / `intro_pending` / `outro_pending` i18n keys so the pending variant tells the customer "show this QR at the counter to pay" instead of "to pick up". | `App\Mail\OrderPlacedMail` |
| OrderPaidInvoiceMail (+ PDF) | `OrderClosingService::close` AND `StripePaymentService::confirmAndRecordPayment` (full-payment path), email present | `App\Mail\OrderPaidInvoiceMail` |

Both queued via `Mail::to(...)->queue(...)`. Failure is `Log::warning`'d
and swallowed — mail must never break the order flow.

### Mailpit (local dev)

`compose.local-server.yml` already wires Mailpit:

```yaml
MAIL_MAILER: smtp
MAIL_HOST: mailpit
MAIL_PORT: 1025
MAIL_FROM_ADDRESS: hello@tempo.local
```

Inspect captured mail at **http://localhost:8125**. Queue worker has to
run so queued mails dispatch:

```sh
docker compose exec app php artisan queue:work --queue=default
```

In dev you can also `QUEUE_CONNECTION=sync` in `.env` to skip the worker
and dispatch synchronously.

### Production driver

Plan-035 ships dev-only mail (Mailpit). For prod, the recommended path:

1. `composer require resend/resend-laravel` + add `RESEND_API_KEY` to env.
2. `MAIL_MAILER=resend` in compose / env.
3. Resend free tier: 3000 emails / month. Sufficient for early beta.
4. Migrate to AWS SES (~$0.10/1k) when volume > 100k/month.

No code change in `App\Mail\*` is needed — the Mailable abstracts over
the driver.

### Templates

- Markdown: `resources/views/emails/{order_placed,order_paid_invoice}.blade.php`
- PDF (DOMPDF, A5): `resources/views/emails/invoice_pdf.blade.php`
- Translations: `lang/{vi,en,ja}/emails.php`

Locale derived from `$order->branch->locale` (parsed first segment).

### QR payload

`App\Services\Order\OrderQrService::generatePng(CustomerOrder)`:

```json
{ "v": 1, "order_id": "uuid", "code": "ORD-1234", "branch": "hanoi", "type": "takeaway" }
```

Plan-036 (POS scanner) will add HMAC signing before any production roll
beyond the friendly-takeover risk model.

---

## Phone validation by branch country

### BE side

`EffectiveOrderPolicyService::deriveCountry(string $locale): string`
parses BCP-47 / bare locales:

- `vi-VN` → `VN`
- `ja-JP` → `JP`
- `en-GB` → `GB`
- `vi` (bare) → `VN`
- `null` / unknown → `US`

That country is shipped in `effective_order_policy.phone_country` so the
FE doesn't have to re-derive.

### FE side

`web/customer/lib/phone.ts`:
- `deriveCountry(locale)` — same logic mirrored in TS.
- `validatePhoneForCountry(value, country)` — uses `libphonenumber-js`
  `parsePhoneNumberFromString` + `isValid()`. Returns
  `{ valid, formatted, errorKey }`.
- `formatAsYouType(value, country)` — used in the input `onChange` so the
  visible value tracks the local grouping (e.g. `033 690 9454` for VN).
- `toE164(value, country)` — what `lib/api` sends to BE; falls back to
  raw on parse failure.

### Edge cases

- Branch with no `locale` set → fallback `US`. Admin warning ("locale
  chưa cấu hình") TBD in admin-web shop edit page.
- Customer pastes JP number on a VN branch → `phoneWrongCountry` error
  surfaces "Vui lòng nhập số điện thoại của VN".

---

## Smoke test (end-to-end)

```sh
# 1. Backend up + Mailpit running
docker compose -f compose.local-server.yml --env-file .env.local-server up -d
docker compose exec app php artisan migrate

# 2. Customer-web up
docker compose -f compose.local-server.yml --env-file .env.local-server up -d --build customer-web

# 3. Admin opens a takeaway order on Hanoi branch
#    - http://localhost:5450/{locale}/menus → pick items → /checkout
#    - Phone field validates against VN
#    - Email field optional (or required if shop toggled)

# 4. Submit order
#    - http://localhost:8125 (Mailpit) → see OrderPlacedMail with QR

# 5. POS pays + closes
#    - Mailpit refresh → see OrderPaidInvoiceMail with PDF attachment

# 6. Verify policy override
#    - Admin: /shop/hanoi/settings → "Theo HQ / Bật / Tắt"
#    - Save Off → new takeaway order → status=open (no staff confirm)
```

---

## Pest tests

`tests/Feature/Customer/Plan035PaymentPolicyTest.php` covers:

- `deriveCountry` for BCP-47 + bare locales.
- `/branches` index surfaces the policy + locale.
- `/cart-config` surfaces the same policy block.
- Shop override `prep_before_payment=false` → status `open`.
- No override + brand default `true` → status `pending`.
- OrderPlacedMail only queued when email present.
- BrandOrderPolicy override flips resolved default.

7 tests / 25 assertions, all green.

---

## Payment gateway handoff (plan-048)

How the two takeaway money paths meet the plan-047/048 payment gateway:

| Path | What happens at customer submit | Where money is recorded |
|---|---|---|
| **Stripe online** (`payment_method=stripe`) | Customer-web creates a PaymentIntent; the orchestrator reserves a durable `PaymentAttempt` stamped with the **policy-resolved** connection/option (HQ vs franchise via `CustomerWebStripeConnectionResolver`; bootstrap fallback logs `customer_web_stripe_bootstrap_fallback`) | Webhook/sync confirm writes ONE ledger row with `payment_attempt_id` + `gateway_connection_id` + `gateway_option_id`, then settles the order through the canonical boundary |
| **Counter-pay** (`payment_method=counter`) | Order lands `confirmed` (or `open` when `prep_before_payment=false`) with **zero** `order_payments` rows — invariant #5: no fake payment row at submit | POS staff checkout → cash/card_terminal `recordTender` (internal ledger; `gateway_*` stay null by design) |

Ops notes:

- A counter order closed without any payment row is a **bug** (monitor: "Order
  closed without payment" in plan-048 ROLLOUT.md must stay zero).
- Rollback: flipping `PAYMENT_ORCHESTRATOR_TRANSPORT_CUSTOMER_WEB=false`
  reverts Stripe prepare/finalize to the legacy path; counter-pay is unaffected
  (it never touches the gateway).
- Acceptance coverage: `tests/Feature/Payment/Plan048CustomerWebStripeConnectionTest.php`
  (B1/B2/B4 + bootstrap fallback + replay idempotency).

## Open items / follow-ups

- HQ admin-web page for `brand_order_policies` (plan-036 or follow-up).
- BE-side `PhoneFormatForBranch` Rule (current build only validates on
  FE; backend just stores the string). Adding `giggsey/libphonenumber-for-php`
  is the next step when we want defence in depth.
- POS QR scanner (plan-036).
- Production email driver wiring (Resend → SES migration runbook).
- Order `pending` auto-void timeout (`branches.takeaway_payment_timeout_minutes`
  already exists; verify the reaper job catches them).
