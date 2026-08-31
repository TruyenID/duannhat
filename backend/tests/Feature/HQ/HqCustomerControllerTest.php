<?php

/**
 * #130 P1 — HQ/CustomerController coverage
 *
 * Endpoints (auth: sso, scope: brand):
 *   GET /api/v1/hq/{brandSlug}/customers           — paginated list
 *   GET /api/v1/hq/{brandSlug}/customers/{id}      — detail with orders
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\User;
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
        'slug' => 'hqc-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/customers";
});

// =============================================================================
// /customers (index)
// =============================================================================

it('returns paginated customers for the brand', function () {
    Customer::factory()->count(5)->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('caps per_page at 100', function () {
    Customer::factory()->count(5)->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
    $response = $this->actingAs($this->user)->getJson("{$this->base}?per_page=500");
    expect((int) $response->json('meta.per_page'))->toBe(100);
});

it('does not include customers from another organization', function () {
    Customer::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    Customer::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
    ]);

    $response = $this->actingAs($this->user)->getJson($this->base);
    expect(collect($response->json('data')))->toHaveCount(1);
});

it('filters by search', function () {
    Customer::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'first_name' => 'Alice',
    ]);
    Customer::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'first_name' => 'Bob',
    ]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}?search=Alice");
    expect($response->json('data'))->toHaveCount(1);
});

it('returns 401 without auth on index', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

// -----------------------------------------------------------------------------
// #1712 — cột "Cửa hàng". Danh sách HQ trải khắp brand, nên mỗi dòng phải tự
// nói nó thuộc chi nhánh nào.
// -----------------------------------------------------------------------------

it('embeds the branch on each listed customer', function () {
    Customer::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $response = $this->actingAs($this->user)->getJson($this->base)->assertOk();

    expect($response->json('data.0.branch.id'))->toBe($this->branch->id)
        ->and($response->json('data.0.branch.name'))->toBe($this->branch->name);
});

it('still names the branch after the branch is soft-deleted', function () {
    Customer::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
    $this->branch->delete();

    $response = $this->actingAs($this->user)->getJson($this->base)->assertOk();

    expect($response->json('data.0.branch.name'))->toBe($this->branch->name);
});

it('leaves branch null for a customer not attached to any branch', function () {
    Customer::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'organization_id' => $this->orgId,
    ]);

    $response = $this->actingAs($this->user)->getJson($this->base)->assertOk();

    expect($response->json('data.0.branch'))->toBeNull();
});

it('does not run a branch query per customer', function () {
    Customer::factory()->count(5)->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $branchQueries = 0;
    DB::listen(function ($query) use (&$branchQueries) {
        if (str_contains($query->sql, '"branches"') || str_contains($query->sql, '`branches`')) {
            $branchQueries++;
        }
    });

    $this->actingAs($this->user)->getJson($this->base)->assertOk();

    expect($branchQueries)->toBe(1);
});

// =============================================================================
// /customers/{id} (show)
// =============================================================================

it('returns customer detail with orders', function () {
    $customer = Customer::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
    CustomerOrder::factory()->count(2)->create([
        'customer_id' => $customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}/{$customer->id}")
        ->assertOk();

    expect($response->json('data.id'))->toBe($customer->id);
});

it('returns 403 (or 404) when accessing customer from another org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    $foreignCustomer = Customer::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}/{$foreignCustomer->id}");
    expect($response->status())->toBeIn([403, 404]);
});

it('returns 401 without auth on show', function () {
    $customer = Customer::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->getJson("{$this->base}/{$customer->id}")->assertUnauthorized();
});
