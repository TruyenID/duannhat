---
title: Payment Gateways API
category: reference
tags: [payments, gateways, policy, effective-options, api, plan-047]
summary: Reference for plan-047 gateway administration, shop/device policy configuration, effective-option resolution, and runtime client endpoints.
related: [api-payment-methods, api-orders, order-domain]
---

# Payment Gateways API

Reference for the plan-047 payment gateway module: provider connections, HQ/shop policy, effective-option resolution for clients, and runtime payment commands. Canonical design: [plan-047 DESIGN — API design](../../plans/plan-047/DESIGN.md#api-design).

All admin routes require SSO Sanctum auth. Device/runtime routes use the same compound auth as their transport (`auth.sso_or_device` for POS, device token for kiosk/workstation).

> **Secrets:** No endpoint returns raw provider credentials. Rotation accepts secrets in the request body but responses expose only fingerprints and health metadata.

---

## HQ administration

Base path: `/api/v1/hq/{brandSlug}`

| Method | Path | Purpose | Successor to legacy |
|--------|------|---------|---------------------|
| GET | `/payment-gateways` | List HQ-owned connections and health | — |
| POST | `/payment-gateways` | Initiate provider onboarding (idempotent per merchant identity) | — |
| GET | `/payment-gateways/{connection}` | Connection detail + capabilities | — |
| PATCH | `/payment-gateways/{connection}` | Update non-secret configuration | — |
| POST | `/payment-gateways/{connection}/validate` | Validate connection and refresh capabilities | — |
| POST | `/payment-gateways/{connection}/rotate` | Replace secret reference (never echoes secret) | — |
| GET | `/payment-gateways/{connection}/disconnect-impact` | Shops/devices affected by disconnect | — |
| DELETE | `/payment-gateways/{connection}` | Guarded disconnect | — |
| GET | `/payment-options` | List HQ default/blocked policies per catalog option | Replaces HQ `payment-methods` CRUD for policy |
| PATCH | `/payment-options/{option}` | Set HQ preference (`enabled` / `disabled` / `blocked`) | Replaces HQ `payment-methods` PATCH |
| GET | `/payment-coverage` | Shop readiness and policy coverage matrix | — |

See [plan-047 DESIGN — Admin/HQ](../../plans/plan-047/DESIGN.md#adminhq) for ownership and franchise semantics.

---

## Shop configuration

Base path: `/api/v1/shops/{shopSlug}`

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/payment-configuration` | Ownership model, connection health, mutable flags |
| POST | `/payment-gateways` | Franchise-owned onboarding only |
| PATCH | `/payment-gateways/{connection}` | Franchise connection metadata |
| PATCH | `/payment-options/{option}` | Shop preference or restore inheritance |
| GET | `/devices/{device}/payment-options` | Device policy read |
| PATCH | `/devices/{device}/payment-options` | Device policy (can only narrow shop-effective options) |
| GET | `/effective-payment-options` | Human-client effective list + resolver trace |

Query `?channel=` on effective options (`pos`, `kiosk`, `customer_web`, …) when evaluating channel-specific rails.

HQ-managed shops see HQ connections as read-only in configuration. Franchise shops without a connection get `setup_required: true` and an empty effective set — no HQ merchant fallback.

See [plan-047 DESIGN — Shop/device](../../plans/plan-047/DESIGN.md#shopdevice).

---

## Runtime / device effective options

These endpoints replace legacy `GET …/payment-methods` for checkout UIs.

| Transport | Method | Path | Auth |
|-----------|--------|------|------|
| POS | GET | `/api/v1/pos/effective-payment-options` | Device token + `X-Shop-Slug` |
| Shop (SSO) | GET | `/api/v1/shops/{shopSlug}/effective-payment-options` | Sanctum |
| Kiosk | GET | `/api/v1/kiosk/effective-payment-options` | Device token |
| Workstation | GET | `/api/v1/workstation/effective-payment-options` | Device token |

### Response shape (effective options)

Each call returns a policy snapshot envelope plus resolved options:

| Field | Notes |
|-------|-------|
| `revision` | Monotonic policy revision for stale-guard |
| `snapshot_hash` | Immutable hash of the branch-base projection |
| `ownership_revision` | Console ownership token snapshot |
| `options[]` | Resolved rows sorted for display |

Each option includes (no secrets):

| Field | Notes |
|-------|-------|
| `id` | Stable gateway option UUID |
| `display_name` | Localized label |
| `provider`, `rail`, `method_type` | Catalog identity |
| `effective` | Whether checkout may use this option now |
| `source`, `reason`, `trace` | Resolver explanation |
| `connection_id`, `shop_option_id` | Safe linkage for sync-up |
| `legacy_payment_method_id`, `legacy_payment_method_code` | Compatibility bridge during rollout |
| `client` | Capability flags (`requires_tendered`, `immediate_settlement`, `supports_pos_checkout`, …) |

POS responses are enriched via `PosEffectivePaymentOptionEnricher` (plan-047 T6.1).

Workstation receives the same logical snapshot for SQLite projection (plan-047 T6.3/T6.4).

---

## Runtime payment commands

Existing order payment routes remain the mutation surface; they now resolve through the orchestrator and policy resolver. Structured error codes include:

Two of them are thrown by `PaymentPolicySubmissionValidator` (constants
`CODE_STALE` / `CODE_DISABLED`); the rest are produced by
`PolicyReasonCode::publicErrorCode()` — that `match` is the authoritative list,
so read it there rather than trusting this table:

| Code | Meaning |
|------|---------|
| `PAYMENT_OPTION_DISABLED` | Submitted option is not effective (also the public code for owner-policy / shop / device blocks) |
| `PAYMENT_POLICY_STALE` | Client revision/hash outdated — refresh effective options |
| `PAYMENT_OWNERSHIP_UNRESOLVED` | Cannot resolve who owns the merchant connection |
| `PAYMENT_CONNECTION_REQUIRED` | No usable connection (missing, ambiguous, inactive, pending verification, revoked) |
| `PAYMENT_CONNECTION_UNAVAILABLE` | Connection degraded / unavailable / runtime down |
| `PAYMENT_CONNECTION_RESTRICTED` | Connection exists but is restricted by the provider |
| `PAYMENT_ENVIRONMENT_MISMATCH` | Live/test environment mismatch |
| `PAYMENT_CURRENCY_UNSUPPORTED` | Option does not support the order currency |
| `PAYMENT_CHANNEL_UNSUPPORTED` | Channel or device class not supported by the option |
| `PAYMENT_OPERATION_UNSUPPORTED` | Requested operation not supported by the option |
| `PAYMENT_CAPABILITY_UNAVAILABLE` | Provider/capability inactive, unverified, or expired |

> **`GATEWAY_SETUP_REQUIRED` is NOT a runtime payment error.** It is a *blocker*
> code emitted by `PaymentReadinessOverviewBuilder` for the admin readiness
> panel (it ships with an `href` to the gateway settings page), not by the
> mutation path. Do not branch on it in a checkout client.

> **Ba mã từng liệt kê ở đây CHƯA BAO GIỜ TỒN TẠI** —
> `GATEWAY_ACTION_REQUIRED`, `PAYMENT_RECONCILIATION_REQUIRED`,
> `PAYMENT_ALREADY_PROCESSED`. Không phải bị gỡ: đo 2026-08-07 bằng
> `grep -rn` trên `backend/{app,config,routes,tests}` → **zero hit cả ba**, và
> không có commit nào từng thêm rồi xoá chúng. Chúng nằm cạnh ba mã có thật nên
> đọc rất đáng tin — client nào `switch` trên chúng sẽ rơi vào nhánh chết im
> lặng. Nếu cần ngữ nghĩa "provider next action" (3DS / wallet redirect) thì
> nó **chưa có mã lỗi**; đừng bịa một mã rồi bắt backend theo sau (#2029).

Primary routes (shop-scoped; POS mirrors under `/api/v1/pos/…`):

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/orders/{id}/payments` | Record tender (references effective option or legacy method UUID during rollout) |
| POST | `/orders/{id}/payments/{payment}/confirm` | Confirm pending tender |
| POST | `/orders/{id}/payments/{payment}/fail` | Fail pending tender |
| POST | `/orders/{id}/payments/{payment}/refund` | Refund succeeded tender |

Customer-web Stripe flows use `/api/v1/customer/orders/{id}/…` (PaymentIntent create/confirm, webhooks via inbox). Workstation sync uses `/api/v1/workstation/payments`.

See [Orders API](api-orders.md) for request/response contracts and [plan-047 DESIGN — Runtime commands](../../plans/plan-047/DESIGN.md#runtime-commands).

---

## Related

- [Payment Methods API (legacy compatibility)](api-payment-methods.md) — deprecated list/CRUD; sunset 2027-01-01
- [Orders API](api-orders.md) — payment mutation endpoints
- [Payment topology and tender model](../guide/payment-topology-and-tender-model.md) — shop topologies, internal vs gateway tenders, webhook intake target state (plan-048)
- [plan-047 DESIGN.md](../../plans/plan-047/DESIGN.md) — full endpoint matrix and UI routes
- [plan-048 WEBHOOKS.md](../../plans/plan-048/WEBHOOKS.md) — generic provider webhook intake design and cutover
