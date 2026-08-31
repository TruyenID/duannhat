<?php

/**
 * plan-040 Cluster H — TH.8 (NEW-BP-2): a ProductionOrder whose
 * `recipe_multiplier` overrides the SKU's default scales the derived item
 * quantities by the resolved (order) multiplier, not the SKU's.
 */

use App\Models\Branch;
use App\Models\Material;
use App\Models\Organization;
use App\Models\ProductionOrder;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\Warehouse;
use App\Services\Inventory\ProductionOrderService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);
    $this->service = app(ProductionOrderService::class);
});

it('scales derived items by the order recipe_multiplier overriding the SKU default (NEW-BP-2)', function () {
    $flour = Material::factory()->create(['organization_id' => $this->orgId]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'output_quantity' => 1,
        'ingredients' => [
            ['type' => 'material', 'material_id' => $flour->id, 'quantity' => 100, 'unit' => 'g'],
        ],
    ]);

    // SKU default multiplier 1, but the order overrides with 2.
    $sku = ProductSku::factory()->create([
        'recipe_id' => $recipe->id,
        'recipe_multiplier' => 1.0,
    ]);

    $order = $this->service->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'output_variant_id' => $sku->id,
        'planned_quantity' => 3,
        'output_unit' => 'piece',
        'recipe_multiplier' => 2.0,
        'created_by_id' => (string) Str::uuid(),
    ]);

    $order = ProductionOrder::with('items')->findOrFail($order->id);

    // 100g × planned(3) × multiplier(2) / output_quantity(1) = 600g.
    expect($order->items)->toHaveCount(1)
        ->and((float) $order->items->first()->planned_quantity)->toBe(600.0)
        ->and($order->items->first()->material_id)->toBe($flour->id);
});
