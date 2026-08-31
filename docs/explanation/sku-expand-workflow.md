---
title: SKU expand workflow
category: explanation
tags: [sku, product, option, expand]
summary: "What the product options expand endpoint must do when a new attribute is added to a product that already has SKUs, and the gaps found in the current implementation."
related: [hq-catalog-menu-review]
---

# SKU expand workflow — analysis and fix plan

## Background

A product has at most 3 attributes (options), and each attribute has several
option values. A SKU is a combination of those option values. When a user adds a
new attribute to a product that already has SKUs, the endpoint
`POST /hq/{brand}/products/{product}/options/expand` is responsible for expanding
the SKU structure.

---

## Current architecture

### Data relations

```
Product
  └──< ProductOption (position 1/2/3)
              └──< ProductOptionValue
                          ▲
                          │ FK option_value{N}_id (onDelete: RESTRICT)
                          │
                    ProductSku
                          │
                          │ FK product_sku_id (onDelete: RESTRICT)
                          │
                    CustomerOrderItem
                          ├── unit_price       ← snapshot ✅
                          ├── subtotal         ← snapshot ✅
                          └── (option labels)  ← NOT snapshotted ⚠️

Menu (branch)
  └──< MenuProduct (1 row per product/section)
              └──< MenuProductSku ──► ProductSku
                          ├── selling_price        (copied from the SKU at clone time, can be overridden)
                          ├── is_price_overridden
                          └── is_active            (per-SKU branch toggle)

Menu (master)
  └──< MenuProduct
              └── (NO MenuProductSku — a master menu is a template)
```

### Unique constraints and soft delete (verified)

| Table | Unique | Soft delete |
|---|---|---|
| `product_skus` | `(product_id, option_signature)` | ✅ has `deleted_at` |
| `menu_product_skus` | `(menu_product_id, product_sku_id)` | ✅ has `deleted_at` |

**Note**: both tables have soft delete, and the unique constraint **does not
include `deleted_at`** — which means soft-deleted rows still occupy the unique
slot. A plain `firstOrCreate()` skips soft-deleted rows when searching, which then
causes a unique constraint violation on insert. `withTrashed()` must be handled
manually.

---

## Current flow of `expandOption()`

**Endpoint**: `POST /hq/{brand}/products/{product}/options/expand`

```
BEGIN TRANSACTION

  Step 1 — Validate position and key uniqueness

  Step 2 — Create the new ProductOption

  Step 3 — Create a ProductOptionValue for each submitted value

  Step 4 — Update existing SKUs (modify in place)
    - Query: ProductSku WHERE product_id = ? AND option_value{N}_id IS NULL
    - Assign: sku.option_value{N}_id = default_value.id
    - Recompute option_signature
    - Handle duplicate signatures: set is_active=false, suffix "__dup_{id}"
    - saveQuietly() — bypass the observer to avoid a double compute

  Step 5 — generateMissingCombinations()
    - Compute the Cartesian product of all option values
    - Fetch existing signatures (withTrashed) to skip
    - Create new SKUs for the missing combinations
    - Limit: at most 500 combinations

COMMIT
```

**Worked example** (2 attributes × 2 values, then attribute C is added):

