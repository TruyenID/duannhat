# ADR — Unified payment ownership and orchestration boundaries

- **Status:** Accepted for implementation constraints
- **Date:** 2026-07-22
- **Scope:** Plan 047, Gate 0
- **Blocked dependency:** **Platform** (`dxs-platform/platform`) owns the branch-management model
  and already carries both sides of it (`brand → organization` = brand owner, `branch →
  organization` = operator). What is missing is the franchise-grant lifecycle (`status`,
  `validFrom`/`validTo`) and a read endpoint returning the resolved model plus a monotonic
  `ownership_revision`. Measured 2026-08-06 — verification commands in `plans/plan-047/NOTES.md`
  § T0.1/T0.2. An earlier revision of this line named a now-dormant system; that pointer went stale
  unnoticed and cost a later session a day, which is why the note below carries HOW TO CHECK rather
  than only a name.

## Context

Tempo currently has one payment ledger but more than one application path capable of moving a
payment or settling an order. Stripe credentials are process-global, the merchant owner is not
represented per shop, provider calls can occur inside database transactions, and offline clients
do not have a versioned effective-policy contract. Adding more providers to those paths would
multiply reconciliation, authorization, and accounting risk.

This ADR fixes the boundaries that all later implementation tasks must obey. It deliberately does
not invent the missing Identity ownership projection or decide a merchant-of-record question on
behalf of finance/legal.

## Decision 1 — Merchant of record is explicit and immutable per attempt

Every gateway connection records an explicit legal owner, settlement owner, environment, provider
merchant identity, and approved charge model. Every payment attempt snapshots those values before a
provider call. Shop type is an input to authorization and connection resolution; it is never enough
on its own to infer the merchant of record.

| Resolved management model | Eligible connection owner | Allowed behavior | Failure behavior |
|---|---|---|---|
| `hq_managed` | The owning HQ organization unit | Use an active HQ connection whose legal and settlement model was explicitly approved | No eligible connection means `PAYMENT_CONNECTION_REQUIRED`; never search a franchise/shop connection |
| `franchise` | The active franchise grantee/shop organization unit | Use that grantee's active connection and explicit charge model | No eligible connection means `PAYMENT_CONNECTION_REQUIRED`; never fall back to HQ |
| `unresolved` | None | No externally funded attempt may start | `PAYMENT_OWNERSHIP_UNRESOLVED` with no provider call |

The following are release gates, not runtime inference rules:

1. Finance/legal must record which entity is merchant of record for each connection.
2. Finance/legal must approve the Stripe Connect charge model for that entity.
3. The connection must store the approved result (`direct`, `destination`, or
   `separate_charges_and_transfers`) explicitly and audit every change.

Operational defaults may be proposed during onboarding, but code must not silently assign them.
For example, a franchise-owned connected account commonly maps to a direct charge, while a
platform-owned merchant relationship may require a destination or separate charge. Those examples
are not universal legal rules and therefore are not hard-coded.

## Decision 2 — Gateway hierarchy separates contract, rail, and policy

The model is:

```text
Provider
  -> Connection (merchant contract, owner, environment, secret reference)
      -> Connection option (rail/brand/channel/currency/capability)
          -> HQ/shop preference
              -> device restriction
                  -> immutable attempt snapshot
```

Effective availability is the intersection of all upstream capabilities and policies. A downstream
shop or device can disable an allowed option but cannot enable an option denied by provider,
connection, HQ, environment, currency, time window, or management ownership. The server resolves
and enforces the result; clients only render the explanation and submit the selected option ID.

## Decision 3 — Secrets are opaque, versioned references

Domain records store only an opaque `secret_ref` and a non-secret key version/fingerprint. Provider
credentials, webhook signing secrets, PAN, and CVV are forbidden in gateway/catalog/policy records,
API resources, queues, telemetry, exception context, and offline snapshots.

`GatewaySecretStore` is the only application boundary allowed to resolve a secret. Its contract
must support tenant/environment authorization, versioned rotation, audit metadata, revocation, and
dual-read during a bounded webhook-secret rotation window. DTOs and adapter results cannot expose a
resolved secret.

The production backend currently deploys on XServer and has no verified KMS/Vault/Secrets Manager
integration. On 2026-07-22 the product owner approved the dedicated server-only encrypted-at-rest
implementation for this deployment shape:

- encrypted secret versions are stored in dedicated non-API database tables;
- XChaCha20-Poly1305 binds tenant, connection, provider, environment, purpose, version, reference,
  and key ID as authenticated associated data;
- master keys are loaded from an owner-only keyring file owned by the dedicated PHP service account
  and located outside the repository and web root, never
  from `APP_KEY`, Laravel config cache, or the database;
- rotation/revocation is transactionally coupled to the connection's opaque active reference and
  an append-only, database-protected audit record;
- API credentials cut over immediately; webhook credentials may dual-read only until an explicit,
  bounded overlap deadline.

An external managed store remains a compatible future implementation of the same contract. The
operational procedure and recovery constraints are recorded in `SECRET-STORE-RUNBOOK.md`.

Plaintext database columns, browser-submitted reusable credentials, and a single process-global
provider key are rejected. The selected implementation and recovery/key-rotation runbook must be
followed before real connection credentials are migrated.

## Decision 4 — Provider calls never execute inside a business transaction

Money-moving commands use a three-stage boundary:

1. **Prepare transaction:** authorize tenant/actor, resolve and snapshot policy, lock the relevant
   order/ledger rows briefly, enforce idempotency and amount limits, persist an attempt in a pending
   state, then commit.
