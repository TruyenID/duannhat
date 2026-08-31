# Rollout SLOs and release gates

These are the approved engineering release criteria for Plan 047. They are deliberately stricter
than steady-state availability targets because a payment migration can appear healthy while moving
money or settlement side effects twice. Finance/legal approval of each merchant model and provider
connection remains a separate hard gate.

No legacy writer may be deleted because a canary “looks good.” Every gate needs a timestamped,
reproducible evidence bundle linked from the release record.

## Scope and measurement unit

Metrics are partitioned by `organization_id`, merchant owner, connection, provider, environment,
currency, transport, operation, and rollout slice. Currency amounts are compared in integer minor
units; values of different currencies are never summed.

The rollout clock excludes a provider-declared sandbox outage only for latency/availability SLOs.
It never excludes ledger drift, duplicate movement, cross-tenant access, lost operation identity,
or an unreconciled live provider success. An exclusion requires an incident link and Payment/SRE
approval; silent dashboard filtering is prohibited.

## Approved SLOs

| Objective | Exact indicator | Release target | Warning / automatic action |
|---|---|---|---|
| Ledger integrity | Count of orders whose stored `paid_amount` differs from the immutable successful-payment minus successful-refund ledger projection, in minor units | **0 mismatched orders and 0 mismatched minor units** on every 5-minute run and daily full scan | One mismatch: stop ramp immediately, page Payment on-call, preserve evidence, reconcile before resume. Any duplicate/excess money movement triggers rollback |
| Provider settlement parity | Per connection/currency/provider business day: provider settled transaction IDs and gross/refund/fee/net amounts versus imported settlement projection and Tempo ledger | **100% ID coverage and exact gross/refund/net parity** after the provider file/API availability deadline; fees match the configured fee model | Missing/extra provider ID or amount mismatch: freeze that connection; unresolved past one reconciliation cycle blocks release |
| Canonical settlement parity | Fully paid order has exactly one settlement marker and the expected inventory, table/session, invoice, mail, broadcast, receipt/tax, till and audit outcomes for its transport | **100% marker cardinality and outcome parity**; no duplicate irreversible side effect | Any missing/duplicate marker or irreversible side effect: stop affected slice; duplicate side effect or stock/accounting impact triggers rollback |
| Webhook intake durability | Verified webhook persisted/deduplicated before acknowledgement; receipt-to-success/dead-letter latency | **99.9% ≤ 60 s, 99.99% ≤ 5 min; 0 verified events lost; oldest unprocessed ≤ 5 min** | Oldest > 2 min warns; > 5 min or any loss pauses ramp. Backlog > 15 min triggers rollback unless provider polling proves all monetary states and incident commander explicitly holds |
| Ambiguous-attempt reconciliation | Time from `reconciliation_required`/due time to provider-proven normalized state | **99% ≤ 5 min, 99.9% ≤ 15 min; 0 live monetary attempts older than 30 min without operator ownership** | Any captured/succeeded-but-unledgered attempt > 2 min pages immediately; > 5 min rolls back the affected slice. Generic unknown > 15 min pauses ramp |
| Refund reconciliation | Due pending/unknown refund age until provider-proven terminal or explicit operator ownership | **99% ≤ 15 min, 99.9% ≤ 60 min; 0 due refunds unowned after 60 min** | Provider-accepted refund without ledger finalization > 15 min warns; > 60 min pauses connection and pages Payment/Finance |
| Rollback readiness | Incident declaration to new-route disable, then to proven stable compatibility routing | **new starts stopped ≤ 5 min; routing stable ≤ 15 min; 100% in-flight identities retained** | Rehearsal exceeding either target blocks ramp. Rollback that loses provider/attempt/inbox data is a release failure |
| Policy convergence | Connected device has current published effective-policy revision or is explicitly stale-blocked | **99% ≤ 2 min, 99.9% ≤ 5 min; 0 stale external payment starts beyond policy rule** | Any external payment begun after a deny/expiry with stale policy triggers affected-device disable and rollout pause |

The percentile targets apply only when the denominator has at least 1,000 observations. Below that,
every observation is inspected and the stage stays open until the volume/time gate below is met.
Zero-tolerance indicators have no error budget.

