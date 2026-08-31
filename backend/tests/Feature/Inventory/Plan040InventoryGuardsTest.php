<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\Warehouse;
use App\Services\Inventory\DisposalService;
use App\Services\Omnify\WarehouseService;
use App\Services\Product\MaterialService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * plan-040 Cluster J — inventory-side guards.
 * Covers NEW-MR-7 (lookup org/brand closure), M9 (Omnify base list org-scope),
 * M12 (DisposalService deducts + writes a movement).
 */
beforeEach(function () {
    $this->orgA = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgA, 'console_organization_id' => $this->orgA]);
    $this->brandA = Brand::factory()->create(['console_organization_id' => $this->orgA]);

    $this->orgB = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgB, 'console_organization_id' => $this->orgB]);
    $this->brandB = Brand::factory()->create(['console_organization_id' => $this->orgB]);
});

// ── NEW-MR-7 — MaterialService::lookup includeIds is org/brand scoped ───────

it('does not leak cross-tenant rows through lookup includeIds', function () {
    $mine = Material::factory()->create([
        'organization_id' => $this->orgA,
        'brand_id' => $this->brandA->id,
        'is_active' => true,
    ]);
    $foreign = Material::factory()->create([
        'organization_id' => $this->orgB,
        'brand_id' => $this->brandB->id,
        'is_active' => true,
    ]);

    $rows = app(MaterialService::class)->lookup($this->orgA, includeIds: [$foreign->id]);
    $ids = array_column($rows, 'id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($foreign->id);
});

it('still force-includes an in-tenant inactive material via includeIds', function () {
    $active = Material::factory()->create([
        'organization_id' => $this->orgA,
        'brand_id' => $this->brandA->id,
        'is_active' => true,
    ]);
    $inactive = Material::factory()->create([
        'organization_id' => $this->orgA,
        'brand_id' => $this->brandA->id,
        'is_active' => false,
    ]);

    $rows = app(MaterialService::class)->lookup($this->orgA, includeIds: [$inactive->id]);
    $ids = array_column($rows, 'id');

    expect($ids)->toContain($active->id)
        ->and($ids)->toContain($inactive->id);
});

// ── M9 — Omnify base list() org-scoped via the editable service layer ───────

it('org-scopes the generated WarehouseService::list()', function () {
    $mine = Warehouse::factory()->create(['organization_id' => $this->orgA]);
    Warehouse::factory()->create(['organization_id' => $this->orgB]);

    $rows = app(WarehouseService::class)->list(['organization_id' => $this->orgA]);

    expect($rows->total())->toBe(1)
        ->and((string) $rows->first()->id)->toBe((string) $mine->id);
});

// ── M12 — DisposalService::create deducts stock + writes a movement ─────────

function pj40DisposableItem(string $orgId, float $onHand, float $disposeQty): StockTransactionItem
{
    $warehouse = Warehouse::factory()->create(['organization_id' => $orgId]);
    $material = Material::factory()->create(['organization_id' => $orgId]);
    $lot = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouse->id,
        'material_id' => $material->id,
        'received_qty' => $onHand,
        'qty_on_hand' => $onHand,
        'status' => 'active',
    ]);
    $txn = StockTransaction::factory()->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'disposal',
        'status' => 'completed',
    ]);

    return StockTransactionItem::factory()->create([
        'stock_transaction_id' => $txn->id,
        'material_id' => $material->id,
        'material_lot_id' => $lot->id,
        'product_sku_id' => null,
        'base_quantity' => $disposeQty,
        'quantity' => $disposeQty,
        'unit' => 'kg',
    ]);
}

it('deducts lot on-hand and writes a stock movement on disposal', function () {
    $item = pj40DisposableItem($this->orgA, onHand: 100, disposeQty: 30);

    app(DisposalService::class)->create([
        'stock_transaction_item_id' => $item->id,
        'disposal_reason' => 'expired',
        'cost_at_disposal' => 1,
    ]);

    expect((float) $item->materialLot->fresh()->qty_on_hand)->toBe(70.0);

    expect(StockMovement::where('material_lot_id', $item->material_lot_id)
        ->where('movement_type', 'out')
        ->where('quantity', 30)
        ->exists())->toBeTrue();
});

it('rejects a disposal larger than the lot on-hand', function () {
    $item = pj40DisposableItem($this->orgA, onHand: 20, disposeQty: 50);

    expect(fn () => app(DisposalService::class)->create([
        'stock_transaction_item_id' => $item->id,
        'disposal_reason' => 'damaged',
        'cost_at_disposal' => 1,
    ]))->toThrow(ValidationException::class);

    expect((float) $item->materialLot->fresh()->qty_on_hand)->toBe(20.0);
});
