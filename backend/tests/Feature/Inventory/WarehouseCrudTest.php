<?php

use App\Models\Branch;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/shops/{$this->shop->slug}";

    $this->actingAs($this->user);
});

// =========================================================================
//  Authentication
// =========================================================================

it('returns 401 when not authenticated', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/warehouses")
        ->assertUnauthorized();
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists warehouses for the user organization', function () {
        Warehouse::factory()->count(3)->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);

        $this->getJson("{$this->baseUrl}/warehouses")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters warehouses by search', function () {
        Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'name' => 'Main Storage']);
        Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'name' => 'Branch Depot']);

        $this->getJson("{$this->baseUrl}/warehouses?search=Main")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Main Storage');
    });

    it('filters warehouses by type', function () {
        Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'type' => 'main']);
        Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'type' => 'branch']);

        $this->getJson("{$this->baseUrl}/warehouses?type=main")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters warehouses by is_active', function () {
        Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'is_active' => true]);
        Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'is_active' => false]);

        $this->getJson("{$this->baseUrl}/warehouses?is_active=1")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('does not show warehouses from other organizations', function () {
        Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
        Warehouse::factory()->create(['organization_id' => fake()->uuid()]);

        $this->getJson("{$this->baseUrl}/warehouses")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a warehouse with all fields', function () {
        $payload = [
            'name' => 'Central Warehouse',
            'code' => 'CW-001',
            'type' => 'main',
            'address' => '123 Main St',
        ];

        $this->postJson("{$this->baseUrl}/warehouses", $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Central Warehouse')
            ->assertJsonPath('data.code', 'CW-001')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.auto_approve_stock_in', false);

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Central Warehouse',
            'code' => 'CW-001',
            'organization_id' => $this->orgId,
        ]);
    });

    it('auto-generates code when not provided', function () {
        $this->postJson("{$this->baseUrl}/warehouses", [
            'name' => 'Auto Code WH',
            'type' => 'main',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Auto Code WH');

        $warehouse = Warehouse::where('name', 'Auto Code WH')->first();

        expect($warehouse->code)->not->toBeEmpty();
    });

    it('validates that name is required', function () {
        $this->postJson("{$this->baseUrl}/warehouses", ['type' => 'main'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a warehouse with members_count', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);

        $this->getJson("{$this->baseUrl}/warehouses/{$warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $warehouse->id)
            ->assertJsonStructure(['data' => ['id', 'name', 'code', 'members_count']]);
    });

    it('returns 403 for a warehouse from another organization', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => fake()->uuid()]);

        $this->getJson("{$this->baseUrl}/warehouses/{$warehouse->id}")
            ->assertForbidden();
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('updates name and address', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
            'name' => 'Old Name',
        ]);

        $this->putJson("{$this->baseUrl}/warehouses/{$warehouse->id}", [
            'name' => 'New Name',
            'address' => 'Updated address',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    });

    it('cannot change the code via update', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
            'code' => 'ORIGINAL',
        ]);

        $this->putJson("{$this->baseUrl}/warehouses/{$warehouse->id}", [
            'name' => 'Same Name',
            'code' => 'CHANGED',
        ])->assertOk();

        expect($warehouse->fresh()->code)->toBe('ORIGINAL');
    });
});

// =========================================================================
//  Destroy
// =========================================================================

describe('destroy', function () {
    it('soft deletes a warehouse and returns 204', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);

        $this->deleteJson("{$this->baseUrl}/warehouses/{$warehouse->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('warehouses', ['id' => $warehouse->id]);
    });

    it('cannot delete a warehouse with pending transactions', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $warehouse->id,
            'status' => 'pending',
        ]);

        $this->deleteJson("{$this->baseUrl}/warehouses/{$warehouse->id}")
            ->assertStatus(500);
    });
});

// =========================================================================
//  Restore
// =========================================================================

describe('restore', function () {
    it('restores a soft-deleted warehouse', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
        $warehouse->delete();

        $this->postJson("{$this->baseUrl}/warehouses/{$warehouse->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $warehouse->id);

        expect($warehouse->fresh()->deleted_at)->toBeNull();
    });
});

// =========================================================================
//  Toggle Active
// =========================================================================

