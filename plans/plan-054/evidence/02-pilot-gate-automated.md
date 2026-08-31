# Evidence 02 — #1232 pilot gate, the automated half (2026-07-30)

Evidence 01 proved the SDK, the credentials and the QR lifecycle on the PayPay
sandbox. This round answers a different question: **of the seven checklist items
in #1232, which ones can be settled without a human, and what is the real state
of the ones that cannot.**

Nothing here was run against a real shop, no flag was turned on, and no money
moved.

## Verdict per checklist item

| # | Item | State after this round |
|---|---|---|
| 1 | Real scan with the sandbox app | **BLOCKED — human + provider.** Code path proven (see item 2 row); the scan itself needs a phone, and the sandbox is refusing us today (§3). |
| 2 | Webhook registered + poll cut | **HALF DONE.** The *code* path is now proven end to end with the poll switched off (§1). Registering the URL in the PayPay dashboard is human. |
| 3 | Sweeper retires stale attempts | **HALF DONE.** Command runs; every decision is covered by tests; the "real overdue attempt on staging" run cannot be reproduced locally (§2, §3). |
| 4 | No refunds — operations must know | **BLOCKED — operations ruling.** The 409 is enforced and tested; the manual procedure is a business decision nobody has written down. |
| 5 | Shop-level kill switch | **DONE at code level** (§4). Only a human click remains. |
| 6 | Dine-in / logged-in customer | **BLOCKED — product ruling**, unchanged. |
| 7 | Two phones on one order | **DONE** (§5) — and the issue's premise turned out to be half wrong. |

## 1. Item 2 — the webhook alone settles the order, with the poll off

New test: `backend/tests/Feature/Payment/Plan054PayPayPilotGateTest.php`.

What was missing before it: nothing walked the **whole** road. The existing
coverage splits in two and leaves the join untested —
`Plan054PayPayWebhookLedgerTest` starts from an inbox row it created itself
(applicator inwards), and `Plan048ProviderWebhookIntakeTest` C5 posts an
*unmatched* notification through HTTP and stops at
`paypay_no_matching_attempt`. Neither answers "a customer scanned, we never
polled — did the money land?".

The new test posts a **correctly HMAC-signed** notification to
`POST /api/v1/webhooks/payment/paypay`, with `PayPayQrCodeClient` bound to an
instance that **throws on `retrieve()` and `findPayment()`** — i.e. the customer
poll and the sweeper are switched off in the only way a test can state it. Then:

```
inbox outcome            orchestrator_paypay_attempt_recovered   ← item 1 bullet 4
attempt state            succeeded
order_payments.status    succeeded, amount 3000, channel customer_web
  gateway_connection_id  = the connection                        ← item 1 bullet 3
  gateway_option_id      = paypay.web_payment.qr.v1              ← item 1 bullet 3
order                    paid_amount 3000, status closed
```

Signature verification delegates to the **production** `PayPayPaymentGateway`,
not a fake that accepts a magic header, so the green run means a real HMAC check
was passed. The negative control (same payload signed with the wrong secret) must
400 and persist nothing.

Two further cases that a first live webhook burst will hit: a **provider retry**
of the same notification writes exactly one ledger row, and intake **queues**
rather than settling inline (a 2xx is a receipt, not a payment).

The `gateway_option_id` assertion was mutation-checked: forcing that stamp to
`null` in `OrderPaymentService` fails the test (the production file was restored;
`git diff` on it is empty). Before this test no test asserted the option stamp on
a PayPay row at all — the attempt factory fills `connection_option_id` with
whatever option row happens to exist, so a value was always present and always
ignored.

**Still human:** the URL is not registered at PayPay. No test can register it,
and until it is, poll remains the only settlement path in production.

## 2. Item 3 — the sweeper

```
$ docker compose exec app php artisan payments:sweep-paypay-qr --dry-run
payments:sweep-paypay-qr — candidates=0 booked=0 stranded=0 retired=0 still_open=0 unresolved=0 duration=14ms (dry-run)
```

Zero candidates because the dev database has no PayPay connection or attempt — the
connection created during evidence 01 was lost to a reseed (R1). So the run proves
the command is wired and harmless, not that it retires anything.

The decisions themselves are covered by `Plan054SweepStalePayPayQrTest`
(19 cases, green), including the one the issue is actually about — *"retires a
grace-expired code PayPay still calls `CREATED` with no payment behind it"* — plus
*"writes nothing on a dry run, whatever PayPay answers"* and *"reports the decision
it would have taken"*.

**Still human:** the issue asks for the dry run on **staging against a real
overdue attempt**. That needs a staging database with a live connection and a
minted code, which is the same dependency as item 1.

## 3. The sandbox is refusing us today — item 1 is blocked on more than a phone

