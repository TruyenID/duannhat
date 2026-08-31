---
title: Controller Rules
category: contributing
tags: [controller, trait, authorization, crud, workflow, organization-context, bulk-operations]
summary: Defines mandatory rules for writing thin controllers that only authorize, delegate to services, and return responses, including traits for organization context and bulk operations.
related: [service, policy, route, api-development]
---

# Controller Rules

> This document defines **mandatory rules** for writing controllers in dxs-product. All contributors (human and AI) must follow these standards.

## Core Principles

1. **Controllers are THIN** -- they only do 3 things: authorize, delegate, respond
2. **MUST NOT contain business logic** -- no queries, no calculations, no business conditionals
3. **MUST NOT access the DB directly** -- everything goes through a Service
4. **Every method MUST have Policy authorization**
5. **The same Service is reused across scopes** -- a brand-scoped controller and a shop-scoped controller call the same `ProductService` / `MenuService` / etc., differing only in the `brand_id` or `branch_id` filter they pass in. Business logic for a domain lives in **one** service, regardless of which URL prefix the request came in on.

---

## Base Controller

```php
// app/Http/Controllers/Controller.php
namespace App\Http\Controllers;

abstract class Controller
{
    // Intentionally empty — logic lives in traits
}
```

---

## Controller Traits

### HasOrganizationContext

