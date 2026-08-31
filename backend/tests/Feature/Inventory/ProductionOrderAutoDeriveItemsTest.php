<?php

use App\Models\Branch;
use App\Models\Material;
use App\Models\ProductionOrder;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Warehouse;

/**
 * BR-02 — when the FE creates a ProductionOrder with only
 * (warehouse, output_variant, planned_quantity), ProductionOrderService
 * should derive the component items from the output SKU's recipe rather
 * than requiring callers to copy/paste ingredients by hand.
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    // Warehouse FK requires a real Branch row — random uuid from the
    // factory default fails on FK enforcement under the dockerised MySQL
    // test runner. The native sqlite runner skips FK checks but we want
    // both paths to pass.
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/shops/{$this->shop->slug}";

    $this->actingAs($this->user);
});

it('auto-derives items from the output SKU recipe when caller omits items', function () {
    $flour = Material::factory()->create(['organization_id' => $this->orgId]);
    $milk = Material::factory()->create(['organization_id' => $this->orgId]);

    // Recipe: 500g flour + 200ml milk = 10 pancakes per batch.
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'output_quantity' => 10,
        'output_unit' => 'piece',
        'ingredients' => [
            ['type' => 'material', 'material_id' => $flour->id, 'quantity' => 500, 'unit' => 'g'],
            ['type' => 'material', 'material_id' => $milk->id, 'quantity' => 200, 'unit' => 'ml'],
        ],
    ]);

    $sku = ProductSku::factory()->create([
        'recipe_id' => $recipe->id,
        'recipe_multiplier' => 1.0,
    ]);

    // Order for 30 pancakes — should consume 1500g flour + 600ml milk.
    $response = $this->postJson("{$this->baseUrl}/production-orders", [
        'warehouse_id' => $this->warehouse->id,
        'output_variant_id' => $sku->id,
        'planned_quantity' => 30,
        'output_unit' => 'piece',
    ])->assertCreated();

    $orderId = $response->json('data.id');
    $order = ProductionOrder::with('items')->findOrFail($orderId);

    expect($order->items)->toHaveCount(2);

    $flourItem = $order->items->firstWhere('material_id', $flour->id);
    $milkItem = $order->items->firstWhere('material_id', $milk->id);

    expect($flourItem)->not->toBeNull();
    expect((float) $flourItem->planned_quantity)->toBe(1500.0);
    expect($flourItem->unit)->toBe('g');

    expect($milkItem)->not->toBeNull();
    expect((float) $milkItem->planned_quantity)->toBe(600.0);
    expect($milkItem->unit)->toBe('ml');
});

it('honours sku.recipe_multiplier when deriving items', function () {
    $flour = Material::factory()->create(['organization_id' => $this->orgId]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'output_quantity' => 1,
        'ingredients' => [
            ['type' => 'material', 'material_id' => $flour->id, 'quantity' => 100, 'unit' => 'g'],
        ],
    ]);

    // Size L SKU uses 1.5× the recipe.
    $sku = ProductSku::factory()->create([
        'recipe_id' => $recipe->id,
        'recipe_multiplier' => 1.5,
    ]);

    $response = $this->postJson("{$this->baseUrl}/production-orders", [
        'warehouse_id' => $this->warehouse->id,
        'output_variant_id' => $sku->id,
        'planned_quantity' => 4,
        'output_unit' => 'piece',
    ])->assertCreated();

    $order = ProductionOrder::with('items')->findOrFail($response->json('data.id'));

    expect($order->items)->toHaveCount(1);
    // 100g × 4 orders × 1.5 multiplier ÷ 1 output_qty = 600g.
    expect((float) $order->items->first()->planned_quantity)->toBe(600.0);
});

it('leaves items empty when the SKU has no recipe', function () {
    $sku = ProductSku::factory()->create(['recipe_id' => null]);

    $response = $this->postJson("{$this->baseUrl}/production-orders", [
        'warehouse_id' => $this->warehouse->id,
        'output_variant_id' => $sku->id,
        'planned_quantity' => 10,
        'output_unit' => 'piece',
    ])->assertCreated();

    $order = ProductionOrder::with('items')->findOrFail($response->json('data.id'));

    expect($order->items)->toHaveCount(0);
});

it('honours caller-supplied items and skips derivation', function () {
    $flour = Material::factory()->create(['organization_id' => $this->orgId]);
    $sugar = Material::factory()->create(['organization_id' => $this->orgId]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'output_quantity' => 1,
        'ingredients' => [
            ['type' => 'material', 'material_id' => $flour->id, 'quantity' => 100, 'unit' => 'g'],
        ],
    ]);

    $sku = ProductSku::factory()->create(['recipe_id' => $recipe->id]);

    // Caller supplies its own items list (e.g. an experimental batch using
    // sugar instead of flour). Service must NOT overlay the recipe items on
    // top — caller intent wins.
    $response = $this->postJson("{$this->baseUrl}/production-orders", [
        'warehouse_id' => $this->warehouse->id,
        'output_variant_id' => $sku->id,
        'planned_quantity' => 5,
        'output_unit' => 'piece',
        'items' => [
            [
                'component_type' => 'material',
                'material_id' => $sugar->id,
                'planned_quantity' => 250,
                'unit' => 'g',
            ],
        ],
    ])->assertCreated();

    $order = ProductionOrder::with('items')->findOrFail($response->json('data.id'));

    expect($order->items)->toHaveCount(1);
    expect($order->items->first()->material_id)->toBe($sugar->id);
});
