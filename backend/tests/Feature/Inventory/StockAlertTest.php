<?php

use App\Models\Branch;
use App\Models\ProductSku;
use App\Models\StockAlert;
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

it('returns 401 when not authenticated for index', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/stock-alerts")
        ->assertUnauthorized();
});

it('returns 401 when not authenticated for summary', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/stock-alerts/summary")
        ->assertUnauthorized();
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists stock alerts for the user organization', function () {
        StockAlert::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-alerts")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not show alerts from other organizations', function () {
        StockAlert::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        StockAlert::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-alerts")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters alerts by status', function () {
        StockAlert::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'status' => 'active',
            'resolved_at' => null,
        ]);
        StockAlert::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'status' => 'resolved',
        ]);

        $this->getJson("{$this->baseUrl}/stock-alerts?status=active")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters alerts by alert_type', function () {
        StockAlert::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'alert_type' => 'low_stock',
        ]);
        StockAlert::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'alert_type' => 'out_of_stock',
        ]);

        $this->getJson("{$this->baseUrl}/stock-alerts?alert_type=low_stock")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters alerts by warehouse_id', function () {
        $otherWarehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId,
        ]);

        StockAlert::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);
        StockAlert::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $otherWarehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-alerts?warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('returns paginated results with meta', function () {
        StockAlert::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-alerts?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'total'],
            ]);
    });

    it('returns empty list when no alerts exist', function () {
        $this->getJson("{$this->baseUrl}/stock-alerts")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

// =========================================================================
//  Summary
// =========================================================================

describe('summary', function () {
    it('returns alert counts grouped by status', function () {
        StockAlert::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'status' => 'active',
            'resolved_at' => null,
        ]);
        StockAlert::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'status' => 'resolved',
        ]);

        $this->getJson("{$this->baseUrl}/stock-alerts/summary")
            ->assertOk()
            ->assertJsonPath('data.active', 3)
            ->assertJsonPath('data.resolved', 2);
    });

    it('returns zero counts when no alerts exist', function () {
        $this->getJson("{$this->baseUrl}/stock-alerts/summary")
            ->assertOk()
            ->assertJsonPath('data.active', 0)
            ->assertJsonPath('data.resolved', 0);
    });

    it('excludes alerts from other organizations in summary', function () {
        StockAlert::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'status' => 'active',
            'resolved_at' => null,
        ]);
        StockAlert::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'status' => 'active',
            'resolved_at' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-alerts/summary")
            ->assertOk()
            ->assertJsonPath('data.active', 2);
    });
});
