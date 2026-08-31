---
title: Service Rules
category: contributing
tags: [service, business-logic, db-transaction, workflow, audit, row-locking, crud]
summary: Defines mandatory rules for writing service classes that contain all business logic, use DB transactions for multi-step operations, and write audit logs for status changes.
related: [controller, policy, api-development]
---

# Service Rules

> This document defines **mandatory rules** for writing service classes in dxs-product. All contributors (human and AI) must follow these standards.

## Core Principles

1. **Services contain ALL business logic** -- workflow, calculations, business validation
2. **Services MUST NOT access the Request object** -- they receive `array $data` or typed parameters
3. **Services MUST NOT return HTTP responses** -- they return a Model or data
4. **All multi-step operations MUST be wrapped in `DB::transaction()`**
5. **All status changes MUST write an audit log**

---

## Template -- CRUD Service

```php
namespace App\Services\Product;

use App\Enums\ProductStatusEnum;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Product;
use App\Traits\GeneratesSku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductService
{
    use GeneratesSku;

    protected string $skuPrefix = 'PR';
    protected string $skuModel = Product::class;

    // =========================================================================
    //  Query
    // =========================================================================

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Product::query()
            ->with(['productType', 'categories'])
            ->withCount('variants');

        // Organization scope (REQUIRED)
        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        // Conditional filters — use ->when() for clean code
        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        });

        $query->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        $query->when($filters['product_type_id'] ?? null, fn ($q, $id) => $q->where('product_type_id', $id));

        // Soft deletes
        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        // Sorting
        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Product
    {
        return Product::with([
            'productType',
            'categories',
            'variants.units',
            'variants.recipe.material',
        ])->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            // 1. Auto-generate SKU
            if (empty($data['sku'])) {
                $data['sku'] = $this->generateUniqueSku(
                    additionalWhere: ['organization_id' => $data['organization_id']]
                );
            }

            // 2. Extract nested data
            $categoryIds = $data['category_ids'] ?? [];
            $variants = $data['variants'] ?? [];
            unset($data['category_ids'], $data['variants']);

            // 3. Create product
            $product = Product::create($data);

            // 4. Attach categories
            if (! empty($categoryIds)) {
                $product->categories()->attach($categoryIds);
            }

            // 5. Create variants (or default)
            if (! empty($variants)) {
                foreach ($variants as $variantData) {
                    $this->createVariant($product, $variantData);
                }
            } else {
                $this->createDefaultVariant($product);
            }

            // 6. Load and return
            return $product->load([
                'productType', 'categories', 'variants.units',
            ])->loadCount('variants');
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $categoryIds = $data['category_ids'] ?? null;
            unset($data['category_ids']);

            $product->update($data);

            if ($categoryIds !== null) {
                $product->categories()->sync($categoryIds);
            }

            return $product->load([
                'productType', 'categories', 'variants.units',
            ])->loadCount('variants');
        });
    }

    // =========================================================================
    //  Delete & Restore
    // =========================================================================

    public function delete(Product $product): bool
    {
        return DB::transaction(function () use ($product) {
            $product->variants()->delete(); // Soft delete children first
            return $product->delete();
        });
    }

    public function restore(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $product->restore();
            $product->variants()->withTrashed()
                ->where('deleted_at', '>=', $product->deleted_at)
                ->restore();

            return $product->load([
                'productType', 'categories', 'variants',
            ]);
        });
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    public function submitForApproval(Product $product): Product
    {
        $this->assertStatus($product, [
            ProductStatusEnum::Draft,
            ProductStatusEnum::Rejected,
        ], 'submit for approval');

        // Business rule: must have at least 1 variant
        if ($product->variants()->count() === 0) {
            throw new \InvalidArgumentException(
                'Product must have at least one variant before submitting.'
            );
        }

        $product->update([
            'status' => ProductStatusEnum::Pending->value,
            'rejected_by_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        $product->logAudit('submitted_for_approval');

        return $product->load(['productType', 'categories', 'variants']);
    }

    public function approve(Product $product, string $approverId): Product
    {
        $this->assertStatus($product, [ProductStatusEnum::Pending], 'approve');

        // Business rule: cannot self-approve
        if ($product->created_by_id === $approverId) {
            throw new \InvalidArgumentException(
                'Cannot approve your own product.'
            );
        }

        $product->update([
            'status' => ProductStatusEnum::Approved->value,
            'approved_by_id' => $approverId,
            'approved_at' => now(),
            'rejected_by_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        $product->logAudit('approved', ['approved_by_id' => $approverId]);

        return $product->load(['productType', 'categories', 'variants']);
    }

    public function reject(Product $product, string $rejectedById, string $reason): Product
    {
        $this->assertStatus($product, [ProductStatusEnum::Pending], 'reject');

        $product->update([
            'status' => ProductStatusEnum::Rejected->value,
            'rejected_by_id' => $rejectedById,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $product->logAudit('rejected', [
            'rejected_by_id' => $rejectedById,
            'rejection_reason' => $reason,
        ]);

        return $product->load(['productType', 'categories', 'variants']);
    }

    public function activate(Product $product): Product
    {
        $this->assertStatus($product, [
            ProductStatusEnum::Approved,
            ProductStatusEnum::Inactive,
        ], 'activate');

        $product->update(['status' => ProductStatusEnum::Active->value]);
        $product->logAudit('activated');

        return $product->load(['productType', 'categories', 'variants']);
    }

    public function deactivate(Product $product): Product
    {
        $this->assertStatus($product, [ProductStatusEnum::Active], 'deactivate');

        $product->update(['status' => ProductStatusEnum::Inactive->value]);
        $product->logAudit('deactivated');

        return $product->load(['productType', 'categories', 'variants']);
    }

    // =========================================================================
    //  Dropdown
    // =========================================================================

    /**
     * @return array<int, array{id: string, name: string, sku: string}>
     */
    public function dropdown(string $organizationId, array $includeIds = []): array
    {
        $query = Product::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->select(['id', 'name', 'sku']);

        if (! empty($includeIds)) {
            $query->orWhereIn('id', $includeIds);
        }

        return $query->orderBy('name')->get()->toArray();
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    /**
     * Assert product is in one of the allowed statuses.
     *
     * @param  ProductStatusEnum[]  $allowedStatuses
     */
    private function assertStatus(Product $product, array $allowedStatuses, string $action): void
    {
        $allowedValues = array_map(fn ($s) => $s->value, $allowedStatuses);

        if (! in_array($product->status, $allowedValues, true)) {
            throw new InvalidStatusTransitionException(
                "Cannot {$action}: product status is '{$product->status}', "
                .'expected one of: '.implode(', ', $allowedValues)
            );
        }
    }

    private function createVariant(Product $product, array $data): void
    {
        // ... variant creation with cost calculation
    }

    private function createDefaultVariant(Product $product): void
    {
        // ... create default variant using product name/sku
    }
}
```

