<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Str;

// =============================================================================
// plan-006 test-gap coverage — cross-status guards, order_type change
// (last-write-wins), and real sequential table double-booking races on the
// create / init / update order-header endpoints. The shipped suite only
// exercised the `checkout` status for the 409 guard and never asserted a
// successful order_type overwrite nor a double-book between two *real* orders.
// =============================================================================

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
        'slug' => 'cross-status-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

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

    // A fresh open spot order (no tables, no guest_count)
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $this->order = CustomerOrder::first();
});

// =========================================================================
//  Cross-status — init only works on `open` (409 otherwise)
// =========================================================================

it('rejects init on a dining order with 409', function () {
    $this->order->update(['status' => 'dining']);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [$this->table1->id],
            'guest_count' => 4,
        ])
        ->assertStatus(409);

    // Guard fires before any mutation — the table stays free.
    expect($this->table1->fresh()->current_order_id)->toBeNull();
    expect($this->order->fresh()->guest_count)->toBeNull();
});

it('rejects init on a paying order with 409', function () {
    $this->order->update(['status' => 'paying']);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'guest_count' => 2,
        ])
        ->assertStatus(409);
});

it('rejects init on a voided order with 409', function () {
    $this->order->update(['status' => 'voided']);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [$this->table1->id],
        ])
        ->assertStatus(409);

    expect($this->table1->fresh()->current_order_id)->toBeNull();
});

it('rejects init on a pending (takeaway) order with 409', function () {
    $this->order->update(['status' => 'pending']);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'guest_count' => 3,
        ])
        ->assertStatus(409);
});

// =========================================================================
//  Cross-status — general update only works on `open` (409 otherwise)
// =========================================================================

it('rejects general update on a dining order with 409', function () {
    $this->order->update(['status' => 'dining']);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'guest_count' => 8,
        ])
        ->assertStatus(409);

    expect($this->order->fresh()->guest_count)->toBeNull();
});

it('rejects general update on a voided order with 409', function () {
    $this->order->update(['status' => 'voided']);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'note' => 'should not persist',
        ])
        ->assertStatus(409);

    expect($this->order->fresh()->note)->toBeNull();
});

it('rejects general update on a paying order with 409', function () {
    $this->order->update(['status' => 'paying']);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'guest_count' => 5,
        ])
        ->assertStatus(409);
});

// =========================================================================
//  order_type change — general update is last-write-wins on order_type
// =========================================================================

it('overwrites order_type from spot to dine_in on an open order', function () {
    expect($this->order->order_type->value)->toBe('spot');

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'order_type' => 'dine_in',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.order_type', 'dine_in');

    expect($this->order->fresh()->order_type->value)->toBe('dine_in');
});

it('overwrites order_type to takeaway without releasing already-assigned tables', function () {
    // Assign a table to the order via init, then flip the order_type. The
    // general update touches only header fields — it must NOT release tables.
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [$this->table1->id],
        ])
        ->assertSuccessful();

    expect($this->table1->fresh()->current_order_id)->toBe($this->order->id);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'order_type' => 'takeaway',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.order_type', 'takeaway');

    // Table assignment is unaffected by an order_type change.
    expect($this->table1->fresh()->current_order_id)->toBe($this->order->id);
    expect($this->table1->fresh()->status->value)->toBe('occupied');
});

// =========================================================================
//  Concurrency — real sequential double-booking of one table across two orders
// =========================================================================

it('rejects a second create that targets a table already held by a real order', function () {
    // Order A takes table1 for real (not a synthetic current_order_id).
    $orderAId = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'table_ids' => [$this->table1->id],
        ])
        ->assertCreated()
        ->json('data.id');

    $ordersBefore = CustomerOrder::count();

    // Order B loses the race for the same table.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'table_ids' => [$this->table1->id, $this->table2->id],
        ])
        ->assertStatus(422);

    // Table1 stays with A; table2 (the free one in B's batch) is untouched;
    // order B was never persisted (atomic all-or-nothing).
    expect($this->table1->fresh()->current_order_id)->toBe($orderAId);
    expect($this->table2->fresh()->current_order_id)->toBeNull();
    expect(CustomerOrder::count())->toBe($ordersBefore);
});

it('rejects an init that targets a table already held by another real order', function () {
    // Order A (this->order) grabs table1 via init.
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [$this->table1->id],
        ])
        ->assertSuccessful();

    // Order B: a second open order that tries to init the same table.
    $orderBId = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$orderBId}/init", [
            'table_ids' => [$this->table1->id],
        ])
        ->assertStatus(422);

    // Table1 stays with A; B ends up with no tables.
    expect($this->table1->fresh()->current_order_id)->toBe($this->order->id);
    expect(Table::where('current_order_id', $orderBId)->exists())->toBeFalse();
});
