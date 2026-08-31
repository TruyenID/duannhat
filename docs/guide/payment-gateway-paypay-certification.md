---
title: PayPay gateway certification evidence
category: guide
tags: [paypay, payment-gateway, certification, plan-047]
summary: "Second-provider proof for PayPay OPA PreAuth and Capture 1.0, recorded as architecture-review evidence rather than a production go-live checklist."
related: [api-payment-gateways]
---

# PayPay gateway certification evidence (plan-047 Gate 8)

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

This document records the second-provider proof for PayPay OPA PreAuth & Capture 1.0.
It is evidence for architecture review — not a production go-live checklist.

## Scope

| Item | Status |
|------|--------|
| Adapter | `App\Services\Payment\Gateway\PayPay\PayPayPaymentGateway` via [`godx-jp/paypayopa-php-sdk`](https://github.com/godx-jp/paypayopa-sdk-php) `^2.1` |
| Sandbox fake | `Tests\Fakes\Payment\PayPayFakePaymentGateway` (contract certification) |
| Shared contract suite | `PayPayPaymentGatewayContractTest` extends `PaymentGatewayContractTestCase` |
| Runtime adapter smoke | `PayPayPaymentGatewayAdapterTest` |
| Provider-specific checks | `PayPayPaymentGatewayCertificationTest` |
| Capability seed | `paypay.preauth.wallet.v1` in `plans/plan-047/CAPABILITIES.md` + `PaymentGatewayCatalogSeeder` |

The official `paypayopa/php-sdk` is **not** used directly: upstream blocks `firebase/php-jwt ^7`.
Tempo consumes the **godx-jp fork** (`^2.1`) which adds php-jwt ^6|^7 and PHP 8.1+ baseline.
See `GODX-FORK.md` in https://github.com/godx-jp/paypayopa-sdk-php.

## T8.2 — Provider identity and recovery encoding

- **Assume merchant:** `CreatePaymentCommand` metadata `merchant_account_reference` must match
  `GatewayConnectionData::merchantAccountReference`. Mismatch → `PAYMENT_GATEWAY_AUTHENTICATION_FAILED`.
- **Store / terminal:** Required on the capability row (`assume_merchant`, `store_id`, `terminal_id`);
  persisted by orchestrator metadata policy outside the fake adapter.
- **Polling / webhook recovery:** `payPayPreauthCapability()` sets `poll_payment`, `poll_refund`, and
  `webhook_transaction_events` to true with a 30s authorization read timeout.
- **Partial refund:** Conditional on `connection_partial_refund_enabled` (PayPay merchant contract).
- **Deprecated hosts:** Webhook bodies referencing `api.paypay.ne.jp` are rejected at verification time.

## Sandbox test assets (2026-07-27)

API sandbox credentials (`PAYPAY_API_KEY` / `PAYPAY_API_SECRET` /
`PAYPAY_MERCHANT_ID`) are configured in `backend/.env` — merchant
`991602796635988897`, client `a_Jf0KxxXIi6`. Connectivity verified 2026-07-27
(QR create + delete `SUCCESS` against `stg-api.paypay.ne.jp`).

PayPay-app sandbox test users (scan test QR codes, complete payments — no real
money):

| # | Phone | Password |
|---|-------------|------------|
| 1 | 09009354551 | FrZmoL0PUF |
| 2 | 07068417027 | 0zMgVghHyl |
| 3 | 07070013608 | 8dDpFF0mdd |

`PAYPAY_WEBHOOK_SECRET` is still unset — sandbox webhook intake accepts
unsigned payloads by design (loud `paypay_webhook_unverified_accept` log);
fill it in once issued to exercise the signed path. Day-to-day test recipes
live in the `paypay-test` skill (`.claude/skills/paypay-test/SKILL.md`).

## T8.3 — Test execution

```sh
cd backend
vendor/bin/pest --compact tests/Unit/Services/Payment/PayPayPaymentGatewayAdapterTest.php
vendor/bin/pest --compact tests/Unit/Services/Payment/PayPayPaymentGatewayContractTest.php
vendor/bin/pest --compact tests/Unit/Services/Payment/PayPayPaymentGatewayCertificationTest.php
```

Both suites must pass in CI before marking Gate 8 complete.

## T8.4 — Residual gaps and follow-up

| Gap | Owner | Follow-up |
|-----|-------|-----------|
| Production PayPay merchant onboarding | HQ ops | Separate onboarding issue after sandbox sign-off |
| Live `apigw.*.paypay.ne.jp` credential rotation | Platform | Use existing gateway secret rotation APIs (Gate 5) |
| Partial refund entitlement proof | Merchant contract | Attach provider test account evidence to capability revision |
| SBPS second provider | Future plan | Out of scope for plan-047 Gate 8 (PayPay selected in ADR) |

## Rollback evidence

Gate 7 kill switches (`PAYMENT_ORCHESTRATOR_TRANSPORT_*`) remain the rollback lever. PayPay fake
adapter changes do not alter orchestrator, ledger writer, or settlement handlers.

## Compliance sign-off

- Architecture: Gate 8 contract + certification tests green; no orchestrator/ledger coupling in fake adapter.
- Security: Webhook verification requires `PayPay-Signature`; deprecated hostname rejected; no secrets in test payloads.
- Operations: Observation report (`payments:observation-report --strict`) unchanged by Gate 8 work.
