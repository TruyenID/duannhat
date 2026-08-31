# Plan 047 — Design

This document defines the proposed payment architecture for [Plan 047](README.md). It is an
implementation design, not approval to modify production code.

## Context and boundaries

The payment domain is an application module inside the existing Laravel modular monolith. Provider
adapters are infrastructure components behind the module boundary. Tempo does not need a separate
payment microservice to obtain provider isolation, reliable retries, or shop-level configuration.

The module owns:

- payment policy resolution;
- payment attempts and provider operations;
- the order-payment ledger writer;
- provider event normalization and reconciliation;
- the fully-paid settlement trigger.

Order, inventory, till, invoice, table, notification, and reporting modules remain consumers of
stable payment/settlement contracts. Godx Console remains authoritative for organization, brand,
branch, membership, and the management/ownership classification needed by the resolver.

## Target architecture

```text
Transport adapters
  Customer API | POS API | Kiosk API | Workstation sync | Provider webhook | Admin API
        |
        v
PaymentPolicyResolver ----> EffectivePaymentOption + policy_revision + explanation
        |
        v
PaymentOrchestrator  <----> PaymentAttempt / PaymentRefund / ProviderEventInbox
        |
        +----> PaymentGatewayRegistry ----> Stripe | PayPay | SBPS adapters
        |                                      |
        |                               external provider call
        |
        +----> OrderPaymentLedgerWriter ----> order_payments
        |
        +----> OrderService.settleIfPaid()
                    |
                    +----> OrderSettlementHandler ----> inventory/table/session/invoice/mail/events/audit
```

Only `PaymentOrchestrator` may invoke `OrderPaymentLedgerWriter`. Adapters return normalized value
objects and have no Eloquent model dependency. Controllers, webhooks, and workstation sync handlers
translate transport input into commands and translate results into API responses.

Plan 047 also closes adjacent mutation bypasses in the same transport migration:

```text
Product transports/imports ------> ProductService -----> Product persistence
Menu transports/jobs/overrides --> MenuService --------> Menu persistence
Customer auth/admin/sync --------> CustomerService ----> Customer persistence
Order transports/sync/jobs ------> OrderService -------> Order persistence
Payment transports/providers ----> PaymentOrchestrator -> Payment persistence
                                                       -> OrderService command
```

These are public mutation facades, not God classes. Typed handlers and repositories remain internal
to each domain. Read-only query services are separate. The complete ownership, allowlist, exception,
and migration rules are defined in [DOMAIN-BOUNDARIES.md](DOMAIN-BOUNDARIES.md).

## Core rules

### R1 — One writer

All create, confirm, capture, fail, expire, cancel, refund, and provider-sync mutations pass through
`PaymentOrchestrator`. `OrderPaymentService` becomes a compatibility facade during migration and is
removed or reduced to query-only behavior after cutover. `StripePaymentService` is split into an
adapter and temporary compatibility facade; neither writes models after cutover.

### R2 — Prepare, call, finalize

External calls use a short transaction on each side of the network boundary:

```text
TX A: lock order -> validate policy/money -> create durable attempt + stable provider key -> commit
CALL: invoke provider with the stored key (no DB transaction held)
TX B: lock attempt/order/payment -> apply normalized result idempotently -> settle if paid -> commit
UNKNOWN: mark reconciliation_required -> scheduled/provider-event recovery queries provider state
```

Provider success followed by a process crash remains recoverable because the attempt and provider
key exist before the call. A retry reuses that identity rather than issuing a second charge.

### R3 — Explicit merchant ownership

`PaymentGatewayConnection` stores its legal/settlement owner and provider account identity. The
resolver also consumes the authoritative shop management model from Console/SSO. It never infers
franchise ownership from `is_headquarters`, UI route, user role, or a nullable branch ID.

Resolution:

```text
HQ-managed branch -> matching active HQ-owned connection assigned/allowed for that branch
franchise branch  -> matching active branch/franchise-owned connection
missing/ambiguous -> SETUP_REQUIRED (never fallback)
```

