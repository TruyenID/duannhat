<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'create-rework-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table1 = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
        'current_order_id' => null,
    ]);

    $this->table2 = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
        'current_order_id' => null,
    ]);

    $pt = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);

    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 1200,
        'is_active' => true,
    ]);
});

// =========================================================================
//  Happy path
// =========================================================================

it('creates a header-only order with default order_type=spot when no fields provided', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.order_type', 'spot');

    expect(CustomerOrder::count())->toBe(1);
    $order = CustomerOrder::first();
    expect($order->opened_at)->not->toBeNull();
    expect($order->subtotal)->toBe('0.00');
});

it('creates an order with table_ids and assigns all tables', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'table_ids' => [$this->table1->id, $this->table2->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open');

    $order = CustomerOrder::first();
    expect($this->table1->fresh()->current_order_id)->toBe($order->id);
    expect($this->table2->fresh()->current_order_id)->toBe($order->id);
    expect($this->table1->fresh()->status->value)->toBe('occupied');
    expect($this->table2->fresh()->status->value)->toBe('occupied');

    // Regression: the denormalized primary-table pointer must be back-filled
    // (table_id is guarded → forceFill) so continue-table can resolve the order.
    expect($order->fresh()->table_id)->toBe($this->table1->id);
});

it('creates an order with all optional fields', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'dine_in',
            'customer_id' => $this->customer->id,
            'guest_count' => 4,
            'note' => 'VIP table',
            'table_ids' => [$this->table1->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.order_type', 'dine_in')
        ->assertJsonPath('data.guest_count', 4)
        ->assertJsonPath('data.note', 'VIP table');
});

// =========================================================================
//  Validation
// =========================================================================

it('rejects create with non-existent table_ids', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'table_ids' => [(string) Str::uuid()],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['table_ids.0']);
});

it('rejects create with invalid order_type', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'invalid_value',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['order_type']);
});

it('rejects create with guest_count=0', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'guest_count' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guest_count']);
});

it('rejects create with non-existent customer_id', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'customer_id' => (string) Str::uuid(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_id']);
});

// =========================================================================
//  Authorization
// =========================================================================

it('returns 401 for unauthenticated create request', function () {
    $this->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertUnauthorized();
});

// =========================================================================
//  Edge cases
// =========================================================================

it('creates order with empty table_ids array as spot order', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'table_ids' => [],
        ])
        ->assertCreated()
        ->assertJsonPath('data.order_type', 'spot');

    expect($this->table1->fresh()->current_order_id)->toBeNull();
});

it('rejects create atomically when one table is occupied', function () {
    // Occupy table3
    $zone = Zone::factory()->for($this->shop, 'branch')->create(['organization_id' => $this->orgId]);
    $table3 = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
        'current_order_id' => (string) Str::uuid(),
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'table_ids' => [$this->table1->id, $this->table2->id, $table3->id],
        ])
        ->assertStatus(422);

    // None of the tables should be assigned
    expect($this->table1->fresh()->current_order_id)->toBeNull();
    expect($this->table2->fresh()->current_order_id)->toBeNull();
    expect(CustomerOrder::count())->toBe(0);
});

it('rejects create when table status is cleaning', function () {
    $this->table1->update(['status' => 'cleaning']);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'table_ids' => [$this->table1->id],
        ])
        ->assertStatus(422);
});

// =========================================================================
//  Side effects
// =========================================================================

it('creates audit log entry on order creation', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $order = CustomerOrder::first();
    expect(AuditLog::where('auditable_id', $order->id)->where('action', 'opened')->exists())->toBeTrue();
});

// =========================================================================
//  Existing flow: spot order + addItems
// =========================================================================

it('allows adding items to a spot order with no tables', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $order = CustomerOrder::first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 2],
            ],
        ])
        ->assertCreated();

    expect($order->fresh()->items)->toHaveCount(1);
});

// =========================================================================
//  plan-006 — table-assignment concurrency guard
// =========================================================================

it('fetches tables for availability inside the order transaction so the lock serializes concurrent assignment', function () {
    // The pessimistic `lockForUpdate()` added to validateAndAssignTables() is
    // only effective while the SELECT runs inside the order-creation
    // transaction — that is what serializes two concurrent orders racing for
    // the same table and makes the loser re-read the now-occupied row. This
    // guards against the fix being defeated by moving the availability check
    // out of DB::transaction. (SQLite compiles FOR UPDATE away, so the lock
    // clause itself is not observable here — the transactional context is.)
    $levelsAtTableSelect = [];
    DB::listen(function ($query) use (&$levelsAtTableSelect) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'from "tables"') && str_contains($sql, '"id" in')) {
            $levelsAtTableSelect[] = DB::transactionLevel();
        }
    });

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'table_ids' => [$this->table1->id, $this->table2->id],
        ])
        ->assertCreated();

    expect($levelsAtTableSelect)->not->toBeEmpty();
    foreach ($levelsAtTableSelect as $level) {
        expect($level)->toBeGreaterThanOrEqual(1);
    }
});

it('rejects init table assignment atomically when one target table is occupied', function () {
    // Order created with no tables, then init tries to assign one free + one
    // already-occupied table. The whole set must be rejected and the free
    // table left untouched — the same availability guard the row lock protects
    // on the deferred (init) assignment path.
    $order = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated()
        ->json('data.id');

    $zone = Zone::factory()->for($this->shop, 'branch')->create(['organization_id' => $this->orgId]);
    $occupied = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
        'current_order_id' => (string) Str::uuid(),
    ]);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$order}/init", [
            'table_ids' => [$this->table1->id, $occupied->id],
        ])
        ->assertStatus(422);

    expect($this->table1->fresh()->current_order_id)->toBeNull();
    expect(Table::where('current_order_id', $order)->exists())->toBeFalse();
});
