<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\User;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branchId = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ])->id;
    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

// =============================================================================
// Happy path
// =============================================================================

it('reorders branch menus and reassigns sequential priority', function () {
    [$a, $b, $c] = Menu::factory()->count(3)->sequence(
        ['priority' => 10],
        ['priority' => 20],
        ['priority' => 30],
    )->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'is_master' => false,
        'master_menu_id' => null,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
    ]);

    // Reverse order: c → b → a
    $response = $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/reorder", [
            'branch_id' => $this->branchId,
            'menu_ids' => [$c->id, $b->id, $a->id],
        ]);

    $response->assertOk();

    expect($c->fresh()->priority)->toBe(1)
        ->and($b->fresh()->priority)->toBe(2)
        ->and($a->fresh()->priority)->toBe(3);
});

// =============================================================================
// Edge cases
// =============================================================================

it('returns 422 when menu_ids is missing', function () {
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/reorder", [
            'branch_id' => $this->branchId,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['menu_ids']);
});

it('returns 422 when branch_id is missing', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'is_master' => false,
        'master_menu_id' => null,
        'created_by_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/reorder", [
            'menu_ids' => [$menu->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id']);
});

it('returns 422 when menu_ids contains duplicates', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'is_master' => false,
        'master_menu_id' => null,
        'created_by_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/reorder", [
            'branch_id' => $this->branchId,
            'menu_ids' => [$menu->id, $menu->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['menu_ids']);
});

it('returns 422 when menu_ids does not cover all branch menus', function () {
    [$a, $b] = Menu::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'is_master' => false,
        'master_menu_id' => null,
        'created_by_id' => $this->user->id,
    ]);

    // Send only one of the two
    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/reorder", [
            'branch_id' => $this->branchId,
            'menu_ids' => [$a->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['menu_ids']);
});

it('returns 422 when menu_ids contains IDs from another branch', function () {
    $ownMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'is_master' => false,
        'master_menu_id' => null,
        'created_by_id' => $this->user->id,
    ]);

    $otherBranchId = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ])->id;
    $otherBranchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranchId,
        'is_master' => false,
        'master_menu_id' => null,
        'created_by_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/menus/reorder", [
            'branch_id' => $this->branchId,
            'menu_ids' => [$ownMenu->id, $otherBranchMenu->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['menu_ids']);
});

it('requires authentication', function () {
    $this->putJson("{$this->baseUrl}/menus/reorder", [
        'branch_id' => $this->branchId,
        'menu_ids' => [],
    ])->assertUnauthorized();
});