Stripe charge model (`direct`, `destination`, or `separate`) is stored on the connection/config and
approved as a legal/accounting decision. Device policy never changes the merchant of record.

### R4 — Policy lattice

Each layer produces an allow/deny result plus a reason:

```text
provider capability
  AND connection capability/health/environment
  AND owner/HQ policy
  AND shop preference
  AND device restriction
= effective option
```

Provider unavailable, connection restricted, HQ blocked, shop disabled, device disabled, currency
unsupported, or channel unsupported are distinct reason codes. Shop policy may disable or restore
an inheritable default but cannot enable `blocked`. Device policy is `inherit` or `disabled`; it
cannot widen the shop set.

### R5 — Immutable transaction identity

Every external operation records the connection, option, environment, currency, minor amount,
provider object IDs, provider status, stable idempotency key, and a redacted request/response
summary. Changing a shop connection later never rewrites historical payment identity.

### R6 — Append-only refunds

Refunds are separate operations linked to the captured payment. Under a payment lock:

```text
refundable = captured_amount - sum(succeeded or pending provider refunds)
requested <= refundable
```

The original payment remains captured/succeeded. A derived `refund_status` or net amount may report
`none`, `partial`, or `full`; it is not the source of truth. Each refund has its own stable provider
idempotency key and lifecycle.

### R7 — Idempotent settlement

`OrderService::settleIfPaid(orderId)` is the only public order-settlement command. Its internal
`OrderSettlementHandler` derives net paid value from the ledger under an order lock, owns the
transition, and invokes each required side effect through an idempotent boundary/outbox marker.
Calling it twice is a no-op after the first successful settlement. Payment code never imports or
mutates `CustomerOrder` to maintain `paid_amount`, status, timestamps, table/session, or side effects.

### R8 — One mutation gateway per adjacent aggregate

Product, Menu, Customer and Order each have one public mutation facade. Controllers, importers,
console commands, jobs, listeners and Workstation sync handlers delegate typed commands; they do
not call Eloquent mutations, relationship mutations, raw owned-table writes, or generated CRUD
services. A report-mode allowlist documents legacy debt, shrinks in every touched task, and is empty
for runtime code before Gate 4 closes. CI then runs the architecture guard in strict mode.

### R9 — Snapshot, do not cross-mutate

Order creation reads effective Product/Menu/Customer data through query contracts and stores the
required immutable commercial/tax/identity snapshots. Later Product/Menu/Customer changes do not
rewrite historical orders or payments. Cross-domain effects use public commands or idempotent
outbox events and cannot create cyclic service dependencies.

## Proposed domain model

Names may be refined during schema validation, but boundaries and uniqueness rules are mandatory.

| Entity/table | Purpose | Important constraints |
|---|---|---|
| `payment_gateway_providers` | Provider catalog and adapter key | unique `code`; no credentials |
| `payment_gateway_options` | Provider capability/rail/brand/channel catalog | unique `(provider_id, code)` |
| `payment_gateway_connections` | Merchant account/contract owned by HQ or shop/franchise | unique provider merchant identity per environment; tenant/owner checks |
| `payment_gateway_connection_options` | Capability state reported/configured for one connection | unique `(connection_id, option_id)` |
| `shop_payment_options` | Shop preference and selected permitted connection | unique `(branch_id, option_id)`; connection owner must resolve to shop |
| `device_payment_options` | Device-only restriction | unique `(device_id, shop_payment_option_id)`; only inherit/disabled |
| `payment_policy_revisions` | Monotonic scope revision and published snapshot metadata | unique revision per branch; immutable publication record |
| `payment_attempts` | Durable prepare/call/finalize saga for charge/authorize/capture/cancel | unique `(connection_id, operation, idempotency_key)` and provider object identity |
| `payment_refunds` | Independent refund lifecycle | unique provider refund identity per connection; amount > 0 |
| `payment_provider_events` | Verified webhook inbox/dead-letter record | unique `(connection_id, provider_event_id)`; payload encrypted/redacted or retained by policy |
| `order_payments` | Existing financial ledger | references attempt/connection/option and immutable provider snapshots |