---

## Rules

### 1. Constructor -- Dependency Injection

```php
// ✅ Correct — inject other services as needed
public function __construct(
    private readonly StockLevelService $stockLevelService,
    private readonly WarehouseService $warehouseService,
) {}

// ❌ Wrong — inject Request, Controller, or HTTP concerns
public function __construct(
    private readonly Request $request,         // NEVER
    private readonly ProductController $ctrl,  // NEVER
) {}
```

### 2. List Method Pattern

```php
public function list(array $filters = []): LengthAwarePaginator
{
    $query = Model::query()
        ->with([...])           // Eager load to prevent N+1
        ->withCount([...]);     // Counts for display

    // 1. Organization scope (REQUIRED)
    $query->where('organization_id', $filters['organization_id']);

    // 2. Conditional filters with ->when()
    $query->when($filters['search'] ?? null, fn ($q, $s) => ...);
    $query->when($filters['status'] ?? null, fn ($q, $s) => ...);

    // 3. Soft deletes
    if (! empty($filters['with_trashed'])) {
        $query->withTrashed();
    }

    // 4. Sorting
    // ...

    // 5. Paginate
    return $query->paginate($filters['per_page'] ?? 25);
}
```

### 3. Create/Update -- DB Transaction

**REQUIRED** -- use `DB::transaction()` when:
- Creating a model with relations (categories, variants, items)
- Updating a model and syncing relations
- Workflow actions with side effects (stock changes)