Evidence 01 (2026-07-29) got `SUCCESS` from `createQRCode` and
`getPaymentDetails`. The same calls, same credentials, from `tempo-app` today:

```
key=a_Jf0K… secret_len=44 merchant=991602796635988897
CREATE => EXCEPTION code=0 msg=""
```

The empty message and code 0 are not the SDK being unhelpful about a 4xx — PayPay's
edge is answering with **HTML**, so the SDK's `json_decode` yields null and
`ClientControllerException` is constructed without ever reaching its parent
constructor (`vendor/godx-jp/paypayopa-php-sdk/src/core/Controller.php:156`,
which emits `Trying to access array offset on null`). Unauthenticated probe of the
same host:

```
$ curl -i https://stg-api.paypay.ne.jp/v2/codes/payments/probe-does-not-exist
HTTP/2 502
content-type: text/html; charset=utf-8
x-rate-limited: 1
<html><head><title>502 Bad Gateway</title></head>… openresty
```

`x-rate-limited: 1` on a 502. The host is reachable; the sandbox merchant is being
throttled or has exhausted quota. Evidence 01 already noted the quota is small
("ten reloads and it is gone").

Consequences worth carrying into the pilot decision:

- **Item 1 cannot be attempted until the sandbox answers again.** That is a
  provider-side condition, not a code change, and it needs whoever owns the PayPay
  for Developers account.
- **Our classification of this failure is correct**, which is the reassuring half:
  `PayPayOutageClassifier` treats code 0 as a provider outage, and
  `PayPayQrCodeClient::findPayment()` only maps a **404** to "nobody scanned it",
  so a 502 rethrows and the sweeper marks the attempt `unresolved` instead of
  retiring a code it cannot read. An ambiguous answer never closes an attempt.
- **The circuit breaker that would consume that classification is flag-gated OFF**
  (`payments.circuit_breaker.enabled`, #1105). During a PayPay incident every mint
  fails one by one, with no breaker in front. Not a regression and not in this
  issue's scope — but it is the shape of a real PayPay outage, and today's 502 is
  what one looks like from here.

## 4. Item 5 — the shop switch is reachable without touching the DB

- Backend: `routes/api/shops/payment-policy.php` → `settings/paypay`,
  `PayPayShopSwitchService`.
- Admin UI: `admin-web/src/app/shop/[shopSlug]/settings/payments/components/paypay-section.tsx`
  (present on the pointer `dev` currently carries) — a dedicated card, because the
  generic options list is built from `payment_gateway_connection_options` and that
  row only exists after the shop's first PayPay checkout, i.e. it is missing
  exactly when opting out matters.
- Behaviour: `Plan054PayPayContextTest` — *"lets a shop opt out of PayPay even
  though the brand enabled it"*; P-13 covers "the other shop keeps it".

Only a human clicking it remains.

## 5. Item 7 — two phones, and a premise that was half wrong

The issue states the later mint kills the earlier phone's code. Since the resume
path landed, **that is no longer true for an unchanged bill**: both phones are
handed the same `merchant_payment_id` and nothing is killed. Both halves are now
pinned in `Plan054PayPayPilotGateTest`:

- unchanged bill → phone B gets **the same** code, one attempt, `delete` never
  called;
- bill moves (a coupon lands between the mints) → phone B gets a **new** code, the
  old one is killed, and phone A's own poll now answers **phone B's**
  `merchant_payment_id`. That difference is the only signal phone A has, and it is
  what stops a healthy countdown running over a dead QR.

## Commands run

```sh
cd backend
php -d memory_limit=-1 vendor/bin/pest --compact \
  tests/Unit/Services/Payment/PayPayPaymentGatewayAdapterTest.php \
  tests/Unit/Services/Payment/PayPayPaymentGatewayContractTest.php \
  tests/Unit/Services/Payment/PayPayPaymentGatewayCertificationTest.php \
  tests/Unit/Services/Payment/PayPayQrCapabilityTest.php
# → 27 passed (316 assertions)

php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/Payment/Plan054*.php
# → see PR body for the exact count after this round's new file

docker compose exec app php artisan payments:sweep-paypay-qr --dry-run
```

## What must still happen before a real shop is switched on

1. **Someone with the PayPay for Developers account**: get the sandbox answering
   again (quota / credentials), then do the scan of item 1 and the staging sweep of
   item 3.
2. **Someone with dashboard access**: register
   `https://<backend-host>/api/v1/webhooks/payment/paypay` for Transaction events,
   then re-run the item-2 proof against the real provider — the code path is ready,
   the registration is not.
3. **Operations**: rule on the manual refund procedure (item 4) and on dine-in /
   logged-in customers (item 6), then write the ruling into
   `docs/guide/paypay-customer-web-qr.md`.
