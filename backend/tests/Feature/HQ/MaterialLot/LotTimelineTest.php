<?php

/**
 * Plan-018 Group C.1 — Lot timeline tests.
 */

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ExpiryAlert;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use App\Services\Inventory\LotTimelineService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'tl-'.Str::random(4),
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
    $this->lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
    ]);

    $this->service = app(LotTimelineService::class);
});

function createAuditLog(string $lotId, string $action, $createdAt = null): AuditLog
{
    return AuditLog::create([
        'auditable_type' => MaterialLot::class,
        'auditable_id' => $lotId,
        'action' => $action,
        'user_id' => null,
        'metadata' => [],
        'created_at' => $createdAt ?? now(),
    ]);
}

it('merges events from multiple sources sorted by occurred_at DESC', function () {
    // 2 audit events
    createAuditLog($this->lot->id, 'received', now()->subDays(9));
    createAuditLog($this->lot->id, 'quarantined', now()->subDays(5));

    // 3 movements
    $stockTx = StockTransaction::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
    ]);
    for ($i = 1; $i <= 3; $i++) {
        StockMovement::factory()->create([
            'material_lot_id' => $this->lot->id,
            'warehouse_id' => $this->warehouse->id,
            'stock_transaction_id' => $stockTx->id,
            'created_at' => now()->subDays(8 - $i),
        ]);
    }

    // 2 genealogy edges
    $childLot1 = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
    ]);
    $childLot2 = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
    ]);
    GenealogyLink::factory()->create([
        'parent_lot_id' => $this->lot->id,
        'child_lot_id' => $childLot1->id,
        'source_event_type' => 'split',
        'created_at' => now()->subDays(3),
    ]);
    GenealogyLink::factory()->create([
        'parent_lot_id' => $this->lot->id,
        'child_lot_id' => $childLot2->id,
        'source_event_type' => 'split',
        'created_at' => now()->subDays(2),
    ]);

    // 2 expiry alerts
    ExpiryAlert::factory()->create([
        'material_lot_id' => $this->lot->id,
        'fired_at' => now()->subDays(4),
    ]);
    ExpiryAlert::factory()->create([
        'material_lot_id' => $this->lot->id,
        'fired_at' => now()->subDays(1),
    ]);

    $result = $this->service->timeline($this->lot);

    expect($result->total())->toBe(9);

    $items = $result->items();
    for ($i = 0; $i < count($items) - 1; $i++) {
        expect($items[$i]['occurred_at']->gte($items[$i + 1]['occurred_at']))->toBeTrue();
    }

    $types = collect($items)->pluck('type')->unique()->sort()->values()->all();
    expect($types)->toBe(['audit', 'expiry_alert', 'genealogy', 'movement']);
});

it('filters by type when types[] param provided', function () {
    createAuditLog($this->lot->id, 'received');
    $stockTx = StockTransaction::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
    ]);
    StockMovement::factory()->create([
        'material_lot_id' => $this->lot->id,
        'warehouse_id' => $this->warehouse->id,
        'stock_transaction_id' => $stockTx->id,
    ]);

    $result = $this->service->timeline($this->lot, ['types' => ['movement']]);

    expect($result->total())->toBe(1);
    expect($result->items()[0]['type'])->toBe('movement');
});

it('paginates at 50 per page', function () {
    for ($i = 0; $i < 60; $i++) {
        createAuditLog($this->lot->id, 'status_change', now()->subMinutes($i));
    }

    $page1 = $this->service->timeline($this->lot, ['per_page' => 50, 'page' => 1]);
    $page2 = $this->service->timeline($this->lot, ['per_page' => 50, 'page' => 2]);

    expect($page1->total())->toBe(60);
    expect(count($page1->items()))->toBe(50);
    expect(count($page2->items()))->toBe(10);
});