```php
public function create(array $data): Product
{
    return DB::transaction(function () use ($data) {
        // All DB operations here are atomic
        $product = Product::create($data);
        $product->categories()->attach($categoryIds);
        return $product->load([...]);
    });
}
```

### 4. Workflow Methods -- Status Assertion

**REQUIRED** -- validate status before transitioning:

```php
public function approve(Model $model, string $approverId): Model
{
    // 1. Assert current status
    $this->assertStatus($model, [StatusEnum::Pending], 'approve');

    // 2. Business rules validation
    if ($model->created_by_id === $approverId) {
        throw new \InvalidArgumentException('Cannot self-approve.');
    }

    // 3. Update status + audit fields
    $model->update([
        'status' => StatusEnum::Approved->value,
        'approved_by_id' => $approverId,
        'approved_at' => now(),
    ]);

    // 4. Audit log
    $model->logAudit('approved', ['approved_by_id' => $approverId]);

    // 5. Return loaded model
    return $model->load([...]);
}
```

### 5. Audit Logging

**REQUIRED** -- call `$model->logAudit()` for:
- Workflow state changes (approved, rejected, submitted, activated, ...)
- Custom actions (synced, cloned, ...)

```php
$product->logAudit('approved', ['approved_by_id' => $approverId]);
$product->logAudit('rejected', ['rejection_reason' => $reason]);
$menu->logAudit('synced_from_master', ['items_added' => $count]);
```

Audit logging is NOT needed for standard CRUD (create/update/delete) -- the `AuditsActivity` trait handles those automatically.

### 6. Return Values

**REQUIRED** -- return the loaded model with key relations:

```php
// ✅ Correct — load relations before returning
return $product->load(['productType', 'categories', 'variants.units']);

// ✅ Correct — fresh() if the model is stale
return $transaction->fresh()->load(['items', 'warehouse']);

// ❌ Wrong — returning model without loading relations
return $product;

// ❌ Wrong — returning array/JSON
return ['id' => $product->id, 'name' => $product->name];
```

### 7. Error Handling

Use exceptions for business rule violations:

```php
// Custom exception (422)
throw new InvalidStatusTransitionException('Cannot approve: status is draft');

// Insufficient stock (422)
throw new InsufficientStockException($warehouseId, $shortages);

// Circular reference (422)
throw new CircularReferenceException('Material A → B → C → A');

// Standard Laravel (422)
throw new \InvalidArgumentException('Product must have at least one variant.');
```

**MUST NOT return error responses from a service:**

```php
// ❌ Wrong
return ['error' => 'Cannot approve', 'status' => 422];

// ❌ Wrong
return response()->json(['message' => 'Error'], 422);
```

### 8. Stock Operations -- Row Locking

**REQUIRED** -- use `lockForUpdate()` when modifying stock levels:

```php
public function adjustQuantity(
    StockLevel $stockLevel,
    float $delta,
    StockTransaction $transaction,
    bool $allowNegative = false,
): StockMovement {
    return DB::transaction(function () use (...) {
        // 1. Lock the row
        $locked = StockLevel::lockForUpdate()->find($stockLevel->id);

        // 2. Calculate new quantity
        $before = (float) $locked->quantity;
        $after = $before + $delta;

        // 3. Validate
        if ($after < 0 && ! $allowNegative) {
            throw new InsufficientStockException(...);
        }

        // 4. Update
        $locked->update(['quantity' => $after]);

        // 5. Create movement record
        return StockMovement::create([
            'warehouse_id' => $locked->warehouse_id,
            'product_sku_id' => $locked->product_sku_id,
            'material_id' => $locked->material_id,
            'stock_transaction_id' => $transaction->id,
            'movement_type' => $delta > 0 ? 'in' : 'out',
            'quantity' => abs($delta),
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit' => $locked->unit,
        ]);
    });
}
```

