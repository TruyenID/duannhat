# Plan 022 — Design

> Design for [Material System — Correctness Fixes & UX Hardening](README.md). Schema diffs, flow diagrams, migration strategy, and architectural decisions for all 5 phases.
>
> Operational feature designs (reservation, split, allergen policy, timeline, drill, substitution) are in plan-018 DESIGN (đã archive — xem git history).

## Context

@see [material-system-deep-dive.md](../material-system-deep-dive.md) — source gap analysis
@see plan-017 DESIGN (đã archive — xem git history) — original schema (this plan corrects)
@see plan-018 DESIGN (đã archive — xem git history) — feature designs (runs before this plan)

---

## Current vs Target: Complete Flow Diagram

### CURRENT (shipped plan-017 — 9 bugs)

```
Material.components ──► MaterialBatchService::deriveItemsFromRecipe()
    ↑ BOM #1                     (no Recipe check, no approval guard)
    │
Material ──► Recipe.ingredients ──► OrderClosingService::recordSalesGenealogy()
                 ↑ BOM #2              (records ALL active lots — over-broad)
                 │
                 └─► AllergenRollupService (OK)

StockTransactionService::calculateBaseQuantity() → return $quantity; (stub 1:1)

MaterialBatchService::complete() → 'expiry_date' => null (production lots invisible)

MaterialBatchService::cancel() → assertStatus([Draft, Pending]) (stuck if InProgress)

No auto-expire cron → expired lots stay active in FEFO pool
```

### TARGET (after plan-022)

```
Recipe.ingredients ──► MaterialBatchService::deriveItemsFromRecipe()
    ↑ SINGLE BOM         (Recipe must be approved, Material.components dropped)
    │
    ├─► OrderClosingService::recordSalesGenealogy()
    │       (precise FEFO pick per ingredient — 1 edge per consumed lot)
    │
    └─► AllergenRollupService (unchanged)

StockTransactionService::calculateBaseQuantity(qty, unit, materialId)
    → MaterialUnit.ratio lookup → qty × ratio → base unit

MaterialBatchService::complete()
    → expiry_date = min(parent_lots.min_expiry, material.shelf_life_days)

MaterialBatchService::cancel()
    → allow Draft/Pending/Approved/InProgress (was: only Draft/Pending)
    → reverse stock + write reversal genealogy ONLY IF the batch carries a
      stock_out_transaction_id (defensive; see T7 note — the real
      start→complete flow never leaves a *cancellable* batch with a
      deduction, so in_progress cancel is a status-only flip)

Auto-expire cron (8am JST) → lots past expiry_date → status=expired
```

---

## Phase 1 — Foundation

### T1: Unit conversion — schema diff

| Table | Column | Type | Default | Notes |
|---|---|---|---|---|
| `material_lots` | `entered_quantity` | DECIMAL(15,4) nullable | NULL | Original qty as entered by operator |
| `material_lots` | `entered_unit` | VARCHAR(20) nullable | NULL | Original unit as entered |

### T1: Unit conversion — flow change

```
BEFORE (stub):
  receive(2, 'bag_25kg', flour)
    → calculateBaseQuantity(2, 'bag_25kg') → return 2
    → lot.qty_on_hand = 2
    → stock_levels.quantity = 2
    → stock_movements.delta = +2
    → WRONG: 2 bags ≠ 2 grams

AFTER (real):
  receive(2, 'bag_25kg', flour)
    → MaterialUnit::where(flour, 'bag_25kg') → ratio=25000
    → calculateBaseQuantity(2, 'bag_25kg', flour) → return 50000
    → resolveBaseUnit(flour) → 'g'
    → lot.qty_on_hand = 50000
    → lot.unit = 'g'
    → lot.entered_quantity = 2
    → lot.entered_unit = 'bag_25kg'
    → stock_levels.quantity = 50000
    → stock_movements.delta = +50000
    → CORRECT: 50000 grams
```

### T1: calculateBaseQuantity implementation

