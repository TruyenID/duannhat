<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Table;
use App\Models\TableStatusChange;
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
    ]);

    $this->staffRole = Role::firstOrCreate(
        ['slug' => 'staff'],
        ['name' => 'Staff', 'level' => 10],
    );
    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->staff->assignRole($this->staffRole, $this->orgId);
    grantOrgAccess($this->staff, $this->orgId);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
    ]);
});

// =========================================================================
//  Status changes
// =========================================================================

it('changes status from free to occupied and writes a TableStatusChange row', function () {
    $response = $this->actingAs($this->staff)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}/status", [
            'status' => 'occupied',
            'note' => 'First customer',
        ]);

    $response->assertOk()->assertJsonPath('data.status', 'occupied');

    $this->assertDatabaseHas('table_status_changes', [
        'table_id' => $this->table->id,
        'from_status' => 'free',
        'to_status' => 'occupied',
        'changed_by_id' => $this->staff->id,
        'note' => 'First customer',
    ]);
});

it('logs a no-op when changing to the same status', function () {
    $this->actingAs($this->staff)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}/status", [
            'status' => 'free',
        ])
        ->assertOk();

    expect(TableStatusChange::where('table_id', $this->table->id)->count())->toBe(1);
});

it('rejects status change for an inactive table', function () {
    $this->table->update(['is_active' => false]);

    $this->actingAs($this->staff)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}/status", [
            'status' => 'occupied',
        ])
        ->assertStatus(500); // InvalidArgumentException -> 500 (would be 422 if mapped via render())
});

it('rejects unknown status values', function () {
    $this->actingAs($this->staff)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}/status", [
            'status' => 'totally_made_up',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('returns paginated status history newest-first', function () {
    TableStatusChange::factory()->for($this->table, 'table')->create([
        'from_status' => 'free',
        'to_status' => 'occupied',
        'changed_at' => now()->subMinutes(10),
    ]);
    TableStatusChange::factory()->for($this->table, 'table')->create([
        'from_status' => 'occupied',
        'to_status' => 'cleaning',
        'changed_at' => now()->subMinutes(2),
    ]);
    TableStatusChange::factory()->for($this->table, 'table')->create([
        'from_status' => 'cleaning',
        'to_status' => 'free',
        'changed_at' => now(),
    ]);

    $response = $this->actingAs($this->staff)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}/status-history");

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.to_status', 'free')   // newest first
        ->assertJsonPath('data.2.to_status', 'occupied');
});
