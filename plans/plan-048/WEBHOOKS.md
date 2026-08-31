# Plan 048 — Provider webhook design

Target state for payment provider asynchronous events. Supersedes the single legacy route
`POST /api/v1/customer/stripe/webhook` as the **only** intake path.

## Current state (2026-07-23)

| Provider | HTTP route | Intake service | Inbox | Applicator events |
|---|---|---|---|---|
| **Stripe** | `POST /api/v1/customer/stripe/webhook` | `intakeLegacyStripeWebhook()` | Yes | `payment_intent.succeeded`, `charge.refunded` |
| **PayPay** | *none* | — | Adapter has `verifyWebhook()` | Not wired |
| **Internal ledger** | N/A | N/A | N/A | N/A |

Legacy intake hard-codes `LegacyGlobalStripeConnection` — not HQ/franchise connection rows.

## Target architecture

```text
POST /api/v1/webhooks/payment/{provider}
  (optional: ?connection={uuid} or signed path token)

  → resolve connection (header HMAC / path token / Stripe metadata)
  → PaymentGatewayRegistry::forProvider()
  → adapter.verifyWebhook(VerifyWebhookCommand)
  → PaymentOrchestrator::processProviderEvent()
  → payment_provider_events row (dedupe)
  → ProcessPaymentProviderEventJob
  → ProviderEventApplicator
```

### Connection resolution strategies

| Provider | How to pick `PaymentGatewayConnection` |
|---|---|
| **Stripe Connect** | `account` field on event object → map to connection.merchant_account_id |
| **Stripe legacy (transition)** | Fallback to org-scoped legacy connection until backfill complete |
| **PayPay** | Merchant id in payload → connection.merchant_account_id; or single connection per env in sandbox |

### Backward compatibility

- Keep `POST /api/v1/customer/stripe/webhook` as **alias** → new generic intake with legacy connection resolver during Gate 2–3.
- Deprecation header `Sunset: 2027-06-01` on alias; document in `docs/reference/api-payment-gateways.md`.

## Event coverage matrix (minimum viable)

| Event | Stripe | PayPay | Action |
|---|---|---|---|
| Payment succeeded | `payment_intent.succeeded` | payment authorized/captured (mapped) | `finalize` or legacy bridge |
| Payment failed/canceled | `payment_intent.payment_failed`, `canceled` | revert/cancel states | `reconcile` / fail attempt |
| Refund | `charge.refunded` | refund status (poll + webhook if available) | legacy ledger + `reconcileRefund` |
| Dispute | `charge.dispute.*` | N/A v1 | Log + operator queue (no auto ledger) |

PayPay: CAPABILITIES notes **poll + transaction events** — implement applicator mapping in Gate 6 even if webhook volume is low.

## Security

- Raw body signature verification **before** any DB write except idempotent dedupe ack.
- Webhook secrets from **GatewaySecretStore** per connection — not `STRIPE_WEBHOOK_SECRET` global.
- Dual-read window during rotation (047 SECRET-STORE-RUNBOOK).
- Return **2xx quickly** after inbox persist; never hold DB transaction across provider logic.

## Admin surfacing

HQ connection detail shows:

- Webhook URL to register with provider (copy button).
- Last verified event timestamp + dead-letter count.
- Link to operator recovery (`payments:process-provider-events --dry-run`).

## Acceptance (see TESTS.md)

- D1–D10 parity from Plan 047 re-run against **new route**.
- PayPay fake gateway: signed payload → inbox → applicator idempotency.
- Wrong signature → 400, no inbox row.
- Duplicate event id → 200, one processed outcome.
