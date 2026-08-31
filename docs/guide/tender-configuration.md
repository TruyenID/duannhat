---
title: Tender configuration
category: guide
tags: [tender, till, peripheral, issue-1156]
summary: "The vendor-neutral tender model — org-wide vocabulary, per-terminal accepts lists with vendor templates, per-branch activation overrides, and tender_key attribution on payments."
related: [device-and-payment-management]
---

# Tender configuration — vocabulary, per-device accepts, per-branch activation (#1156)

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

> Canonical reference for the vendor-neutral tender model introduced by
> issue **#1156**: the org-wide tender **vocabulary** (`till_tender_types`),
> the per-terminal **accepts** list
> (`peripheral_devices.metadata.accepts` + vendor templates), per-branch
> **activation** overrides (`TenderTypeResolver`), and brand-level
> attribution on payments (`order_payments.tender_key`) that the
> capability-driven 精算 manifest consumes. Backend slice; the POS
> sub-choice UI and per-terminal 精算 sections build on these primitives.

Related: [payment-topology-and-tender-model.md](payment-topology-and-tender-model.md)
(internal vs gateway tenders, plan-048),
[cashier-shift-recovery.md](cashier-shift-recovery.md) (shift state machine),
[manager-till-tracking.md](manager-till-tracking.md) (plan-036 surface).

---

## Tender vs payment method — two different axes

A **payment method** (`payment_methods`) is what the POS *charges through*:
cash, card terminal, Stripe, PayPay-native, on-account… It selects a money
path (auto-confirm vs pending, gateway vs internal) and is what plan-047's
`PaymentPolicyResolver` gates per device.

A **tender** (`till_tender_types.tender_key`) is what the cashier
*reconciles at 精算*: the brand-level line on the terminal's 日計 slip —
`credit`, `paypay`, `rakuten_pay`, `id`, `ic`… One payment method
(`card_terminal`, the generic 決済端末 button) fans out into many tenders,
because the physical terminal accepts card + QR wallets + e-money on one
device while the drawer close must compare per-brand expected vs the
per-brand totals the terminal prints.

