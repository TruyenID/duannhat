---
title: Payment gateway architecture proof — adding a provider without touching the core
category: explanation
tags: [payments, gateway, architecture, plan-047, provider, adapter]
summary: Plan-047 Gate 8 evidence (#968 T8.4) — the measurement showing a second payment provider is additive, the exact files a new adapter touches, the one residual provider conditional that survives in the compat layer, and the provider gaps that are deliberately still open.
related: [payments-overview, payment-topology-and-tender-model, money-ledger-architecture]
---

# Payment gateway architecture proof

Plan-047 closes on one claim: **a second payment provider can be added without
modifying core order, ledger or settlement logic.** This file is the measurement
behind that claim, plus an honest list of what it does *not* prove.

It exists because the claim is otherwise unfalsifiable in review. "The
architecture is provider-neutral" is the kind of sentence everyone nods at and
nobody re-checks — until someone adds provider #3, finds an `if ($provider ===
…)` in the ledger writer, and copies it.

## The claim, stated so it can fail

> No file on the money path branches on a provider's name.

The money path is: `PaymentOrchestrator` → `Orchestration/Commands/*` →
`OrderPaymentLedgerWriter` → `OrderService::settleIfPaid`.

## The measurement

Run from the repo root:

```sh
grep -rniE "'(stripe|paypay|sbps)'" \
  backend/app/Services/Payment/Orchestration/PaymentOrchestrator.php \
  backend/app/Services/Payment/Orchestration/Commands \
  backend/app/Services/Payment/Orchestration/Internal/OrderPaymentLedgerWriter.php \
  backend/app/Services/Order \
  --include='*.php' | wc -l
```

Result on `dev` at 2026-08-05: **0**.

| Layer | Provider-name references | Reading |
|---|---|---|
| `PaymentOrchestrator` | 0 | lifecycle commands are provider-neutral |
| `Orchestration/Commands/*` (12 commands) | 0 | prepare/finalize/reconcile/refund carry provider identity as *data* |
| `OrderPaymentLedgerWriter` | 0 | the sole ledger writer cannot tell providers apart |
| `app/Services/Order/*` | 0 | settlement is reached identically from every provider |

⚠️ **Quote the flag exactly.** `--include='*.php'` must be quoted: unquoted, zsh
expands it against the current directory and the grep silently measures the
wrong thing. The first run of this measurement returned a confident `0` that
way, for every path, including ones that were not even scanned.

## What a new provider actually touches

Adding PayPay (T8.1/T8.2) was **additive**. A new adapter touches exactly:

1. `backend/app/Services/Payment/Gateway/Contracts/PaymentGatewayContract.php` —
   implement, do not edit. Eight methods: `capabilities`, `preparePayment`,
   `retrievePayment`, `capture`, `cancel`, `refund`, `retrieveRefund`,
   `verifyWebhook`.
2. `backend/config/payments.php` → `gateway_drivers` — **one line**:

   ```php
   'gateway_drivers' => [
       'paypay' => PayPayPaymentGateway::class,
       'stripe' => StripePaymentGateway::class,
   ],
   ```
3. A per-provider bootstrap + canonical-method provisioner under
   `Orchestration/Internal/` — one file each, added never edited.
4. A capability row in `plans/plan-047/CAPABILITIES.md` and a fixture in
   `PaymentGatewayFixtures`.
5. A contract test extending `tests/Contracts/Payment/PaymentGatewayContractTestCase.php`.

Nothing in that list is a core file. That is the proof.

## The one residual provider conditional

Honesty matters more here than a clean claim:

```php
// OrderPaymentOrchestrationCompat.php:167
public function isStripeCanonicalMethod(PaymentMethod $method): bool
{
    return (string) $method->code === 'stripe';
}
```

This is **not** on the ledger path — it lives in the compat layer that keeps the
legacy customer-web Stripe route working. It disappears with
`LegacyGlobalStripeConnection`, which is tracked in
[#1087](https://github.com/godx-jp/godx-tempo/issues/1087) and cannot be removed
earlier: that class is currently the only customer-web Stripe path, so deleting
it removes live card acceptance.

Anyone adding provider #3 should read this as: *the compat layer is allowed to
know provider names; nothing downstream of it is.*

## Residual provider gaps — deliberately open

| Gap | State | Owner |
|---|---|---|
| **SB Payment Service (SBPS)** adapter | capability matrix written (`plans/plan-047/CAPABILITIES.md`), **no adapter implemented** | [#1796](https://github.com/godx-jp/godx-tempo/issues/1796) |
| **PayPay** certification against the real sandbox | contract + certification *tests* pass; real-credential run not done | [#1119](https://github.com/godx-jp/godx-tempo/issues/1119) |
| **Stripe Terminal** `card_present` runtime | in the capability catalog, runtime fail-closed | [#1088](https://github.com/godx-jp/godx-tempo/issues/1088) |
| Cloud/workstation status vocabulary | **Resolved** — `PaymentStatusCompatibility` deleted (#1822); the poll contract moved to `App\Support\PaymentPollStatus`, the `'confirmed'` alias is gone | [#1120](https://github.com/godx-jp/godx-tempo/issues/1120) · [#1822](https://github.com/godx-jp/godx-tempo/issues/1822) |

**SBPS carries a dated constraint** worth surfacing before anyone plans it:
SBPS's existing partial-sale and partial-refund functions are scheduled to end
**2026-09-30**, with amount-change as the replacement. An adapter designed
against today's partial-refund semantics would need re-modelling almost
immediately. Details and source link in `plans/plan-047/CAPABILITIES.md`.

## What this file does NOT prove

Plan-047's remaining checklist items are **operational**, and no measurement in
this repo can stand in for them:

- staging dry-run and production backfill of the legacy Stripe ledger
  ([#1115](https://github.com/godx-jp/godx-tempo/issues/1115),
  [#1116](https://github.com/godx-jp/godx-tempo/issues/1116));
- a real 24h offline soak on physical workstation hardware
  ([#1117](https://github.com/godx-jp/godx-tempo/issues/1117));
- rollback rehearsal and the 14-day production observation window
  ([#1118](https://github.com/godx-jp/godx-tempo/issues/1118));
- compliance sign-off, which is a human signature and not an artifact this repo
  can generate.

Treat a green test suite here as evidence about *code shape*, not about money
that has moved.
