<?php

/**
 * Plan-018 Group C.2 — Mock recall drill tests.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\RecallAffectedOrder;
use App\Models\RecallDrill;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\RecallDrillService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'drill-'.Str::random(4),
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

    $this->service = app(RecallDrillService::class);
});

it('runs a drill and writes a RecallDrill row with elapsed_seconds', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 50,
    ]);

    $childLot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
    ]);

    $order = CustomerOrder::factory()->create([
        'customer_takeaway_phone' => '090-1234-5678',
    ]);

    GenealogyLink::factory()->create([
        'parent_lot_id' => $lot->id,
        'child_lot_id' => $childLot->id,
        'source_event_type' => 'material_batch',
        'consumed_at' => now()->subDays(2),
    ]);

    GenealogyLink::factory()->create([
        'parent_lot_id' => $lot->id,
        'child_lot_id' => null,
        'customer_order_id' => $order->id,
        'source_event_type' => 'customer_order',
        'consumed_at' => now()->subDay(),
    ]);

    $drill = $this->service->run($this->brand->id, $this->orgId, $this->user->id, $lot->id);

    expect($drill)->toBeInstanceOf(RecallDrill::class);
    expect($drill->elapsed_seconds)->toBeGreaterThanOrEqual(0);
    expect($drill->affected_lots_count)->toBeGreaterThanOrEqual(1);
    expect($drill->affected_orders_count)->toBeGreaterThan(0);
    expect($drill->selected_lot_id)->toBe($lot->id);
    expect($drill->started_at)->not->toBeNull();
    expect($drill->completed_at)->not->toBeNull();
});

it('does NOT quarantine lots or send notifications', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'qty_on_hand' => 50,
    ]);

    $this->service->run($this->brand->id, $this->orgId, $this->user->id, $lot->id);

    $lot->refresh();
    expect($lot->status->value ?? $lot->status)->toBe('active');

    expect(RecallAffectedOrder::count())->toBe(0);
});

it('auto-picks a random lot when no lot specified', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'qty_on_hand' => 50,
    ]);

    $drill = $this->service->run($this->brand->id, $this->orgId, $this->user->id);

    expect($drill->selected_lot_id)->toBe($lot->id);
});

it('computes completeness_percent based on customer contact info', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 50,
    ]);

    $orderWithContact = CustomerOrder::factory()->create([
        'customer_takeaway_phone' => '090-1111-2222',
    ]);
    $orderWithoutContact = CustomerOrder::factory()->create([
        'customer_takeaway_phone' => null,
        'customer_id' => null,
    ]);

    GenealogyLink::factory()->create([
        'parent_lot_id' => $lot->id,
        'customer_order_id' => $orderWithContact->id,
        'source_event_type' => 'customer_order',
    ]);
    GenealogyLink::factory()->create([
        'parent_lot_id' => $lot->id,
        'customer_order_id' => $orderWithoutContact->id,
        'source_event_type' => 'customer_order',
    ]);

    $drill = $this->service->run($this->brand->id, $this->orgId, $this->user->id, $lot->id);

    expect((float) $drill->completeness_percent)->toBe(50.0);
});
