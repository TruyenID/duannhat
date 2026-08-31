# Provider capability contract

This document defines the capability data that the policy resolver and payment orchestrator must
consume. It is an implementation contract, not a provider marketing comparison. A provider,
merchant contract, account configuration, rail, channel, currency, environment, API version, and
effective time must all match before an operation is available.

The catalog describes what an integration can potentially do. A connection capability snapshot
describes what one merchant account has actually been approved and tested to do. Shop and device
policy may narrow that snapshot, but may never expand it.

## Resolution rule

For a requested operation, the resolver must find one unambiguous capability row matching:

1. provider and integration product;
2. rail and, where material, payment-method type or card/wallet brand;
3. channel and device class;
4. exact ISO 4217 currency and minor-unit semantics;
5. sandbox/test/live environment;
6. merchant connection and provider account configuration;
7. API/specification version; and
8. `effective_from <= operation_started_at < effective_to`, where a null `effective_to` means open-ended.

The row is usable only when its verification state is `verified` and every requested operation is
explicitly `supported` or has a machine-evaluable `conditional` predicate that is true. `unknown`,
`contract_required`, `certification_required`, expired,
conflicting, or missing data resolves to unavailable with `PAYMENT_CAPABILITY_UNAVAILABLE`. The
orchestrator snapshots the winning capability ID, revision, version, and limits when an attempt is
prepared; later catalog changes do not rewrite an in-flight attempt.

The effective option is the intersection below. There is no fallback that broadens a deny:

```text
catalog capability
  AND connection approval/configuration
  AND owner/HQ policy
  AND shop policy
  AND device policy
  AND runtime health
```

Runtime health may temporarily make an option unavailable, but it is not persisted as a permanent
provider capability.

## Required data shape

