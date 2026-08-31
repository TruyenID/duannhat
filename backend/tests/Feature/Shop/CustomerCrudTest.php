<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
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
        'slug' => 'test-shop',
        'is_active' => true,
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

it('lists customers for the branch', function () {
    Customer::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('creates a customer with required first_name', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/customers", [
            'first_name' => 'Yuki',
            'last_name' => 'Tanaka',
            'phone' => '09012345678',
            'email' => 'tanaka@example.com',
        ])
        ->assertCreated()
        ->assertJsonPath('data.first_name', 'Yuki')
        ->assertJsonPath('data.last_name', 'Tanaka')
        ->assertJsonPath('data.full_name', 'Yuki Tanaka')
        ->assertJsonPath('data.phone', '09012345678')
        ->assertJsonPath('data.email', 'tanaka@example.com')
        ->assertJsonPath('data.organization_id', $this->orgId)
        ->assertJsonPath('data.branch_id', $this->shop->id);
});

it('shows a single customer', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'Show',
        'last_name' => 'Test',
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Show')
        ->assertJsonPath('data.last_name', 'Test');
});

it('updates a customer', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/customers/{$customer->id}", [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'phone' => '08011111111',
        ])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Updated')
        ->assertJsonPath('data.last_name', 'Name')
        ->assertJsonPath('data.phone', '08011111111');
});

it('soft deletes a customer (204)', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/customers/{$customer->id}")
        ->assertNoContent();

    expect(Customer::withTrashed()->find($customer->id)->deleted_at)->not->toBeNull();
});

it('restores a soft-deleted customer', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);
    $customer->delete();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/customers/{$customer->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.deleted_at', null);
});

// =========================================================================
//  Search / filter
// =========================================================================

it('searches customers by first_name', function () {
    Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'Yuki',
        'last_name' => 'Tanaka',
    ]);
    Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'Hana',
        'last_name' => 'Suzuki',
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers?search=Tanaka")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.last_name', 'Tanaka');
});

// =========================================================================
//  Validation
// =========================================================================

it('returns 422 when first_name is missing', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/customers", [
            'phone' => '09012345678',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('first_name');
});

// =========================================================================
//  Authorization
// =========================================================================

it('returns 401 for unauthenticated request', function () {
    $this->getJson("/api/v1/shops/{$this->shop->slug}/customers")
        ->assertUnauthorized();
});
