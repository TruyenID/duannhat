---
title: Route Rules
category: contributing
tags: [route, naming, middleware, api-versioning, nested-resources, workflow-actions, lookup]
summary: Defines mandatory rules for API route definitions including versioned prefixes, named routes, domain-grouped file structure, lookup endpoints, and conventions for nested resources and workflow actions.
related: [controller, api-development, api-overview]
---

# Route Rules

> This document defines **mandatory rules** for defining API routes in dxs-product. All contributors (human and AI) must follow these standards.

## Core Principles

1. **API-only** -- no Inertia/web routes (frontend is a separate Next.js app)
2. **Versioned** -- prefix `/api/v1/`
3. **Named routes** -- every route must have a name
4. **Grouped by scope, then by domain** -- one folder per scope (`hq/`, `shops/`), one file per domain inside it
5. **Mounted under a slug-resolving prefix** -- every domain file is included inside an `hq/{brandSlug}` or `shops/{shopSlug}` group so the resolve middleware loads the scope before any controller runs
6. **Auto-discovery** -- `routes/api.php` globs each scope folder and `require`s every `.php` file in it; adding a new domain is just dropping a new file into the right folder

---

## File Structure

```text
routes/
├── api.php                 # Entry point — auto-loads each scope folder
└── api/
    ├── files.php           # Misc top-level: /api/v1/files/...
    ├── hq/                 # Brand-scoped: /api/v1/hq/{brandSlug}/...
    │   ├── brand.php       #   brand info root
    │   ├── catalog.php     #   products, skus, options, product-types, categories
    │   ├── materials.php   #   materials, recipes
    │   └── menus.php       #   menus, master-menus
    └── shops/              # Shop-scoped: /api/v1/shops/{shopSlug}/...
        ├── shop.php        #   shop info root, zones, tables
        ├── menus.php       #   shop menus (read + restricted writes)
        └── inventory.php   #   warehouses, stock-*, transfers, counts, batches, production-orders, disposals
```

A domain file does **not** declare its scope prefix itself. It just declares its resources at the relative path (e.g. `Route::prefix('zones')->...`). `routes/api.php` decides which scope prefix to mount it under by `require`-ing it inside the right group. This is what makes a single resource definition reusable across scopes.

**Adding a new domain:** create a new file under `routes/api/hq/` or `routes/api/shops/` — `api.php` will pick it up automatically via `glob()`. No edits to the entry point required.

### Entry Point

```php
// routes/api.php

use App\Http\Controllers\Api\V1\LocaleController;
use App\Http\Controllers\Api\V1\UserContextController;
use App\Http\Middleware\ResolveBrandFromSlug;
use App\Http\Middleware\ResolveShopFromSlug;
use Illuminate\Support\Facades\Route;

// dxs/laravel-auth auto-registers /auth/*.

Route::prefix('v1')
    ->middleware(['sso.auth'])
    ->group(function () {
        // -----------------------------------------------------------------
        // Session helpers (no scope)
        // -----------------------------------------------------------------
        Route::get('me/context', [UserContextController::class, 'context'])->name('api.v1.me.context');
        Route::post('locale', [LocaleController::class, 'updateLocale'])->name('api.v1.locale.update');
        Route::post('timezone', [LocaleController::class, 'updateTimezone'])->name('api.v1.timezone.update');

        // -----------------------------------------------------------------
        // HQ (brand-scoped) — catalog & master data
        // ResolveBrandFromSlug puts `brand` `brand_id` on the request.
        // -----------------------------------------------------------------
        Route::prefix('hq/{brandSlug}')
            ->middleware([ResolveBrandFromSlug::class])
            ->group(function () {
                foreach (glob(__DIR__.'/api/hq/*.php') as $file) {
                    require $file;
                }
            });

        // -----------------------------------------------------------------
        // Shop-scoped — physical operations & inventory
        // ResolveShopFromSlug puts `shop` `shop_id` `brand_id` on the request.
        // -----------------------------------------------------------------
        Route::prefix('shops/{shopSlug}')
            ->middleware([ResolveShopFromSlug::class])
            ->group(function () {
                foreach (glob(__DIR__.'/api/shops/*.php') as $file) {
                    require $file;
                }
            });

        // -----------------------------------------------------------------
        // Misc top-level routes
        // -----------------------------------------------------------------
        if (file_exists(__DIR__.'/api/files.php')) {
            require __DIR__.'/api/files.php';
        }
    });

// -----------------------------------------------------------------
// POS — its OWN group, deliberately outside `sso.auth`.
// Compound auth: the admin-web SSO Sanctum token works, and so does the
// pos-web device token from /api/v1/devices/pair. That is what lets
// pos-web fall back from a downed workstation to Cloud without re-auth.
// ResolvePosShop reads the shop from the X-Shop-Slug header, so the URL
// shape is identical on Cloud and on the workstation's local server.
// -----------------------------------------------------------------
Route::prefix('v1')
    ->middleware(['auth.sso_or_device', 'throttle:pos'])
    ->group(function () {
        Route::prefix('pos')
            ->middleware([ResolvePosShop::class])
            ->name('api.v1.pos.')
            ->group(function () {
                require __DIR__.'/api/pos.php';
            });
    });
```

