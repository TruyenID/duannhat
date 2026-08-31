<?php

/**
 * Plan-024 T3.4 — G4: Allow-negative sales-flow transactions.
 *
 * Covers TESTS.md scenarios:
 *   - Happy 7:   allow_negative_sales=true, qty=5g, consume 10g → StockLevel=-5g,
 *                out_of_stock StockAlert created, no exception
 *   - Happy 8:   allow_negative_sales=false, same scenario → InsufficientStockException
 *   - Edge 5:    zero StockLevel row (first-ever consumption):
 *                  - allow_negative_sales=false → InsufficientStockException
 *                  - allow_negative_sales=true → StockLevel created at -N, out_of_stock alert
 *   - Validation 2: PUT stock-level with min_stock > max_stock → 422
 *   - Validation 3: PUT stock-level with negative min_stock → 422
 *   - Validation 4: PATCH warehouse settings with allow_negative_sales non-boolean → 422
 *   - Authz 5:   non-org-admin PATCH warehouse settings allow_negative_sales → 403
 *   - Authz 6:   org-admin PATCH warehouse settings allow_negative_sales=true → 200
 */

use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Role;
use App\Models\StockAlert;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockTransactionService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($role, $this->orgId);
    $this->actingAs($this->user);

    $this->service = app(StockTransactionService::class);
});

/**
 * Helper — consume stock via a sales-subtype transaction.
 */
function consumeMaterial(StockTransactionService $service, string $orgId, string $warehouseId, string $materialId, float $qty, string $subType = 'sales'): StockTransaction
{
    $txn = $service->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouseId,
        'type' => 'stock_out',
        'sub_type' => $subType,
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $materialId,
            'quantity' => $qty,
            'unit' => 'g',
        ]],
    ]);

    return $service->submit($txn);
}

// =========================================================================
//  Happy 7 — allow_negative_sales=true completes the txn with negative qty
// =========================================================================

it('writes negative StockLevel + fires out_of_stock alert when allow_negative_sales=true', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => true,
    ]);

    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $this->material->id,
        'quantity' => 5,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    $txn = consumeMaterial($this->service, $this->orgId, $warehouse->id, $this->material->id, 10);

    expect($txn->status->value)->toBe('completed');

    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('material_id', $this->material->id)
        ->whereNull('material_lot_id')
        ->first();
    expect((float) $level->quantity)->toBe(-5.0);

    $alert = StockAlert::where('warehouse_id', $warehouse->id)
        ->where('material_id', $this->material->id)
        ->where('status', 'active')
        ->first();
    expect($alert)->not->toBeNull();
    $alertType = $alert->alert_type instanceof BackedEnum ? $alert->alert_type->value : (string) $alert->alert_type;
    expect($alertType)->toBe('out_of_stock');
});

// =========================================================================
//  sales_material_consumption subtype — same allow-negative path
// =========================================================================

it('writes negative StockLevel for sales_material_consumption subtype when allow_negative_sales=true', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => true,
    ]);

    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $this->material->id,
        'quantity' => 5,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    $txn = consumeMaterial($this->service, $this->orgId, $warehouse->id, $this->material->id, 10, 'sales_material_consumption');

    expect($txn->status->value)->toBe('completed');

    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('material_id', $this->material->id)
        ->whereNull('material_lot_id')
        ->first();
    expect((float) $level->quantity)->toBe(-5.0);
});

// =========================================================================
//  Positive path — sufficient stock, completes normally regardless of flag
// =========================================================================

it('completes normally when stock is sufficient regardless of allow_negative_sales setting', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => true,
    ]);

    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $this->material->id,
        'quantity' => 20,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    $txn = consumeMaterial($this->service, $this->orgId, $warehouse->id, $this->material->id, 10);

    $statusValue = $txn->status instanceof BackedEnum ? $txn->status->value : (string) $txn->status;
    expect($statusValue)->toBe('completed');

    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('material_id', $this->material->id)
        ->first();
    expect((float) $level->quantity)->toBe(10.0);

    // #2409 review — the half that was documented but never asserted.
    //
    // `allow_negative_sales` emits the unmet residual as a NULL-lot line, and
    // that line DECREMENTS the NULL-lot row rather than being written as a
    // negative outright. The forced alert hangs off `$quantityBefore <
    // $changeQuantity` alone, so a positive NULL-lot balance that covers the
    // draw finishes silently. Asserting only `quantity === 10.0` above cannot
    // tell that apart from a version that fires out_of_stock on every
    // allow-negative residual — which is exactly what the service comment and
    // `inventory-domain.md` used to claim.
    expect(StockAlert::where('warehouse_id', $warehouse->id)
        ->where('material_id', $this->material->id)
        ->exists())->toBeFalse();
});

// =========================================================================
//  Happy 8 — allow_negative_sales=false → InsufficientStockException
// =========================================================================