### Omnify ownership

All durable business entities and shared enums are defined first in `schemas/Backend/Payment/` and
`schemas/Shared/Enum/`, then generated with `npm run omnify:gen`. Generated base classes and
migrations are never hand-edited. Schema files require a domain header plus comments for every
property and constraint.

The root `omnify.yaml` deliberately has `service.enable: false`. Plan 047 preserves that decision:
new orchestration services are hand-written under `backend/app/Services/Payment/`; no schema adds
`service: {}` and no deleted generated service layer is restored.

The Workstation keeps manual/local SQLite projection tables for effective options, policy revision,
attempt identity, and sync state. These are not an independent merchant credential store.

### Existing schema compatibility

- `PaymentMethod` remains a compatibility/display tender record during rollout. Its out-of-schema
  `type` field must move into YAML before further generation.
- `PaymentMethod` maps to one effective gateway option or to an internal tender such as cash/debt.
- `OrderPayment` gains nullable gateway/attempt references so historical and cash rows remain valid.
- `PaymentStatus` becomes one Cloud/Workstation vocabulary. Provider raw status remains separate.
- Existing `stripe_payment_intent_id` fields remain readable during backfill, then are deprecated
  only after all references and rollback windows are closed.

## State machines

### Attempt

```text
prepared -> provider_pending -> action_required -> processing -> succeeded
    |              |                  |               |
    +--------------+------------------+---------------+-> failed
    +--------------------------------------------------> canceled
    +--------------------------------------------------> reconciliation_required
reconciliation_required -> provider query/event -> any terminal or active state
```

Transitions are command- and provider-capability-aware. Raw Stripe/PayPay/SBPS states are retained,
but clients consume normalized states and next actions.

### Refund

```text
prepared -> submitted -> pending -> succeeded
    |          |           |
    +----------+-----------+-> failed
    +-----------------------> canceled (only when provider supports it)
    +-----------------------> reconciliation_required
```

### Provider event inbox

```text
received_verified -> queued -> processing -> succeeded
                               |             |
                               +-> retryable +-> dead_letter / operator_resolved
```

Signature/auth verification happens before an event is accepted as verified. Duplicate delivery
returns success without repeating normalized operations. Events may arrive out of order; transition
guards and provider retrieval decide truth.

## Adapter contract

```php
interface PaymentGatewayContract
{
    public function capabilities(GatewayConnectionData $connection): CapabilitySet;
    public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult;
    public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult;
    public function capture(CapturePaymentCommand $command): GatewayPaymentResult;
    public function cancel(CancelPaymentCommand $command): GatewayPaymentResult;
    public function refund(RefundPaymentCommand $command): GatewayRefundResult;
    public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult;
    public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent;
}
```

Unsupported operations return a typed capability error. Adapters receive decrypted credentials only
at the call boundary through a secret resolver. Data transfer objects must not serialize secrets.

## API design

Exact route names follow existing route groups, middleware, and policies.

### Admin/HQ

| Method | Proposed path | Purpose |
|---|---|---|
| GET | `/api/v1/hq/{brand}/payment-gateways` | list HQ-owned connections and health |
| POST | `/api/v1/hq/{brand}/payment-gateways` | initiate provider onboarding/connection |
| GET/PATCH | `/api/v1/hq/{brand}/payment-gateways/{connection}` | inspect or update non-secret configuration |
| POST | `.../{connection}/validate` | validate connection and refresh capabilities |
| POST | `.../{connection}/rotate` | replace secret reference without returning secret |
| DELETE | `.../{connection}` | guarded disconnect with impact assessment |
| GET/PATCH | `/api/v1/hq/{brand}/payment-options` | manage HQ defaults/blocks |
| GET | `/api/v1/hq/{brand}/payment-coverage` | shop readiness and policy coverage |

### Shop/device