`tender_key` is **attribution, never money**: it does not change any amount,
any rounding, or any pricing (that boundary is `OrderPricingCalculator`).
Online settlement (plan-050, #1155) has no close-time step either — this
model only feeds the *recorded-vs-slip* comparison at till close.

## The 3-layer model

```
1. VOCABULARY  (org-wide)     till_tender_types, branch_id NULL
                              seeded by TillTenderTypeSeeder — the brand
                              superset of the org's OPERATING COUNTRY
                              (config/tender_vocabulary.php), vendor-neutral
2. ACCEPTS     (per-device)   peripheral_devices(payment_terminal).metadata.accepts
                              = the subset THIS terminal takes under THIS
                              shop's acquirer contract; metadata.model only
                              PREFILLS it from a vendor template, editable
3. EFFECTIVE   (per-branch)   TenderTypeResolver::effectiveForBranch()
                              org vocabulary ∩ branch activation overrides;
                              the 精算 manifest additionally intersects with
                              the accepts of terminals registered at the
                              branch
```

### Layer 1 — vocabulary (`till_tender_types`)

Seeded per organization by `TillTenderTypeSeeder` (branch_id NULL): `cash`
and `credit` anchors plus the QR (`rakuten_pay`, `paypay`, `d_barai`,
`au_pay`, `merpay`, `ginko_pay`, `wechat_pay`, `alipay`, `unionpay`) and
e-money (`id`, `ic`, `edy`, `waon`, `nanaco`, `quicpay`) brand sets. Shops
can add custom rows (vouchers, …) via the existing admin CRUD at
`/shops/{slug}/tender-types` (`TillTenderTypeController`) — those are
branch-scoped rows with their own keys. `parent_tender_key` still supports
terminals that split brands differently (Visa/Master under `credit`).

### Layer 2 — accepts (`peripheral_devices.metadata.accepts`)

Payment terminals and coin changers (`PeripheralDeviceService::NETWORK_TYPES`)
may carry `metadata.accepts`: an array of `tender_key` strings. Validation
(shared by the shop SSO path and the workstation device-token path through
`PeripheralDeviceService::metadataRulesFor($type, $organizationId)`):

- each key must be an existing **active org-level** vocabulary row
  (branch_id NULL) of the device's organization — unknown, inactive,
  branch-only, or foreign-org keys are a 422;
- keys must be distinct;
- the whole field is optional (`sometimes`) — a device without `accepts`
  simply doesn't anchor any 精算 section.

**Template prefill.** `config/tender_templates.php` maps terminal model
slugs to their vendor-standard accepts:

| slug | accepts |
|---|---|
| `stera` | credit, paypay, rakuten_pay, d_barai, au_pay, merpay, id, ic, edy, waon, nanaco, quicpay |
| `starpay` | credit, paypay, au_pay, wechat_pay, alipay, unionpay |

When a `payment_terminal` is **created** with `metadata.model` but
**without** `metadata.accepts`, `PeripheralDeviceService::create()` (via
`TenderTemplateService::acceptsForModel()`) prefills `accepts` from the
matching template. Matching is case-insensitive and substring-tolerant
("Stera", "stera terminal (SMBC GMO)" both match `stera`). The prefill is
**intersected with the org's active org-level vocabulary** so it always
satisfies the same invariant an explicit payload is validated against.
Explicit `accepts` always wins — including an explicit empty array — and
`update()` never prefills, so an operator's later edits are respected. The
template is a starting point, not a contract: the same Stera under two
different acquirer contracts carries different brand sets, and the operator
trims the list to match.

### Layer 3 — effective per branch (`TenderTypeResolver`)

`App\Services\Till\TenderTypeResolver::effectiveForBranch($orgId, $branchId)`
replaces "is_active is global" with per-branch activation. A branch-scoped
row with the **same `tender_key`** as an org row is that branch's
**override** and wins wholesale:

| org row | branch override | effective for the branch |
|---|---|---|
| active | — | shown (pass-through) |
| active | `is_active=false` | **hidden** |
| inactive | `is_active=true` | **shown** (branch re-activation) |
| inactive | — | hidden |
| — | branch-only custom row | shown when active |

Ordering is deterministic — `sort_order` asc, then `tender_key` asc — so
Cloud and any future workstation port render the same sequence.

Consumers today: `GET /pos/till/tender-types` (POS close screen; now
org-scoped and override-aware) and the shop surface below. The raw-row CRUD
surface (`/shops/{slug}/tender-types`) intentionally keeps showing rows
as-is for management.

## Country-parametrized vocabulary (#1153 × #1156)

The vocabulary an organization receives follows its immutable
`organizations.operating_country` (mirrored from the Platform, default JP) —
same philosophy as the compliance profiles: **one machinery, country only
changes the data** (`config/tender_vocabulary.php`).

| Country | Currency | Vocabulary |
|---|---|---|
| `JP` | JPY | 17 rows — cash · credit (anchor, Stera-日計-shaped grouping) · 9 QR brands (PayPay, 楽天ペイ, d払い…) · 6 e-money (iD, 交通系IC, WAON…) |
| `VN` | VND | 7 rows — cash · credit · **VietQR** (anchor → method `transfer`, no terminal slip — confirmation is the bank app) · MoMo · ZaloPay · VNPAY · ShopeePay |
| other | — | falls back to JP (matches the column default) |

Consequences downstream, automatically:
- `metadata.accepts` validation only admits keys from the org's own
  vocabulary — a VN org cannot list `paypay` on a terminal.
- Template prefill (stera/starpay are JP vendors) intersects with the org
  vocabulary, so a template applied in the wrong country degrades to the
  legal subset instead of leaking foreign brands.
- The 精算 manifest and close-page inputs per country come for free, since
  both read the effective vocabulary.

Adding a country = adding one config entry (+ its seeder test case). Do not
fork seeders per country.

## Per-branch activation endpoints

```
GET   /api/v1/shops/{shopSlug}/till/tender-types
PATCH /api/v1/shops/{shopSlug}/till/tender-types/{tenderKey}   { "is_active": bool }
```

(`ShopTillTenderActivationController`, routes in
`backend/routes/api/shops/till.php`; auth mirrors the other shop-till
surfaces — `ResolveShopFromSlug` enforces shop access.)

- **GET** returns the branch's *effective* list (resolver output, active
  rows only) as `TillTenderTypeResource` (now including `is_active`).
