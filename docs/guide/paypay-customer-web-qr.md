---
title: PayPay dynamic QR for customer-web (plan-054)
category: guide
tags: [payment, paypay, qr, customer-web, webhook, jpy, operations]
summary: >
  A guest on customer-web picks PayPay, scans a per-order QR, and the order
  settles itself. JPY-only, customer-web-only, no account linking. REFUNDS DO
  NOT WORK through the system in the pilot — they must be done by hand on the
  PayPay merchant portal. The money is real but it is invisible to shift
  reconciliation and the Z-report, exactly like customer-web Stripe.
related:
  - guide/payment-gateway-paypay-certification.md
  - guide/payment-topology-and-tender-model.md
  - guide/async-payment-methods.md
  - guide/cashier-shift-recovery.md
status: shipped — 63/65; the two open rows are blocked on PayPay, not on code. See "Where this stands"
---

# PayPay dynamic QR (customer-web)

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

## What this is

A guest orders on customer-web, chooses PayPay, and gets a QR minted **for that
order and that amount**. They scan it in the PayPay app, PayPay tells us the
money moved, and the order settles with no staff step.

Two properties make it different from every other PayPay integration in this
repo, and both are load-bearing:

- **The customer is a guest.** There is no login, no linked wallet, no stored
  card. That rules out the capability we already had (below).
