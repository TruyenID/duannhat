<?php

/**
 * Plan-018 Group D.3 — Supplier yield variance report tests.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
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
        'slug' => 'yield-'.Str::random(4),
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
});

it('returns yield_percent computed from material_lots → genealogy_links → material_batches', function () {
    $supplierLot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'source' => 'inbound',
        'supplier_name' => '日本製粉',
        'qty_on_hand' => 100,
        'status' => 'active',
    ]);

    $outputLot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'source' => 'production',
        'qty_on_hand' => 95,
        'status' => 'active',
    ]);

    GenealogyLink::factory()->create([
        'parent_lot_id' => $supplierLot->id,
        'child_lot_id' => $outputLot->id,
        'qty_consumed' => 100,
        'unit' => 'kg',
        'consumed_at' => now()->subDays(3),
    ]);

    MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'output_lot_id' => $outputLot->id,
        'planned_yield' => 98,
        'actual_yield' => 95,
        'status' => 'completed',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/hq/'.$this->brand->slug.'/reports/supplier-yield?'.http_build_query([
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
            'supplier_name' => '日本製粉',
        ]));
    $response->assertJsonCount(1, 'suppliers');
    $response->assertJsonPath('suppliers.0.supplier_name', '日本製粉');
    $response->assertJsonPath('suppliers.0.lots_count', 1);
    expect((float) $response->json('suppliers.0.yield_percent'))->toBe(95.0);
    expect((float) $response->json('suppliers.0.variance_from_planned'))->toBe(-3.0);
});

it('cache hit on second call within 1h returns identical payload without re-aggregating', function () {
    $supplierLot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'source' => 'inbound',
        'supplier_name' => 'テスト仕入先',
        'qty_on_hand' => 50,
        'status' => 'active',
    ]);

    $outputLot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'source' => 'production',
        'qty_on_hand' => 48,
        'status' => 'active',
    ]);

    GenealogyLink::factory()->create([
        'parent_lot_id' => $supplierLot->id,
        'child_lot_id' => $outputLot->id,
        'qty_consumed' => 50,
        'unit' => 'kg',
        'consumed_at' => now()->subDays(1),
    ]);

    MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'output_lot_id' => $outputLot->id,
        'planned_yield' => 50,
        'actual_yield' => 48,
        'status' => 'completed',
    ]);

    $url = '/api/v1/hq/'.$this->brand->slug.'/reports/supplier-yield?date_from='.now()->subDays(7)->toDateString().'&date_to='.now()->toDateString();

    $this->actingAs($this->user)->getJson($url)->assertOk();

    DB::enableQueryLog();
    $response = $this->actingAs($this->user)->getJson($url);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk();
    $response->assertJsonCount(1, 'suppliers');

    $aggregateQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'material_lots'));
    expect($aggregateQueries)->toBeEmpty();
});