describe('toggle active', function () {
    it('toggles the is_active flag', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
            'is_active' => true,
        ]);

        $this->postJson("{$this->baseUrl}/warehouses/{$warehouse->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect($warehouse->fresh()->is_active)->toBeFalse();
    });

    it('cannot deactivate a warehouse with pending transactions', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
            'is_active' => true,
        ]);
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/warehouses/{$warehouse->id}/toggle-active")
            ->assertStatus(500);
    });

    it('can activate a warehouse even with pending transactions', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
            'is_active' => false,
        ]);
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $warehouse->id,
            'status' => 'pending',
        ]);

        $this->postJson("{$this->baseUrl}/warehouses/{$warehouse->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    });
});

// =========================================================================
//  Settings
// =========================================================================

describe('settings', function () {
    it('updates warehouse settings', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
            'auto_approve_stock_in' => false,
            'disposal_approval_threshold' => null,
        ]);

        $this->patchJson("{$this->baseUrl}/warehouses/{$warehouse->id}/settings", [
            'auto_approve_stock_in' => true,
            'disposal_approval_threshold' => 5000.00,
        ])
            ->assertOk()
            ->assertJsonPath('data.auto_approve_stock_in', true)
            ->assertJsonPath('data.disposal_approval_threshold', '5000.00');
    });
});

// =========================================================================
//  Members
// =========================================================================

describe('members', function () {
    it('lists members of a warehouse', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
        WarehouseMember::factory()->count(2)->create(['warehouse_id' => $warehouse->id]);

        $this->getJson("{$this->baseUrl}/warehouses/{$warehouse->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('adds a member to a warehouse', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
        $userId = fake()->uuid();

        $this->postJson("{$this->baseUrl}/warehouses/{$warehouse->id}/members", [
            'user_id' => $userId,
            'role' => 'manager',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $userId)
            ->assertJsonPath('data.role', 'manager');

        $this->assertDatabaseHas('warehouse_members', [
            'warehouse_id' => $warehouse->id,
            'user_id' => $userId,
            'role' => 'manager',
        ]);
    });

    it('prevents adding duplicate member', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
        $userId = fake()->uuid();
        WarehouseMember::factory()->create([
            'warehouse_id' => $warehouse->id,
            'user_id' => $userId,
        ]);

        $this->postJson("{$this->baseUrl}/warehouses/{$warehouse->id}/members", [
            'user_id' => $userId,
            'role' => 'staff',
        ])
            ->assertStatus(500);
    });

    it('updates a member role', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
        $member = WarehouseMember::factory()->create([
            'warehouse_id' => $warehouse->id,
            'role' => 'staff',
        ]);

        $this->putJson("{$this->baseUrl}/warehouses/{$warehouse->id}/members/{$member->id}", [
            'role' => 'manager',
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'manager');

        expect($member->fresh()->role->value)->toBe('manager');
    });

    it('removes a member from a warehouse', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
        $member = WarehouseMember::factory()->create(['warehouse_id' => $warehouse->id]);

        $this->deleteJson("{$this->baseUrl}/warehouses/{$warehouse->id}/members/{$member->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('warehouse_members', ['id' => $member->id]);
    });
});

// =========================================================================
//  Lookup
// =========================================================================

describe('lookup', function () {
    it('returns only active warehouses with id, code, name, and type', function () {
        Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'is_active' => true, 'name' => 'Active WH']);
        Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'is_active' => false, 'name' => 'Inactive WH']);

        $response = $this->getJson("{$this->baseUrl}/warehouses/lookup")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        expect($response->json('data.0'))->toHaveKeys(['id', 'code', 'name', 'type']);
    });
});

// =========================================================================
//  Org Isolation
// =========================================================================

it('returns 403 when updating another org warehouse', function () {
    $warehouse = Warehouse::factory()->create(['organization_id' => fake()->uuid()]);

    $this->putJson("{$this->baseUrl}/warehouses/{$warehouse->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

it('returns 403 when deleting another org warehouse', function () {
    $warehouse = Warehouse::factory()->create(['organization_id' => fake()->uuid()]);

    $this->deleteJson("{$this->baseUrl}/warehouses/{$warehouse->id}")
        ->assertForbidden();
});

// =========================================================================
//  404 — Non-existent & Soft-deleted
// =========================================================================

it('returns 404 for non-existent warehouse', function () {
    $this->getJson("{$this->baseUrl}/warehouses/".fake()->uuid())
        ->assertNotFound();
});

it('returns 404 when showing a soft-deleted warehouse', function () {
    $warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
    $warehouse->delete();

    $this->getJson("{$this->baseUrl}/warehouses/{$warehouse->id}")
        ->assertNotFound();
});