```php
private function calculateBaseQuantity(
    float $quantity,
    ?string $unit,
    ?int $materialId = null
): float {
    if ($unit === null || $materialId === null) {
        return $quantity;
    }

    $materialUnit = MaterialUnit::where('material_id', $materialId)
        ->where('unit', $unit)
        ->first();

    if (! $materialUnit) {
        throw ValidationException::withMessages([
            'unit' => "Unit '{$unit}' is not defined for this material.",
        ]);
    }

    if ($materialUnit->is_base) {
        return $quantity;
    }

    return $quantity * (float) $materialUnit->ratio;
}
```

### T3: Recipe ↔ Batch integration — flow change

```
BEFORE:
  MaterialBatchService::create({material_id, multiplier})
    → deriveItemsFromRecipe()
    → Material::find(material_id)
    → material.components → foreach component → batch items
    → NO recipe check. NO approval check.

AFTER:
  MaterialBatchService::create({material_id, multiplier})
    → deriveItemsFromRecipe()
    → Material::find(material_id)
    → material.recipe (via recipes() relation)
    → if no recipe → 422 "Material has no active recipe"
    → recipe.ingredients → foreach ingredient → batch items

  MaterialBatchService::submit()
    → recipe.approval_status !== 'approved' → 422 "Recipe must be approved"

  MaterialBatchService::approve()
    → same recipe approval check (defense in depth)
```

### T4: Unify BOM — migration strategy

```
Step 1: Migration script (non-destructive)
┌─────────────────────────────────────────────────────────────┐
│ foreach Material with non-empty components:                 │
│   if material has Recipe:                                   │
│     compare components vs recipe.ingredients                │
│     log mismatches to console                               │
│   if material has NO Recipe:                                │
│     create Recipe from components                           │
│     set approval_status = 'approved' (already in prod)      │
│     link material_id                                        │
│     run AllergenRollupService::recomputeForRecipe()        │
└─────────────────────────────────────────────────────────────┘

Step 2: Service rewire (same PR)
  MaterialBatchService → reads recipe.ingredients
  MaterialService → remove components validation
  MaterialService::lookup → components_count → recipe_count

Step 3: Drop column (1 sprint later)
  ALTER TABLE materials DROP COLUMN components
```

### T4: Decision D1 — Why Recipe wins over Material.components

| Criteria | Material.components | Recipe.ingredients |
|---|---|---|
| Approval workflow | ❌ None | ✅ draft→pending→approved→rejected |
| Allergen rollup | ❌ No automatic rollup | ✅ AllergenRollupService auto-recomputes |
| Auto-repend on change | ❌ Silent drift | ✅ Structural changes repend to pending |
| Self-approval guard | ❌ None | ✅ Creator cannot approve own |
| Link to ProductSku | ❌ Indirect via Material | ✅ Direct via recipe_id FK |
| OrderClosing reads | ❌ Doesn't read | ✅ Already reads here |
| Batch reads | ✅ Currently reads here | ❌ Doesn't read (bug) |

Recipe has richer lifecycle controls. The only reason components existed separately was historical — batch was implemented before Recipe had the approval workflow.

---

## Phase 2 — Safety

### T5: Production lot expiry — schema diff

| Table | Column | Type | Default | Notes |
|---|---|---|---|---|
| `materials` | `shelf_life_days` | INT nullable | NULL | NULL = no shelf-life policy |

### T5: Expiry computation logic

```php
// In MaterialBatchService::complete(), replacing 'expiry_date' => null:

$consumedLots = MaterialLot::whereIn('id', $consumedLotIds)->get();

$parentMinExpiry = $consumedLots
    ->whereNotNull('expiry_date')
    ->min('expiry_date');

$policyExpiry = $material->shelf_life_days
    ? now()->addDays($material->shelf_life_days)
    : null;

$outputExpiry = match (true) {
    $parentMinExpiry !== null && $policyExpiry !== null
        => Carbon::min(Carbon::parse($parentMinExpiry), $policyExpiry),
    $parentMinExpiry !== null => Carbon::parse($parentMinExpiry),
    $policyExpiry !== null => $policyExpiry,
    default => null, // backward compat: no policy, no parent expiry
};
```