| Method | Proposed path | Purpose |
|---|---|---|
| GET | `/api/v1/shops/{shop}/payment-configuration` | ownership, connection health, option source/effective state |
| POST/PATCH | `/api/v1/shops/{shop}/payment-gateways/...` | franchise-owned onboarding/config only |
| PATCH | `/api/v1/shops/{shop}/payment-options/{option}` | set shop preference or restore inheritance |
| GET/PATCH | `/api/v1/shops/{shop}/devices/{device}/payment-options` | read/set stricter device policy |
| GET | `/api/v1/shops/{shop}/effective-payment-options` | authenticated human-client effective list |
| GET | `/api/v1/workstation/effective-payment-options` | device-authenticated snapshot + revision |

Every effective option includes stable ID, display name, provider, rail, brand, channel, connection
identity safe for display, policy revision, effective boolean, source, reason code, next action, and
client capabilities. No secret or raw provider credential field exists.

### Runtime commands

Existing transport routes remain compatible while they are moved behind commands. New responses
use structured codes such as `PAYMENT_OPTION_DISABLED`, `PAYMENT_POLICY_STALE`,
`GATEWAY_SETUP_REQUIRED`, `GATEWAY_ACTION_REQUIRED`, `PAYMENT_RECONCILIATION_REQUIRED`, and
`PAYMENT_ALREADY_PROCESSED`.

## UI design

Payment operations stay in Tempo `admin-web` and reuse `@godxjp/ui`. They are not duplicated in
Godx Console.

### HQ

- `/hq/{brandSlug}/settings/payments` — readiness overview.
- `/hq/{brandSlug}/settings/payments/gateways` — provider connections and health.
- `/hq/{brandSlug}/settings/payments/gateways/{connectionId}` — onboarding, validation, rotation,
  impact-aware disconnect.
- `/hq/{brandSlug}/settings/payments/methods` — provider/rail-grouped default, off, and blocked
  policies with source and effective preview.
- `/hq/{brandSlug}/settings/payments/shops` — searchable shop ownership/readiness coverage.

Multi-section settings use a left local navigation on desktop and compact tabs on mobile. Existing
payment-method URLs may redirect for compatibility.

### Shop

`/shop/{shopSlug}/settings/payments` is a real deep-linkable route with four sections: ownership,
connection, accepted options, and devices.

- HQ-managed shops see the HQ connection and health as read-only.
- Franchise shops can onboard/rotate their own connection. Missing setup shows zero external
  methods and a setup action, never HQ fallback.
- Each option shows provider capability, HQ source, shop preference, effective status, and reason.
- A disabled control explains which upstream layer blocks it.

### Device, POS, and Kiosk

Device detail provides `Use shop defaults` or `Customize for this device`; custom policy can only
disable shop-effective options. Reset restores inheritance. POS and Kiosk remove hard-coded code
allowlists and render only resolver-provided options. An empty effective set blocks checkout with a
manager-facing recovery message.

Loading, data, empty, prerequisite, permission, and transient-error states are mutually exclusive.
All new strings ship in Japanese, English, and Vietnamese. Status never relies on color alone.

## Offline and policy revision design

The Cloud publishes an immutable effective-policy snapshot with monotonic `revision`, `published_at`,
and option/config hashes. Workstation stores only non-secret option data and the revision.

T2.6 publishes the branch/shop-base projection only. Its canonical SHA-256 input contains schema
version, exact tenant/brand/branch scope, the opaque Identity ownership token, a safe configuration
hash, and option rows sorted by option UUID. Each option includes the complete safe effective result:
reason/error, selected connection/owner identifiers, and ordered resolver trace. Correlation IDs,
publication cause, timestamp, allocated revision, and all provider credentials are excluded. The
closed publication-cause enum remains audit metadata; resolver trace is the source semantics covered
by the effective snapshot hash.