**A route group is never widened to admit a device token.** Adding device auth
to an existing SSO group would hand a device every route in it. A surface that
needs both gets its own group with `auth.sso_or_device`, as POS does above. The
design record for why POS has this shape:
[`docs/explanation/pos-web-cloud-auth.md`](../../../docs/explanation/pos-web-cloud-auth.md).

### Resolve middleware

| Middleware              | Reads        | Writes onto `$request->attributes`              | Aborts                                                       |
| ----------------------- | ------------ | ----------------------------------------------- | ------------------------------------------------------------ |
| `ResolveBrandFromSlug`  | `{brandSlug}` | `brand` (Brand model), `brand_id`               | 400 if missing slug, 404 if not found / inactive, 403 if brand belongs to another organization |
| `ResolveShopFromSlug`   | `{shopSlug}`  | `shop` (Branch model), `shop_id`, `brand_id`    | 400 if missing slug, 404 if not found / inactive, 403 if shop belongs to another organization  |

Controllers MUST read these attributes via small private helpers (`resolvedShop()`, `resolvedShopId()` — see [Controller Rules](controller.md#scoping-from-resolved-attributes)). They MUST NOT re-query the brand or shop from the slug themselves.

---

## HQ Domain Routes

HQ resources are split across `routes/api/hq/{brand,catalog,materials,menus}.php`. Example from `catalog.php`:

```php
// routes/api/hq/catalog.php — mounted under /api/v1/hq/{brandSlug}/...

use App\Http\Controllers\Api\V1\HQ\ProductController;
use App\Http\Controllers\Api\V1\HQ\ProductOptionController;
use App\Http\Controllers\Api\V1\HQ\ProductOptionValueController;
use App\Http\Controllers\Api\V1\HQ\ProductSkuController;
use App\Http\Controllers\Api\V1\HQ\ProductTypeController;
use App\Http\Controllers\Api\V1\HQ\CategoryController;
use App\Http\Controllers\Api\V1\HQ\VariantUnitController;
use App\Http\Controllers\Api\V1\HQ\MaterialController;
use App\Http\Controllers\Api\V1\HQ\RecipeController;
use App\Http\Controllers\Api\V1\HQ\MenuController;

// =========================================================================
//  Products
// =========================================================================

Route::prefix('products')->name('api.v1.products.')->group(function () {
    // Lookup BEFORE apiResource so {product} doesn't capture "lookup"
    Route::get('lookup', [ProductController::class, 'lookup'])->name('lookup');
    Route::get('dropdown', [ProductController::class, 'lookup'])->name('dropdown'); // @deprecated

    Route::post('bulk-delete', [ProductController::class, 'bulkDelete'])->name('bulkDelete');

    Route::get('import/template', [ProductController::class, 'importTemplate'])->name('importTemplate');
    Route::post('import', [ProductController::class, 'importCsv'])->name('import');
    Route::get('export', [ProductController::class, 'exportCsv'])->name('export');

    Route::get('/', [ProductController::class, 'index'])->name('index');
    Route::post('/', [ProductController::class, 'store'])->name('store');
    Route::get('{product}', [ProductController::class, 'show'])->name('show');
    Route::put('{product}', [ProductController::class, 'update'])->name('update');
    Route::delete('{product}', [ProductController::class, 'destroy'])->name('destroy');
    Route::post('{product}/restore', [ProductController::class, 'restore'])->name('restore')->withTrashed();

    // Workflow actions (POST, see Rule 4)
    Route::post('{product}/submit-for-approval', [ProductController::class, 'submitForApproval'])->name('submitForApproval');
    Route::post('{product}/approve', [ProductController::class, 'approve'])->name('approve');
    Route::post('{product}/reject', [ProductController::class, 'reject'])->name('reject');
    Route::post('{product}/activate', [ProductController::class, 'activate'])->name('activate');
    Route::post('{product}/deactivate', [ProductController::class, 'deactivate'])->name('deactivate');

    // Nested SKUs (1 level only)
    Route::get('{product}/skus', [ProductSkuController::class, 'index'])->name('skus.index');
    Route::post('{product}/skus', [ProductSkuController::class, 'store'])->name('skus.store');

    // Nested options (1 level only)
    Route::get('{product}/options', [ProductOptionController::class, 'index'])->name('options.index');
    Route::post('{product}/options', [ProductOptionController::class, 'store'])->name('options.store');
});

// =========================================================================
//  SKUs (standalone — show/update/delete + import/export)
// =========================================================================

Route::prefix('skus')->name('api.v1.skus.')->group(function () {
    Route::get('import/template', [ProductSkuController::class, 'importTemplate'])->name('importTemplate');
    Route::post('import', [ProductSkuController::class, 'importCsv'])->name('import');
    Route::get('export', [ProductSkuController::class, 'exportCsv'])->name('export');

    Route::get('/', [ProductSkuController::class, 'indexAll'])->name('indexAll');
    Route::get('lookup', [ProductSkuController::class, 'lookup'])->name('lookup');
    Route::get('dropdown', [ProductSkuController::class, 'lookup'])->name('dropdown'); // @deprecated

    Route::get('{sku}', [ProductSkuController::class, 'show'])->name('show');
    Route::put('{sku}', [ProductSkuController::class, 'update'])->name('update');
    Route::delete('{sku}', [ProductSkuController::class, 'destroy'])->name('destroy');
    Route::post('{sku}/restore', [ProductSkuController::class, 'restore'])->name('restore');
    Route::post('{sku}/toggle-status', [ProductSkuController::class, 'toggleStatus'])->name('toggleStatus');
    Route::get('{sku}/check-usage', [ProductSkuController::class, 'checkUsage'])->name('checkUsage');
});

// =========================================================================
//  SKU Units (selling units per SKU)
// =========================================================================

Route::prefix('sku-units')->name('api.v1.skuUnits.')->group(function () {
    Route::get('{unit}', [VariantUnitController::class, 'show'])->name('show');
    Route::put('{unit}', [VariantUnitController::class, 'update'])->name('update');
    Route::delete('{unit}', [VariantUnitController::class, 'destroy'])->name('destroy');
    Route::post('{unit}/set-base', [VariantUnitController::class, 'setBase'])->name('setBase');
});

// Nested unit list under each SKU (1 level)
Route::get('skus/{sku}/units', [VariantUnitController::class, 'index'])->name('api.v1.skus.units.index');
Route::post('skus/{sku}/units', [VariantUnitController::class, 'store'])->name('api.v1.skus.units.store');

// =========================================================================
//  Product Options & Option Values (standalone show/update/delete)
// =========================================================================

Route::prefix('product-options')->name('api.v1.productOptions.')->group(function () {
    Route::get('{option}', [ProductOptionController::class, 'show'])->name('show');
    Route::put('{option}', [ProductOptionController::class, 'update'])->name('update');
    Route::delete('{option}', [ProductOptionController::class, 'destroy'])->name('destroy');
});

Route::prefix('product-option-values')->name('api.v1.productOptionValues.')->group(function () {
    Route::get('{value}', [ProductOptionValueController::class, 'show'])->name('show');
    Route::put('{value}', [ProductOptionValueController::class, 'update'])->name('update');
    Route::delete('{value}', [ProductOptionValueController::class, 'destroy'])->name('destroy');
});

// Values nested under their parent option
Route::get('product-options/{option}/values', [ProductOptionValueController::class, 'index'])->name('api.v1.productOptions.values.index');
Route::post('product-options/{option}/values', [ProductOptionValueController::class, 'store'])->name('api.v1.productOptions.values.store');

// =========================================================================
//  Categories, Product Types, Materials, Recipes (standard CRUD)
// =========================================================================

// Pattern: lookup BEFORE apiResource to avoid route conflict
Route::get('categories/lookup', [CategoryController::class, 'lookup'])->name('api.v1.categories.lookup');
Route::get('categories/dropdown', [CategoryController::class, 'lookup'])->name('api.v1.categories.dropdown'); // @deprecated
Route::post('categories/bulk-delete', [CategoryController::class, 'bulkDelete'])->name('api.v1.categories.bulkDelete');
Route::post('categories/{category}/restore', [CategoryController::class, 'restore'])->name('api.v1.categories.restore');
Route::apiResource('categories', CategoryController::class)->names('api.v1.categories');

// (product-types, materials, recipes follow the same pattern.)
```

> The full files live under `routes/api/hq/`. `materials.php`, `menus.php`, and `brand.php` follow the same conventions.

---

## Inventory Domain Routes

```php
// routes/api/shops/inventory.php — mounted under /api/v1/shops/{shopSlug}/...
// Same pattern as catalog.php. All names use the api.v1.shops.{entity}.{action} convention.

// Warehouses, Stock Levels, Stock Transactions, Stock Transfers,
// Stock Counts, Stock Alerts, Disposals, Material Batches,
// Production Orders, Production Calculator
```

---

## Shop Domain Routes

```php
// routes/api/shops/shop.php — mounted under /api/v1/shops/{shopSlug}/...

use App\Http\Controllers\Api\V1\Shop\TableController;
use App\Http\Controllers\Api\V1\Shop\ZoneController;
use Illuminate\Support\Facades\Route;

// Lookup BEFORE apiResource so {model} doesn't capture it.
Route::prefix('zones')->name('api.v1.shops.zones.')->group(function () {
    Route::get('lookup', [ZoneController::class, 'lookup'])->name('lookup');

    Route::get('/', [ZoneController::class, 'index'])->name('index');
    Route::post('/', [ZoneController::class, 'store'])->name('store');
    Route::get('{zone}', [ZoneController::class, 'show'])->name('show');
    Route::put('{zone}', [ZoneController::class, 'update'])->name('update');
    Route::delete('{zone}', [ZoneController::class, 'destroy'])->name('destroy');
    Route::post('{zone}/restore', [ZoneController::class, 'restore'])->name('restore')->withTrashed();
    Route::post('{zone}/toggle-active', [ZoneController::class, 'toggleActive'])->name('toggleActive');
});

Route::prefix('tables')->name('api.v1.shops.tables.')->group(function () {
    Route::get('/', [TableController::class, 'index'])->name('index');
    Route::post('/', [TableController::class, 'store'])->name('store');
    Route::get('{table}', [TableController::class, 'show'])->name('show');
    Route::put('{table}', [TableController::class, 'update'])->name('update');
    Route::delete('{table}', [TableController::class, 'destroy'])->name('destroy');
    Route::post('{table}/restore', [TableController::class, 'restore'])->name('restore')->withTrashed();
    Route::post('{table}/toggle-active', [TableController::class, 'toggleActive'])->name('toggleActive');

    // Runtime status (Shop Staff allowed)
    Route::post('{table}/status', [TableController::class, 'changeStatus'])->name('changeStatus');
    Route::get('{table}/status-history', [TableController::class, 'statusHistory'])->name('statusHistory');

    // QR token rotation (Manager+Admin only)
    Route::post('{table}/regenerate-qr', [TableController::class, 'regenerateQr'])->name('regenerateQr');
});
```

> Notice the file does **not** declare a `shops/{shopSlug}` prefix itself — `routes/api.php` provides that. The same controllers could be mounted under any future scope (e.g. a partner-API prefix) by `require`-ing this file in a different group, with no controller changes.

---

## Rules

### 1. Route Naming

```text
api.v1.{domain}.{entity}.{action}
```

| Example | Route Name |
|---------|-----------|
| `GET /api/v1/products` | `api.v1.products.index` |
| `POST /api/v1/products/{product}/approve` | `api.v1.products.approve` |
| `GET /api/v1/products/{product}/skus` | `api.v1.products.skus.index` |
| `GET /api/v1/skus/{sku}/units` | `api.v1.skus.units.index` |
| `POST /api/v1/menus/{menu}/items` | `api.v1.menus.items.store` |

### 2. HTTP Method Usage

| Method | Used for | Example |
|--------|---------|---------|
| `GET` | Read (list, show, lookup, export) | `GET /products` |
| `POST` | Create, workflow actions, import, bulk | `POST /products`, `POST /products/{id}/approve` |
| `PUT` | Full update | `PUT /products/{id}` |
| `PATCH` | Partial update (bulk operations) | `PATCH /menus/{id}/items/bulk-update-availability` |
| `DELETE` | Soft delete | `DELETE /products/{id}` |

### 3. Lookup Endpoint (replaces Dropdown)

Use `/lookup` as the standard endpoint name for lightweight select lists. `/dropdown` is **deprecated** but kept as an alias for backward compatibility.

```php
// ✅ Correct — /lookup is the standard name
Route::get('categories/lookup', [CategoryController::class, 'lookup'])->name('api.v1.categories.lookup');
Route::get('categories/dropdown', [CategoryController::class, 'lookup'])->name('api.v1.categories.dropdown'); // @deprecated

// Lookup routes MUST be placed BEFORE apiResource to avoid {model} conflict
Route::apiResource('categories', CategoryController::class)->names('api.v1.categories');
```

### 4. Workflow Actions Use POST

All workflow actions use `POST`, not `PATCH`:

```php
// ✅ Correct
Route::post('{product}/approve', ...);
Route::post('{product}/submit-for-approval', ...);

// ❌ Wrong
Route::patch('{product}/approve', ...);
```

Reason: workflow actions are **actions** (state changes), not **partial updates** (field changes).

### 5. Nested Resources

Limit nesting to **1 level**:

```php
// ✅ Correct — 1 level
Route::get('products/{product}/skus', ...);
Route::get('products/{product}/options', ...);
Route::get('skus/{sku}/units', ...);
Route::get('menus/{menu}/items', ...);

// ❌ Wrong — 2+ levels
Route::get('products/{product}/skus/{sku}/units', ...);
```

Instead, use standalone routes for second-level resources:

```php
Route::get('sku-units/{unit}', [VariantUnitController::class, 'show']);
Route::put('sku-units/{unit}', [VariantUnitController::class, 'update']);
```

### 6. Plural kebab-case Paths

| Convention | Example |
|------------|---------|
| Plural | `/products`, `/categories`, `/skus` |
| kebab-case | `/product-types`, `/sku-units`, `/product-option-values` |

Singular and camelCase paths are forbidden. Multi-word standalone resources keep the kebab-case form even when the controller name is camelCase.

## Anti-patterns

- **Defining routes outside `routes/api/*.php`** (e.g. inside service providers).
- **Skipping `->name(...)`.** Every route must be addressable by name.
- **Reusing `/dropdown` for new endpoints.** Always pair `/lookup` (live) with `/dropdown` (deprecated alias) when migrating.
- **Two-level nesting.** Promote the inner resource to a standalone route group.
- **Adding workflow actions as `PUT`/`PATCH`.** They are state transitions, not field updates.

## Checklist

- [ ] Route file lives under `routes/api/hq/<domain>.php` or `routes/api/shops/<domain>.php` (or `routes/api/files.php` for misc top-level)
- [ ] Path is plural kebab-case
- [ ] Route is named with the `api.v1.{domain}.{entity}.{action}` pattern
- [ ] Workflow actions use `POST`
- [ ] Nesting is limited to one level
- [ ] `/lookup` is present (and `/dropdown` is only kept as a deprecated alias when needed)
- [ ] Lookup/bulk routes are declared **before** `apiResource` to avoid `{model}` capturing them