- **The QR is dynamic and short-lived.** PayPay stamps its own expiry —
  measured at **create + 301 s (~5 min)** on the sandbox, and
  `CreateQrCodePayload` exposes **no setter for it**
  ([`PayPayQrCodeClient.php:95-98`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayQrCodeClient.php#L95-L98)).
  A shop's `takeaway_payment_timeout_minutes` (5–120 min) cannot stretch it.
  The order's `payment_due_at` is the real deadline; the QR is a sub-window
  inside it that gets re-minted when it lapses.

## What this is NOT

**Not preauth.** `paypay.preauth.wallet.v1` already exists
([`PaymentGatewayCatalogSeeder.php:20`](../../backend/database/seeders/PaymentGatewayCatalogSeeder.php#L20))
and cannot serve this flow: `createPayment` throws without a
`userAuthorizationId`
([`PayPayPaymentGateway.php:250-255`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L250-L255)),
and that id only exists after the customer has linked their PayPay account to
the merchant — a step a walk-in guest never performs. QR therefore ships as a
**separate capability**, `paypay.web_payment.qr.v1`
([`PaymentGatewayCatalogSeeder.php:36`](../../backend/database/seeders/PaymentGatewayCatalogSeeder.php#L36),
seeded at [`:358-424`](../../backend/database/seeders/PaymentGatewayCatalogSeeder.php#L358-L424)).
The preauth capability is untouched.

**Not a Stripe payment method.** PayPay is a native gateway here (PayPay OPA
SDK, `godx-jp/paypayopa-php-sdk`), not a Stripe payment-method type. Nothing in
this flow touches `StripePaymentService`.

**Not POS, kiosk, workstation or the dine-in counter.** The catalog option
declares `channels: ['customer_web']` and `device_classes: ['browser']`
([`:389-390`](../../backend/database/seeders/PaymentGatewayCatalogSeeder.php#L389-L390)).
A `paypay` row can only be written by the customer-web funnel.

**Not multi-currency.** `currencies: [JPY]`, and the availability service
refuses a non-JPY branch on its own rather than trusting the policy engine
(which hardcodes `'JPY'` in its request regardless of the branch) —
[`PayPayAvailabilityService.php:36-40`](../../backend/app/Services/Customer/PayPayAvailabilityService.php#L36-L40).

---

## ⚠️ **Refunds do not work. Read this before you enable anything.**

**The pilot cannot refund a PayPay payment through TempoFast.** There is no
working PayPay refund path, and the catalog option deliberately declares no
`refund` operation
([`PaymentGatewayCatalogSeeder.php:396-401`](../../backend/database/seeders/PaymentGatewayCatalogSeeder.php#L396-L401)).

Why: `OrderPaymentService::refund()` gates the *real* provider reversal on
`isStripePayment()`
([`:1028`](../../backend/app/Services/Customer/OrderPaymentService.php#L1028)),
which requires **both** a `reference_no` starting `pi_` **and** payment-method
code `stripe`
([`:1238-1249`](../../backend/app/Services/Customer/OrderPaymentService.php#L1238-L1249)).
A PayPay row carries `reference_no = tempoqr-…` and method code `paypay`, so it
fails that test and drops into the **ledger-only** branch.

Ledger-only is the one outcome that must never happen here. It would:

- flip the original row to `refunded` ([`:1057`](../../backend/app/Services/Customer/OrderPaymentService.php#L1057)),
- write a negative `succeeded` row and lower `paid_amount` ([`:1091-1118`](../../backend/app/Services/Customer/OrderPaymentService.php#L1091-L1118)),
- void the invoice and **issue a 適格返還請求書 / 赤伝** ([`:1122`](../../backend/app/Services/Customer/OrderPaymentService.php#L1122))

…for money that is **still sitting at PayPay**. The books would say refunded,
the customer would still be out of pocket, and a tax document would exist for a
reversal that never happened. It also bypasses both money-safety gates, since
`stripe_live_refunds_enabled` and the per-refund cap live inside the Stripe
branch ([`:1036-1046`](../../backend/app/Services/Customer/OrderPaymentService.php#L1036-L1046)).

**Decision (plan-054 D5): a `paypay` row is refused with 409 at every refund
path. The 409 is intentional — it is not a bug, do not "fix" it by relaxing
the check.**

### What staff must actually do

1. Refund on the **PayPay merchant portal**, by hand.
2. Record it outside TempoFast (paper log / the shop's own ledger).
3. Accept that **the TempoFast ledger will not reflect it** — `paid_amount`,
   revenue reports and the invoice all keep showing the original sale.

Anyone reconciling a month with PayPay refunds in it must add the portal's
refund list manually. Wiring a real refund is a later plan; it needs a
provider-refund call against `/v2/refunds` plus the reversal-document story,
neither of which exists today.

---

## Where this stands

Re-measured against `dev` on 2026-08-06 (epic #1898). The three rows this table
previously listed as outstanding — the refund guard, the mint service, and the
customer-web UI — have all landed since it was written, and the standing
instruction below it ("do not enable until the refund guard is in") was
therefore blocking on something already done:

| Piece | State |
|---|---|
| QR capability + catalog option (`paypay.web_payment.qr.v1`) | landed |
| `PayPayQrCodeClient` (mint / read / invalidate) + QR state map | landed |
| `payment-context` → `paypay_enabled` | landed |
| `recordPayPayPayment()` ledger funnel + its five guards | landed |
| Runtime provisioning (`PayPayCustomerWebBootstrap`) | landed |
| Canonical `paypay` PaymentMethod provisioner | landed |
| Drawer wall (customer-web money off the gap-claim panel), M4f | landed |
| QR mint / status service (`PayPayPaymentService`) + its 3 routes | landed |
| Refund 409 guard (D5) | landed — [`OrderPaymentService.php:1034`](../../backend/app/Services/Customer/OrderPaymentService.php#L1034) |
| Webhook → ledger path + stale-QR sweeper | landed |
| customer-web UI (checkout + dine-in) | landed |
| **A real customer scanning a real QR end to end** | **never yet proven** — see [evidence/01](../../plans/plan-054/evidence/01-sandbox-verification.md) |
| **Registered Live webhook URL** | **DONE — PayPay is delivering.** Prod `laravel.log` carries `Provider webhook signature verification failed {"provider":"paypay"}` from 2026-08-10 05:06:50 onward: the URL is registered and Live traffic arrives. |
| **Live webhook ACCEPTED** | **not yet — ours rejects it (#2445).** Every delivery so far fails verification. `PAYPAY_WEBHOOK_SECRET` is empty on prod (fine — optional, HMAC simulation only), so an OPA payload must clear `services.paypay.webhook_source_ips.live` and a payload without `notification_type` fails closed on a Live connection. Which of the two it is now shows in the log: the rejection line carries `client_ip` + `has_notification_type` (#2445). |

The frontmatter used to read `status: in flight on feature/customer-paypay-qr`.
That branch **never existed** — `git branch -a --list '*customer-paypay*'` and
`git ls-remote origin 'refs/heads/*customer-paypay*'` both return nothing. The
work landed on `issue-*` branches like everything else here, so the frontmatter
was pointing readers at a branch they could not check out to see "the rest".

So the remaining blocker is no longer registration — PayPay **is** delivering
to prod. What is left is that we REJECT what they send: read the rejection line
in `laravel.log` (`client_ip`, `has_notification_type`) and fix whichever branch
it names. Until then `payments:sweep-paypay-qr` books the money a minute later
instead of instantly. Until that registration lands, `#2445` runs
`payments:sweep-paypay-qr` every minute so a closed-tab COMPLETED payment is
booked without waiting for grace. **Refunds still do not work** — read the
section at the top of this document; that is a deliberate limitation, not a
missing guard.

---

## Configuration

### Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `PAYPAY_API_KEY` | — | OPA API key |
| `PAYPAY_API_SECRET` | — | OPA API secret (HMAC signing material) |
| `PAYPAY_MERCHANT_ID` | — | the assume-merchant identity on every OPA call |
| `PAYPAY_ENVIRONMENT` | `sandbox` | which PayPay host the SDK talks to |
| `PAYPAY_WEBHOOK_SECRET` | — | HMAC-SHA256 secret for inbound notifications |

All five live under `services.paypay.*`
([`config/services.php:52-62`](../../backend/config/services.php#L52-L62)).
Credentials are **deployment-global, not per connection**:
`PayPayCredentialsResolver` reads them from config for every connection
([`:23-30`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayCredentialsResolver.php#L23-L30)).
One PayPay merchant serves the whole deployment, and there is exactly one
webhook secret.

> `.env.example` lists the other four but **not** `PAYPAY_ENVIRONMENT`. Add it
> explicitly to any `.env` you care about rather than relying on the default.

### Why `PAYPAY_ENVIRONMENT` is explicit and not derived from `APP_ENV`

`PayPaySdkClientFactory::productionMode()` picks PayPay's **live** host purely
from the connection's `environment` column, and only for `live`
([`PayPaySdkClientFactory.php:25-28`](../../backend/app/Services/Payment/Gateway/PayPay/PayPaySdkClientFactory.php#L25-L28)).
Everything else goes to `stg-api.paypay.ne.jp`.

Deriving that from `app()->environment('production')` would mean: the moment
the pilot deploys to production while still holding **sandbox** credentials —
the normal pilot posture — every call goes to PayPay's **live** API with a
sandbox key. Stripe avoids this by reading the marker inside its own secret
(`_live_`); PayPay keys carry no such marker, so it has to be stated.

`PayPayCustomerWebBootstrap::resolveEnvironment()`
([`:271-281`](../../backend/app/Services/Payment/Orchestration/Internal/PayPayCustomerWebBootstrap.php#L271-L281)):

- reads `services.paypay.environment`, falling back to `sandbox` on anything
  unrecognised;
- **throws** if it says `live` while `APP_ENV` is not `production`.

A mismatched key/environment pair fails closed in both directions — PayPay
answers 401 (verified with a negative control: one wrong character in the
secret → 401).

Legal values are the `PaymentGatewayEnvironmentEnum` cases: `local`, `sandbox`,
`test`, `live`. Use `sandbox` for the pilot.

> Round-1 review worried that `sandbox` would collide with a policy-loader rule
> computing `production ? Live : Test`. It does not:
> `EloquentPaymentPolicyCandidateLoader::resolveEnvironment()` assigns that
> value at [`:52`](../../backend/app/Services/Payment/Policy/Persistence/EloquentPaymentPolicyCandidateLoader.php#L52)
> and **nothing reads it** — candidates take their environment from
> `$connection->environment` at [`:107-109`](../../backend/app/Services/Payment/Policy/Persistence/EloquentPaymentPolicyCandidateLoader.php#L107-L109).
> It is dead code.

### There is no admin UI — rows are provisioned at runtime

Same shape as Stripe, and for the same reason: `db:seed` never runs on staging
or production, and the policy engine cannot resolve there at all
(`BranchManagementProjectionSource` is bound to the *Unavailable*
implementation, so every option is denied at the ownership gate before a single
row is read). Every customer-web Stripe order already runs through that
fallback.

`PayPayCustomerWebBootstrap::resolveForOrder()`
([`:50-68`](../../backend/app/Services/Payment/Orchestration/Internal/PayPayCustomerWebBootstrap.php#L50-L68))
creates, lazily and idempotently inside one retrying transaction:

1. the `paypay` provider row;
2. the `paypay.web_payment.qr.v1` catalog option (delegated to the seeder, so
   there is one definition);
3. a **per-organization** connection;
4. the connection-option with `approved_currencies: ['JPY']` (uppercase — the
   resolver compares with a strict `in_array`, so `jpy` is a silent denial) and
   `approved_channels: ['customer_web']`;
5. a **published** policy revision.

Three details that were deliberately *not* copied from the Stripe bootstrap:

- **`merchant_account_id` is synthetic**: `orchestrator:customer-web:{orgId}`
  ([`:143`](../../backend/app/Services/Payment/Orchestration/Internal/PayPayCustomerWebBootstrap.php#L143)).
  The connections table is unique on `(provider_id, environment,
  merchant_account_id)` with **no** `organization_id` in the key, so storing the
  real (deployment-global) merchant id would give the second tenant either
  someone else's connection or a unique violation — a 500 at checkout for
  everyone but the first org. `PayPayCredentialsResolver` recognises the
  `orchestrator:` prefix and falls back to config
  ([`:19-26`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayCredentialsResolver.php#L19-L26)),
  so the synthetic id never reaches PayPay. **See the webhook caveat below —
  this has a consequence.**
- **The policy revision is published, never hand-written**
  ([`:218-259`](../../backend/app/Services/Payment/Orchestration/Internal/PayPayCustomerWebBootstrap.php#L218-L259)).
  The Stripe bootstrap writes a row with `source => 'orchestrator_bootstrap'`,
  which is not a valid `PaymentPolicyPublicationSource`; because
  `publishAtomically` validates the newest stored revision before writing a new
  one, that row makes every *later* real publish on the branch throw —
  permanently bricking the admin payment-settings screens, including the
  shop-level switch that turns PayPay off.
- **Nothing is created when PayPay is unconfigured**
  ([`:75-82`](../../backend/app/Services/Payment/Orchestration/Internal/PayPayCustomerWebBootstrap.php#L75-L82))
  — a half-configured deployment must not end up with rows advertising a
  gateway it can never call.

Pinned by `backend/tests/Feature/Payment/Plan054PayPayBootstrapTest.php`
(synthetic merchant reference, idempotency, published revision, environment
from config not `APP_ENV`, live refused outside production, QR capability not
preauth).

> **Removing the env vars is not a kill switch.** The config gate only blocks
> *creating* rows. Once a connection exists, nothing re-reads
> `services.paypay.*` at runtime, so deleting `PAYPAY_MERCHANT_ID` does not hide
> PayPay — it produces calls with empty credentials, 401,
> `GatewayAuthenticationFailed`. To switch a branch off, use the shop-level
> `shop_payment_options.preference = Disabled`.

### Scope: brand-wide by default

Entitlement is loaded per **organization + brand**, not per branch. Turning
PayPay on for a brand turns it on for **every branch in that brand**. A branch
that does not want it must actively write `shop_payment_options.preference =
Disabled`; a missing row means `Inherit`, which means "follow the brand". This
is the intended behaviour, not a bug — but it surprises people, so say it out
loud before a pilot.

### How `paypay_enabled` is computed

`GET /api/v1/customer/branches/{slug}/payment-context` returns a plain boolean
([`CustomerBranchController.php:230-235`](../../backend/app/Http/Controllers/Api/V1/Customer/CustomerBranchController.php#L230-L235)).
Deliberately a boolean, not an options array: the endpoint is **public and
unauthenticated**, and its docblock is explicit — *"Ids only, never
provider/connection detail"*
([`:204`](../../backend/app/Http/Controllers/Api/V1/Customer/CustomerBranchController.php#L204)).
The effective-options presenter carries connection ids, operator org-unit ids
and the full policy trace; none of that may leak here.

`PayPayAvailabilityService::forBranch()`
([`:29-43`](../../backend/app/Services/Customer/PayPayAvailabilityService.php#L29-L43))
returns `enabled` only when **both** hold:

| Check | Failure reason |
|---|---|
| `api_key`, `api_secret` **and** `merchant_id` are all non-blank ([`:50-59`](../../backend/app/Services/Customer/PayPayAvailabilityService.php#L50-L59)) | `credentials_missing` |
| `branch.currency` is `JPY`, case-insensitively ([`:38`](../../backend/app/Services/Customer/PayPayAvailabilityService.php#L38)) | `currency_unsupported` |

Note that **`reason` is not exposed in the API response** — only the boolean
is. When diagnosing a `false`, check the two conditions yourself (below).

**`paypay_enabled = false` does not hide the PayPay button.** The radio renders
either way; the flag only decides whether choosing it runs the QR flow or keeps
today's behaviour, where `payment_method: "qr_pay"` is a label staff settle by
hand. This was a conscious inversion of the original design: making the flag
gate visibility would have made a working button *disappear* at every branch,
since zero branches are configured today.

---

## What operators will and will not see

**PayPay money behaves exactly like customer-web Stripe money. No better, no
worse.** Everything below is a pre-existing property of the customer-web
channel, not something plan-054 introduced.

Every row written by `recordPayPayPayment()` carries `channel =
'customer_web'` and leaves `till_session_id` **NULL** — no drawer collected it
([`OrderPaymentService.php:1517-1543`](../../backend/app/Services/Customer/OrderPaymentService.php#L1517-L1543);
the channel is a server-owned column, never metadata).

| Surface | Shows PayPay? | Why |
|---|---|---|
| Shift reconciliation / 過不足 | **No** | `reconcile()` filters `order_payments.till_session_id = $session->id` ([`TillSessionService.php:2295`](../../backend/app/Services/Pos/TillSessionService.php#L2295)) — a NULL never matches |
| Z-report / per-rate settlement, chain summary (plan-046) | **No** | same attribution, same filter |
| Gap-payment claim panel at shift open | **No** | explicitly walled off — see below |
| POS revenue **by payment method** | **Yes** | `PosRevenueService::byPaymentMethod()` has **no** channel or till filter ([`:353-387`](../../backend/app/Services/Pos/PosRevenueService.php#L353-L387)); it groups `order_payments` by method, so a `PayPay` row appears with its own name |
| POS revenue **KPIs / series / tax breakdown** | **Yes** | derived from `customer_orders` where `status = 'closed'` ([`aggregateTotals():146-197`](../../backend/app/Services/Pos/PosRevenueService.php#L146-L197)) — order-based and channel-blind |
| **Shop dashboard "today"** | **Yes** | `ShopDashboardService::kpis()` sums `customer_orders.total_amount` for closed orders in the branch's business day ([`:118-140`](../../backend/app/Services/Dashboard/ShopDashboardService.php#L118-L140)) — also order-based, also channel-blind |
| Workstation / pos-web "amount remaining" | **Yes** | the order's `paid_amount` moves, and the workstation picks it up on its 5 s `GET /workstation/orders?updated_since=` tick |

The short version for a manager: **sales figures include PayPay; cash-drawer
figures do not.** That is correct — no cashier's drawer ever held this money —
but it means the shift Z-report will not tie out to the day's revenue by the
PayPay amount, and it was never supposed to.

> Plan-054's own D10 table says the shop dashboard does **not** see this money.
> That is wrong: the dashboard reads orders, not payments, and has no channel
> filter. Same for `PosRevenueService`'s revenue KPI. Only the *till/drawer*
> readers are blind to it.

### The drawer wall — why gap-claim excludes customer-web

`TillSessionService::collectedAtTheDrawer()`
([`:381-387`](../../backend/app/Services/Pos/TillSessionService.php#L381-L387))
keeps `channel = 'customer_web'` rows off both the gap **preview** and the
gap **claim**.

Without it the failure is nasty and delayed: a customer-web payment has NULL
attribution forever by design, so it looks exactly like a gap payment. A
cashier claims it at shift open, the amount lands in a tender bucket at close,
and `close()` aborts **422 `VARIANCE_REASON_REQUIRED`** on the qr/emoney
buckets — the shift becomes unclosable, with the cause hours behind.

Worse for PayPay specifically: the canonical method code is `paypay`
([`PayPayCanonicalPaymentMethodProvisioner.php:24`](../../backend/app/Services/Payment/Orchestration/Internal/PayPayCanonicalPaymentMethodProvisioner.php#L24)),
and the reconcile category rollup only maps `cash` / `card` / `transfer` /
`e_wallet`
([`TillSessionService.php:2425-2430`](../../backend/app/Services/Pos/TillSessionService.php#L2425-L2430)).
A claimed `paypay` row would fall into **no** bucket at all and could never be
balanced.

The wall applies to preview **and** claim, because a stale pos-web build (or a
plain `curl`) can post ids the preview never showed it.

> A dedicated `paypay` method code, rather than reusing the seeded `e_wallet`
> row, is deliberate: `e_wallet` means 電子マネー (tap-IC — iD/WAON/nanaco) in
> reconciliation, and an online wallet payment is not that. `paypay` is already
> declared as a `qr`-category tender key in
> [`config/tender_vocabulary.php:31`](../../backend/config/tender_vocabulary.php#L31).

---

## Webhook setup

**Endpoint (production):**
`POST https://tempo.godx.jp/api/v1/webhooks/payment/paypay`
([`routes/api.php`](../../backend/routes/api.php) →
[`PaymentProviderWebhookController::handle()`](../../backend/app/Http/Controllers/Api/V1/Webhooks/PaymentProviderWebhookController.php)).

**Register with PayPay:**

| Environment | How |
|---|---|
| Sandbox | PayPay for Developers → Open API Configurations → Webhook URL |
| **Live** | **Contact form** — there is no self-service console for production OPA notifications ([FAQ](https://integration.paypay.ne.jp/hc/en-us/articles/12689966136591)) |

Contact-form payload to send (copy/paste):

```
Merchant ID: 653886312490745856
Environment: Production (Live)
Setting: Webhook URL (payment transaction notification)
New value: https://tempo.godx.jp/api/v1/webhooks/payment/paypay
Notes: TempoFast customer-web QR (OPA). Please send Transaction Events
(notification_type present). We allowlist PayPay OPA source IPs per FAQ 4414062832143.
```

Until Live registration is confirmed, settlement falls back to
`payments:sweep-paypay-qr` (every minute; books COMPLETED immediately, retires
unscanned CREATED only after grace — #2445).

**Signature header:** `PayPay-Signature` (falls back to `Authorization`).
Set `PAYPAY_WEBHOOK_SECRET` to the issued value; verification is
`hash_hmac('sha256', rawBody, secret)`
([`PayPayPaymentGateway.php:217-222`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L217-L222)).

Verification order in `verifyWebhook()`
([`:202-246`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L202-L246)):

1. body mentioning the deprecated host `api.paypay.ne.jp` → rejected outright;
2. **missing/blank signature header → rejected**, always, even in sandbox;
3. secret configured → HMAC must match;
4. **no secret configured:**
   - connection `environment = live` → **rejected, fail closed** (#1107: a live
     connection must never accept an unverifiable payload)
     ([`:223-226`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L223-L226));
   - any non-live connection → **accepted**, with a deliberately loud warning
     `paypay_webhook_unverified_accept` on the `payment_orchestration` channel
     ([`:227-234`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L227-L234)).

That last branch exists because PayPay's sandbox does not reliably sign its
notifications. It is a convenience, and the log line is how you notice you are
still relying on it. **Live OPA notifications** carry `notification_type` and
are accepted when the source IP is on PayPay's published allowlist — HMAC
secret is optional for that path. A live payload *without* `notification_type`
and without a matching HMAC is still rejected (400).

Rejections answer **400 "Invalid signature."**; an internal fault *after*
verification answers **500**, on purpose, so PayPay retries and dashboards can
tell forged traffic from our own outages.

### Which connection a webhook lands on — a live caveat

`WebhookConnectionResolver::resolvePayPay()`
([`:110-130`](../../backend/app/Services/Payment/ProviderEvent/WebhookConnectionResolver.php#L110-L130)):

1. `?connection={uuid}` hint, when the URL carries one;
2. otherwise match the payload's `merchant_id` / `merchantId` / `assumeMerchant`
   against `payment_gateway_connections.merchant_account_id`;
3. otherwise, **if there is exactly one active PayPay connection**, use it;
4. otherwise → `null` → `WebhookVerificationFailed` → **HTTP 400**
   ([`ProviderEventIntakeService.php:56-59`](../../backend/app/Services/Payment/ProviderEvent/ProviderEventIntakeService.php#L56-L59)).

Because the bootstrap stores a **synthetic** `merchant_account_id`, step 2 can
never match the real merchant id PayPay sends. A single-organization
deployment is fine — step 3 catches it. **With a second organization there are
two active PayPay connections and step 3 stops working: notifications start
returning 400 for both.** One PayPay merchant serves the whole deployment, so
there is no second webhook URL to disambiguate with. Treat this as a hard
single-tenant limit for the pilot, or register the URL with an explicit
`?connection=<uuid>`.

### What the webhook actually does

The notification is only a **trigger**; provider truth comes from an
authoritative retrieve. `ProviderEventApplicator::applyPayPayNotification()`
([`:278-310`](../../backend/app/Services/Payment/ProviderEvent/ProviderEventApplicator.php#L278-L310))
records one of these outcomes on the `payment_provider_events` row:

| `outcome` | Meaning |
|---|---|
| `orchestrator_paypay_attempt_recovered` | matched a live attempt and re-read it from PayPay — the good path |
| `orchestrator_paypay_refund_recovered` | same, for a refund |
| `paypay_ignored_terminal` | the attempt/refund is already in a terminal state — **usually** a replay, but see the alarm rule below |
| `paypay_no_matching_attempt` | **no attempt exists for this reference** — see troubleshooting |
| `paypay_ignored_missing_reference` | payload carried no provider object id |
| `paypay_attempt_recovery_unavailable` | retrieve could not be performed |

### When those two outcomes ring an alarm (#3115)

`paypay_ignored_terminal` and `paypay_no_matching_attempt` both mean "PayPay says
money moved and nothing here will book it". Whether that is an emergency depends
on **whose money it is**, and this merchant is shared: the Live merchant also
serves WooCommerce `menu.betoya.jp`, whose relay tees every notification here, so
foreign `pp_*` references land on `paypay_no_matching_attempt` all day (measured
2026-08-17: 32 in six hours). Alarming on those trains operators to ignore the
alarm.

`alarmOnUnbookableNotification()` therefore rings only for money that is provably
**ours**, and ownership is decided in this order:

1. **A matching `payment_attempts` / `payment_refunds` row exists** — proof, no
   guessing needed. This covers every reference shape we mint, including the two
   that carry no prefix: preauth `merchantPaymentId()` and refund
   `merchant_refund_id` are both bare operation ids.
2. Otherwise, **the reference starts with `tempoqr-`** — the only case left where
   we still have to guess, and the one the WooCommerce noise falls outside of.

Until #3115 the rule was step 2 alone. That silenced every unbookable
notification for a preauth attempt or a refund of ours, which is exactly the
shape the alarm exists to catch. Gate:
`tests/Feature/Payment/Issue3115PayPayUnbookableOwnershipTest.php` — it proves
both directions, because an alarm that cries wolf gets switched off rather than
argued with.

Retrieval dispatches on the merchant-payment-id prefix `tempoqr-`
([`PayPayQrCodeClient.php:35`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayQrCodeClient.php#L35),
used at [`PayPayPaymentGateway.php:336-360`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L336-L360)).
This is not cosmetic: `Code::getPaymentDetails` reads `/v2/codes/payments/{id}`
while `Payment::getPaymentDetails` reads `/v2/payments/{id}`, the SDK gives no
parameter to choose, and asking the wrong one 404s → the SDK throws → the
provider-event job dead-letters after five tries.

A QR that lapses reports `EXPIRED`, which maps to **Canceled**, not
`ReconciliationRequired`
([`PayPayLifecycleMapper.php:282-288`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayLifecycleMapper.php#L282-L288)) —
a customer who did not scan in time is an ordinary outcome, not something for
an operator to chase.

### The ledger funnel

Both writers — the provider-event queue worker and the customer's status poll —
converge on **one** method, `OrderPaymentService::recordPayPayPayment()`
([`:1409-1555`](../../backend/app/Services/Customer/OrderPaymentService.php#L1409-L1555)),
modelled on `StripePaymentService::markOrderPaidFromIntent` (which owns the
guards) and **not** on `recordStripeWebhookPayment` (a bare SELECT-then-INSERT
that is only safe because all its callers already hold the order lock).

Five guards, in order, each for a case that actually happens:

| # | Guard | Line | On failure |
|---|---|---|---|
| 1 | `lockForUpdate()` on the order | [`:1419`](../../backend/app/Services/Customer/OrderPaymentService.php#L1419) | serialises the two writers |
| 2 | idempotency on `idempotency_key` **or** `reference_no`, under the lock | [`:1431-1443`](../../backend/app/Services/Customer/OrderPaymentService.php#L1431-L1443) | `reason: already_recorded` |
| 3 | order not voided/expired | [`:1450-1463`](../../backend/app/Services/Customer/OrderPaymentService.php#L1450-L1463) | `paypay_payment_for_dead_order` + stranded |
| 4 | currency matches the order's | [`:1467-1479`](../../backend/app/Services/Customer/OrderPaymentService.php#L1467-L1479) | `paypay_currency_mismatch` + stranded |
| 5 | would not push past the order total | [`:1491-1508`](../../backend/app/Services/Customer/OrderPaymentService.php#L1491-L1508) | `paypay_payment_would_overpay` + stranded |

The `idempotency_key` is the merchant payment id — that is the DB backstop,
since `order_payments` is unique on `(customer_order_id, idempotency_key)` and
nothing enforces uniqueness on `reference_no`.

**"Stranded" means the guard refused to book money PayPay already took.** The
amount comes back to the caller (`stranded_amount`) to be handed back *outside*
the lock — we never call a provider API while holding the order row. Any
`stranded_amount` in the logs is money that needs a human.

Pinned by `backend/tests/Feature/Payment/Plan054RecordPayPayPaymentTest.php`.

---

## Troubleshooting

Logs: `backend/storage/logs/payment-orchestration.log` (channel
`payment_orchestration`, daily, 14 days —
[`config/logging.php:144-150`](../../backend/config/logging.php#L144-L150)).
Application-level PayPay warnings (`paypay_payment_for_dead_order`,
`paypay_currency_mismatch`, `paypay_payment_would_overpay`) go to the **default**
log channel, not that one.

### "The PayPay button does nothing"

The button always renders. If picking it falls back to the manual `qr_pay`
label, `payment_context.paypay_enabled` is `false`. The API does not tell you
why, so check the two inputs directly:

```sh
curl -s https://<host>/api/v1/customer/branches/<slug>/payment-context | jq .data
docker compose exec app php artisan config:show services.paypay
```

- any of `api_key` / `api_secret` / `merchant_id` blank → `credentials_missing`;
- `branches.currency` not `JPY` → `currency_unsupported`.

Remember `config:cache` — a `.env` edit without a cache clear leaves the old
values in place.

If `paypay_enabled` is `true` and the flow still does nothing, look for a
provisioning failure instead: `paypay_bootstrap_policy_scope_invalid` in
`payment-orchestration.log`
([`PayPayCustomerWebBootstrap.php:248-256`](../../backend/app/Services/Payment/Orchestration/Internal/PayPayCustomerWebBootstrap.php#L248-L256))
means the org/brand/branch console ids disagree or are not real UUIDs — a
tenant-data problem, surfaced as "PayPay unavailable" rather than a 500.

### "The customer paid but the order is still pending"

Work from the provider event backwards.

```sql
-- 1. Did the notification arrive at all?
SELECT id, provider_event_id, state, outcome, last_error_code, created_at
FROM   payment_provider_events
WHERE  provider_id = (SELECT id FROM payment_gateway_providers WHERE code='paypay')
ORDER  BY created_at DESC LIMIT 20;
```

- **No row at all** → PayPay never reached us, or intake answered 400. Check the
  web-server access log for `POST /api/v1/webhooks/payment/paypay`, then
  `grep 'Provider webhook signature verification failed' storage/logs/laravel*.log`.
  With more than one active PayPay connection this is the multi-tenant
  resolution failure described above.
- **`outcome = paypay_no_matching_attempt`** → the money arrived for a reference
  we have no `payment_attempts` row for. Either the QR was minted without
  reserving an attempt first (an orphan QR — the mint path must reserve the
  attempt *before* calling PayPay), or the attempt belongs to a different
  connection. Cross-check:

```sql
SELECT id, state, connection_id, provider_object_id, created_at
FROM   payment_attempts
WHERE  provider_object_id = 'tempoqr-…';
```

- **`outcome = orchestrator_paypay_attempt_recovered` but the order is still
  pending** → the attempt was updated but the ledger row was not written. Look
  for the guard that refused it:

```sh
grep -E 'paypay_payment_for_dead_order|paypay_currency_mismatch|paypay_payment_would_overpay' \
  backend/storage/logs/laravel-*.log
```

Each of those lines carries `order_id`, `merchant_payment_id` and `amount`.
**They mean PayPay is holding money we refused to book** — the customer must be
refunded on the merchant portal by hand (see the refund section).

- **No guard fired either** → check the ledger directly; `already_recorded` is
  silent by design:

```sql
SELECT id, status, amount, channel, till_session_id, reference_no, idempotency_key
FROM   order_payments WHERE reference_no = 'tempoqr-…';
```

A row present with `status = succeeded` while the order stayed open points at
`syncLedgerCacheAndSettleIfPaid` / `isPaidEnough` — i.e. the order total moved
after the QR was minted (a coupon, an added item), so the payment no longer
covers it.

### "A provider event dead-lettered"

`state = 'dead_letter'`, `last_error_code = 'PROCESSING_EXHAUSTED'`, after five
tries with backoff `[10, 30, 60, 120, 300]`
([`ProcessPaymentProviderEventJob.php:16-19,43-46`](../../backend/app/Jobs/Payment/ProcessPaymentProviderEventJob.php#L16-L19)).
Two log lines mark it: `provider_event_processing_failed` (per attempt, with
the exception message) and `provider_event_dead_letter`
([`ProviderEventInboxService.php:165-168`](../../backend/app/Services/Payment/ProviderEvent/ProviderEventInboxService.php#L165-L168)).

```sh
docker compose exec app php artisan payments:process-provider-events --dead-letter
docker compose exec app php artisan payments:process-provider-events --requeue=<uuid>            # dry run
docker compose exec app php artisan payments:process-provider-events --requeue=<uuid> --execute
```

Fix the cause before requeuing — a requeue replays into the same failure. The
usual causes for PayPay: the retrieve hit the wrong endpoint (a merchant
payment id missing the `tempoqr-` prefix), or PayPay returned ≥400 (the SDK
throws on anything but 401/403, which the call guard swallows).

### "An attempt is stuck in `provider_pending`"

`payments:sweep-paypay-qr` handles this, hourly on the scheduler
([`routes/console.php:71-75`](../../backend/routes/console.php#L71)). It asks
PayPay what happened to every QR attempt past the grace window and books,
strands or retires each one through the same `recordPayPayPayment` funnel a
webhook uses. Run it by hand with `--dry-run` first to see the decisions:

```sh
docker compose exec app php artisan payments:sweep-paypay-qr --dry-run
```

The generic commands do NOT cover this: `payments:reconcile-attempts` only
selects `state = reconciliation_required`
([`ReconcilePaymentAttempts.php:40-42`](../../backend/app/Console/Commands/ReconcilePaymentAttempts.php#L40-L42)),
and `payments:expire-stale` works on `order_payments` rows with an `expires_at`,
not on attempts. That is why the QR sweep is its own command.

To see the backlog without running the sweep, read the `PayPay QR` rows of
`payments:observation-report`. A `stale` count that does not fall means the
scheduler is not running the sweep — and an unswept QR is money PayPay may have
taken that is not in the ledger.

---

## Known limitations — what plan-054 deliberately did not do

1. **No refunds.** See the top of this document. Manual portal refund plus a
   manual record; the TempoFast ledger will be wrong until a later plan wires
   `/v2/refunds` and the reversal-document story.
2. **No POS / kiosk / workstation / dine-in-counter surface.** The capability is
   `customer_web` only. Money reaches the workstation as a moving
   `paid_amount`, never as a payment line — a cashier sees "remaining 0", not
   "paid by PayPay". Tender detail on the LAN is a different plan.
3. **No shift attribution.** `till_session_id` stays NULL forever, so 精算,
   Z-report, per-rate settlement and the plan-046 chain aggregate never see this
   money. Identical to customer-web Stripe.
4. **No settlement ingest.** PayPay payouts are not reconciled against the
   gateway-settlement pipeline, so plan-050's aging alarm will start crying
   false positives around T+45 days.
5. **QR lifetime is PayPay's, not the shop's.** ~5 minutes, not configurable.
   `takeaway_payment_timeout_minutes` governs the *order*; the QR must be
   re-minted inside that window. The UI must not let a customer read "QR
   expired" as "order expired".
6. **Credentials are deployment-global.** One merchant, one webhook secret, one
   key pair for every tenant — "per-branch provisioning" only ever means "within
   one PayPay merchant" ([`PayPayCredentialsResolver.php:23-30`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayCredentialsResolver.php#L23-L30)).
7. **Effectively single-tenant for webhooks** while `merchant_account_id` is
   synthetic — see the resolution caveat above.
8. **No orchestrator glue for the mint call.** The QR is minted by calling the
   SDK directly, so the provider circuit breaker (which only wraps
   `preparePayment`, and is OFF by default anyway —
   `PAYMENTS_CIRCUIT_BREAKER_ENABLED`) and the adapter's own capability
   assertion do not cover it. `PayPayQrCodeClient` is not a
   `PaymentGatewayContract` and therefore cannot run the shared contract suite.
9. ~~**No `ProviderPending` sweeper.**~~ **Resolved** —
   `payments:sweep-paypay-qr` runs hourly
   ([`routes/console.php:71`](../../backend/routes/console.php#L71)) and
   reclaims attempts waiting on a QR nobody scanned. Backlog is visible as the
   `PayPay QR` rows of `payments:observation-report`.
10. **The policy engine still cannot resolve on production.** PayPay rides the
    same bootstrap fallback Stripe already rides
    (`BranchManagementProjectionSource` bound to *Unavailable*, plan-047 T2.5).
    That debt blocks Stripe too; it is not plan-054's to pay, but it does mean
    `payment-context` returns `policy_revision: null` and
    `gateway_option_id: null` for every branch in production.
11. **Nobody has ever scanned a real QR end to end.** Sandbox proves credential
    validity, QR create/read/delete, and webhook intake; a customer actually
    completing a payment is still the plan's open exit criterion
    ([evidence/01](../../plans/plan-054/evidence/01-sandbox-verification.md)).

## Testing

Day-to-day recipes live in the `paypay-test` skill
(`.claude/skills/paypay-test/SKILL.md`) — sandbox smoke, signed webhook
simulation, and the fake gateway for feature tests.

```sh
cd backend
vendor/bin/pest --compact tests/Unit/Services/Payment/PayPayQrCapabilityTest.php
vendor/bin/pest --compact tests/Unit/Services/Customer/PayPayAvailabilityServiceTest.php
vendor/bin/pest --compact tests/Feature/Payment/Plan054PayPayContextTest.php
vendor/bin/pest --compact tests/Feature/Payment/Plan054RecordPayPayPaymentTest.php
vendor/bin/pest --compact tests/Feature/Payment/Plan054PayPayBootstrapTest.php
```

The SDK is a real dependency and lives in `vendor/`, which is a **named Docker
volume** — `composer install` has to run **twice** (once on the host for native
pest, once in the container for artisan/HTTP), or one side reports the class
missing.
