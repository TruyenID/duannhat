<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'tr-'.Str::random(4),
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/trace";

    $this->parent = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
    ]);
    $this->child = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'source' => 'production',
    ]);
    GenealogyLink::create([
        'parent_lot_id' => $this->parent->id,
        'child_lot_id' => $this->child->id,
        'qty_consumed' => 50,
        'unit' => 'kg',
        'consumed_at' => now()->subDay(),
        'source_event_type' => 'material_batch',
        'source_event_id' => (string) Str::uuid(),
    ]);
});

it('returns forward trace tree for a parent lot', function () {
    $response = $this->getJson("{$this->baseUrl}/lot/{$this->parent->id}?direction=forward")
        ->assertOk();

    expect($response->json('data.lot_id'))->toBe($this->parent->id)
        ->and($response->json('data.children'))->toHaveCount(1)
        ->and($response->json('data.children.0.lot_id'))->toBe($this->child->id)
        ->and($response->json('data.children.0.edge.source_event_type'))->toBe('material_batch');
});

it('returns backward trace tree for a child lot', function () {
    $response = $this->getJson("{$this->baseUrl}/lot/{$this->child->id}?direction=backward")
        ->assertOk();

    expect($response->json('data.lot_id'))->toBe($this->child->id)
        ->and($response->json('data.parents'))->toHaveCount(1)
        ->and($response->json('data.parents.0.lot_id'))->toBe($this->parent->id);
});

it('returns both directions when direction=both', function () {
    $response = $this->getJson("{$this->baseUrl}/lot/{$this->child->id}?direction=both")
        ->assertOk();

    expect($response->json('data.parents'))->toHaveCount(1);
    expect($response->json('data.children'))->toBe([]);
});

it('traces a customer order back to source supplier lots', function () {
    $orderId = (string) Str::uuid();
    GenealogyLink::create([
        'parent_lot_id' => $this->parent->id,
        'child_lot_id' => null,
        'customer_order_id' => $orderId,
        'qty_consumed' => 10,
        'unit' => 'kg',
        'consumed_at' => now(),
        'source_event_type' => 'customer_order',
        'source_event_id' => (string) Str::uuid(),
    ]);

    $response = $this->getJson("{$this->baseUrl}/customer-order/{$orderId}")
        ->assertOk();

    expect($response->json('data.customer_order_id'))->toBe($orderId)
        ->and($response->json('data.direct_lots'))->toHaveCount(1)
        ->and($response->json('data.direct_lots.0.lot_id'))->toBe($this->parent->id);
});

it('returns 404 for an unknown lot', function () {
    $bogusId = (string) Str::uuid();
    $this->getJson("{$this->baseUrl}/lot/{$bogusId}")->assertNotFound();
});

it('returns 401 unauthenticated', function () {
    Auth::forgetGuards();
    $this->getJson("{$this->baseUrl}/lot/{$this->parent->id}")->assertUnauthorized();
});

it('rate-limits /trace/lot at 30 req/min', function () {
    RateLimiter::clear($this->user->id);

    // 30 requests should succeed
    for ($i = 0; $i < 30; $i++) {
        $this->getJson("{$this->baseUrl}/lot/{$this->parent->id}")->assertOk();
    }

    // 31st should hit the throttle
    $this->getJson("{$this->baseUrl}/lot/{$this->parent->id}")->assertStatus(429);
});
