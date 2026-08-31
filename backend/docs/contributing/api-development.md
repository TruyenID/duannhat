---
title: API Development Rules
category: contributing
tags: [api, service-layer, architecture, naming, error-handling, response-format, middleware, checklist]
summary: Defines mandatory rules for building APIs including the service layer pattern, controller/service/policy responsibilities, naming conventions, error handling, and response formatting.
related: [controller, service, policy, route, testing]
---

# API Development Rules

> This document defines **mandatory rules** for all developers (human and AI) when building APIs for dxs-product.

## Core Principles

1. **Every API endpoint MUST follow the Service Layer Pattern.**
2. **Controllers MUST NOT contain business logic.** Controllers only: authorize, delegate to service, return response.
3. **Services MUST NOT access the Request object.** Services receive `array $data` or typed parameters.
4. **All DB changes MUST go through a Service.** Controllers never call `Model::create()` directly.
5. **All multi-step operations MUST be wrapped in `DB::transaction()`.**
6. **Every endpoint MUST have Policy authorization.**
7. **One service per domain — reused across route prefixes.** A `ProductService` is called by every controller that operates on products, regardless of whether the request came in via `/api/v1/hq/{brandSlug}/products` or any future scope. Business logic stays in the service so it cannot drift between prefixes.

---

## Architecture

### Service Layer Pattern

Every API endpoint MUST follow this flow:

```text
Request → FormRequest (validate) → Controller → Service → Model → Resource → Response
            ↑ Omnify Base              ↑ Policy         ↑ Omnify Base   ↑ Omnify Base
```

| Layer | Responsibility | MUST NOT do |
|-------|---------------|-------------|
| **FormRequest** | Validate input, inject org context | Contain business logic |
| **Controller** | Route handling, authorize, delegate to service, return response | Contain business logic, query DB directly |
| **Service** | Business logic, workflow, DB transactions | Return HTTP response, access Request object |
| **Model** | Data access, relationships, scopes | Contain workflow logic |
| **Resource** | Transform model to JSON | Contain logic, query DB |
| **Policy** | Authorization rules | Contain business logic |

---

## Directory Structure

```text
./
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php              # Base controller
│   │   │   └── Api/V1/
│   │   │       ├── HQ/                      # Mounted under /api/v1/hq/{brandSlug}/...
│   │   │       │   ├── ProductController.php
│   │   │       │   ├── ProductOptionController.php
│   │   │       │   ├── ProductOptionValueController.php
│   │   │       │   ├── ProductSkuController.php
│   │   │       │   ├── CategoryController.php
│   │   │       │   ├── ProductTypeController.php
│   │   │       │   ├── VariantUnitController.php
│   │   │       │   ├── MaterialController.php
│   │   │       │   ├── RecipeController.php
│   │   │       │   └── MenuController.php
│   │   │       ├── Shop/                    # Mounted under /api/v1/shops/{shopSlug}/...
│   │   │       │   ├── ZoneController.php
│   │   │       │   └── TableController.php
│   │   │       └── Inventory/               # Mounted under /api/v1/shops/{shopSlug}/...
│   │   │           ├── WarehouseController.php
│   │   │           ├── StockLevelController.php
│   │   │           ├── StockTransactionController.php
│   │   │           ├── StockTransferController.php
│   │   │           ├── StockCountController.php
│   │   │           ├── StockAlertController.php
│   │   │           ├── DisposalController.php
│   │   │           ├── MaterialBatchController.php
│   │   │           └── ProductionOrderController.php
│   │   ├── Controllers/Traits/
│   │   │   ├── HasOrganizationContext.php   # Org-level guard rail (organization_id from user)
│   │   │   ├── HasBulkOperations.php        # Bulk delete
│   │   │   └── HasImportExport.php          # CSV import/export
│   │   ├── Middleware/
│   │   │   ├── ResolveBrandFromSlug.php     # {brandSlug} → request attribute `brand`/`brand_id`
│   │   │   └── ResolveShopFromSlug.php      # {shopSlug}  → request attribute `shop`/`shop_id`/`brand_id`
│   │   ├── Requests/                        # Omnify generated + custom overrides
│   │   └── Resources/                       # Omnify generated + custom overrides
│   ├── Services/
│   │   ├── Product/                         # Reused by every controller that touches products,
│   │   │   ├── ProductService.php           # regardless of which URL prefix the request came from
│   │   │   ├── ProductOptionService.php
│   │   │   ├── ProductOptionValueService.php
│   │   │   ├── ProductSkuService.php
│   │   │   ├── CategoryService.php
│   │   │   ├── MaterialService.php
│   │   │   ├── RecipeService.php
│   │   │   └── MenuService.php
│   │   ├── Shop/
│   │   │   ├── ZoneService.php
│   │   │   ├── TableService.php
│   │   │   ├── TableStatusService.php       # Runtime status transitions + audit log
│   │   │   └── QrTokenGenerator.php
│   │   └── Inventory/
│   │       ├── WarehouseService.php
│   │       ├── StockLevelService.php
│   │       ├── StockTransactionService.php
│   │       ├── StockTransferService.php
│   │       ├── StockCountService.php
│   │       ├── StockAlertService.php
│   │       ├── DisposalService.php
│   │       ├── MaterialBatchService.php
│   │       ├── ProductionOrderService.php
│   │       └── ProductionCalculatorService.php
│   ├── Policies/
│   │   ├── ProductPolicy.php
│   │   ├── StockTransactionPolicy.php
│   │   └── Traits/
│   │       └── ChecksWarehouseContext.php
│   ├── Traits/
│   │   ├── AuditsActivity.php
│   │   ├── GeneratesSku.php
│   │   └── HasOrganizationScope.php
│   ├── Enums/
│   │   ├── ProductStatusEnum.php
│   │   ├── StockTransactionStatusEnum.php
│   │   └── ...
│   ├── Exceptions/
│   │   ├── InsufficientStockException.php
│   │   ├── InvalidStatusTransitionException.php
│   │   └── CircularReferenceException.php
│   └── Jobs/
│       └── WriteAuditLog.php
├── routes/
│   ├── api.php                              # Mounts each domain file under its scope prefix
│   ├── api/
│   │   ├── hq.php                           # HQ (brand-scoped): /api/v1/hq/{brandSlug}/...
│   │   ├── shop.php                         # Shop-scoped:       /api/v1/shops/{shopSlug}/... (zones, tables)
│   │   ├── inventory.php                    # Shop-scoped:       /api/v1/shops/{shopSlug}/... (warehouses, stock)
│   │   └── files.php                        # Misc               /api/v1/files/...
├── tests/
│   └── Feature/
│       ├── Product/
│       └── Inventory/
```

