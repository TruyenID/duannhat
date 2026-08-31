<?php

use App\Models\Branch;
use App\Models\ProductSku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->variant = ProductSku::factory()->create();

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/shops/{$this->shop->slug}";

    $this->actingAs($this->user);
});

// =========================================================================
//  Authentication
// =========================================================================

it('returns 401 when not authenticated', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/stock-levels")
        ->assertUnauthorized();
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists stock levels for the user organization', function () {
        $variants = ProductSku::factory()->count(3)->create();
        foreach ($variants as $variant) {
            StockLevel::factory()->create([
                'warehouse_id' => $this->warehouse->id,
                'product_sku_id' => $variant->id,
                'material_id' => null,
            ]);
        }

        $this->getJson("{$this->baseUrl}/stock-levels")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not show stock levels from other organizations', function () {
        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $otherWarehouse = Warehouse::factory()->create(['organization_id' => fake()->uuid()]);
        StockLevel::factory()->create([
            'warehouse_id' => $otherWarehouse->id,
            'product_sku_id' => ProductSku::factory()->create()->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-levels")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters stock levels by warehouse_id', function () {
        $otherWarehouse = Warehouse::factory()->create(['organization_id' => $this->orgId]);

        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);
        StockLevel::factory()->create([
            'warehouse_id' => $otherWarehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-levels?warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters stock levels by alert_enabled', function () {
        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'alert_enabled' => true,
        ]);
        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => ProductSku::factory()->create()->id,
            'material_id' => null,
            'alert_enabled' => false,
        ]);

        $this->getJson("{$this->baseUrl}/stock-levels?alert_enabled=1")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a stock level with warehouse and variant', function () {
        $level = StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-levels/{$level->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $level->id)
            ->assertJsonStructure(['data' => ['id', 'quantity', 'warehouse', 'warehouse_name']]);
    });

    it('returns 404 for non-existent stock level', function () {
        $this->getJson("{$this->baseUrl}/stock-levels/".fake()->uuid())
            ->assertNotFound();
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('updates min_stock and max_stock', function () {
        $level = StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'min_stock' => 10,
            'max_stock' => 100,
        ]);

        $this->putJson("{$this->baseUrl}/stock-levels/{$level->id}", [
            'min_stock' => 20,
            'max_stock' => 200,
        ])
            ->assertOk()
            ->assertJsonPath('data.min_stock', 20)
            ->assertJsonPath('data.max_stock', 200);

        expect((float) $level->fresh()->min_stock)->toBe(20.0)
            ->and((float) $level->fresh()->max_stock)->toBe(200.0);
    });

    it('updates alert_enabled', function () {
        $level = StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'alert_enabled' => false,
        ]);

        $this->putJson("{$this->baseUrl}/stock-levels/{$level->id}", [
            'alert_enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.alert_enabled', true);
    });
});

// =========================================================================
//  Movements
// =========================================================================

describe('movements', function () {
    it('lists movements for a stock level', function () {
        $level = StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);

        StockMovement::factory()->count(3)->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'stock_transaction_id' => $txn->id,
        ]);

        $this->getJson("{$this->baseUrl}/stock-levels/{$level->id}/movements")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not return movements from other warehouse/variant combinations', function () {
        $level = StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);

        StockMovement::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'stock_transaction_id' => $txn->id,
        ]);

        $otherVariant = ProductSku::factory()->create();
        StockMovement::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $otherVariant->id,
            'material_id' => null,
            'stock_transaction_id' => $txn->id,
        ]);

        $this->getJson("{$this->baseUrl}/stock-levels/{$level->id}/movements")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});