| Field | Meaning |
|---|---|
| `provider` / `integration_product` | Adapter and provider product/API family, not a display label |
| `rail` / `method_type` / `brands` | Cash, card, wallet, QR, e-money; provider subtype; exact brands or `account_configured` |
| `channels` / `device_classes` | Customer web, POS, Kiosk, Self-Regi (セルフレジ, #1085 — money-collection self-checkout: internal cash + card_terminal certified by default, distinct from the policy-only Kiosk channel), Workstation; browser, reader, tap-to-pay, server-to-server |
| `currencies` | Explicit currency entries with minor units; never infer from provider-wide marketing claims |
| `environment` | `sandbox`, `test`, or `live`; identities and keys cannot cross environments |
| `workflows` | `sale` and/or `authorize_capture` |
| `operations` | Create, retrieve, authorize, capture, cancel/revert, refund, retrieve refund |
| `limits` | Partial/multiple capture and refund, min/max amounts, authorization/cancel/refund windows |
| `recovery` | Webhook event coverage, polling/retrieve API, reconciliation artifact |
| `merchant_identity` | Required account, merchant, store, terminal, connected-account, or contract identity |
| `api_version` / `effective_from` / `effective_to` | Versioned and time-bounded applicability |
| `verification` | `verified`, `contract_required`, `certification_required`, or `unknown` with evidence |

Operation support is tri-state: `supported`, `unsupported`, or `conditional`. A conditional operation
must name a machine-evaluable condition such as `capture_method=manual`, `state=AUTHORIZED`, or an
approved merchant capability. Free-text notes alone cannot enable an operation.

## Baseline capability matrix

These rows are the safe seed contract as of 2026-07-22. They do not enable production traffic.

| ID | Provider/product | Rail and brand | Channel | Currency | Env | Workflow and operations | Time/version constraints | Initial verification |
|---|---|---|---|---|---|---|---|---|
| `internal.cash.v1` | Internal ledger | Cash; no brand | POS, Kiosk, Workstation | Branch-configured exact currency | Local/live | Immediate sale record and ledger refund; no provider authorize/capture/cancel/retrieve | Tempo contract v1, open-ended | Verified by application tests; still subject to shop/device policy |
| `stripe.pi.card.web.v1` | Stripe PaymentIntents | Card; brands resolved from connected account and payment-method configuration | Customer web/browser with provider-hosted tokenization | Explicit connection/account currency entries only | Test/live separated | Sale; retrieve; refund/retrieve-refund. Authorize/capture/cancel only when `capture_method=manual` and the exact method supports it | Stripe API version pinned per connection; manual authorization window captured from provider response, never assumed beyond it | Certification required per connected account and charge model |
| `stripe.terminal.card_present.v1` | Stripe Terminal PaymentIntents | `card_present`; supported brands depend on country, reader, and account | POS/Kiosk/Workstation reader or Tap to Pay | Explicit country/account/reader currency rows | Test/live separated | Sale; automatic or conditional manual capture; cancel/refund subject to card brand and regional rules | Terminal SDK/API versions and reader certification pinned; authorization expiry from provider evidence | Disabled pending certification; RUNTIME IMPLEMENTED 2026-07-27 (#1088: server-driven reader registry + charge/cancel behind `payments.stripe_terminal.enabled`, settlement via the #1125 async lifecycle; sandbox-verified with a simulated WisePOS E) |
| `paypay.preauth.wallet.v1` | PayPay OPA PreAuth & Capture 1.0 | PayPay wallet/payment method configured for merchant | Approved native/web authorization flow; store/terminal metadata retained | JPY | Sandbox/test/live separated; test maps to provider staging | Authorize, retrieve, capture, revert/cancel, refund, retrieve refund; partial/multiple behavior only if merchant contract explicitly enables it | OPA 1.0; create/capture read timeout at least 30s; cancel only in provider-allowed state/window; snapshot provider `expiresAt`; deprecated hostnames forbidden | Sandbox contract proof only until merchant onboarding and certification |
| `paypay.web_payment.qr.v1` | PayPay OPA Web Payment 2.0 (dynamic QR, `/v2/codes`) | PayPay wallet; brand `paypay` | Customer web/browser only — no store or terminal metadata to snapshot | JPY | Sandbox/test/live separated | **Sale only**: create, retrieve payment, webhook verification. Authorize/capture/cancel/refund are DECLARED UNSUPPORTED — a QR payment is terminal at `COMPLETED` (no separate capture) and no refund path is wired, so declaring them would let the policy engine approve operations the adapter cannot perform | OPA Web Payment 2.0; amount 1–10,000,000 JPY; deprecated hostnames forbidden; `EXPIRED` maps to `Canceled` (dynamic QR expires on its own, unlike preauth) | Verified by application test `application-test:paypay-opa-web-payment`, evidence `artifact:paypay-qr-code-client`, effective 2026-07-29 |
| `sbps.credit.api.specified.v1` | SBPS credit card API, specified-sales flow | Card; contracted brands only | Web/server API using SBPS tokenization | Contracted exact currencies only | Test/live separated | Purchase/authorize, sale/capture, cancel, refund, result lookup; partial sale/refund time-bounded below | SBPS API function IDs pinned. Existing partial sale and partial refund end 2026-09-30; amount-change replacement must be separately modeled and certified | Contract required; disabled until exact merchant IF specification and test evidence are attached |

### Explicit operation details

| Capability | Authorize | Capture | Cancel/revert | Refund | Partial capture | Partial refund | Multiple refunds | Recovery source |
|---|---|---|---|---|---|---|---|---|
| Internal cash | N/A | N/A | N/A | Application ledger reversal | N/A | Yes, bounded by net paid amount | Yes | Cloud/local sync identity and ledger projection |
| Stripe web card | Conditional | Conditional | Conditional on PaymentIntent state/method | Supported after successful charge | Conditional; most methods release the uncaptured remainder | Supported up to remaining refundable amount | Supported up to remaining refundable amount | Retrieve PaymentIntent/refund plus signed webhooks |
| Stripe Terminal | Conditional | Conditional | Brand/region/state-specific | Supported where Terminal/card network allows | Conditional | Conditional | Conditional | Reader result, retrieve API, signed webhooks |
| PayPay preauth | Supported for approved merchant | Supported from `AUTHORIZED` | State/window-specific and asynchronous where documented | Supported from completed payment | Contract-specific; fail closed | Merchant-enablement-specific; fail closed | Merchant-enablement-specific; fail closed | Payment/refund retrieval, transaction events, daily reconciliation file |
| PayPay dynamic QR (web payment) | **Unsupported** — sale is terminal at `COMPLETED` | Unsupported | Unsupported by the adapter; the CODE expires on its own and maps to `Canceled` | **Unsupported** — no refund path wired (D5) | Unsupported | Unsupported | Unsupported | Payment retrieval plus signed webhooks; entitlement row is created by `PayPayCustomerWebBootstrap` at the first checkout, not by `/validate` |
| SBPS specified sales before the conservative 2026-09-30 boundary | Supported | Supported | Supported for eligible state | Supported | Conditional under current partial-sale API | Conditional under current partial-refund API | Contract/IF-specific; fail closed | Result lookup and contracted notification/reconciliation facilities |
| SBPS specified sales at/after the conservative 2026-09-30 boundary | Supported | Supported | Supported for eligible state | Supported | Unsupported unless a newer verified entitlement exists | Unsupported unless a newer verified entitlement exists | Contract/amount-change design required | Result lookup; amount-change replacement requires new verified row |

“Supported” still means that the exact merchant connection has a verified capability snapshot. The
table must never be used as an account entitlement list.

## Provider constraints encoded by the resolver

### Stripe

- PaymentIntent `requires_capture` is the only normalized evidence that capture is currently legal.
- Cancellation is state and payment-method dependent; a `processing` asynchronous method cannot be
  treated like an uncaptured card authorization.
- The authorization expiry/deadline stored on the attempt is authoritative. Stripe documents a
  default seven-day cancellation of uncaptured PaymentIntents, while card authorization validity
  varies; the resolver must not convert that guidance into one global seven-day guarantee.
- Currency support is resolved for the connected account, country, charge model, payment method,
  and settlement configuration. JPY and VND use zero-decimal API amounts; the Money value object
  remains currency-aware.
- Terminal is a distinct certified capability. The web-card adapter cannot imply reader, Tap to Pay,
  `card_present`, or regional cancel support.

### PayPay

- `merchantPaymentId`, `merchantCaptureId`, and `merchantRefundId` are stable operation identities;
  the original identifier is reused to retrieve an ambiguous result, not replaced blindly.
- The connection stores the PayPay merchant identity. Each attempt snapshots `assumeMerchant`,
  `storeId`, and `terminalId` where used.
- Create authorization and capture use at least the documented 30-second read timeout; timeout is
  `reconciliation_required`, not failure. Retrieval/cancel decides the next legal action.
- Cancel is not a synonym for refund. The default cancel window ends at 00:14:59 on the day after
  the transaction, and preauthorization cancel has state restrictions. The provider's current
  response/state is checked before issuing it.
- Sandbox, staging, and production use the current `apigw.*.paypay.ne.jp` hosts. Deprecated
  `api.paypay.ne.jp` hostnames are rejected by configuration validation.
- Currency is JPY for this capability row. Any future PayPay product, rail, or currency requires a
  new verified row rather than widening this one.

### SB Payment Service

- The current API specified-sales functions expose purchase, sale, cancel, refund, result lookup,
  reauthorization, and amount change. Exact availability still depends on the merchant contract and
  the provider-issued interface specification.
- SBPS announces that the existing partial-sale and partial-refund functions are scheduled to end
  on 2026-09-30, but the public page does not specify the exact cutoff instant/timezone. Tempo uses
  `effective_to: 2026-09-30T00:00:00+09:00` as a deliberately conservative deny boundary, not as a
  claim about SBPS's shutdown hour. Provider-confirmed contract evidence may replace it with the
  exact instant in a newer revision. SBPS recommends amount change after service termination, which
  is a different workflow and requires a new capability/test row.
- Tempo never collects or stores PAN/CVV. Browser card data uses the contracted SBPS tokenization
  flow and only provider tokens cross Tempo application boundaries.

## Serialized example

```yaml
id: paypay.preauth.wallet.v1
revision: 1
provider: paypay
integration_product: opa_preauth_capture
api_version: "1.0"
rail: wallet
method_type: paypay
brands: [paypay]
channels: [customer_web]
device_classes: [browser]
currencies:
  - code: JPY
    minor_unit: 0
environment: sandbox
workflows: [authorize_capture]
operations:
  create: supported
  retrieve: supported
  authorize: supported
  capture:
    support: conditional
    when: attempt.provider_state == "AUTHORIZED" && now < attempt.authorization_expires_at
  cancel:
    support: conditional
    when: provider_cancel_window_open == true
  refund: supported
  retrieve_refund: supported
limits:
  partial_capture: unknown
  partial_refund:
    support: conditional
    when: connection.capabilities.partial_refund_enabled == true
  multiple_refunds:
    support: conditional
    when: connection.capabilities.multiple_refunds_enabled == true
recovery:
  poll_payment: true
  poll_refund: true
  webhook_transaction_events: true
  reconciliation_file: daily
merchant_identity: [assume_merchant, store_id, terminal_id]
effective_from: 2026-07-22T00:00:00+09:00
effective_to: null
verification:
  state: certification_required
  evidence: []
```

Production publication rejects a capability whose evidence list is empty. Evidence contains the
contract/configuration reference, provider test account, certification date, test-run artifact,
reviewer, and an expiry/review date; it never contains credentials.

## Validation and test obligations

1. Schema validation rejects overlapping rows that could both match the same exact operation.
2. Every finite `effective_to` has a boundary test at one millisecond before, exactly at, and one
   millisecond after the timestamp.
3. An unknown brand, currency, channel, environment, API version, or operation fails closed and
   makes no provider call.
4. Test and live connection IDs, secrets, attempts, webhooks, and idempotency keys cannot cross.
5. Connection refresh cannot widen a capability without new immutable evidence and a published
   revision.
6. A lower-level shop/device enable cannot override provider, connection, or HQ deny.
7. In-flight attempts keep their snapshot after policy/catalog publication; new attempts use the
   new revision.
8. Timeout tests assert retrieve/reconcile behavior and prohibit a second create with a new
   operation identity.
9. Contract suites cover full and partial amounts, repeated refunds, authorization expiry,
   cancellation-window expiry, unsupported transitions, webhook duplication/reordering, and
   retrieval after process crash.
10. Scenarios B8, C9, D9, H7, and H8 in [TEST-CASES.md](TEST-CASES.md) are release-blocking evidence
    for resolver narrowing, capability expiry, and second-provider isolation.

## Official evidence reviewed on 2026-07-22

- Stripe: [PaymentIntent lifecycle](https://docs.stripe.com/payments/paymentintents/lifecycle),
  [capture API](https://docs.stripe.com/api/payment_intents/capture),
  [authorization holds and manual capture](https://docs.stripe.com/payments/place-a-hold-on-a-payment-method),
  [currencies](https://docs.stripe.com/currencies), and
  [Terminal card collection](https://docs.stripe.com/terminal/payments/collect-card-payment).
- PayPay: [PreAuth and Capture 1.0](https://www.paypay.ne.jp/opa/doc/v1.0/preauth_capture),
  [partial-refund merchant enablement](https://integration.paypay.ne.jp/hc/en-us/articles/4414048518159-Is-partial-refund-possible),
  and [cancel versus refund](https://integration.paypay.ne.jp/hc/en-us/articles/4414061824399-How-can-I-use-Refund-API-and-Cancel-API-properly).
- SBPS: [credit-card service and function matrix](https://developer.sbpayment.jp/en/payment-service/credit/3767/)
  and [EMV 3-D Secure/token integration](https://developer.sbpayment.jp/en/system-specifications/api-type/10283/).

Provider documentation and merchant entitlements can change. Gate 8 must refresh these sources and
attach account-specific evidence; this review date is not a perpetual certification.
