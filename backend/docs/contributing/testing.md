---
title: Testing Rules
category: contributing
tags: [testing, pest, feature-test, factory, assertion, authorization-test, workflow-test]
summary: Defines mandatory rules for writing Pest tests including CRUD, workflow, and authorization test patterns, factory-based model creation, and directory structure conventions.
related: [controller, service, policy]
---

# Testing Rules

> This document defines **mandatory rules** for writing tests in dxs-product. All contributors (human and AI) must follow these standards.

## Core Principles

1. **Every endpoint MUST have a test** -- CRUD + workflow + authorization
2. **Pest framework** -- do not use PHPUnit syntax
3. **Feature tests are primary** -- Unit tests only for isolated logic
4. **Factory-based** -- use factories, do not create models manually
5. **Tests run fast** -- use `RefreshDatabase`, avoid seeding the entire database

---

## Directory Structure

```text
tests/
├── Feature/
│   ├── Product/
│   │   ├── ProductCrudTest.php
│   │   ├── ProductWorkflowTest.php
│   │   ├── ProductAuthorizationTest.php
│   │   ├── CategoryCrudTest.php
│   │   └── MenuTest.php
│   └── Inventory/
│       ├── StockTransactionCrudTest.php
│       ├── StockTransactionWorkflowTest.php
│       ├── StockTransferTest.php
│       ├── StockCountTest.php
│       └── WarehouseTest.php
└── Unit/
    ├── Services/
    │   └── MaterialGraphServiceTest.php
    └── Models/
        └── ProductTest.php
```

---

## Test Template -- CRUD

```php
// tests/Feature/Product/ProductCrudTest.php

use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'console_organization_id' => 'org-001',
    ]);

    $this->productType = ProductType::factory()->create([
        'organization_id' => 'org-001',
    ]);
});

// =========================================================================
//  Index
// =========================================================================

it('lists products for the user organization', function () {
    Product::factory()->count(3)->create([
        'organization_id' => 'org-001',
        'product_type_id' => $this->productType->id,
    ]);

    // Other org — should NOT appear
    Product::factory()->create(['organization_id' => 'org-other']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/products');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'name', 'sku', 'status']],
            'meta' => ['current_page', 'total'],
        ]);
});

it('filters products by status', function () {
    Product::factory()->create([
        'organization_id' => 'org-001',
        'product_type_id' => $this->productType->id,
        'status' => 'draft',
    ]);

    Product::factory()->create([
        'organization_id' => 'org-001',
        'product_type_id' => $this->productType->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/products?status=draft');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

// =========================================================================
//  Store
// =========================================================================

it('creates a product with variants', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/products', [
            'product_type_id' => $this->productType->id,
            'name' => 'Black Coffee',
            'sku' => 'CF-001',
            'variants' => [
                ['name' => 'Size S', 'sku' => 'CF-001-S', 'cost_price' => 15000],
                ['name' => 'Size M', 'sku' => 'CF-001-M', 'cost_price' => 18000],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Black Coffee')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonCount(2, 'data.variants');

    $this->assertDatabaseHas('products', [
        'name' => 'Black Coffee',
        'organization_id' => 'org-001',
    ]);
});

it('auto-generates SKU when not provided', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/products', [
            'product_type_id' => $this->productType->id,
            'name' => 'Test Product',
        ]);

    $response->assertCreated();
    expect($response->json('data.sku'))->toStartWith('PR');
});

it('validates required fields', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/products', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['product_type_id', 'name']);
});

// =========================================================================
//  Show
// =========================================================================

it('returns product with relations', function () {
    $product = Product::factory()->create([
        'organization_id' => 'org-001',
        'product_type_id' => $this->productType->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/products/{$product->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $product->id);
});

it('returns 404 for another organization product', function () {
    $product = Product::factory()->create([
        'organization_id' => 'org-other',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/products/{$product->id}");

    $response->assertForbidden();
});

// =========================================================================
//  Update
// =========================================================================

it('updates product fields', function () {
    $product = Product::factory()->create([
        'organization_id' => 'org-001',
        'product_type_id' => $this->productType->id,
        'status' => 'draft',
    ]);

    $response = $this->actingAs($this->user)
        ->putJson("/api/v1/products/{$product->id}", [
            'name' => 'Updated Name',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Name');
});

// =========================================================================
//  Destroy
// =========================================================================

it('soft deletes a draft product', function () {
    $product = Product::factory()->create([
        'organization_id' => 'org-001',
        'product_type_id' => $this->productType->id,
        'status' => 'draft',
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/products/{$product->id}");

    $response->assertNoContent();
    $this->assertSoftDeleted('products', ['id' => $product->id]);
});

it('cannot delete an active product', function () {
    $product = Product::factory()->create([
        'organization_id' => 'org-001',
        'product_type_id' => $this->productType->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/products/{$product->id}");

    $response->assertForbidden();
});
```

---

## Test Template -- Workflow