---

## Naming Conventions

### Files and Classes

| Type | Convention | Example |
|------|-----------|---------|
| Controller | `{Entity}Controller` | `ProductController`, `StockTransactionController` |
| Service | `{Entity}Service` | `ProductService`, `StockTransactionService` |
| Policy | `{Entity}Policy` | `ProductPolicy`, `StockTransactionPolicy` |
| Store Request | `{Entity}StoreRequest` | `ProductStoreRequest` |
| Update Request | `{Entity}UpdateRequest` | `ProductUpdateRequest` |
| Custom Request | `{Entity}{Action}Request` | `ProductRejectRequest`, `StockTransactionApproveRequest` |
| Resource | `{Entity}Resource` | `ProductResource` |
| Enum | `{Entity}{Field}Enum` | `ProductStatusEnum`, `StockTransactionTypeEnum` |
| Exception | `{Description}Exception` | `InsufficientStockException` |
| Trait | `{Capability}` | `AuditsActivity`, `GeneratesSku`, `HasOrganizationScope` |
| Test | `{Entity}{Action}Test` | `ProductCrudTest`, `StockTransactionApproveTest` |

### Methods

| Type | Convention | Example |
|------|-----------|---------|
| CRUD (Controller) | REST verbs | `index`, `store`, `show`, `update`, `destroy` |
| CRUD (Service) | Descriptive | `list`, `create`, `findById`, `update`, `delete` |
| Workflow | Action verb | `submit`, `approve`, `reject`, `cancel`, `activate`, `deactivate`, `start`, `complete` |
| Dropdown | `dropdown` | `dropdown()` |
| Bulk | `bulk{Action}` | `bulkDelete()` |
| Toggle | `toggle{Field}` | `toggleStatus()` |
| Check | `check{What}` | `checkUsage()`, `checkSync()` |

### Routes

| Convention | Example |
|-----------|---------|
| kebab-case plural | `/stock-transactions`, `/product-types` |
| Nested resources | `/products/{product}/skus`, `/products/{product}/options` |
| Workflow actions | `/products/{product}/approve` |
| Named `api.v1.{domain}.{entity}.{action}` | `api.v1.product.products.approve` |

### Database

| Convention | Example |
|-----------|---------|
| Table: snake_case plural | `stock_transactions`, `product_skus`, `product_options` |
| Column: snake_case | `created_by_id`, `approved_at` |
| FK: `{relation}_id` | `warehouse_id`, `product_type_id` |
| Status column | `status` (string, using Enum) |
| Timestamps | `created_at`, `updated_at`, `deleted_at` |
| Audit fields | `created_by_id`, `approved_by_id`, `approved_at`, `rejected_by_id`, `rejected_at`, `rejection_reason` |

