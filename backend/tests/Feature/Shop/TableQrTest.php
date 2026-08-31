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
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);
});

it('regenerates the qr_token to a different non-empty value', function () {
    $oldToken = $this->table->qr_token;

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}/regenerate-qr");

    $response->assertOk();

    $newToken = $response->json('data.qr_token');
    expect($newToken)->toBeString()->toHaveLength(32)->not->toBe($oldToken);

    $this->assertDatabaseHas('tables', [
        'id' => $this->table->id,
        'qr_token' => $newToken,
    ]);
});

it('returns 404 when regenerating QR for a soft-deleted table', function () {
    $this->table->delete();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}/regenerate-qr")
        ->assertNotFound();
});

it('returns the qr_token in the table resource', function () {
    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}");

    $response->assertOk();
    expect($response->json('data.qr_token'))->toBeString()->toHaveLength(32);
});