- **PATCH** flips one tender for this branch: first flip **materializes**
  a branch override row (copies the org row's category, anchors, sort,
  payment_method_code and ja/en/vi name translations; sets `branch_id` +
  the submitted `is_active`; 201), later flips update the same row (200).
  A branch-only custom tender's own row is toggled directly. Unknown key →
  404 `TENDER_KEY_UNKNOWN`. Org-wide seeded rows are never mutated, so
  sibling branches are unaffected.

## `tender_key` on payments

`order_payments.tender_key` (nullable `string(50)`, indexed — declared in
`database/migrations/omnify/*_create_order_payments_table.php`)
stamps the brand behind the payment. Intake:

- `POST /shops/{slug}/orders/{order}/payments` and the POS namespace twin
  (`/pos/orders/{order}/payments`) accept an optional `tender_key`
  (`OrderPaymentStoreRequest`). Validated when present: an **active**
  `till_tender_types` row of the order's org (org-wide or the order's own
  branch). **Never required** — absence changes nothing on any path.
- Threaded like `reference_no` through both persistence funnels:
  `OrderPaymentService::create()` → ledger `createRow`, and the
  orchestrator path (`OrderPaymentOrchestrationCompat::recordAutoConfirmTender`,
  post-write parity update — `PaymentTenderPayload` stays untouched so
  offline-replay idempotency hashes don't shift).
- Echoed by `OrderPaymentResource` (`tender_key`).

POS UX (frontend slice, not in this backend change): tapping 決済端末 shows
a sub-choice built from the branch terminals' `accepts` — card / QR brand /
NFC — and the chosen key rides the payment create call.

## How 精算 consumes this (capability-driven manifest)

Target state per #1156 (backend primitives in place; manifest assembly is
the follow-up slice):

- The close screen shows only tenders that are **effective for the branch
  AND anchored by a registered device's `accepts` ∪ actually used in the
  shift** (payments with that `tender_key` in the session) — a shop with no
  terminal sees no terminal sections at all instead of Stera's 17-line
  layout.
- **Expected per brand is computed, not classified**: Σ
  `order_payments.amount` grouped by `tender_key` within the till session.
  The cashier keys in the numbers from the terminal's 日計 print and the
  system does the comparison — no more hand-sorting slip lines.
- **One section per terminal**: each `payment_terminal` with `accepts`
  becomes its own manifest section with its own `terminal_batch_total`
  (column already exists on `till_settlement_tender_details`, plan-030/032).
  Two terminals = two sections; zero terminals = zero sections.
- This reconciliation is *books-vs-slip* (our records vs the terminal's own
  daily total). Actual acquirer money movement belongs to the settlement
  cycle and stays out of scope.

## Operational notes

- **Additive + backward compatible**: no existing row changes shape;
  payments without `tender_key` and terminals without `accepts` behave
  exactly as before. No backfill needed.
- A vocabulary row that is later deactivated/deleted does **not** invalidate
  history: `order_payments.tender_key` is a plain string by design.
- Seeding: `TillTenderTypeSeeder` is idempotent per (org, NULL branch,
  tender_key) — safe to re-run when new orgs appear.
- Tests: `backend/tests/Feature/Shop/Device/PeripheralTerminalAcceptsTest.php`,
  `backend/tests/Feature/Shop/OrderPaymentTenderKeyTest.php`,
  `backend/tests/Feature/Till/TenderTypeResolverTest.php`,
  `backend/tests/Feature/Shop/ShopTillTenderActivationTest.php`.
