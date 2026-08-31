# Plan 048 — Rollout and cutover

Inherits SLOs from [Plan 047 ROLLOUT](../plan-047/ROLLOUT.md). Plan 048 adds **transport ramp order**
and **shop topology** gates.

## Kill switches (reminder)

| Env var | Effect |
|---|---|
| `PAYMENT_ORCHESTRATOR_RUNTIME=false` | Master OFF — all transports legacy |
| `PAYMENT_ORCHESTRATOR_TRANSPORT_POS=false` | POS cloud payments legacy ledger path |
| `PAYMENT_ORCHESTRATOR_TRANSPORT_CUSTOMER_WEB=false` | Stripe prepare/finalize OFF |
| `PAYMENT_ORCHESTRATOR_TRANSPORT_KIOSK=false` | Kiosk orchestrator OFF |
| `PAYMENT_ORCHESTRATOR_TRANSPORT_WORKSTATION=false` | WS sync orchestrator OFF |

Rollback = flip affected switch → verify `payment_orchestration` log `orchestrator_legacy_path` →
run `payments:observation-report --strict`.

### Kill-switch runbook (per transport)

Symptom → switch mapping. Always flip the **narrowest** switch that stops the bleeding; the master
switch is a last resort because it reverts every transport at once.

| Symptom | Switch to flip OFF | Blast radius when OFF |
|---|---|---|
| POS cash/card_terminal ledger drift or till mismatch | `PAYMENT_ORCHESTRATOR_TRANSPORT_POS` | POS cloud payments write via legacy `OrderPaymentService` path only |
| Stripe intent/confirm errors, customer-web checkout failures | `PAYMENT_ORCHESTRATOR_TRANSPORT_CUSTOMER_WEB` | Stripe prepare/finalize falls back to legacy `StripePaymentService` flow |
| Kiosk payment create/poll anomalies | `PAYMENT_ORCHESTRATOR_TRANSPORT_KIOSK` | Kiosk payments legacy path |
| Workstation sync-UP duplicate/missing payments | `PAYMENT_ORCHESTRATOR_TRANSPORT_WORKSTATION` | WS sync payments legacy path; offline queue unaffected |
| Cross-transport corruption, unknown origin | `PAYMENT_ORCHESTRATOR_RUNTIME` (master) | Everything legacy; orchestrator tables stop advancing |

Procedure (any switch):

1. Set the env var to `false` on the affected environment; `php artisan config:clear` (or redeploy —
   config is cached in production).
2. Confirm fallback is active: `payment_orchestration` log channel emits `orchestrator_legacy_path`
   entries for the affected transport within one payment.
3. Quantify damage window: `php artisan payments:observation-report --strict` — attach JSON.
4. In-flight attempts: orchestrator attempts already `prepared` will be picked up by the
   reconciliation job (`payments:process-provider-events`); do **not** hand-edit `payment_attempts`.
5. Webhooks keep flowing regardless of switches (inbox intake is switch-independent by design) —
   verified events queue in `payment_provider_events` and apply when safe.
6. Re-enable: flip back → watch the same log channel for orchestrator-path entries → re-run the
   observation report after the first 10 live payments.

Escalation: if drift persists **with the switch off**, the fault is in the legacy path or data —
stop the ramp, open an incident issue, and run the plan-047 T7.5 rollback rehearsal script against
staging to reproduce.

## Ramp sequence (production)

```text
Stage A — Cloud-only POS internal tender (Gate 1)
  Audience: orgs with VITE_WORKSTATION_API_URL=none
  Enable: TRANSPORT_POS only
  Duration: 7 days clean observation
  Rollback trigger: any ledger drift, till mismatch

Stage B — Customer-web Stripe (Gate 2)
  Audience: orgs with HQ/franchise Stripe connection configured
  Enable: TRANSPORT_CUSTOMER_WEB
  Prerequisite: Stage A green OR org has zero POS orchestrator traffic
  Duration: 7 days; monitor webhook inbox age + confirm-payment 422 rate

Stage C — Webhook route migration (Gate 3)
  Register new URL in Stripe Dashboard (parallel with alias)
  Switch PayPay merchant portal to /webhooks/payment/paypay
  Deprecate alias after 30 days zero traffic on old URL

Stage D — Kiosk (if used)
  Enable: TRANSPORT_KIOSK

Stage E — Workstation shops only
  Enable: TRANSPORT_WORKSTATION per shop flag
  Never enable for cloud-only orgs (no effect, but avoid confusion)

Stage F — PayPay wallet (Gate 6, optional)
  Feature flag per brand; sandbox → one live pilot shop
```

## Staging checklist (before each stage)

```sh
cd backend
php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/Payment/
php artisan payments:observation-report --strict
php artisan payments:process-provider-events --dry-run
```

Attach report JSON to release record.

## Shop topology decision tree

```text
Shop has workstation LAN configured?
  NO  → Stage A + B only (POS Cloud + customer-web)
  YES → Add Stage E after A/B; offline soak mandatory

Shop uses PayPay?
  NO  → Skip Gate 6
  YES → Gate 6 after B + C

Shop uses 釣銭機?
  NO  → Skip Gate 8
  YES → Gate 8 parallel track (does not block A–E)
```

## Takeaway-specific monitoring

| Metric | Alert |
|---|---|
| Counter orders stuck `pending` without staff confirm | Admin queue age > SLA |
| Stripe takeaway confirm 422 / overpayment | Spike vs baseline |
| `awaiting_confirmation` abandoned (plan-037) | Cron cancel rate |
| Order closed without payment (counter) | Should be **zero** — POS must collect |

## Evidence bundle (per stage)

1. `payments:observation-report` JSON (drift = 0)
2. Sample Z-report + ledger export for pilot shop
3. Webhook inbox: oldest unprocessed age screenshot
4. Rollback drill timestamp (kill switch → legacy path → restore)

## Communication

- **Before Stage A:** notify pilot shop — cash/card_terminal unchanged UX; backend path changes.
- **Before Stage B:** Stripe online takeaway — confirm webhook URL updated in Dashboard.
- **Before Stage E:** WS shops — brief offline window acceptable? schedule low-traffic window.
