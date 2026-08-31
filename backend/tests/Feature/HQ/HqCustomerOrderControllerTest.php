<?php

/**
 * #130 P1 — HQ/CustomerOrderController coverage
 *
 * Endpoints (auth: sso, scope: brand):
 *   GET /api/v1/hq/{brandSlug}/orders             — paginated list + aggregate
 *   GET /api/v1/hq/{brandSlug}/orders/{order}     — order detail
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
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
        'slug' => 'hqo-'.Str::random(4),
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

    $this->base = "/api/v1/hq/{$this->brand->slug}/orders";
});

// =============================================================================
// /orders (index)
// =============================================================================

it('returns paginated orders with aggregate envelope', function () {
    CustomerOrder::factory()->count(3)->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'aggregate']);
});

/**
 * #1961 — the aggregate must say which currencies its two money figures were
 * summed across.
 *
 * `SUM(total_amount)` adds every matching order regardless of currency, so a
 * brand running a Hanoi shop and a Tokyo shop produces VND + JPY added together
 * on the default "all branches" view. The client cannot detect this on its own:
 * it holds one PAGE of orders while the aggregate covers the whole filtered set.
 */
it('#1961: reports one currency when every matching order shares a branch currency', function () {
    CustomerOrder::factory()->count(2)->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => 'closed',
    ]);

    $currencies = $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->json('aggregate.currencies');

    expect($currencies)->toBe([$this->branch->currency]);
});

it('#1961: reports BOTH currencies when the filter spans branches that differ', function () {
    $tokyo = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    $this->branch->update(['currency' => 'VND']);

    CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => 'closed',
    ]);
    CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $tokyo->id,
        'organization_id' => $this->orgId,
        'status' => 'closed',
    ]);

    $currencies = $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->json('aggregate.currencies');

    // Sorted, so the assertion does not depend on insertion order.
    expect($currencies)->toBe(['JPY', 'VND']);
});

it('#1961: narrowing to one branch collapses it back to one currency', function () {
    // This is the escape hatch the UI tells the operator about, so it has to
    // actually work — otherwise the message sends them somewhere that does not
    // help.
    $tokyo = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    $this->branch->update(['currency' => 'VND']);

    CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => 'closed',
    ]);
    CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $tokyo->id,
        'organization_id' => $this->orgId,
        'status' => 'closed',
    ]);

    $currencies = $this->actingAs($this->user)
        ->getJson($this->base.'?branch_id='.$tokyo->id)
        ->assertOk()
        ->json('aggregate.currencies');

    expect($currencies)->toBe(['JPY']);
});

it('filters by status', function () {
    CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => 'closed',
    ]);
    CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => 'open',
    ]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}?status=closed");
    expect(collect($response->json('data')))->toHaveCount(1);
});

it('filters by branch_id', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
    CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id,
        'organization_id' => $this->orgId,
    ]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}?branch_id={$this->branch->id}");
    expect(collect($response->json('data')))->toHaveCount(1);
});

it('does not include orders from another organization', function () {
    CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    CustomerOrder::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
    ]);

    $response = $this->actingAs($this->user)->getJson($this->base);
    expect(collect($response->json('data')))->toHaveCount(1);
});

it('returns 401 without auth on index', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

// =============================================================================
// /orders/{id} (show)
// =============================================================================

it('returns order detail', function () {
    $order = CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $order->id);
});

it('returns 403 or 404 for order from another organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    $foreignOrder = CustomerOrder::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
    ]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}/{$foreignOrder->id}");
    expect($response->status())->toBeIn([403, 404]);
});

it('returns 401 without auth on show', function () {
    $order = CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->getJson("{$this->base}/{$order->id}")->assertUnauthorized();
});

it('exposes the order currency from its branch (#431)', function () {
    // A VND branch — both list and detail payloads must carry the branch
    // currency so the admin UI renders money in ₫ regardless of UI locale.
    $vndBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
        'currency' => 'VND',
    ]);

    $order = CustomerOrder::factory()->create([
        'brand_id' => $this->brand->id,
        'branch_id' => $vndBranch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.0.currency', 'VND');

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.currency', 'VND');
});