### Error budgets

- Ledger drift, duplicate provider mutation, cross-tenant/environment access, lost verified webhook,
  lost idempotency identity, and duplicate irreversible settlement effects: budget **zero**.
- Webhook receipt-to-processing over 60 seconds: at most 0.1%; over 5 minutes: at most 0.01%.
- Ambiguous attempts over 5 minutes: at most 1%; over 15 minutes: at most 0.1%.
- Refunds over 15 minutes: at most 1%; over 60 minutes: at most 0.1%.
- Burning 25% of a non-zero stage budget within one hour pauses the ramp. Burning 50% at any point
  rolls back the affected slice. A paused stage restarts its clean observation clock after repair.

Provider-declared long-running asynchronous states are measured from their stored `next_check_at` or
provider deadline, not from initial creation. They must still have a live owner/deadline and are never
allowed to hide captured-but-unledgered money.

## Observation window and ramp

Each slice means one provider + connection cohort + transport, selected deterministically so the
same attempt cannot switch paths during retry. Promotion is manual and requires all preceding gates.

| Stage | Routing | Minimum hold and sample | Promotion evidence |
|---|---:|---|---|
| Shadow | 0% money movement; compare normalized decisions only | 7 consecutive days and 1,000 representative attempts/refunds, including all supported rails/transports | No decision/amount/identity diff; failure and timeout fixtures exercised |
| Internal/certification | Test/sandbox plus staff-controlled live transactions | Complete provider contract, crash, webhook replay, refund, settlement and rollback suites | Signed provider/account capability evidence and exact reconciliation report |
| Canary | 1% of eligible live starts, max one approved connection initially | 24 clean hours and at least 200 external attempts | All SLOs green; daily settlement matched; support/finance review complete |
| Ramp 1 | 5% | 24 clean hours and at least 500 attempts | Same evidence plus one successful reconciliation cycle |
| Ramp 2 | 25% | 48 clean hours and at least 1,000 attempts | Same evidence; no open Sev-1/Sev-2 payment incident |
| Ramp 3 | 50% | 72 clean hours and at least 2,000 attempts | Same evidence; rollback rehearsal still within target |
| Full routing | 100% for the slice | **14 consecutive clean days**, at least 1,000 external attempts, and at least 7 provider settlement/reconciliation cycles | Exact ledger, settlement and side-effect parity; alert history and incident exclusions reviewed |
| Legacy deletion | 100% across all production slices | **30 consecutive clean days** after the last full-routing slice and at least one month-end/finance close where available | Restore/rollback limits approved; no unresolved drift; deprecation evidence complete |

If normal volume does not reach the sample threshold, observation extends to 30 days; it does not
lower the sample requirement without a written Payment, SRE, and Finance risk acceptance. A provider
or transport with materially different behavior receives its own clock.

## Gate 0: instrumentation and pre-cutover evidence

Before any live route flag is enabled:

1. Dashboards and alerts are exercised with synthetic stuck attempt, dead letter, failed refund,
   ledger drift, policy lag, and settlement mismatch fixtures from scenario H5.
2. Every metric label is bounded and contains no customer data, secret, token, provider payload, PAN,
   or CVV. Attempt IDs belong in correlated logs/traces, not metric labels.
3. Full and incremental ledger projections return the same checksum on production-like data.
4. Provider settlement ingestion is restartable and reports missing, extra, duplicate, and amount-
   mismatched IDs without modifying ledger amounts.
5. Kill switches exist per provider, connection cohort, and transport; the default is legacy/off.
6. New and compatibility paths share a durable attempt/idempotency claim so route changes cannot
   double-create a provider operation.
7. The rollback rehearsal passes scenario H10 at each crash boundary: after provider success,
   webhook backlog, policy publication, and offline queue creation.

## Indicator definitions

The final schema may alter physical names, but it must preserve these logical queries and indexes.

### Ledger drift

```text
ledger_net(order, currency) =
  SUM(successful payment amount_minor)
  - SUM(successful refund amount_minor)

drift = orders where
  order.paid_amount_minor != ledger_net(order, order.currency)
  OR a contributing row has a different currency/environment/tenant
```