**Rationale:** Output BTP cannot outlive:
- Its shortest-lived ingredient (parent min expiry)
- Its own shelf-life policy (material setting)
- Take the stricter of the two.

### T6: Auto-expire cron

```
┌────────────────────────────────────────────────────┐
│  7:00 AM JST — material-lots:scan-expiring         │
│    "Lot L-DASHI-001 expires in 1 day"              │
│    → Notification to ops                           │
│                                                    │
│  8:00 AM JST — material-lots:auto-expire           │
│    Lots WHERE expiry_date < today AND active        │
│    → SET status = 'expired'                         │
│    → Blocked from FEFO                             │
│                                                    │
│  1-hour window: ops can act on alert before expire │
└────────────────────────────────────────────────────┘
```

**Race window:** Between cron firing at 08:00 and finishing its UPDATE, a concurrent stock_out may attempt to consume from a lot about to be expired. This is safe because:

1. `pickLotsForConsumption` filters `status = 'active'` ([StockTransactionService.php:573](../../backend/app/Services/Inventory/StockTransactionService.php#L573)) inside a `lockForUpdate` ([line 578](../../backend/app/Services/Inventory/StockTransactionService.php#L578)).
2. The cron `UPDATE ... SET status='expired'` acquires the same row lock. Either:
   - Cron wins → stock_out sees `expired`, skips the lot.
   - Stock_out wins → cron's UPDATE waits, then flips the (now-reduced) lot to expired. Consumption already-committed is fine; the lot just becomes unavailable for the next request.
3. Schedule at 08:00 JST is a low-activity window for restaurant ops (pre-lunch prep). Acceptable per-row consistency cost.

### T7: Cancel from in_progress — flow

> **Correction (post-audit, #634).** The original premise below — that an
> `in_progress` batch has *already deducted* raw material and therefore needs a
> reversal when cancelled — is **false** for the shipped code. `start()`
> deliberately does **not** decrement stock; it only *preview-stamps*
> `material_lot_id` on each material item (best-effort FEFO for the detail UI).
> Actual FEFO lock + decrement, and the write of `stock_out_transaction_id`,
> happen only in `complete()` — which atomically flips the batch to
> `Completed` (an **uncancellable** status) in the same DB transaction. See
> [MaterialBatchService::start()](../../backend/app/Services/Inventory/MaterialBatchService.php#L448-L482)
> ("We don't decrement the lot here — that's still complete()'s job") and
> [complete()](../../backend/app/Services/Inventory/MaterialBatchService.php#L567-L769).
>
> **Consequence:** no *cancellable* status (Draft/Pending/Approved/InProgress)
> ever carries a `stock_out_transaction_id`, so cancelling an `in_progress`
> batch is a **status-only flip** — there is nothing to reverse. The
> reversal + reversal-genealogy branch in
> [cancel()](../../backend/app/Services/Inventory/MaterialBatchService.php#L847-L910)
> is retained as **defensive** code guarded by
> `if (batch.stock_out_transaction_id !== null)`: it is a no-op today but keeps
> `cancel()` correct if a future design ever chooses deduct-at-start. Regression
> coverage: `MaterialBatchCancelStatusFlipTest` proves the real
> `start()→cancel()` path performs zero stock movement;
> `MaterialBatchCancelWithLotsTest` unit-tests the defensive reversal branch by
> stamping a stock_out directly (it exercises the branch — it does **not**
> represent a state the public API can reach).

```
BEFORE:
  cancel() → assertStatus([Draft, Pending])
           → if InProgress → InvalidStatusTransitionException (stuck!)

AFTER (as shipped):
  cancel() → assertStatus([Draft, Pending, Approved, InProgress])
           → if batch.stock_out_transaction_id !== null   (defensive; never
             true for a cancellable batch in the real flow — see note above):
               1. Read stock_out_transaction_items with material_lot_id stamps
               2. Create StockTransaction(stock_in, adjustment_in)
                  with items: [{material_lot_id, qty to restore}]
               3. Submit + auto-approve → completeTransaction:
                  - StockLevel.quantity += restored qty
                  - MaterialLot.qty_on_hand += restored qty
                  - StockMovement(delta=+qty)
               4. Write GenealogyLink per consumed lot:
                  source_event_type = 'reversal'
                  source_event_id = batch.id
               5. Clear batch.stock_out_transaction_id
           → set status = Cancelled

  TraceService:
    When building tree, filter source_event_type='reversal'
    Show as "reversed" badge but exclude from recall blast radius
```

---

## Phase 3 — Correctness

### T8: Sales-edge FEFO — flow change

```
BEFORE (best-effort):
  recordSalesGenealogy():
    foreach ingredient_material:
      lots = ALL active lots (FEFO sorted)    ← problem
      foreach lots:
        recordSalesConsumption(lot, order)    ← N edges per material

  Result: Order #1234 buys 50g peanut
          3 active peanut lots → 3 edges
          Recall lot #3 → order #1234 flagged (WRONG)

AFTER (precise):
  recordSalesGenealogy():
    foreach ingredient_material:
      qtyNeeded = computeQtyFromRecipe(recipe, material, item.qty)
      picks = pickLotsForConsumption(material, warehouse, qtyNeeded)
      foreach picks:
        recordSalesConsumption(pick.lot, order, pick.qty)

  Result: Order #1234 buys 50g peanut
          FEFO picks lot #1 (50g) → 1 edge
          Recall lot #3 → order #1234 NOT flagged (CORRECT)
```

### T8: computeQtyFromRecipe logic

```php
private function computeQtyFromRecipe(
    Recipe $recipe,
    string $materialId,
    int $orderItemQty
): float {
    $ingredients = collect($recipe->ingredients ?? []);

    $ingredient = $ingredients->firstWhere('material_id', $materialId);
    if (! $ingredient) {
        return 0;
    }

    $ingredientQty = (float) ($ingredient['qty'] ?? 0);
    $recipeOutputQty = (float) ($recipe->output_quantity ?: 1);

    // Scale: if recipe makes 1 serving and order has 3 items → 3× ingredient
    return ($ingredientQty / $recipeOutputQty) * $orderItemQty;
}
```

---

## Summary: all schema changes

| Phase | Table | Change |
|---|---|---|
| 1 | `material_lots` | ADD `entered_quantity` DECIMAL(15,4) nullable (populated for BOTH supplier receive AND production output) |
| 1 | `material_lots` | ADD `entered_unit` VARCHAR(20) nullable (populated for BOTH supplier receive AND production output) |
| 1 | _pre-deploy guard_ | T1.7 migration aborts if any existing `material_lots` row has `unit != base unit` |
| 1 | `materials` | DROP `components` JSON (PR-3, 1 sprint after PR-2) |
| 2 | `materials` | ADD `shelf_life_days` INT nullable |

## Sequencing (critical)

```
PR-1: T1 unit conversion + T1.7 guard + T1.8 StockCount audit
        │
        ▼
PR-2: T4.2 (auto-create Recipe from components)
        ▼   ← MUST be in same PR before T3 reads Recipe
      T3 (batch reads Recipe, require approved)
        ▼
      T4.3-T4.4 (service + frontend cleanup)
        │
        ▼  (1 sprint soak)
PR-3: T4.5 DROP components column
        │
        ▼
PR-4: T2 yield validation (depends on T4 — isProduced = recipe exists)
```

T3 before T4.2 = every batch creation 422s on deploy (every material with `components` but no Recipe). T4.2 before T3 = migration creates the Recipes first, T3's "must be approved" guard passes because migration auto-approves.
