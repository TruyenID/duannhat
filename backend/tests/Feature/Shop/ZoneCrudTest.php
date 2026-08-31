<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Str;

beforeEach(function () {
    // Align Organization.id with console_organization_id so policy and FK both line up.
    $this->orgId = (string) Str::uuid();
    $this->org = Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'main-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
});

// =========================================================================
//  Happy path
// =========================================================================

it('creates a zone scoped to the resolved branch', function () {
    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/zones", [
            'code' => 'TER',
            'name' => 'Terrace',
            'description' => 'Outdoor seating',
            'display_order' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.code', 'TER')
        ->assertJsonPath('data.name', 'Terrace')
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('zones', [
        'code' => 'TER',
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
    ]);
});

it('lists zones ordered by display_order', function () {
    Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'B',
        'display_order' => 2,
    ]);
    Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'A',
        'display_order' => 1,
    ]);
    Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'C',
        'display_order' => 3,
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/zones");

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.code', 'A')
        ->assertJsonPath('data.1.code', 'B')
        ->assertJsonPath('data.2.code', 'C');
});

it('updates a zone name', function () {
    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'name' => 'Old',
    ]);

    $response = $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/zones/{$zone->id}", [
            'name' => 'New',
        ]);

    $response->assertOk()->assertJsonPath('data.name', 'New');
});

it('soft-deletes the zone and cascades to its tables', function () {
    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);
    $t1 = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);
    $t2 = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    $response = $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/zones/{$zone->id}");

    $response->assertNoContent();

    $this->assertSoftDeleted('zones', ['id' => $zone->id]);
    $this->assertSoftDeleted('tables', ['id' => $t1->id]);
    $this->assertSoftDeleted('tables', ['id' => $t2->id]);
});

it('restores a zone without auto-restoring its tables', function () {
    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);
    $table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/zones/{$zone->id}")
        ->assertNoContent();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/zones/{$zone->id}/restore")
        ->assertOk();

    expect(Zone::find($zone->id))->not->toBeNull();
    expect(Table::find($table->id))->toBeNull(); // table is still soft-deleted
});

// =========================================================================
//  Validation
// =========================================================================

it('rejects empty code', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/zones", [
            'code' => '',
            'name' => 'Terrace',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('rejects duplicate code in the same shop', function () {
    Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'TER',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/zones", [
            'code' => 'TER',
            'name' => 'Another Terrace',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('allows the same code in a different shop (uniqueness is per-branch)', function () {
    $otherShop = Branch::create([
        'console_branch_id' => fake()->uuid(),
        'console_organization_id' => $this->orgId,
        'slug' => 'second-shop',
        'name' => 'Second Shop',
        'is_active' => true,
    ]);

    Zone::factory()->for($otherShop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'TER',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/zones", [
            'code' => 'TER',
            'name' => 'Terrace',
        ])
        ->assertCreated();
});

// =========================================================================
//  Edge cases
// =========================================================================

it('returns empty data for a brand new shop with no zones', function () {
    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/zones");

    $response->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});
