<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotSourceEnum;
use App\Omnify\Enums\MaterialLotStatusEnum;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
    ]);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->baseUrl = "/api/v1/shops/{$this->shop->slug}";

    $this->actingAs($this->user);
});

it('receives a supplier lot, mints MaterialLot row + auto stock_in transaction + lot-grain stock_levels increment', function () {
    $payload = [
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'supplier_name' => '日本製粉',
        'supplier_lot_code' => 'JP-WHEAT-2026-04-001',
        'received_qty' => 5000,
        'unit' => 'kg',
        'expiry_date' => now()->addMonths(6)->toDateString(),
    ];

    $response = $this->postJson("{$this->baseUrl}/material-lots/receive", $payload)
        ->assertCreated();

    $lotId = $response->json('data.id');
    expect($lotId)->not->toBeNull();

    // 1. MaterialLot row exists with the right shape
    $lot = MaterialLot::find($lotId);
    expect($lot)->not->toBeNull()
        ->and($lot->status->value)->toBe(MaterialLotStatusEnum::Active->value)
        ->and((float) $lot->received_qty)->toBe(5000.0)
        ->and((float) $lot->qty_on_hand)->toBe(5000.0)
        ->and($lot->source->value)->toBe(MaterialLotSourceEnum::Inbound->value)
        ->and($lot->lot_code)->toStartWith('L-')
        ->and($lot->organization_id)->toBe($this->orgId)
        ->and((string) $lot->material_id)->toBe((string) $this->material->id)
        ->and((string) $lot->warehouse_id)->toBe((string) $this->warehouse->id)
        ->and($lot->supplier_name)->toBe('日本製粉');

    // 2. StockTransaction was auto-completed (warehouse.auto_approve_stock_in=true)
    $stockTxn = StockTransaction::where('reference_type', 'material_lot')
        ->where('reference_id', $lotId)
        ->first();
    expect($stockTxn)->not->toBeNull()
        ->and($stockTxn->type instanceof BackedEnum ? $stockTxn->type->value : $stockTxn->type)->toBe('stock_in')
        ->and($stockTxn->sub_type instanceof BackedEnum ? $stockTxn->sub_type->value : $stockTxn->sub_type)->toBe('purchase')
        ->and($stockTxn->status instanceof BackedEnum ? $stockTxn->status->value : $stockTxn->status)->toBe('completed');

    // 3. stock_levels row at lot grain (warehouse, material, lot) — NOT the
    //    legacy NULL bucket
    $level = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $this->material->id)
        ->where('material_lot_id', $lotId)
        ->first();
    expect($level)->not->toBeNull()
        ->and((float) $level->quantity)->toBe(5000.0);
});

it('rejects expiry_date before received_at', function () {
    $this->postJson("{$this->baseUrl}/material-lots/receive", [
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_at' => now()->toDateTimeString(),
        'expiry_date' => now()->subDay()->toDateString(),
        'received_qty' => 100,
    ])->assertStatus(422);
});

it('rejects non-positive received_qty', function () {
    $this->postJson("{$this->baseUrl}/material-lots/receive", [
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 0,
    ])->assertStatus(422);
});

// =========================================================================
//  Tier 1.D — temperature CCP
// =========================================================================

it('rejects receive when material requires temperature but none provided', function () {
    $this->material->update([
        'requires_temperature_check' => true,
        'temperature_min' => -2,
        'temperature_max' => 4,
    ]);

    $this->postJson("{$this->baseUrl}/material-lots/receive", [
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 10,
        'unit' => 'kg',
    ])->assertStatus(422);
});

it('rejects out-of-range temperature without override reason', function () {
    $this->material->update([
        'requires_temperature_check' => true,
        'temperature_min' => -2,
        'temperature_max' => 4,
    ]);

    $this->postJson("{$this->baseUrl}/material-lots/receive", [
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 10,
        'unit' => 'kg',
        'received_temperature' => 8.5,
    ])->assertStatus(422);
});

it('accepts in-range temperature → is_temperature_compliant=true', function () {
    $this->material->update([
        'requires_temperature_check' => true,
        'temperature_min' => -2,
        'temperature_max' => 4,
    ]);

    $response = $this->postJson("{$this->baseUrl}/material-lots/receive", [
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 10,
        'unit' => 'kg',
        'received_temperature' => 2.0,
    ])->assertCreated();

    expect($response->json('data.is_temperature_compliant'))->toBeTrue();
});

it('accepts out-of-range temperature WITH override reason → is_temperature_compliant=false', function () {
    $this->material->update([
        'requires_temperature_check' => true,
        'temperature_min' => -2,
        'temperature_max' => 4,
    ]);

    $response = $this->postJson("{$this->baseUrl}/material-lots/receive", [
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 10,
        'unit' => 'kg',
        'received_temperature' => 8.5,
        'temperature_override_reason' => 'Cooler door left open during unload',
    ])->assertCreated();

    expect($response->json('data.is_temperature_compliant'))->toBeFalse()
        ->and($response->json('data.temperature_override_reason'))->toBe('Cooler door left open during unload');
});

it('returns 401 when unauthenticated', function () {
    Auth::forgetGuards();

    $this->postJson("{$this->baseUrl}/material-lots/receive", [
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 100,
    ])->assertUnauthorized();
});
