# Plan 043 — Notes

> ### ⚠️ Một thứ trong file này KHÔNG CÒN TỒN TẠI (đo lại 2026-08-07, #2049)
>
> **`ConsumptionTaxCalculator` đã bị xoá** (`916616ec2`) — và bị xoá vì
> **đúng lý do ngược lại** với cách plan này từng mô tả nó. Plan gọi nó là
> *"single source of truth"* cho hằng số STANDARD; commit xoá nó ghi rằng nó
> không còn caller nào và các hằng RATE_STANDARD/REDUCED/EXEMPT đã nằm inline.
> Đừng đi tìm hằng số ở đó, và đừng dựng lại nó.
>
> Phần còn lại của plan-043 (tax type phạm vi brand, chuỗi 4 tầng resolve,
> làm tròn một lần mỗi mức) thì ĐÃ SHIP và vẫn đúng — xem
> `docs/guide/tax-types.md`.


> ## ⚠️ SUPERSEDED IN PART — read this before trusting anything below
>
> The **two-rate tax type** (`rate_dine_in` / `rate_takeaway` chosen by
> `order_type`) was **removed on 2026-07-26 (#1099)**. A tax type is ONE rate;
> the MENU decides the consumption context. Every mention of it below is a
> record of what was built, **not an instruction**.
>
> Still true and still shipped: immutable per-line snapshots, rounding ONCE per
> rate group (インボイス), 総額表示 mode, service-charge rate, per-rate output,
> the workstation Go engine.
>
> Current truth: [`docs/guide/tax-types.md`](../../docs/guide/tax-types.md).

> Working log for [Tax Types — Japanese Consumption Tax](README.md). Append-only. Newest entries on top.

Use this file for:
- Decisions made during execution (with reasoning)
- Blockers and how they were resolved
- Context discovered while researching
- Links to relevant code, PRs, conversations
- Anything you want future-you (or another contributor) to know

---

## 2026-07-14 (round 4) — Never-audited areas + re-audit of round-3 → all code-fixable findings fixed

Round 4 swept the areas earlier passes never touched (Omnify schema drift,
godx-kiosk, tms-app, admin-web reports/exports) + adversarially re-reviewed the
round-3 fixes, and ran the FULL backend suite for the first time. Details in
[TAX-AUDIT-FIXES.md](TAX-AUDIT-FIXES.md) § ROUND 4. Fixed: kiosk now shows the
税込/税抜 label + per-rate breakdown (added the missing `is_tax_included` on
Cloud KioskOrderResource and `tax_breakdown` on the workstation kiosk shape);
UpdateMeta order_type flip is now fully atomic (meta+re-resolve+recalc in one
tx); admin-web renders the `brand_default` in-use count, the `service_charge_tax`
residual, and the `prices_include_tax_at_open` badge (with the backend serialize
added); pos-web test spy hygiene. Key results: Omnify schemas are drift-free
(`omnify:gen` safe); the new net `taxable` semantics matched all three
frontends' existing expectations (no consumer broke); full backend suite 4838
passed / 0 fail. Left as backlog with reasons: kiosk 店内/持ち帰り UI (business
decision), per-rate CSV export, tax-audit-log + MenuProduct-audit UIs, dashboard
by_rate payload. Suites after fixes: backend green, Go full green, pos-web
316/316, kiosk 112/112 vitest + 0 new typecheck errors, admin-web 0 new
typecheck errors.

---

## 2026-07-14 (round 3) — Adversarial re-audit of the fixes themselves → all findings fixed

Round 3 audited the round-1/2 fixes adversarially + every consumer of the new
`tax_breakdown` semantics. Everything found was fixed same-day (details in
[TAX-AUDIT-FIXES.md](TAX-AUDIT-FIXES.md) § ROUND 3): pos-web 403 mapping for
the tax toggle (A1), scNet clamp (B1), restored-brand provisioning + a
**pre-existing latent bug** — `deleted_at => null` silently dropped by the
fillable guard meant org-sync restore NEVER worked for Organization/Brand/
Branch (B2), bulkDelete race catch (B3), Go never-unstamp + keep-own-type
off-menu + voided guard + single-tx UpdateMeta (B4/B5/B7/B9),
default promotion for partially-seeded brands (B6). Key negative result:
the new net `taxable` semantics matched what all three frontends already
expected — no consumer broke. Suites: backend green, Go full green,
pos-web 316/316 + tsc, customer-web 96/96.

---

## 2026-07-14 — Full tax-audit fix sweep → [TAX-AUDIT-FIXES.md](TAX-AUDIT-FIXES.md)

Processed EVERY finding from the 2026-07-13/14 two-round tax audit. Full
per-item record (problem → fix → files → tests, refuted claims, ops checklist)
lives in **[TAX-AUDIT-FIXES.md](TAX-AUDIT-FIXES.md)**. Highlights:

- **Backend:** brand auto-provisioning of standard tax types at the prod
  entrypoint (GodxOrgSyncService) + loud TaxResolver 0%-fallback warning;
  `tax_breakdown` semantics fixed (net-of-discount taxable, 内税-net, additive
  `service_charge_tax` reconciling field); finalized-order breakdown now
  reconciles sc tax as a residual against the stored figure (immune to later
  setting edits); tax-breakdown print toggle requires a signed-in user (403 for
  bare devices); mid-shift guard + till open serialize on the settings row;
  TaxType delete/deactivate guards hardened; MenuProduct audits; client
  tax-field injection regression pinned.
- **Workstation Go:** order_type flip now re-resolves every line (was
  under-collecting on LAN); lazy re-stamp of offline NULL-rate lines once tax
  types sync (Cloud BUG-8 mirror); reconcile adopts Cloud's per-line tax
  snapshot; paid-order total-change + >1-unit payment caps now scream in logs.
  **New bug found while fixing:** the POS item_update queue payload nests the
  edit under `patch` but the sync handler read flat keys — LAN qty/note edits
  synced UP as nulls (Cloud patched nothing). Fixed to read both shapes +
  forward toppings.
- **customer-web:** ZERO_FRACTION currency set completed (GNF/VUV/XAF/XOF/XPF).
- **Data (dev):** `orders:backfill-tax-snapshots` executed — 34 open orders /
  102 lines stamped, 0 unstamped open lines remain.
- **Verification:** backend Unit 301/301 + Feature batch 1174/1174; Go full
  service+handler green; customer-web 96/96; 15 new tests.

---

## 2026-07-13 — Per-line `tax_amount` allocated (largest remainder) so Σ line == group tax

**Problem found (review).** The per-line `tax_amount` snapshot was computed with
**independent per-line rounding** in `CustomerOrderService::stampLineTaxAmounts`
(`round(net_line × rate/100)`), while `order.tax_amount` uses **once-per-group**
rounding (`OrderPricingCalculator::priceGroups`). The two disagree for a
multi-line rate group (the ¥333×3 @8% case: Σ line = **81**, order = **80**).
That drift is not just cosmetic: two surfaces **sum the per-line snapshots** —
`CustomerOrderResource.tax_breakdown` and `OrderTaxBreakdownAggregator` (Z-report
PDF, revenue dashboards) — so the reports showed the インボイス-**forbidden**
per-line-rounded figure and could not reconcile to `order.tax_amount`.

**Decision.** Keep the per-line `tax_amount` snapshot (it's useful for
split-bill and per-line invoice display), but **stop rounding it per line**.
Each rate group's once-rounded tax is now **allocated to its lines by
largest-remainder** so, within a group, **Σ line == the group tax** the order
total uses. The ¥333×3 group stamps `27 + 27 + 26 = 80`.

Alternatives rejected: (a) drop the column and recompute the breakdown on read —
the aggregator's design principle is to read frozen snapshots and never recompute
tax math (so later rate edits can't rewrite finalized reports), which requires the
stored value to already be correct; (b) change the consumers to recompute
group-once — impossible for the cross-order aggregator without per-order discount
proration. Allocation at stamp time satisfies both.

**Implementation.**
- `OrderPricingCalculator`: extracted the shared once-per-group formula into
  `groupTaxFor()` (now used by both `priceGroups` and the stamper, so they can
  never drift), added `lineTaxIdeal()` (exact unrounded share) and
  `allocateGroupTax()` (largest-remainder / Hamilton; exact sum; non-negative;
  handles the 内税 sub-step residual; deterministic tie-break by input order).
- `CustomerOrderService::stampLineTaxAmounts`: rewritten to bucket non-voided
  lines by snapshot rate, rebuild the **same** `netGroup` `priceGroups` uses, and
  allocate. `order.tax_amount` is unchanged (only line snapshots move).
- Doc/behaviour comments updated in `OrderTaxBreakdownAggregator` +
  `CustomerOrderResource`.

**Residual (documented, not a bug).** `order.tax_amount` can still exceed Σ line
by the **service-charge tax** — an order-level charge that owns no line.

**Tests.** `OrderPricingCalculatorTest` (8 new `allocateGroupTax` cases: the
¥333×3 excluded proof, whole-step + deterministic tie-break, discount pro-rata,
内税/included, USD sub-unit, single-line, exempt) + `WorkstationTaxRecomputeTest::BUG-2h`
(end-to-end through the recompute path: order 80, Σ line 80, lines `[26,27,27]`).

### Workstation Go — LAN Z-report fixed (same session)

Investigation confirmed the workstation has the **same bug in a user-visible
surface**: `internal/handler/lan_shift_report.go` built its per-rate tax
breakdown (消費税内訳, printed on the 精算 shift-close report) by
`SUM(oi.tax_amount)` grouped by rate — summing the per-line-rounded snapshots.
For a 3×¥333 @8% order that printed **81**, disagreeing with both the group-once
rule and the report's own total-tax line (`Σ order.tax_amount`).

Go's write paths are messier than Cloud's (two separate `recalcOrderTotals`
impls — the handy one is still legacy single-rate — and per-line `tax_amount` is
never re-stamped after `createItem`), so rather than port the per-line
allocation into every write path, the report now **recomputes group-once per
(order, rate)** and sums across the shift — the インボイス-correct aggregate,
independent of the flawed per-line snapshots, and it reconciles to
`Σ order.tax_amount`:

- `internal/service/pricing.go`: extracted the shared once-per-group formula
  into exported `GroupTaxFor()` (also now used by `priceGroups`) + exported
  `CurrencyStep()`.
- `internal/handler/lan_shift_report.go`: the breakdown query groups by
  `(order, rate)`, applies pro-rata discount, and rounds once per group via
  `GroupTaxFor` (mirrors `OrderPricingCalculator::priceGroups`).
- Test: `TestBuildShiftReport_PerRateBreakdown_GroupOnceMultiLine` (3×¥333 @8% →
  80, not 81; reconciles to `TaxTotal`); the existing single-line breakdown test
  still passes. Full `internal/service` + `internal/handler` suites green.

### Workstation Go — per-line snapshot now allocation-correct on every write path (same session)

Closed the follow-up above — the Go per-line `tax_amount` snapshot is now the
true mirror of the Cloud fix, not just the Z-report:

- `internal/service/pricing.go`: ported `LineTaxIdeal()` + `AllocateGroupTax()`
  (largest-remainder / Hamilton, exact sum, non-negative, 内税 sub-step residual,
  deterministic tie-break) — 1:1 with `OrderPricingCalculator`.
- `internal/service/order_service_pos.go`: added `stampLineTaxAmounts(tx, orderID)`
  (groups non-voided lines by rate, rebuilds the same net base `priceGroups` uses,
  allocates, UPDATEs each `order_items.tax_amount`) + `refreshItemTaxAmounts` (patches
  the in-memory items for the immediate response). `recalcOrderTotals` is now
  tx-wrapped and calls the stamp; exported `RecalcOrderTotals` added.
- Wired the stamp into every engine write path: order **create** + **addItems**
  (`order_service.go`) and `recalcOrderTotals` (covers POS void/update + coupon
  apply/release). Re-stamping ALL non-voided lines each recompute also fixes the
  stale-after-qty-edit gap.
- `internal/handler/local_handy.go`: the legacy single-rate `Server.recalcOrderTotals`
  now **delegates** to `s.orders.RecalcOrderTotals` (per-rate totals + allocated
  per-line tax); the single-rate body remains only as a fallback when the engine
  is unwired (bare-Server unit tests).
- Tests: `TestAllocateGroupTax` (unit, mirrors the PHP allocateGroupTax cases) +
  `TestStampLineTaxAmounts_ReconcilesToGroupTax` (3×¥333 @8% through the real
  `RecalcOrderTotals`: order 80, stored per-line `[26,27,27]`, Σ 80 == order tax).
  Full `internal/service` + `internal/handler` suites green; gofmt + vet clean.

Net: on **both** Cloud and Workstation the order total, the per-line `tax_amount`
snapshots, the tax breakdown, and the Z-report all round once per group and
reconcile. No known remaining plan-043 tax-rounding gap.

---

## 2026-07-10 — 総額表示 menu display (Q10 resolved) + LAN per-product tax rates + BUG-10 promotion percent

Session after the BUG-1…9 fixes (all committed earlier today — see BUG-REPORT.md).
Three things shipped, all on `feature/plan-043-tax-types`.

### A. 総額表示 (税込/税抜) menu display — resolves README **Q10**

Q10 previously read *"Japanese shops run flag ON; no display-rounding rule built."*
Built it. The branch `prices_include_tax` toggle now drives the **displayed**
product price on pos-web + customer-web menus, not just the cart math.

Semantics (settled with the user over 3 iterations — earlier "show BOTH 税込+税抜"
and "single label, same number" were rejected):

- **One price + one label, both driven by the toggle.** OFF (税抜) → show the net
  (stored) price + "Chưa gồm thuế / 税抜". ON (税込) → show **net + tax** + "Đã gồm
  thuế / 税込". So the number visibly changes between modes (e.g. ¥2,350 → ¥2,585).
- New helper `menuDisplayPrice(base, ratePercent, pricesIncludeTax, currency)`:
  excluded → `base`; included → `roundStep(base × (1 + rate/100))`.
  pos-web `src/app/pos/lib/tax-display.ts`, customer-web `lib/tax.ts` (+ unit tests).
- **Consistency with the charge:** in the shop's OFF mode the cart engine
  (`OrderPricingCalculator`) charges `net + tax`, so the ON-mode display equals
  what the customer actually pays. The stored `selling_price` is the pre-tax base;
  the toggle is treated as a display preference (see caveat below).
- Rate is resolved **per-product, not per-SKU** — pos-web `productRateForOrderType`,
  customer-web `resolveLineRate`. Confirmed `TaxResolver::resolveForLine(Product …)`
  is product-level (MenuProduct → Product → branch → brand); `product_skus` has NO
  tax field. All SKUs of a product share one rate; only the price differs.

Surfaces updated (every place a **raw menu/product/SKU/topping sticker price**
shows) — commits `c722f9cf`, `b781d421`, `4ca07d20`, `1ac46dcc`, `b2eacb80`,
`169c4375`, `93d730c3`:
- pos-web: `menu-catalog` (card price + variant range + promo strikethrough +
  single 税込/税抜 label), `variant-picker-popover`, `product-options-dialog`
  (SKU + topping + running-total + all promo `StrikethroughPrice` sites).
- customer-web: `happy-hour` `HappyHourPrice` (menu-card / list / carousel),
  `product-modal` (hero + variant/topping upcharge + footer total + add toast),
  `featured-products-section` (branch-default rate — no per-product tax fields).
- i18n: `pos.menu.taxIncluded/taxExcluded` + `menu.taxIncluded/taxExcluded`
  (ja 税込/税抜 · vi Đã gồm thuế/Chưa gồm thuế · en Tax incl./Tax excl.).

**Deliberately NOT transformed** (would double-apply tax — these already reflect
the toggle via the tax engine): pos-web payment / split-bill / receipt / debt
(all backend order fields); customer-web cart → checkout totals (`computeCartTax`
mirrors the backend) + order history / dine-in summary/paid. The value fed into
`addToCart` is never transformed — display only.

**Update (2026-07-14) — pos-web `order-cart` NOW presents 税込 in 総額表示 mode.**
The original "order-cart deliberately not transformed" decision produced a real
UX bug: the menu card showed the gross price (`menuDisplayPrice`, net×(1+r)) but
the cart line + subtotal showed the net (`order.subtotal`), so "add Bún Chả to
order" jumped from ¥2,151 (menu) to ¥1,955 (cart). Fixed in `order-cart.tsx`:
when the shop's `prices_include_tax` is ON **and** the order snapshot was stored
net (`is_tax_included=false`, the system invariant), the cart re-presents
- line rows → gross via `menuDisplayPrice` on each line's stamped `tax_rate`,
- subtotal → `order.subtotal + Σ tax_breakdown.tax` (group-once authoritative),
- service → `order.service_charge + (tax_amount − item tax)`,
- per-rate rows → 内消費税 (informational), and the grand total is UNCHANGED
  (`order.total_amount`). Reconciles exactly: gross subtotal − discount + gross
  service ≡ total. Helpers `itemTaxTotal` / `serviceTaxTotal` in
  `pos-web/src/app/pos/lib/tax-display.ts` (unit-tested). LAN parity required
  emitting `tax_breakdown` from the workstation's shared order shape
  (`customer_order_shape.go`) — previously only the kiosk bill carried it.

**Caveat (now defended, was "logged, not a bug"):** `prices_include_tax` in the
engine is an *entry-basis* flag (whether the stored price already includes tax),
but the menu + cart treat it as a *display* preference (stored = net, add tax
when ON). These agree in the shop's real net/OFF mode. If a snapshot were ever
stored **gross** (`is_tax_included=true`), re-multiplying would double-count — so
both the cart line transform (`showGrossLines`) and the summary transform
(`showGrossSummary`) gate on `!order.is_tax_included` and show the stored gross
as-is instead.

### B. LAN pos menu exposes per-product tax rates (workstation)

For pos-web **LAN mode** to compute the display without a Cloud round-trip, the
workstation LAN menu now serves `tax_rate_dine_in` / `tax_rate_takeaway` on
each product. Helper `resolveProductTaxRates(productID)` mirrors the resolver
chain off the SQLite mirror (menu_items.tax_type_id via sku join →
`shop_settings.default_tax_type_id` → `tax_types.is_default`).
Applied to **both** `loadMenuProducts` (`/menus/{menu}` detail — cart path) AND
`handleLocalPosMenuProducts` (`/menus/{menu}/products` paginated — the endpoint
pos-web's grid actually calls via `dispatchMenuTwoSeg`; the first fix landed only
in the detail path and the grid still showed one price → root-caused + fixed).
Workstation `431709a`, `d9c1a77`; umbrella bumps `b5fcfccd`, `c637a672`.

### C. BUG-10 🔴 — promotion percent applied as basis points (100× under-discount)

Surfaced from a live question ("menu ¥2,450 but the order line is ¥2,446?"). A
15% Happy Hour promo only discounted ~0.15% (¥4), and the badge showed **−0%**.
Root cause: the **workstation promotion path** treated `discount_value` as
basis-points (÷10000) while Cloud (`CustomerOrderService`: `rawUnitPrice*(100-n)/100`,
`n` validated 0.01–100), the coupon path, and `sync_pull` all use **plain percent**
(15 = 15%). The coupon path was migrated earlier; promotions were missed.
Fixed 3 sites → `×(100-value)/100` with a `>=100` guard:
`service.applyDiscount` (at-cart order-line price = the actual charge),
`handler.applyDiscountForBadge` (badge `discounted_price`),
`activePromotionForProduct` (badge `discount_percent`, was `value/100` → floored 15→0).
Test fixtures converted from basis-points (2000/5000/1000 → 20/50/10) + added
15%→850 / 20%→800. Verified live: LAN now serves `discount_percent=15`,
`discounted_price=1997` (=2350×0.85). Workstation `6b24958`; umbrella bump `9edb9002`.
Note: strictly a MenuPromotion-discount bug (README lists promotion *allocation*
as out of scope), but it was a live money bug affecting **every % promo
fleet-wide**, surfaced during plan-043 testing → fixed. Tracked as BUG-10 in
BUG-REPORT.md / BUGS-FOUND.md.

## 2026-07-09 — T6.2/T6.3 DONE: dropped shop_order_settings.tax_rate

Executed the destructive drop in-branch (user asked to complete the whole plan).
It IS safe for this monorepo: all apps rebuild together from this branch, and
the workstation Go engine (Phase 3) already resolves per-line tax from synced
tax_types + tolerates a missing tax_rate key. Every brand is seeded with an
is_default type so the resolver always resolves (the dropped legacy fallback is
unreachable).

Done: dropped tax_rate from ShopOrderSetting.yaml + omnify:gen; removed all
readers — TaxResolver (legacyRate tier gone → 0%), OrderPricingCalculator,
Split callers, ShopOrderSettingsController (validation/payload/response/OA),
model fillable, seeder, CustomerBranchController, Workstation BranchController,
KioskOrderResource, CustomerTableController; client readers removed (admin-web
settings box, pos-web settings service, customer-web brand-context, workstation
sync_pull). Full backend suite green (4001 pass, 2 pre-existing fails); all
frontends typecheck 0-new; Go green.

**Real gap found + fixed (T6.2 surfaced it):** the workstation LAN add-items
Cloud endpoint (`OrderLifecycleController::addItems`) reimplements arithmetic
and did NOT resolve tax types — it relied on the now-removed branch fallback.
Fixed it to resolve + stamp per-line tax at Cloud (mirroring
CustomerOrderService::addItems), so workstation-synced lines get the correct
rate. Verified: a workstation line resolves to the brand
STANDARD 10% at Cloud.

**BackfillTaxTypes command** (migrates FROM tax_rate) is now guarded with
Schema::hasColumn → no-ops post-drop (run it PRE-drop per the runbook); its
migration-path test was removed (untestable once the column is gone). The
orders:backfill-tax-snapshots command is unaffected (reads item.tax_rate, kept).

## 2026-07-09 — T1.0 RESOLVED: seed direction = Japanese legal standard

Adopted **店内 (eat-in / spot / dine_in) 10% · 持ち帰り (takeaway) 8%** — the
Japanese legal standard (軽減税率). The customer's handwritten note was reversed
(§1.1 ⚠️); the standard is legally unambiguous, so the seed uses it. Seed types:
標準 STANDARD 10/10 (default, non-food) · 軽減 REDUCED 10/8 (food) ·
非課税 EXEMPT 0/0. This unblocks T6.4 (TaxTypeSeeder) + T1.17 (demo seeder).
Recommend the customer confirm at UAT, but the direction is the correct default
and editable per-brand at any time (rate edits don't touch history — snapshots).

## 2026-07-09 — Phases 1-5 shipped; Phase 6 destructive drop is DEPLOY-GATED

Implementation status at end of the execute run: **Phases 1-5 complete** (~50
tasks), all committed on `feature/plan-043-tax-types` (23 umbrella commits +
workstation submodule at 49a1a2e). Full backend suite green (4002 pass, 2
pre-existing unrelated fails); all frontends typecheck with 0 new errors; Go
`go test ./...` green; the engine matches PHP to the yen (6 parity fixtures).
The feature is ADDITIVE and deployable as-is — legacy `tax_rate` still works in
parallel.

**Phase 6 T6.1-T6.4 are intentionally NOT executed** — they are the deploy-
order-gated + Q1-gated tail the plan explicitly reserves for last (spec PHẦN 11,
DESIGN Decision 7):
- **T6.2 (drop `tax_rate`)** is DESTRUCTIVE and must run ONLY after every
  branch has a `default_tax_type_id` AND the workstation fleet is updated.
  Dropping it now would remove the legacy fallback the resolver/engine/split
  and the deployed customer-web (which still reads `settings.tax_rate` per T5.5)
  all rely on — breaking the additive-compat the whole rollout depends on.
- **T6.1 (pre-drop gate)** can't pass until the backfill (`tax-types:backfill`)
  has run against real/seeded branch data.
- **T6.4 (TaxTypeSeeder) + T1.17 (demo-order seeder)** are Q1-gated (seed rate
  direction — customer must confirm eat-in 10% / takeaway 8%). Without the
  seeder there are no tax types on a fresh `migrate:fresh --seed`, so the drop
  + T6.1 gate can't be exercised in the dev DB either.
- **T6.3 (remove legacy readers)** is coupled to T6.2 (can't remove tax_rate
  readers while tax_rate is still the live fallback).

Runbook for finishing Phase 6 (post-merge, at deploy time):
1. Customer answers Q1 → land T6.4 TaxTypeSeeder + T1.17 demo seeder.
2. Deploy Phases 1-5; run `php artisan tax-types:backfill` (+ `orders:backfill-
   tax-snapshots`) in each environment; release the workstation fleet.
3. Run the T6.1 gate query (every branch has default_tax_type_id; fleet updated).
4. Only then: T6.2 drop `tax_rate` via YAML + omnify:gen, T6.3 remove legacy
   readers, T6.5 final sweep, T6.7 final bump.
Also deferred (follow-ups, not blockers): T2.3 MenuProduct tax-override write
endpoint; the インボイス T13 seller-registration entry UI (Q5).

## 2026-07-09 — T4.7 decision: refund tax (Q4 default adopted)

No separate tax-adjustment slip in v1. A refund is a negative payment row; the
refunded tax is DERIVED from the order's immutable per-line snapshots (already
correct per rate), so the order's per-rate breakdown stays consistent after a
refund without a new document. Revisit only if the customer/税理士 asks for a
dedicated 適格返還請求書 (qualified return invoice). `OrderPaymentRefundTest`
already asserts the snapshot is untouched by a refund (Phase 1 suite green).

## 2026-07-09 — T1.18: full backend suite green (3978 passed / 2 pre-existing fails)

Ran the ENTIRE backend Pest suite after all Phase-1 engine changes:
**3978 passed, 2 failed, 3 skipped.** The two failures are PRE-EXISTING and
unrelated to plan-043:
  - `Kiosk/KioskPaymentThrottleTest > it does not 429` — rate-limiter flakiness
    (confirmed failing on baseline via a real stash earlier).
  - `Shop/Device/ShopDeviceCrudTest > rejects duplicate` — the test asserts a
    500 but the endpoint now returns a 422 validation error (device-CRUD, no
    tax/order code path; subagent confirmed it fails on baseline).
Neither touches the tax engine, resolver, split, settings, or invoice code.

Key result: the ~55 "affected" test files in spec Appendix A did NOT need
rewriting — my changes were deliberately backward-compatible (additive resource
fields, legacy tax_rate fallback in the resolver + engine + split, single-rate
orders reproduce the pre-plan-043 numbers to the yen). So the engine swap landed
without breaking existing coverage — the opposite of the "20 directly-broken
files" the plan budgeted for. TaxTypeFactory was emitted by omnify:gen (T1.5),
so no manual factory was needed.

T1.17 (CustomerOrderSeeder per-line snapshots) is DEFERRED with T6.4: it needs
seeded TaxTypes (TaxTypeSeeder = T6.4, gated by Q1) and must coordinate with the
uncommitted DatabaseSeeder.php working-tree edits. Doing it now would only stamp
legacy-fallback snapshots. Both seeder tasks unblock together once Q1 is answered.

## 2026-07-09 — Discovery on T1.10: MenuProduct has no field-update endpoint

DESIGN endpoint #13 assumes a "MenuProduct update endpoint" accepting
`tax_type_id`. It does NOT exist: `routes/api/hq/menus.php` only exposes
add / remove / toggle / reorder / layout-sync for menu products —
`MenuProductStoreRequest` is empty and adds go through `MenuAddProductsRequest`
(product_ids only). So the MenuProduct-level tax override (resolver §7 tier 1)
has no write path yet.

Impact: **none for the engine** — `TaxResolver` already reads
`MenuProduct.tax_type_id` when present (T1.7), so an override set by a seeder /
future endpoint resolves correctly. The PRODUCT-level assignment + branch/brand
defaults (all implemented + tested in T1.10) are the v1 path. Wiring a
MenuProduct override write endpoint (new route + controller method + request +
service, with the same same-brand/active validation) is a
distinct feature — **deferred**; Phase 2 T2.3 (menu inherit/override dropdown)
must add it or be re-scoped. Logged so T2.3 doesn't assume the endpoint exists.

## 2026-07-09 — BLOCKER on T1.6: pre-commit hook forbids hand-written migrations

The plan (DESIGN data-model table + TASKS conventions) assumes `customer_invoices`
is "a legacy manual table — manual migration allowed there only". That assumption
is now STALE: `.githooks/pre-commit` blocks EVERY hand-written migration outside a
3-entry BLESSED whitelist (Laravel cache/jobs/sessions driver tables only). The
hook message is explicit: extending BLESSED is itself a review-block; "the
conversion has already been done for every domain table … no legitimate reason
left to add a new hand-written migration."

Investigated the table's real shape:
- NO schema YAML, NO Omnify module, NO omnify migration, NO Eloquent model.
- Created by a hand-written plan-038 migration (`2026_06_20_174544_create_customer_invoices_table.php`)
  that predates the hook (so it grandfathered in).
- InvoiceController accesses it purely via `DB::table('customer_invoices')` (raw
  query builder — insert/update/select), NOT a model.
- The repo's "fold" precedent (commit 12411fc9) only ever ALTERED tables that were
  ALREADY in Omnify (Category/CustomerOrder/Branch) by editing their YAML — it did
  NOT adopt a never-in-Omnify raw table.

So T1.6 as written (hand-written ALTER) cannot be committed. Three real options —
**paused for user decision** (hard rule #8; the plan doesn't make this call):
  A. Adopt customer_invoices (+ invoice_counters) INTO Omnify: author full YAML
     matching the plan-038 table exactly + the 2 new columns, delete the 3 hand
     migrations, regen. Cleanest vs the hook, but large scope, touches plan-038,
     risk of a column-mismatch against live invoice rows.
  B. Add the migration to BLESSED — hook says reviewers MUST refuse; dead on arrival.
  C. No schema change: store tax_breakdown + seller_registration_number inside the
     existing `items_json` blob (the table is already a raw JSON store with no
     model). Zero migration, passes the hook, but diverges from DESIGN (dedicated
     columns) and shifts the shape read by T1.13 / T4.4.
Migration file kept locally (unstaged) pending the decision; docker DB has the 2
columns applied (harmless — a migrate:fresh reconciles under any option).

**RESOLVED → Option A (adopt into Omnify).** User picked A. Executed:
- New `schemas/Backend/Shop/CustomerInvoice.yaml` matching the plan-038 table
  exactly + the 2 new columns. Reference columns (customer_order_id, customer_id,
  issued_by_id, branch_id, organization_id) are PLAIN `type: Uuid` (no FK, no
  relation) so the adoption is behaviour-preserving vs the legacy
  `foreignUuid()`-without-`constrained()` shape (AuditLog idiom).
- Deleted the hand-written `..._create_customer_invoices_table.php`; omnify:gen
  emits `omnify/2000_01_01_000025_create_customer_invoices_table.php` + model +
  factory + resource/policy/request bases + morph-map registration.
- `invoice_counters` NOT adopted — it has a keyless composite PK
  (branch_id + year_month, no surrogate id, no created_at) that Omnify can't
  model, and plan-043 doesn't change it. Left as the grandfathered plan-038
  hand migration. Same for `add_invoice_prefix_to_branches` (SSO package, tax-
  unrelated).
- **Verified:** `migrate:fresh --seed` clean in docker; the recreated
  customer_invoices columns match the original 1:1 EXCEPT `currency_code`
  char(3)→varchar(3) (Omnify has no Char type — behaviourally identical for a
  fixed 3-char ISO code). Index NAMES differ (Omnify auto-names) but cover the
  same columns; InvoiceController uses DB::table so names don't matter. Invoice
  Pest suite green (4 passed).
- **Production caveat (logged, not a dev blocker):** re-creating an
  already-deployed table via a fresh omnify CREATE migration would hit
  "table exists" on an environment that still has the old hand migration in its
  migrations table. Dev uses migrate:fresh so this is moot here; the plan-043
  rollout (spec PHẦN 11) already prescribes migrate:fresh for dev. Flag for the
  deploy runbook if customer_invoices is ever live in prod before this ships.
- InvoiceController still uses `DB::table('customer_invoices')` — unchanged in
  T1.6; T1.13 rewrites its write path to populate tax_breakdown.

## 2026-07-09 — Implementation: Phase 1 execution notes (T1.5 discoveries)

Branch `feature/plan-043-tax-types` off `dev` (issue #483, gh issue develop).

**Discoveries during `npm run omnify:gen` (v5.9.0, schema v54):**

1. **Editable service siblings are NO LONGER auto-generated.** Codegen emits
   only `app/Omnify/Modules/<Entity>/Services/<Entity>ServiceBase.php`. The old
   `app/Services/Omnify/<Entity>Service.php extends <Entity>ServiceBase` files
   are now flagged "orphan editable service … preserved for now" — the whole
   fleet of them. The LIVE editable services are hand-written standalone
   classes under `app/Services/<Domain>/` (e.g. `App\Services\Product\ProductTypeService`,
   a plain `class ProductTypeService` with NO `extends`, using the base model
   directly). **Action:** `TaxTypeService` will be hand-written at
   `app/Services/Tax/TaxTypeService.php` in **T1.10** (not auto-created here).
   T1.5's "confirm the editable sibling was created" expectation is stale —
   recorded here, no blocker.

2. **`TaxTypeFactory` WAS generated** (`database/factories/TaxTypeFactory.php`)
   + `TaxType` + `TaxTypeTranslation` editable models + OmnifyServiceProvider
   morph-map registration. So **T1.18 no longer needs to create TaxTypeFactory** —
   it only needs the plan's test scenarios.

3. **Go (workstation) regen is a no-op for tax.** `omnify:gen` writes 52 Go
   files to `workstation-app/` but `git status` there is clean — TaxType is not
   yet in the Go project's schema scope, so the Go structs regenerate
   byte-identical. Adding tax to the Go payloads is Phase 3 (T3.1–T3.4), as
   planned. No accidental submodule drift.

**Migrate verified** (`docker compose exec app php artisan migrate --force`):
8 new omnify migrations apply clean (tax_types + tax_type_translations create,
6 alter tables). Pest still green pre-logic (ShopOrderSettingsTest 29 passed).

## 2026-07-07 — Completeness re-audit, round 4 (independent agents)

Rounds 1–3 were self-review; round 4 used **two independent sub-agents with fresh context**: (A) spec→plan traceability over all of PHẦN 1–13 + Appendices, (B) plan-internal contract audit (endpoint↔test 1:1, screen↔browser, verify-line reachability, matrix counts, PHẦN 6–9 fidelity). Findings fixed:

**From agent A (spec→plan):**
1. Workstation **order** endpoints (`GET /workstation/orders` recovery + `POST /orders` response) had no task serializing per-line snapshots — T3.2 widened + Happy-path scenario added (the Go round-trip test consumes this).
2. **Lazy re-stamp** path (§11.3 — order open at deploy, stamped at next recalculation) was defined but untested — Migration scenario added.
3. Split mirror #3 (`payment-view.tsx`) had no test — Browser scenario added (payment/summary views).
4. **Dashboard** per-rate additions untested — Side-effects scenario added + T4.6 Verify updated.
5. Tier-4 resolver ambiguity (`is_default` vs `BrandOrderPolicy`, spec §3.6/§6.4/§7) — resolved as **Decision 9**: brand default = `TaxType.is_default`, no BrandOrderPolicy field; T1.7 updated. **(2026-07-11, #696)** Single-default is now DB-enforced, not just service-enforced: generated `default_marker` col + `UNIQUE(default_marker)` (MySQL has no partial index; NULLs don't collide) + `Brand::lockForUpdate()` serialized flip in `TaxTypeService::setDefault()` — concurrent writes can no longer commit two brand defaults (which would make tier-4 nondeterministic). Concurrency test gates the slice.
6. `[Go]` order_type re-resolve on workstation + `split_bill_rounding_mode` × per-rate interplay — scenarios added; Q6 rate-change advisory also added to the tax-types editor (T2.1), not just settings.

**From agent B (internal contracts):**
7. Missing authz scenarios: `/workstation/menu` 401; cross-customer breakdown read; pos_device folded into the HQ-route denial bullet.
8. Missing Browser coverage for modified screens: pos-web split/debt/tab-bar (scenario added); workstation `Settings.tsx` → explicit TESTS out-of-scope entry (no Wails browser harness).
9. **Product/MenuProduct write-path validation had NO implementing task** (T1.2 is YAML-only; same-brand + is_active rules need FormRequest/service code) — folded into T1.10 with files.
10. T5.6's Verify referenced a nonexistent `[Go]` split scenario — scenario added (`local_pos_phase3` parity).
11. Convention violations: 7 tasks lacked `Files:` lines (T1.19, T3.9, T3.10, T5.8, T6.1, T6.5, T6.7 + T5.3) — all added; T5.3/T5.4 Verify lines now reference their Browser scenarios descriptively.
12. Submodule-bump ritual generalized in TASKS Conventions to every phase touching workstation-app (4 & 5, not just 3 & 6).
13. **Repair of a self-inflicted wound:** the round-2 edit that inserted Decision 8 had swallowed the `## Alternatives considered` heading — restored.
14. TaxType CRUD happy-path bullet extended with explicit index-pagination/show/delete-204 assertions; refund-snapshot-immutability scenario added (Q4 default); `close_report_tax_breakdown` field-lifecycle row now lists the pos-web editor.

TESTS now **110 scenarios**; TASKS still 61 tasks (additions folded into existing tasks). Agent-A "borderline" items intentionally NOT acted on: refund slip (Q4 default stands), godx-handy Browser (no harness — carve-out documented).

## 2026-07-07 — Completeness re-audit, round 3

Third pass, focused on "sunken" details (data sources, LAN plumbing, test-contract 1:1, fixtures). 6 more gaps found and fixed:

1. **`customer-web/context/brand-context.tsx:102`** — spec §3.3 names it as the tax data source (`branch.tax_rate`) feeding every customer-web preview; T5.4 + DESIGN now include swapping it to the new settings fields.
2. **T13 registration number value source** — clarified everywhere (DESIGN field lifecycle, README out-of-scope): this plan ships the invoice snapshot column + print slot ONLY; the seller-side settings storage field + entry UI are a follow-up (Q5). Until then the column stays null and templates render the slot only when present.
3. **LAN order-settings endpoint** (`local_pos_order_settings` family, listed in Appendix A) — new settings flags must surface through it for the pos-web settings editor to read/write; added to T3.3.
4. **`TaxTypeFactory` needed in Phase 1, not Phase 6** — T1.18 now creates it (with standard/reduced/exempt states) if omnify:gen doesn't emit one; Pest tests can't run without it.
5. **Endpoint↔TESTS 1:1 contract holes** — inventory #11 (customer/branches), #12 (workstation menu/branch), #13 (product/MenuProduct assignment) had authz/validation but no explicit happy-path Feature scenarios → 3 added (TESTS now 99 scenarios).
6. **Out-of-scope completeness (spec §3.12)** — README now states the shop-level invoice list endpoint (plan-038 T11.3 TBD) and `recipient_email` invoice mailing stay out; T1.15 covers audit for CSV-path assignments; T6.6 also refreshes `workstation-app/docs/plan/08-kiosk-receipt-template.md` (its print-bug note is superseded by T3.7/T4.1).

Systematically re-verified with no change needed: all PHẦN-10 checkboxes still 1:1 with tasks; PHẦN-12 rows all present in README out-of-scope (16/16); 13 open questions accounted for (Q2/Q5 settled); Appendix-B surface rows each have an owning task; workstation-UP recompute path covered by the Go round-trip scenario; `Plan036TillTrackingDemoSeeder` untouched by design (session history, not order tax).

## 2026-07-07 — Completeness re-audit (post-scaffold)

Second full pass over `TAX_FEATURE_PLAN.md` (PHẦN 1–13 + Appendix A/B, every PHẦN-10 checkbox, every Appendix-B surface row) against the 5 plan files. 7 gaps found and fixed:

1. **Local LAN menu API (Go) + SQLite `menu_items` column + TS types** (spec Phase 3 bullet) — was implicit; now explicit in T3.3 + DESIGN #12 + `[Go]` LAN menu scenario.
2. **Split mirror #3 = customer-web `payment-view.tsx`** (spec §3.8 lists 4 mirrors) — now named in T5.4; mirrors: T1.9 backend · T5.2 pos-web · T5.4 customer-web · T5.6 workstation.
3. **`close_report_tax_breakdown` in the pos-web settings editor** — commit 068175f3 put the close-report toggle editors in pos-web AND admin-web; plan only had admin-web. Added to T5.3 + DESIGN screens + Browser scenario.
4. **`till_sessions` per-rate aggregate columns** (spec §5#11 "consider") — decided: **rejected**, derive from snapshots; and the breakdown toggle gates the thermal print only (Z-PDF always includes it) → DESIGN Decision 8, T4.2 note.
5. **Service-charge tax joins the matching rate group in printed breakdowns** (spec §5#7, §8 step 3) — made explicit in DESIGN Approach + a dedicated `[Feature]` scenario.
6. **UI advisory for changing rates outside shift hours** (Q6 / spec §11.8) — helper text added to T2.4.
7. **Missing scenarios / out-of-scope rows** — added: restore/toggle-status happy path, CSV unknown `tax_type_code` → row error, `is_tax_included` immutability on later flag flips, backfill of legacy `tax_rate = 0` branches, LAN menu `[Go]` test, pos-web toggle `[Browser]` test (TESTS now 96 scenarios); README Out-of-scope now also lists pos-web payment-dialog/close-shift and the money-less broadcasts (spec PHẦN 12 rows that were omitted).

Verified-complete (no change needed): all 7 PHẦN-4 bugs mapped to tasks (T1.13, T3.5–T3.7, T1.8, T4.1, T5.1); all 33 PHẦN-5 gaps traceable; every Appendix-B surface row has an owning task; i18n ×6 locations covered (T2.7, T3.8, T5.7, T5.9); order-pull recovery decode covered by T3.3; `OrderPlacedMail` check in T4.5; eager-load N+1 in T1.7.

## 2026-07-07 — Plan created

Initial scaffold via `/mcp__omnify__plan`, from the pre-existing authoritative spec **`TAX_FEATURE_PLAN.md`** (từng ở repo root, đã xoá #2188 — xem git history; 5 source-audit rounds, 14 agents, product-owner notes, 5 locked decisions). Phase 0.4 route: **user-supplied spec** → web research skipped; project-domain verification done inline.

### Discovery (verification pass over the spec's claims)

- `mcp__omnify__omnify_list_schemas` — 152 schemas, **no TaxType exists**; confirmed groups (Product/Shop/Till) and that `CustomerOrder` (42 props) / `CustomerOrderItem` (17 props) / `ShopOrderSetting` (15 props) match the spec's current-state audit.
- `schemas/Backend/Product/ProductType.yaml` — confirmed as the template for TaxType: brand+org CASCADE FKs, softDelete, unique `[brand_id, code]`, translatable `name`, header + per-property comments (convention #5 already practiced here).
- `schemas/Backend/Shop/ShopOrderSetting.yaml` — `tax_rate` present exactly as spec §3.1 says (BR-SOS05); the 4 `close_report_*` toggles exist → new `close_report_tax_breakdown` joins the family; `prep_before_payment` shows the nullable-inherit idiom.
- `schemas/Backend/Shop/BrandOrderPolicy.yaml` — exists with only `default_prep_before_payment`, matching spec §3.6 (candidate home for a brand-level fallback; plan keeps brand default primarily on `TaxType.is_default`).
- `backend/routes/api/hq/catalog.php:23-31` — product-types block confirmed: importTemplate/importCsv/exportCsv/lookup/dropdown(deprecated)/bulk-delete/restore/toggle-status + apiResource. Plan clones it **minus import/export** (DESIGN Decision 6).
- admin-web paths confirmed: `src/app/hq/[brandSlug]/product-types/` (screen to clone), `src/app/shop/[shopSlug]/settings/page.tsx`, `products/components/product-sidebar.tsx`, `components/shared/order-charge-summary.tsx`.
- Preflight: docs/ initialized; `laravel/boost` + `astrotomic/laravel-translatable` + `l5-swagger` present in `backend/composer.json`; highest plan = 042 → this is **plan-043** (matches the spec's own numbering assumption). `gh` authenticated.
- laravel-boost MCP tools were not exposed in this session; schema-state verification was done via omnify MCP + direct YAML reads instead (spec §3 already carries file:line evidence from the 5 audit rounds).

### Decisions taken at plan time

- Spec **PHẦN 13 proposals adopted as defaults** (Q3, Q4, Q6–Q13) — recorded in README Open questions; only **Q1 (seed rate direction)** requires an external (customer) answer and gates seeding tasks T1.14/T6.4.
- **Deactivate-over-delete** for used tax types (RESTRICT everywhere + 409 + toggle-status path) — DESIGN Decision 5.
- **No CSV import/export for tax-types themselves** (3–5 rows/brand); product CSV gains `tax_type_code` instead — DESIGN Decision 6.
- Order-item snapshot = `tax_type_id` (nullable FK RESTRICT) + `tax_rate` + `tax_amount`; code/labels derived via join (safe because RESTRICT keeps rows alive).
- TASKS phases = spec PHẦN 10 phases 1:1, with the PHẦN 11 deploy-order constraint made an explicit gate (T6.1 pre-drop verification).

### Working-tree caveat

`backend/database/seeders/DatabaseSeeder.php` (+ admin-web login/auth files, tms-app pointer) had uncommitted local changes at plan time — spec §3.14 flags this too. T1.17/T6.4 executors must coordinate with those edits instead of clobbering them.