2. **Provider call:** resolve the secret at the adapter boundary and invoke the provider using the
   persisted provider request identity/idempotency key, with no database transaction or row lock
   held.
3. **Finalize transaction:** lock by attempt identity, apply a legal normalized transition, append
   ledger/refund effects exactly once, invoke the idempotent settlement boundary when fully paid,
   persist an outbox/reconciliation marker, then commit.

Timeout, transport failure, process crash, and ambiguous provider result move the attempt to a
reconcilable state. They never create a new provider identity. Recovery retrieves provider state
using the original connection and operation identity before deciding whether a retry is safe.

## Decision 5 — Policy changes never rewrite an in-flight transaction

An attempt becomes in-flight when the prepare transaction commits. From that point its connection,
owner, environment, option, amount, currency, provider request identity, and policy revision are
immutable.

| Situation | Rule |
|---|---|
| Device is online before prepare | Server must resolve the current effective revision; stale or disabled selection is rejected |
| Device is offline and no attempt was prepared in Cloud | A queued external payment cannot claim success locally; on reconnect it must prepare against the current revision or return a structured policy error |
| Attempt was prepared before an option was disabled | Confirm/retrieve/reconcile may finish only that same provider operation; the system cannot create a replacement charge under the stale option |
| Provider succeeded but policy changed before finalize | Finalize and ledger the known provider result; policy disable is not a reason to lose money already moved |
| Cash or another provider-free tender is allowed offline | It follows a separately versioned offline capability and local idempotency contract; it is not implicitly exempt |

There is no time-based grace period for starting a new externally funded operation from a stale
snapshot. Product may later approve a bounded offline authorization feature for a provider that
supports it, but it must be an explicit dated capability with risk limits rather than a generic
fallback.

## Decision 6 — PayPay is the second-provider architecture proof

PayPay is selected for Gate 8's fake/sandbox contract proof because the public integration material
covers merchant payment identity, merchant/store/terminal context, authorization/capture/cancel,
refund, status retrieval, and webhook limitations. The proof must use the shared provider contract
without changing the orchestrator, ledger writer, `OrderService`, or policy resolver.

SBPS remains a future adapter candidate. Its capability entries must be dated/versioned because its
published credit-card partial-settlement/partial-refund behavior changes on 2026-09-30, and complete
specifications may require a merchant contract. Neither provider is enabled in production by this
architecture proof.

## Decision 7 — Adjacent aggregate mutations move once with Plan 047

Plan 047 adopts one public mutation gateway for Payment, Order, Product, Menu, and Customer. This is
an explicit scope amendment accepted on 2026-07-22: when a payment migration touches a controller,
importer, job, command, webhook, listener or sync handler, any direct mutation of those aggregates
in that path moves behind the canonical service in the same task. All already-known runtime
bypasses are removed before Gate 4 closes.

The public gateways are `PaymentOrchestrator`, `OrderService`, `ProductService`, `MenuService`, and
`CustomerService`. They may delegate to internal handlers/repositories and separate read services;
the decision does not require God classes. Payment owns payment persistence only and calls
`OrderService::settleIfPaid()` after ledger finalization. Order reads Product/Menu/Customer through
query contracts and snapshots required values; it cannot mutate those aggregates.

An architecture guard inventories existing violations in report mode, forbids new violations, and
becomes strict per aggregate as its allowlist reaches zero. Migrations, factories, bootstrap seeders,
and audited/restartable maintenance commands are the only reviewed permanent exceptions. Full rules
and current bypass inventory are in [DOMAIN-BOUNDARIES.md](DOMAIN-BOUNDARIES.md).

## Rejected alternatives

- **One global Stripe key:** cannot model owner, environment, rotation, or Connect account isolation.
- **Infer franchise/HQ from local branch flags:** creates a second ownership truth and mishandles
  suspended, expired, ambiguous, or cross-tenant grants.
- **Fallback from franchise to HQ:** silently changes merchant, settlement, refunds, and dispute liability.
- **Store capabilities on `PaymentMethod`:** conflates provider contract, merchant connection, rail,
  and mutable operational policy.
- **Call provider under a row lock:** creates long transactions without making the remote side atomic.
- **Treat webhook order as truth:** providers duplicate, delay, and reorder events.
- **Let devices enforce toggles alone:** direct API callers and stale clients can bypass it.
- **Choose SBPS as the first proof without contracted specifications:** makes the shared contract
  depend on unavailable or changing provider behavior.
- **Leave adjacent direct writers for a later refactor:** forces each transport through two risky
  migrations and permits Payment/Order/catalog/customer invariants to diverge during cutover.
- **One giant service class per domain:** hides responsibilities and creates merge hotspots; one
  public mutation contract can still delegate to cohesive internal command handlers.

## Consequences and enforcement

- Gate 1 ownership schema work stays blocked until Identity issue #67 supplies the canonical
  projection and identifiers.
- Real credential migration stays blocked until the secret-store choice and runbook are approved.
- Real Stripe Connect traffic stays blocked until each connection's merchant-of-record and charge
  model is approved and stored explicitly.
- Architecture tests must prevent direct Payment/Order/Product/Menu/Customer mutations outside the
  approved persistence boundaries and must instrument that provider calls occur outside database
  transactions.
- Every externally visible failure uses a stable typed code and correlation ID; messages may be
  localized without changing recovery behavior.
- Rollout requires zero ledger drift and settlement parity before deleting a legacy writer.

This ADR may be superseded only by another recorded decision that preserves historical attempts and
provides a migration and reconciliation plan.