The incremental check runs every five minutes over changed orders and active attempts. The complete
scan runs daily, before and after every ramp, and at finance close. Both emit row count, absolute
minor-unit delta, stable input watermark, and checksum. A tolerance or floating-point comparison is
forbidden.

### Provider and settlement parity

```text
provider_ids - tempo_terminal_provider_ids = missing_in_tempo
tempo_terminal_provider_ids - provider_ids = missing_at_provider

for each connection + currency + provider_business_date:
  compare count, gross_minor, refund_minor, fee_minor, net_minor
```

Pending authorizations are separate from settled captures. Provider files/APIs are compared only
after their documented availability deadline, but missing live provider successes remain visible in
the real-time attempt dashboard. Fee differences do not mutate the order payment ledger.

Canonical side-effect parity compares expected marker keys rather than counting transient job
executions. Idempotent retries may execute, but only one irreversible result may exist.

### Webhook and reconciliation age

```text
webhook_age = processed_at - received_at
oldest_unprocessed = now - received_at WHERE inbox_state IN (received_verified, queued, processing, retryable)
reconciliation_age = terminal_or_owned_at - GREATEST(reconciliation_due_at, provider_next_check_at)
```

Signature rejection is tracked separately and cannot enter the verified denominator. Duplicate and
out-of-order verified events remain visible counters, while deduplication prevents repeated effects.
Dead-lettering ends processing latency but fails the business SLO until an operator-owned resolution
with reason and next action exists.

## Automatic stop and rollback policy

Immediately stop promotion on any SLO breach, provider capability expiry, unresolved ownership,
secret/tenant/environment anomaly, or Sev-1/Sev-2 payment incident. Trigger rollback for:

- any duplicate/excess provider money movement;
- ledger drift that is not a proven read-model delay resolved within one incremental run;
- captured/succeeded-but-unledgered age above five minutes;
- webhook backlog above fifteen minutes without complete retrieval coverage;
- a route/idempotency defect that could create twice; or
- an authorization/security boundary violation.

Rollback is forward-safe:

1. Freeze new starts for the affected scope and record the flag revision/incident timestamp.
2. Leave durable attempts, refunds, provider events, operation keys, policy revisions, and additive
   schema intact.
3. Allow the orchestrator/reconciler to finish already-claimed operations; do not send them through
   a legacy create path.
4. Route only new, unclaimed operations to the proven compatibility facade.
5. Reconcile provider state, ledger, settlement markers, refunds, tills and offline queues.
6. Resume only after root cause, repair, regression test, complete scan, finance check, and a fresh
   clean observation clock.

Rollback never deletes or rewrites financial evidence and never marks an unknown provider outcome
failed merely to clear an alert.

## Ownership and required evidence

| Role | Accountable evidence |
|---|---|
| Payment engineering | Contract/parity test runs, route and adapter version, capability revision, attempt and ledger checksums |
| SRE/on-call | Dashboards, alert exercises, capacity/backlog test, kill-switch and ≤5/≤15 minute rollback rehearsal |
| Finance/operations | Provider settlement match, merchant/connection/charge-model approval, refund and close review |
| Security/compliance | Tenant/environment isolation, secret/redaction evidence, provider onboarding and PCI/QSA action status |
| Product/support | Shop/device availability behavior, operator runbook, customer/support communication and recovery paths |
| Release owner | Scope, sample counts, clean-window timestamps, exclusions/incidents, sign-offs, promote/hold decision |

The release artifact contains immutable links or checksums for test results, schema/backfill version,
dashboard snapshots/queries, alert history, settlement files or provider API watermark, drift report,
rollback timeline, open incidents, and each sign-off. Credentials and raw sensitive provider payloads
are never attached.

## Release decision

Promotion is allowed only when every applicable target is green for the whole stage, all required
evidence is present, and no hard gate in [README.md](README.md) remains relevant to the enabled
scope. “No alerts fired” is insufficient if the alert exercise or metric denominator is missing.

These SLOs satisfy T0.8 as the engineering contract. Gate 7 must implement the actual metrics,
queries, dashboards, alerts, reconciliation reports, and runbook, then demonstrate them before live
traffic or legacy deletion.