```php
// tests/Feature/Product/ProductWorkflowTest.php

it('submits a draft product for approval', function () {
    $product = Product::factory()->create([
        'organization_id' => 'org-001',
        'product_type_id' => $this->productType->id,
        'status' => 'draft',
        'created_by_id' => $this->user->id,
    ]);

    // ProductService::create() auto-creates a default SKU; add another to exercise multi-SKU paths
    ProductSku::factory()->create(['product_id' => $product->id]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/products/{$product->id}/submit-for-approval");

    $response->assertOk()
        ->assertJsonPath('data.status', 'pending');
});

it('cannot submit a product without variants', function () {
    $product = Product::factory()->create([
        'organization_id' => 'org-001',
        'product_type_id' => $this->productType->id,
        'status' => 'draft',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/products/{$product->id}/submit-for-approval");

    $response->assertUnprocessable();
});

it('approves a pending product', function () {
    $creator = User::factory()->create(['console_organization_id' => 'org-001']);
    $approver = User::factory()->create(['console_organization_id' => 'org-001']);
    // Give approver manager role...

    $product = Product::factory()->create([
        'organization_id' => 'org-001',
        'status' => 'pending',
        'created_by_id' => $creator->id,
    ]);

    $response = $this->actingAs($approver)
        ->postJson("/api/v1/products/{$product->id}/approve");

    $response->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_by_id', $approver->id);
});

it('cannot self-approve a product', function () {
    // ... creator tries to approve their own product → 422
});

it('rejects a pending product with reason', function () {
    // ... manager rejects with rejection_reason → status=rejected
});

it('cannot approve a draft product', function () {
    $product = Product::factory()->create([
        'organization_id' => 'org-001',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/products/{$product->id}/approve");

    $response->assertUnprocessable();
});
```

---

## Test Template -- Authorization

```php
// tests/Feature/Product/ProductAuthorizationTest.php

it('requires authentication', function () {
    $response = $this->getJson('/api/v1/products');
    $response->assertUnauthorized();
});

it('prevents access to other organization data', function () {
    $otherOrgProduct = Product::factory()->create([
        'organization_id' => 'org-other',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/products/{$otherOrgProduct->id}");

    $response->assertForbidden();
});

it('prevents staff from approving', function () {
    $staff = User::factory()->create([
        'console_organization_id' => 'org-001',
    ]);
    // No manager role

    $product = Product::factory()->create([
        'organization_id' => 'org-001',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($staff)
        ->postJson("/api/v1/products/{$product->id}/approve");

    $response->assertForbidden();
});
```

---

## Rules

### 1. Test Naming

Use `it('...')` with a behavior description in English:

```php
// ✅ Correct
it('lists products for the user organization')
it('creates a product with variants')
it('cannot approve a draft product')
it('prevents staff from approving')

// ❌ Wrong
it('test product index')
it('testCreateProduct')
test('product creation works')
```

### 2. Authentication

Always use `actingAs()`:

```php
$this->actingAs($this->user)->getJson('/api/v1/products');
```

### 3. Assertions

```php
// Status codes
$response->assertOk();           // 200
$response->assertCreated();      // 201
$response->assertNoContent();    // 204
$response->assertUnprocessable();// 422
$response->assertForbidden();    // 403
$response->assertUnauthorized(); // 401
$response->assertNotFound();     // 404

// JSON structure
$response->assertJsonStructure(['data' => ['id', 'name']]);
$response->assertJsonCount(3, 'data');
$response->assertJsonPath('data.status', 'approved');

// Database
$this->assertDatabaseHas('products', ['name' => 'Test']);
$this->assertSoftDeleted('products', ['id' => $id]);
$this->assertDatabaseCount('product_skus', 2);
```

### 4. Factory Usage

```php
// ✅ Correct — use factory
$product = Product::factory()->create([
    'organization_id' => 'org-001',
    'status' => 'draft',
]);

// ✅ Correct — use factory states (if available)
$product = Product::factory()->active()->create();

// ❌ Wrong — do not create manually
$product = new Product();
$product->name = 'Test';
$product->save();
```

**A factory default must produce a row that is USABLE (#1868).** No-argument
`create()` is what most tests reach for, so the default has to be the ordinary,
live shape — not a random one.

The trap is specifically `fake()->dateTime()` on a validity window. Faker draws
it from **1970 → now**, so it is *always in the past*: measured 200 draws, 200
past, 0 future. `PaymentGatewayOptionFactory` used it for `effective_to`, which
was harmless until production code started filtering `effective_to > now`. From
that moment every default-built row was **invisible** to that query —
deterministically, not flakily — and any test that seeded one and asserted on the
filtered result passed **because the row was never considered**.

```php
// ❌ Wrong — an open-ended window drawn from the past closes immediately
'effective_from' => fake()->dateTime(),
'effective_to'   => fake()->dateTime(),

// ✅ Correct — the default is "currently in force"; expiry is opt-in
'effective_from' => fake()->dateTimeBetween('-2 years', '-1 day'),
'effective_to'   => null,
```

Same question for any boolean gate (`is_active`, `is_published`): a randomised
default means half the rows a test builds are switched off, and the test that
depends on it passes for the wrong reason. Ask what a test means when it says
nothing — and make the default that.

### 5. Test Coverage Requirements

Each controller needs tests covering:

| Type | Test cases |
|------|-----------|
| **CRUD** | index (list + filters), store (valid + invalid), show, update, destroy |
| **Workflow** | Each transition (valid + invalid status) |
| **Authorization** | Unauthenticated, wrong org, wrong role |
| **Edge cases** | Empty list, not found, duplicate SKU, last variant deletion |

### 6. Running Tests

```bash
# Run specific test file
php artisan test --compact tests/Feature/Product/ProductCrudTest.php

# Run by filter
php artisan test --compact --filter="creates a product"

# Run entire feature directory
php artisan test --compact tests/Feature/Product/

# Run all tests
php artisan test --compact
```