---

## Response Format

### Success -- Single Resource

```php
return new ProductResource($product);
// HTTP 200 (show/update) or 201 (store)
```

```json
{
  "data": {
    "id": "019d4e6a-...",
    "name": "Black Coffee",
    "status": "draft"
  }
}
```

### Success -- Collection (Paginated)

```php
return ProductResource::collection($products);
// HTTP 200
```

```json
{
  "data": [...],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 5, "per_page": 25, "total": 120 }
}
```

### Success -- No Content

```php
return response()->json(null, 204);
// HTTP 204 (delete)
```

### Success -- Custom Message

```php
return response()->json(['message' => 'Product approved successfully.']);
// HTTP 200
```

### Error -- Validation (422)

Handled automatically by FormRequest:

```json
{
  "message": "The name field is required.",
  "errors": { "name": ["The name field is required."] }
}
```

### Error -- Business Rule (422)

```php
throw new InvalidStatusTransitionException('Only pending products can be approved.');
```

```json
{
  "message": "Only pending products can be approved.",
  "error": "INVALID_STATUS_TRANSITION"
}
```

### Error -- Insufficient Stock (422)

```php
throw new InsufficientStockException($warehouseId, $shortages);
```

```json
{
  "message": "Insufficient stock for 2 items.",
  "error": "INSUFFICIENT_STOCK",
  "shortages": [
    { "product_sku_id": "...", "name": "Black Coffee S", "required": 100, "available": 80 }
  ]
}
```

---

## Middleware Stack

Every API route lives inside one of two scope groups. Routes are first authenticated, then have their scope resolved (brand or shop), then handed to the controller.

```php
Route::prefix('v1')
    ->middleware(['sso.auth'])
    ->group(function () {
        // HQ (brand-scoped): catalog, products, menus
        Route::prefix('hq/{brandSlug}')
            ->middleware([ResolveBrandFromSlug::class])
            ->group(function () {
                require __DIR__.'/api/hq.php';
            });

        // Shop-scoped: zones, tables, warehouses, stock
        Route::prefix('shops/{shopSlug}')
            ->middleware([ResolveShopFromSlug::class])
            ->group(function () {
                require __DIR__.'/api/shop.php';
                require __DIR__.'/api/inventory.php';
            });
    });
```

| Middleware                | Purpose                                                                                                  |
| ------------------------- | -------------------------------------------------------------------------------------------------------- |
| `api`                     | Rate limiting, stateless session (applied globally by Laravel)                                           |
| `sso.auth`                | Platform JWT validation (provided by `dxs/laravel-auth`)                                                |
| `ResolveBrandFromSlug`    | Loads `brands.{brandSlug}` → `request->attributes['brand']`, `['brand_id']`. Aborts 404/403 on mismatch. |
| `ResolveShopFromSlug`     | Loads `branches.{shopSlug}` → `request->attributes['shop']`, `['shop_id']`, `['brand_id']`. Aborts 404/403 on mismatch. |

Authorization is handled in the controller via `$this->authorize()` (Policy-based), **not through middleware**. The resolve middleware only loads the scope; it does not check whether the caller may operate on that scope beyond the org-membership guard.

---

## Error Handling

### Custom Exceptions

Create in `app/Exceptions/`:

```php
class InvalidStatusTransitionException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'INVALID_STATUS_TRANSITION',
        ], 422);
    }
}
```

### Exception to HTTP Status Code

| Exception | HTTP Code |
|-----------|----------|
| `AuthenticationException` | 401 |
| `AuthorizationException` | 403 |
| `ModelNotFoundException` | 404 |
| `ValidationException` | 422 |
| `InvalidStatusTransitionException` | 422 |
| `InsufficientStockException` | 422 |
| `CircularReferenceException` | 422 |
| `\Exception` (unhandled) | 500 |

---

## Checklist

- [ ] Controller only authorizes and delegates (no business logic)
- [ ] Service wraps multi-step operations in `DB::transaction()`
- [ ] Policy exists for every action (viewAny, view, create, update, delete, + workflow)
- [ ] FormRequest validates all input
- [ ] Resource returns the correct format (extends OmnifyBase)
- [ ] Route names follow the naming convention
- [ ] Tests cover: CRUD + workflow + authorization + edge cases
- [ ] `vendor/bin/pint --dirty` passes
- [ ] `php artisan test --compact --filter={Feature}` passes