The configuration hash lets any device-policy change publish a new branch revision without putting
device identifiers, per-device rows, or secrets in this branch-base snapshot. Device-specific
projection and delivery remain T6.3/T6.4. Publication locks the branch, verifies the latest stored
snapshot on idempotent replay, and appends `latest + 1` only when the latest hash differs. The unique
`(branch_id, revision)` constraint is the final concurrent-writer backstop. Reverting to an older hash
still appends a new monotonic revision. Local primary-key scope is validated by resolving Organization,
then comparing Brand/Branch Console organization and brand identifiers; local and Console UUIDs are not
treated as interchangeable. T2.6 does not add a route, trigger, Workstation feed, or runtime read/write
cutover.

- A new transaction snapshots selected option, connection identity, and revision.
- Cloud validates the submitted option/revision through the resolver.
- A stale revision that would make the operation unsafe returns `PAYMENT_POLICY_STALE` with refresh
  instructions; it never silently changes merchant connection.
- An in-flight provider attempt continues against its immutable connection even if later disabled;
  new attempts use the new policy. The exact offline grace window is an approval gate.
- Sync replay carries the original attempt/idempotency identity and cashier/till attribution.

## Security and compliance

- Provider-hosted onboarding and hosted/tokenized collection are preferred.
- Tempo rejects PAN/CVV-like fields at request boundaries and never persists them.
- Connections store encrypted secret references, not browser-readable credentials.
- Secret resolution is server-only, scoped by tenant/connection/environment, and audited.
- Webhooks verify provider-specific signature/auth using the raw body before queueing.
- Logs, audit entries, traces, error reports, and inbox payloads use provider-specific redaction.
- Test and live credentials/data cannot share one connection or idempotency namespace.
- Cross-organization, cross-franchise, and cross-device access is denied at policy and query layers.
- Final PCI SAQ scope is confirmed with the acquirer/QSA; tokenization reduces but does not eliminate
  compliance obligations.

## Observability and recovery

Metrics and structured logs include provider, connection ID, environment, normalized operation,
attempt state, reason code, latency, retry count, and correlation ID—never secrets or payment-card
data. Required views/alerts:

- attempts stuck in `provider_pending`, `processing`, or `reconciliation_required`;
- captured/succeeded provider objects missing a ledger result;
- ledger versus `paid_amount` drift;
- webhook age, duplicate rate, failure rate, and dead letters;
- refund pending/failed age;
- connection degradation and shop/device policy revision lag;
- settlement side-effect/outbox failures.

Operator actions are idempotent: retry retrieval, retry normalized processing, mark reviewed with a
reason, or initiate a guarded refund. Operators never edit ledger amounts directly.

## Migration and cutover

1. Freeze and characterize all current payment writers/readers and settlement side effects.
2. Add nullable schema and new tables without changing runtime behavior.
3. Report legacy method/provider identity and backfill only immutable Stripe ledger snapshots proven by
   existing row evidence. Never infer environment, ownership, merchant connection, policy, option, or
   attempt identity from global credentials or a PaymentIntent prefix. Exact connection/option references
   require a reviewed per-PaymentIntent manifest targeting already-verified rows.
4. Introduce orchestrator compatibility facades and shadow normalized outcomes in non-live mode.
5. Route one transport at a time through the orchestrator behind a kill switch.
6. Compare ledger totals, `paid_amount`, refunds, till/Z-report, and side-effect markers continuously.
7. Enable effective-policy UI and device snapshots only after resolver correctness is proven.
8. Cut Stripe webhooks and synchronous confirmation to the inbox/orchestrator.
9. Observe zero drift for the approved window and rehearse rollback.
10. Remove direct Stripe writes, obsolete status compatibility, and legacy hard-coded client logic.

Rollback disables new routing and returns to compatibility facades while retaining additive schema
and durable attempts. It must not delete provider identity needed to reconcile money already moved.

## Documentation deliverables

Implementation updates:

- `docs/explanation/payment-domain.md` for concepts and invariants;
- `docs/reference/api-payment-gateways.md` for admin/effective/runtime APIs;
- `docs/reference/api-payment-methods.md` for compatibility/deprecation behavior;
- `docs/explanation/order-domain.md` for canonical settlement/refund semantics;
- `docs/README.md` navigation entries.