### 9. Read-validate-write -- lock every row you validated

Rule 3 says *when* to open a transaction; this says *what to lock inside it*.
`DB::transaction()` alone does **not** stop another transaction from reading the
same row before your write commits. Any method that reads a shared resource,
checks a condition, then writes is a **read-validate-write cycle** — without a
lock, two concurrent requests both pass the check and both write.

State-transition methods this applies to (not exhaustive): table
assignment/merge/unmerge, order status changes, void/cancel, checkout and payment
recording, stock transfer/adjustment.

```php
public function mergeTable(CustomerOrder $order, Table $target): CustomerOrder
{
    return DB::transaction(function () use ($order, $target) {
        // Lock BOTH rows before validating — not just the one you write to
        $order  = CustomerOrder::lockForUpdate()->findOrFail($order->id);
        $target = Table::lockForUpdate()->findOrFail($target->id);

        if ($target->status === TableStatus::Occupied) {
            throw new BusinessException('Table is already occupied.');
        }

        $order->tables()->syncWithoutDetaching([$target->id]);
        $target->update(['status' => TableStatus::Occupied]);

        return $order->load('tables');
    });
}
```

Lock child rows too when a parent's derived state depends on them (e.g. lock an
order's tables when validating the order total).

> **Note:** `lockForUpdate()` is `SELECT ... FOR UPDATE` in MySQL. It only works
> inside a transaction — calling it outside one is a silent no-op.

### 10. Eager-load every relation the Resource serializes

`whenLoaded()` returns a relation **only if it was eager-loaded**. That makes the
Resource safe from N+1, but it also means a missing `with()` produces **silent
data loss** rather than a slow query: the field just serializes empty and the
client never sees it.

The corollary: if a Resource references `$this->whenLoaded('tables')`, every
service method feeding that Resource must list `tables` in its `with([...])`.

```php
// Resource
'tables' => TableResource::collection($this->whenLoaded('tables')),

// Service — must match
->with(['customer', 'tables:id,code,name,status'])
```

**How to audit:** grep every `whenLoaded(...)` in a Resource and confirm each
service method that returns it eager-loads the same relation.

For display-only relations, name the columns (`tables:id,code,name,status`) —
less memory, and no accidental serialization of large or sensitive columns.

### 11. Never assume a `*_by_id` audit column is non-null

`created_by_id` and friends are `nullable()`: rows created before the column
existed, and rows created by device-token requests (no SSO user), both carry
`NULL`. Always use a fallback chain:

```php
$createdById = $order->created_by_id
    ?? auth()->id()
    ?? $order->customer_id;
```

Apply this to every `*_by_id` column that is `nullable()` in its migration.

---

## Review checklist

Before merging any service change:

- [ ] Every read-validate-write method is wrapped in `DB::transaction()`.
- [ ] Every row read inside a transaction that participates in a validation check uses `lockForUpdate()`.
- [ ] Every relation referenced by a Resource via `whenLoaded()` is in the service's `with([...])` list.
- [ ] Display-only eager-loads specify a column list.
- [ ] No service method reads a nullable `*_by_id` column without a fallback.
- [ ] Every query is scoped by `organization_id`.
- [ ] Workflow methods assert status before transitioning and write an audit log.
- [ ] New service methods have a Pest feature test covering the happy path and at least one failure path.

---

## Anti-patterns

```php
// ❌ Accessing Request in a service
public function create(Request $request) { ... }

// ❌ Returning HTTP response from a service
public function approve($model) {
    return response()->json(['message' => 'Approved']);
}

// ❌ Not wrapping in a transaction
public function create(array $data) {
    $product = Product::create($data);
    $product->categories()->attach($ids); // If this fails → product is created but has no categories
}

// ❌ Not validating status before transition
public function approve($model) {
    $model->update(['status' => 'approved']); // Could approve a draft or cancelled item!
}

// ❌ Query without organization scope
public function list() {
    return Product::paginate(); // Returns data from all organizations!
}
```
