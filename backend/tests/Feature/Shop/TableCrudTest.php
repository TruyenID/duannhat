<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'main-shop',
        'is_active' => true,
    ]);

    $this->zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'TER',
        'name' => 'Terrace',
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);
});

// =========================================================================
//  Happy path
// =========================================================================

it('creates a table with default status free and a non-empty qr_token', function () {
    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables", [
            'code' => 'T-01',
            'seat_count' => 4,
            'zone_id' => $this->zone->id,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.code', 'T-01')
        ->assertJsonPath('data.status', 'free')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.seat_count', 4);

    expect($response->json('data.qr_token'))->toBeString()->toHaveLength(32);
});

it('updates table seat_count and name', function () {
    $table = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/tables/{$table->id}", [
            'seat_count' => 6,
            'name' => 'Window seat',
        ])
        ->assertOk()
        ->assertJsonPath('data.seat_count', 6)
        ->assertJsonPath('data.name', 'Window seat');
});

it('filters tables by zone_id', function () {
    $zoneB = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
        'code' => 'IND',
    ]);
    Table::factory()->count(2)->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);
    Table::factory()->count(3)->for($this->shop, 'branch')->for($zoneB, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tables?zone_id={$this->zone->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters tables by status', function () {
    Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
    ]);
    Table::factory()->count(2)->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tables?status=occupied")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('toggles is_active', function () {
    $table = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$table->id}/toggle-active")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('soft-deletes a table and excludes it from default list', function () {
    $table = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/tables/{$table->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('tables', ['id' => $table->id]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tables")
        ->assertJsonCount(0, 'data');
});

// =========================================================================
//  Validation
// =========================================================================

it('rejects seat_count of 0', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables", [
            'code' => 'T-02',
            'seat_count' => 0,
            'zone_id' => $this->zone->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['seat_count']);
});

it('rejects zone_id from another shop', function () {
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'other-shop',
        'is_active' => true,
    ]);
    $foreignZone = Zone::factory()->for($otherShop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables", [
            'code' => 'T-03',
            'seat_count' => 2,
            'zone_id' => $foreignZone->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['zone_id']);
});

it('rejects duplicate code in the same shop', function () {
    Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'code' => 'T-99',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables", [
            'code' => 'T-99',
            'seat_count' => 2,
            'zone_id' => $this->zone->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});