**REQUIRED** for every controller. Provides the organization-level guard rail (`organization_id` derived from the authenticated user). It is the second of three scoping inputs a controller needs — the other two (`brand_id`, `shop_id`) come from the resolve middleware, see [Scoping from resolved attributes](#scoping-from-resolved-attributes).

```php
namespace App\Http\Controllers\Traits;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

trait HasOrganizationContext
{
    protected function getOrganizationId(): string
    {
        $user = request()->user();

        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $orgId = $user->console_organization_id;

        if (! $orgId) {
            abort(400, 'No organization assigned');
        }

        return $orgId;
    }

    protected function authorizeOrganization(Model $model): void
    {
        if ($model->organization_id !== $this->getOrganizationId()) {
            abort(403, 'Resource does not belong to your organization');
        }
    }
}
```

### HasBulkOperations

Optional -- use for controllers that need bulk delete.

```php
namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait HasBulkOperations
{
    abstract protected function getModelClass(): string;

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $modelClass = $this->getModelClass();
        $orgId = $this->getOrganizationId();
        $deleted = 0;
        $errors = [];

        foreach ($request->input('ids') as $id) {
            $model = $modelClass::where('organization_id', $orgId)->find($id);

            if (! $model) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];
                continue;
            }

            try {
                $this->authorize('delete', $model);
                $model->delete();
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => "{$deleted} items deleted.",
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }
}
```

---

## Scoping from resolved attributes

Every API route in the application is mounted under either `hq/{brandSlug}` or `shops/{shopSlug}`. Before any controller method runs, `ResolveBrandFromSlug` or `ResolveShopFromSlug` has already loaded the scope model and stored it on `$request->attributes`. Controllers MUST read those attributes through small private helpers — they MUST NOT call `Brand::where('slug', ...)` or `Branch::where('slug', ...)` themselves. See [Route Rules](route.md#resolve-middleware) for what each middleware writes.

### What each middleware leaves on the request

| Route prefix              | Middleware              | Attributes available                              |
| ------------------------- | ----------------------- | ------------------------------------------------- |
| `hq/{brandSlug}/...`  | `ResolveBrandFromSlug`  | `brand` (Brand model), `brand_id`                 |
| `shops/{shopSlug}/...`    | `ResolveShopFromSlug`   | `shop` (Branch model), `shop_id`, `brand_id`      |

### Standard helper pattern

Every brand-scoped or shop-scoped controller declares the same private helpers at the bottom of the file:

```php
// app/Http/Controllers/Api/V1/Shop/TableController.php

private function resolvedShop(Request $request): Branch
{
    /** @var Branch $shop */
    $shop = $request->attributes->get('shop');

    return $shop;
}

private function resolvedShopId(Request $request): string
{
    return $request->attributes->get('shop_id');
}

private function resolveTable(Request $request): Table
{
    $shop = $this->resolvedShop($request);
    $id = (string) $request->route('table');

    return Table::where('organization_id', $this->getOrganizationId())
        ->where('branch_id', $shop->id)
        ->findOrFail($id);
}
```

Brand-scoped controllers follow the mirror pattern with `resolvedBrand()` / `resolvedBrandId()`.

### Passing scope to the service

Controllers stay thin by reading the resolved IDs and passing them as **plain filter values** to the service. The service does not know — and does not care — whether those IDs came from a brand prefix, a shop prefix, or anything else.

```php
// shop-scoped controller
public function index(Request $request): AnonymousResourceCollection
{
    $this->authorize('viewAny', Table::class);

    $tables = $this->service->list([
        'branch_id' => $this->resolvedShopId($request),   // ← resolved by middleware
        'zone_id'   => $request->input('zone_id'),
        'status'    => $request->input('status'),
        'per_page'  => min($request->integer('per_page', 25), 100),
    ]);

    return TableResource::collection($tables);
}
```

```php
// brand-scoped controller — same shape, different scope key
public function index(Request $request): AnonymousResourceCollection
{
    $this->authorize('viewAny', Product::class);

    $products = $this->productService->list([
        'organization_id' => $this->getOrganizationId(),
        'brand_id'        => $request->attributes->get('brand_id'), // ← resolved by middleware
        'search'          => $request->input('search'),
        'status'          => $request->input('status'),
        'per_page'        => min($request->integer('per_page', 25), 100),
    ]);

    return ProductResource::collection($products);
}
```

### Why this matters

Because the controller passes `brand_id` / `branch_id` as plain filters, **one service can be wired under multiple route prefixes**. If a future requirement adds shop-level product overrides under `/api/v1/shops/{shopSlug}/products`, the existing `ProductService` is reused — only a new thin controller is needed. Adding business logic into the controller (instead of the service) breaks this property and forces the logic to be re-implemented for every prefix.

Anti-patterns:

```php
// ❌ Re-resolving the slug in the controller
public function index(Request $request, string $shopSlug)
{
    $shop = Branch::where('slug', $shopSlug)->firstOrFail(); // middleware already did this
    // ...
}

// ❌ Reading the slug from the URL instead of the attribute
$shopId = Branch::where('slug', $request->route('shopSlug'))->value('id');

// ❌ Forking business logic per prefix
public function index(Request $request)
{
    if ($request->route('brandSlug')) {
        // brand-specific query
    } else {
        // shop-specific query
    }
}
```

---

## CRUD Controller Template

Every resource controller MUST follow this template:

```php
namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Product\ProductService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;
    use HasBulkOperations;

    public function __construct(
        private readonly ProductService $productService
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $products = $this->productService->list([
            'organization_id' => $this->getOrganizationId(),
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'per_page' => $request->input('per_page', 25),
            // ... other filters
        ]);

        return ProductResource::collection($products);
    }

    public function store(ProductStoreRequest $request): ProductResource
    {
        $this->authorize('create', Product::class);

        $product = $this->productService->create($request->validated());

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorizeOrganization($product);
        $this->authorize('view', $product);

        $product = $this->productService->findById($product->id);

        return new ProductResource($product);
    }

    public function update(ProductUpdateRequest $request, Product $product): ProductResource
    {
        $this->authorizeOrganization($product);
        $this->authorize('update', $product);

        $product = $this->productService->update($product, $request->validated());

        return new ProductResource($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorizeOrganization($product);
        $this->authorize('delete', $product);

        $this->productService->delete($product);

        return response()->json(null, 204);
    }

    public function restore(string $id): ProductResource
    {
        $product = Product::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->findOrFail($id);

        $this->authorize('restore', $product);

        $product = $this->productService->restore($product);

        return new ProductResource($product);
    }

    // =========================================================================
    //  Dropdowns
    // =========================================================================

    public function dropdown(Request $request): JsonResponse
    {
        $items = $this->productService->dropdown(
            organizationId: $this->getOrganizationId(),
            includeIds: $request->input('include_ids', []),
        );

        return response()->json(['data' => $items]);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    public function submitForApproval(Product $product): ProductResource
    {
        $this->authorizeOrganization($product);
        $this->authorize('submitForApproval', $product);

        $product = $this->productService->submitForApproval($product);

        return new ProductResource($product);
    }

    public function approve(Product $product): ProductResource
    {
        $this->authorizeOrganization($product);
        $this->authorize('approve', $product);

        $product = $this->productService->approve(
            product: $product,
            approverId: request()->user()->id,
        );

        return new ProductResource($product);
    }

    public function reject(ProductRejectRequest $request, Product $product): ProductResource
    {
        $this->authorizeOrganization($product);
        $this->authorize('reject', $product);

        $product = $this->productService->reject(
            product: $product,
            rejectedById: request()->user()->id,
            reason: $request->validated('rejection_reason'),
        );

        return new ProductResource($product);
    }

    public function activate(Product $product): ProductResource
    {
        $this->authorizeOrganization($product);
        $this->authorize('activate', $product);

        $product = $this->productService->activate($product);

        return new ProductResource($product);
    }

    public function deactivate(Product $product): ProductResource
    {
        $this->authorizeOrganization($product);
        $this->authorize('deactivate', $product);

        $product = $this->productService->deactivate($product);

        return new ProductResource($product);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    protected function getModelClass(): string
    {
        return Product::class;
    }
}
```

---

## Rules

### 1. Constructor Injection

- Inject **only Services** -- do not inject Repository, Model, or other dependencies
- Use `private readonly` with PHP 8 property promotion
- Maximum 3 services per controller. If more are needed, split the controller

```php
// ✅ Correct
public function __construct(
    private readonly ProductService $productService
) {}

// ❌ Wrong — too many injections
public function __construct(
    private readonly ProductService $productService,
    private readonly CategoryService $categoryService,
    private readonly ProductSkuService $skuService,
    private readonly RecipeService $recipeService,
) {}

// ❌ Wrong — injecting model/repository
public function __construct(
    private readonly Product $product,
    private readonly ProductRepository $repository,
) {}
```

### 2. Authorization Pattern

Always call **2 steps** for single-resource actions:

```php
// Step 1: Verify the resource belongs to the organization
$this->authorizeOrganization($product);

// Step 2: Verify the user has permission
$this->authorize('update', $product);
```

For collection actions (index, store):

```php
// Only a policy check is needed — org scope is handled in the service
$this->authorize('viewAny', Product::class);
```

### 3. Return Types

| Method | Return Type | HTTP Code |
|--------|-----------|----------|
| `index` | `AnonymousResourceCollection` | 200 |
| `store` | `{Entity}Resource` | 201 |
| `show` | `{Entity}Resource` | 200 |
| `update` | `{Entity}Resource` | 200 |
| `destroy` | `JsonResponse` (null) | 204 |
| `restore` | `{Entity}Resource` | 200 |
| Workflow | `{Entity}Resource` | 200 |
| `dropdown` | `JsonResponse` | 200 |
| `bulkDelete` | `JsonResponse` | 200 |

### 4. Method Signatures

```php
// CRUD — always type hint Request and Model
public function index(Request $request): AnonymousResourceCollection
public function store(ProductStoreRequest $request): ProductResource
public function show(Product $product): ProductResource
public function update(ProductUpdateRequest $request, Product $product): ProductResource
public function destroy(Product $product): JsonResponse
public function restore(string $id): ProductResource

// Workflow — Model parameter, custom Request if input is needed
public function approve(Product $product): ProductResource
public function reject(ProductRejectRequest $request, Product $product): ProductResource

// Dropdown — lightweight, no pagination
public function dropdown(Request $request): JsonResponse
```

### 5. Filter Pattern

Filters are passed from controller to service as an array:

```php
$products = $this->productService->list([
    'organization_id' => $this->getOrganizationId(),     // REQUIRED
    'search' => $request->input('search'),
    'status' => $request->input('status'),
    'product_type_id' => $request->input('product_type_id'),
    'category_id' => $request->input('category_id'),
    'is_active' => $request->boolean('is_active'),
    'with_trashed' => $request->boolean('with_trashed', false),
    'sort' => $request->input('sort', '-created_at'),
    'per_page' => min($request->integer('per_page', 25), 100),
]);
```

### 6. Section Comments

Use comment blocks to separate sections within a controller:

```php
// =========================================================================
//  CRUD
// =========================================================================

// =========================================================================
//  Dropdowns
// =========================================================================

// =========================================================================
//  Workflow Actions
// =========================================================================

// =========================================================================
//  Import / Export
// =========================================================================
```

---

## Anti-patterns

```php
// ❌ Query in controller
public function index()
{
    $products = Product::where('organization_id', $orgId)->paginate();
}

// ❌ Business logic in controller
public function approve(Product $product)
{
    if ($product->status !== 'pending') {
        return response()->json(['error' => '...'], 422);
    }
    $product->update(['status' => 'approved']);
}

// ❌ Missing authorization
public function show(Product $product)
{
    return new ProductResource($product); // Missing authorize!
}

// ❌ Manual response instead of Resource
public function show(Product $product)
{
    return response()->json([
        'id' => $product->id,
        'name' => $product->name,
    ]);
}
```