| Before expand | After expand (current) |
|---|---|
| SKU [A1/B1] price=100k | SKU [A1/B1/**C1**] price=100k (modified in place) |
| SKU [A1/B2] price=120k | SKU [A1/B2/**C1**] price=120k (modified in place) |
| SKU [A2/B1] price=90k  | SKU [A2/B1/**C1**] price=90k  (modified in place) |
| SKU [A2/B2] price=110k | SKU [A2/B2/**C1**] price=110k (modified in place) |
|                         | SKU [A1/B1/C2] price=0 **(newly created)** |
|                         | SKU [A1/B2/C2] price=0 **(newly created)** |
|                         | SKU [A2/B1/C2] price=0 **(newly created)** |
|                         | SKU [A2/B2/C2] price=0 **(newly created)** |

---

## Identified bugs

### Bug 1 — new SKUs have no MenuProductSku (the main bug)

After step 5 the new SKUs (the C2 combinations) exist but have **no corresponding
`MenuProductSku`** in any branch menu. The consequence: the branch menu is missing
the C2 combinations entirely, and the user cannot sell them.

```
Branch menu after expand:
  ✅ MenuProductSku → SKU [A1/B1/C1]  price=100k (unchanged, same SKU ID)
  ✅ MenuProductSku → SKU [A1/B2/C1]  price=120k
  ✅ MenuProductSku → SKU [A2/B1/C1]  price=90k
  ✅ MenuProductSku → SKU [A2/B2/C1]  price=110k
  ❌ (missing) SKU [A1/B1/C2] — no MenuProductSku
  ❌ (missing) SKU [A1/B2/C2] — no MenuProductSku
  ❌ (missing) SKU [A2/B1/C2] — no MenuProductSku
  ❌ (missing) SKU [A2/B2/C2] — no MenuProductSku
```

### Bug 2 — withTrashed() skips soft-deleted combinations

In `generateMissingCombinations()`, the existence check on a signature uses
`withTrashed()`:

```php
$existing = ProductSku::withTrashed()
    ->where('product_id', $product->id)
    ->pluck('option_signature')
    ->all();

if (in_array($signature, $existing, true)) {
    continue; // ← skipped outright: not created, not warned about
}
```

If the user previously soft-deleted SKU [A1/B1/C1], that combination is skipped
forever and is never recreated.

---

## Considered and rejected — an `is_legacy` flag

**The idea**: instead of modifying SKUs in place, set `is_legacy = true` and
create an entirely new set of SKUs with price = 0.

**Why it was rejected**: `MenuProductSku.product_sku_id` is a direct FK to
`ProductSku.id`. Once the old SKUs become legacy:

- **Do nothing to the menu** → the branch menu still points at the legacy SKUs and
  never shows the new ones. Unusable.
- **Fix up the menu** → the old `MenuProductSku` rows must be deactivated and new
  ones created → **the branch price overrides are lost entirely**. Unacceptable.

The only benefit (an audit trail) does not outweigh the cost and the risk.

---

## The chosen fix plan

**Principle**: keep step 4 (modify in place). Fix only two specific points.

---

### Fix 1 — sync MenuProductSku for the new SKUs

**Where**: `ProductOptionController::expand()` — after `expandOption()` returns,
inside the same transaction.

**Logic**:
1. Fetch every `MenuProduct` that references this product and belongs to a
   **non-master menu**.
2. For each `MenuProduct`, process each new SKU using `withTrashed()` to avoid a
   unique violation.
3. If the `MenuProductSku` is soft-deleted → restore it (keeping any previous
   price override).
4. If it does not exist → create it with `selling_price = 0` and
   `is_active = false`.

```php
// ProductOptionController::expand()
return DB::transaction(function () use (...) {
    $result = $this->service->expandOption($data, $skuService);

    if ($result['created_skus']->isNotEmpty()) {
        $this->menuService->syncNewSkusToMenuBranches(
            $data['product_id'],
            $result['created_skus']
        );
    }

    return response()->json(['data' => $result], 201);
});
```

```php
// MenuService::syncNewSkusToMenuBranches()
public function syncNewSkusToMenuBranches(string $productId, Collection $newSkus): void
{
    // If the product is not in any menu yet, $menuProducts is empty, the foreach
    // never runs, and this is a safe no-op.
    $menuProducts = MenuProduct::where('product_id', $productId)
        ->whereHas('menu', fn($q) => $q->where('is_master', false))
        ->get();

    foreach ($menuProducts as $menuProduct) {
        foreach ($newSkus as $sku) {
            // menu_product_skus has soft delete plus a unique
            // (menu_product_id, product_sku_id) that excludes deleted_at,
            // so withTrashed() must be checked manually.
            $existing = $menuProduct->menuProductSkus()
                ->withTrashed()
                ->where('product_sku_id', $sku->id)
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore(); // keeps the old selling_price and is_price_overridden
                }
                // else: already active → do nothing
            } else {
                $menuProduct->menuProductSkus()->create([
                    'product_sku_id'      => $sku->id,
                    'selling_price'       => 0,
                    'is_price_overridden' => false,
                    'is_active'           => false,
                ]);
            }
        }
    }
}
```

**Result after the fix**:
```
Branch menu after expand:
  ✅ MenuProductSku → SKU [A1/B1/C1]  price=100k (unchanged, same SKU ID)
  ✅ MenuProductSku → SKU [A1/B2/C1]  price=120k
  ✅ MenuProductSku → SKU [A2/B1/C1]  price=90k
  ✅ MenuProductSku → SKU [A2/B2/C1]  price=110k
  ✅ MenuProductSku → SKU [A1/B1/C2]  price=0, is_active=false  ← new
  ✅ MenuProductSku → SKU [A1/B2/C2]  price=0, is_active=false  ← new
  ✅ MenuProductSku → SKU [A2/B1/C2]  price=0, is_active=false  ← new
  ✅ MenuProductSku → SKU [A2/B2/C2]  price=0, is_active=false  ← new
```

---

### Fix 2 — restore a soft-deleted SKU instead of skipping it

**Where**: `ProductSkuService::generateMissingCombinations()`

**Logic**: split the query into two groups — active and soft-deleted. A signature
that matches a deleted row is restored; a signature that matches nothing is
created.

**`is_active` on restore**: keep the previous state; do **not** force
`is_active = true`. `generateMissingCombinations` only needs the SKU to exist in
the database — it does not need to be active. The branch turns it on through the
MenuProductSku flow. If the user deliberately deactivated that SKU, their decision
should not be overridden.

**`restoreQuietly()` rather than `restore()`**: `option_signature` was already
correct before the delete, so the observer does not need to recompute it on
restore. Use `restoreQuietly()` where the Laravel version supports it, and fall
back to `Model::withoutObservers(fn() => $sku->restore())`.

```php
// Before (buggy)
$existing = ProductSku::withTrashed()
    ->where('product_id', $product->id)
    ->pluck('option_signature')
    ->all();

foreach ($cartesian as $combo) {
    if (in_array($signature, $existing, true)) {
        continue; // skipped, even when deleted
    }
    $created->push($this->create([...]));
}
```

```php
// After (fixed)
$activeSignatures = ProductSku::where('product_id', $product->id)
    ->pluck('option_signature')->flip()->all();

$deletedBySignature = ProductSku::onlyTrashed()
    ->where('product_id', $product->id)
    ->get()->keyBy('option_signature');

foreach ($cartesian as $combo) {
    // ...compute $signature...

    if (isset($activeSignatures[$signature])) {
        continue; // an active one already exists → skip
    }

    if (isset($deletedBySignature[$signature])) {
        // restore instead of skipping — keep the old is_active, do not override
        $restoredSku = $deletedBySignature[$signature];
        $restoredSku->restoreQuietly(); // the signature is already correct; no observer needed
        $created->push($restoredSku->fresh());
        continue;
    }

    $created->push($this->create([...])); // create normally
}
```

**Note**: the `withTrashed()` inside `ProductSkuService::create()` stays as it is,
as a safety net for other callers.

---

## Identified edge cases

### Order history during an expand

`CustomerOrderItem` stores `unit_price` as a snapshot — that is safe. However it
**does not snapshot the option labels**. When SKU [A1/B1] is modified in place
into [A1/B1/C1], older orders display the new combination once the option values
are join-loaded.

| Field | Impact |
|---|---|
| `unit_price` | ✅ Safe — it is a snapshot |
| Option labels in order history | ⚠️ Shows the new combination [A1/B1/C1] instead of [A1/B1] |

`customer_order_items.product_sku_id` already has both
`$table->index('product_sku_id')` and a composite
`(customer_order_id, product_sku_id)`, so the `whereHas` query performs well.

**Proposed handling**: check `has_order_history` **before step 3** (before any
mutation happens), store it in a local variable, and return it at the end:

```php
// At the top of expandOption(), before step 3
$hasOrderHistory = ProductSku::where('product_id', $productId)
    ->whereHas('customerOrderItems')
    ->exists();

// ... all of steps 3, 4, 5 ...

return [
    'option'            => $option->load('values'),
    'updated_skus'      => $updatedCount,
    'created_skus'      => $createdSkus,
    'has_order_history' => $hasOrderHistory,
];
```

**A separate debt** (out of scope for this fix): if audit-proof history is
required, `CustomerOrderItem` needs `sku_name_snapshot` and
`option_labels_snapshot`.

### Restoring a SKU when a MenuProductSku already exists

When a `ProductSku` is soft-deleted, its `MenuProductSku` records are **not
cascade soft-deleted** (Laravel soft delete does not trigger a DB FK cascade).
So when the SKU is restored, the old `MenuProductSku` is still there with its
branch price override intact.

Fix 1 uses `withTrashed()->first()`, so if a `MenuProductSku` already exists
(active or deleted) it neither creates a new one nor overwrites the old price.
Safe.

---

## Ordering inside the transaction

```
BEGIN TRANSACTION (controller level)

  BEGIN SAVEPOINT (expandOption)
    1. Validate position, key uniqueness
    2. Snapshot has_order_history (before any mutation)
    3. Create the ProductOption + ProductOptionValues
    4. Step 4: modify existing SKUs in place (assign the default value FK, recompute the signature)
    5. Step 5: generateMissingCombinations()
       - Active signatures → skip
       - Deleted signatures → restoreQuietly() (keep the old is_active)
       - Missing → create
    6. Return {option, updated_skus, created_skus, has_order_history}
  RELEASE SAVEPOINT

  7. syncNewSkusToMenuBranches() — if created_skus.isNotEmpty()
     - Filter to MenuProducts in non-master menus
     - withTrashed() check on each (menu_product_id, product_sku_id):
       - Trashed → restore
       - Absent → create (is_active=false, selling_price=0)
       - Active → skip

COMMIT
```

---

## Test cases to add

### ProductOptionExpandTest.php

```
Case 1 — new SKUs are synced into the branch menu after an expand
  Setup:   A product with one option [S, M] → 2 SKUs, already added to one branch menu
  Action:  Expand with a Color option [Red (default), Blue]
  Assert:  The branch menu has all 4 MenuProductSku rows: S/Red, M/Red (prices kept), S/Blue, M/Blue (price=0, is_active=false)

Case 2 — no MenuProductSku is created for a master menu
  Setup:   The product is already in a master menu
  Action:  Expand with a new option
  Assert:  The master menu's menuProductSkus count is unchanged

Case 3 — a product in no menu at all expands without error
  Setup:   The product belongs to no menu
  Action:  Expand with a new option
  Assert:  Response 201, no exception, created_skus holds every combination

Case 4 — expanding twice in a row does not duplicate MenuProductSku rows
  Setup:   The product is in a branch menu and has been expanded once
  Action:  Expand a second time (adding a third option)
  Assert:  The MenuProductSku count is correct, with no duplicates and no exception

Case 5 — a soft-deleted SKU is restored instead of skipped
  Setup:   The product has a SKU [A/B] that was previously soft-deleted
  Action:  generateMissingCombinations()
  Assert:  SKU [A/B] is restored (deleted_at = null) and appears in created_skus

Case 6 — a soft-deleted MenuProductSku is restored when the SKU is expanded
  Setup:   The branch menu has a soft-deleted MenuProductSku for SKU X
  Action:  An expand produces SKU X (restored by Fix 2)
  Assert:  The MenuProductSku is restored (not recreated) and its old selling_price is intact
```

---

## Files that need to change

| File | Kind of change |
|---|---|
| `app/Http/Controllers/Api/V1/HQ/ProductOptionController.php` | Wrap expand + sync in `DB::transaction()`, inject `MenuService` |
| `app/Services/Product/ProductSkuService.php` | Fix `generateMissingCombinations()` — restore instead of skip, use `restoreQuietly()` |
| `app/Services/Product/MenuService.php` | Add `syncNewSkusToMenuBranches()` with `withTrashed()` handling |
| `app/Services/Product/ProductOptionService.php` | Check `has_order_history` before step 3 and add it to the return value |
| `tests/Feature/Product/ProductOptionExpandTest.php` | Add the 6 test cases above |

---

## Checklist before implementing

- [x] ~~Confirm: does `MenuProductSku` have soft delete?~~

  **Verified** — it has `deleted_at`. The unique
  `(menu_product_id, product_sku_id)` does not include `deleted_at`, so
  soft-deleted rows still occupy the slot → **`withTrashed()` must be used
  manually** in `syncNewSkusToMenuBranches()`, not a plain `firstOrCreate()`.

- [ ] Confirm: does soft-deleting a `MenuProduct` cascade soft delete to
  `MenuProductSku`?

  **Low impact — Fix 1 covers both cases.**

  `syncNewSkusToMenuBranches()` queries `MenuProduct` without `withTrashed()` (it
  only takes active MenuProducts), so a soft-deleted MenuProduct is not in the
  loop at all — cascade or no cascade makes no difference to this flow.

  The only case worth considering: an **active** MenuProduct whose MenuProductSku
  rows were cascade soft-deleted by some earlier operation. When Fix 1 runs
  `withTrashed()->first()` to look for the old row, it finds it and restores it
  instead of creating a new one — the old selling_price is intact. That is the
  correct behaviour (see Case 6 in the test cases).

  If there is **no** cascade: the MenuProductSku exists normally,
  `withTrashed()->first()` still finds it and skips. The outcome is identical.

  → Worth confirming to complete the documentation, but not blocking the
  implementation.

- [ ] Confirm: should `syncNewSkusToMenuBranches` live in `MenuService` or in its
  own service?

- [ ] Confirm: is `is_active = false` for newly created `MenuProductSku` rows the
  agreed business behaviour?

- [ ] Confirm: does the frontend show a warning when `has_order_history = true`,
  or is it only kept in reserve?

- [ ] Confirm: does the transaction inside `expandOption()` stay, or is it removed
  once the controller wraps it?

  - **Keep both layers** (recommended): `expandOption()` stays transactional on
    its own; `syncNewSkusToMenuBranches` sits outside the savepoint but inside the
    controller's transaction, so a sync failure rolls everything back.
  - **Remove the inner one**: loses independence and increases coupling — not
    advised.

- [x] ~~Verify: does `expandOption()` already return `created_skus`?~~

  **It does**, at lines 297-301. Zero cost.

- [x] ~~Verify: does `expandOption()`'s `created_skus` include the SKUs restored by
  Fix 2?~~

  **Guaranteed by Fix 2**: `$created->push($restoredSku->fresh())` before the
  `continue` makes the chain `generateMissingCombinations() → expandOption() →
  controller` behave correctly. If `generate_combinations = false`, then
  `$createdSkus = collect()` and the `isNotEmpty()` guard in the controller keeps
  the sync from running.

- [x] ~~Confirm: is an index needed on `customer_order_items.product_sku_id`?~~

  **Already present** — `$table->index('product_sku_id')` and
  `$table->index(['customer_order_id', 'product_sku_id'])`.