it('throws InsufficientStockException when allow_negative_sales=false and stock is insufficient', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => false,
    ]);

    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $this->material->id,
        'quantity' => 5,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    expect(fn () => consumeMaterial($this->service, $this->orgId, $warehouse->id, $this->material->id, 10))
        ->toThrow(InsufficientStockException::class);

    // StockLevel should be unchanged
    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('material_id', $this->material->id)
        ->first();
    expect((float) $level->quantity)->toBe(5.0);
});

// =========================================================================
//  Edge 5 — zero StockLevel (first-ever consumption)
// =========================================================================

it('throws when allow_negative_sales=false and no StockLevel row exists', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => false,
    ]);
    // No StockLevel seeded — first-ever consumption

    expect(fn () => consumeMaterial($this->service, $this->orgId, $warehouse->id, $this->material->id, 10))
        ->toThrow(InsufficientStockException::class);
});

it('creates StockLevel with negative quantity when allow_negative_sales=true and zero existing stock', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => true,
    ]);
    // No StockLevel seeded — first-ever consumption

    $txn = consumeMaterial($this->service, $this->orgId, $warehouse->id, $this->material->id, 10);

    expect($txn->status->value)->toBe('completed');

    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('material_id', $this->material->id)
        ->whereNull('material_lot_id')
        ->first();
    expect($level)->not->toBeNull();
    expect((float) $level->quantity)->toBe(-10.0);

    $alert = StockAlert::where('warehouse_id', $warehouse->id)
        ->where('material_id', $this->material->id)
        ->where('status', 'active')
        ->first();
    expect($alert)->not->toBeNull();
    $alertType = $alert->alert_type instanceof BackedEnum ? $alert->alert_type->value : (string) $alert->alert_type;
    expect($alertType)->toBe('out_of_stock');
});

// =========================================================================
//  Validation 2 — PUT stock-level with min_stock > max_stock → 422
// =========================================================================

it('returns 422 when min_stock exceeds max_stock', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);
    $stockLevel = StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $this->material->id,
        'quantity' => 100,
        'unit' => 'g',
        'alert_enabled' => true,
    ]);

    $shop = Branch::where('id', $this->branch->id)->first();

    $this->putJson("/api/v1/shops/{$shop->slug}/stock-levels/{$stockLevel->id}", [
        'min_stock' => 10,
        'max_stock' => 5,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock']);
});

// =========================================================================
//  Validation 3 — PUT stock-level with negative min_stock → 422
// =========================================================================

it('returns 422 when min_stock is negative', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);
    $stockLevel = StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $this->material->id,
        'quantity' => 100,
        'unit' => 'g',
        'alert_enabled' => true,
    ]);

    $shop = Branch::where('id', $this->branch->id)->first();

    $this->putJson("/api/v1/shops/{$shop->slug}/stock-levels/{$stockLevel->id}", [
        'min_stock' => -5, // invalid
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock']);
});

// =========================================================================
//  Validation 4 — PATCH warehouse settings with non-boolean allow_negative_sales → 422
// =========================================================================

it('returns 422 when allow_negative_sales is not a boolean', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $shop = Branch::where('id', $this->branch->id)->first();

    $this->patchJson("/api/v1/shops/{$shop->slug}/warehouses/{$warehouse->id}/settings", [
        'allow_negative_sales' => 'not-a-boolean',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['allow_negative_sales']);
});

// =========================================================================
//  Authz 5 — non-org-admin cannot change warehouse settings
// =========================================================================

it('returns 403 when a same-org non-org-admin (shop-manager) updates warehouse settings', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $shop = Branch::where('id', $this->branch->id)->first();

    // A shop-manager WITHIN this org — org boundary + shop-access middleware
    // both pass, so the 403 must come from the org-admin-only gate on
    // updateSettings. The previous version used a different-org user, which
    // only exercised the org boundary and never proved allow_negative_sales
    // is org-admin-restricted (plan-024 audit: fabricated guarantee).
    $manager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $managerRole = Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);
    $manager->assignRole($managerRole, $this->orgId, $this->branch->id);

    $this->actingAs($manager)
        ->patchJson("/api/v1/shops/{$shop->slug}/warehouses/{$warehouse->id}/settings", [
            'allow_negative_sales' => true,
        ])
        ->assertForbidden();
});

// =========================================================================
//  Authz 6 — org-admin can update warehouse settings
// =========================================================================

it('returns 200 when org-admin updates allow_negative_sales=true', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'allow_negative_sales' => false,
    ]);

    $shop = Branch::where('id', $this->branch->id)->first();

    $this->actingAs($this->user) // org-admin set in beforeEach
        ->patchJson("/api/v1/shops/{$shop->slug}/warehouses/{$warehouse->id}/settings", [
            'allow_negative_sales' => true,
        ])
        ->assertOk();

    $warehouse->refresh();
    expect((bool) $warehouse->allow_negative_sales)->toBeTrue();
});
