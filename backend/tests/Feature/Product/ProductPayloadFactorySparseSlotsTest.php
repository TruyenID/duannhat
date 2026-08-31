<?php

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Services\Product\ProductPayloadFactory;
use Illuminate\Support\Str;

it('retains a null gap when create payload only has option position two', function () {
    $payload = app(ProductPayloadFactory::class)->forCreate([
        'name' => 'Coffee',
        'options' => [[
            'key' => 'temperature', 'name' => 'Temperature', 'position' => 2,
            'values' => [['value' => 'hot', 'label' => 'Hot', 'position' => 1]],
        ]],
        'skus' => [['value_indices' => [0], 'selling_price' => 500]],
    ]);

    expect($payload->skus[0]->optionValueIds)->toBe([null, $payload->options[0]->values[0]->valueId]);
});

it('retains the middle null gap for positions one and three on create and revision', function () {
    $payload = app(ProductPayloadFactory::class)->forCreate([
        'name' => 'Coffee',
        'options' => [
            ['key' => 'size', 'name' => 'Size', 'position' => 1, 'values' => [['value' => 's', 'label' => 'S', 'position' => 1]]],
            ['key' => 'milk', 'name' => 'Milk', 'position' => 3, 'values' => [['value' => 'oat', 'label' => 'Oat', 'position' => 1]]],
        ],
        'skus' => [['value_indices' => [0, 0], 'selling_price' => 500]],
    ]);
    $firstValueId = collect($payload->options)->firstWhere('position', 1)->values[0]->valueId;
    $thirdValueId = collect($payload->options)->firstWhere('position', 3)->values[0]->valueId;
    expect($payload->skus[0]->optionValueIds)->toBe([$firstValueId, null, $thirdValueId]);

    $product = Product::factory()->create();
    $firstOption = ProductOption::factory()->create(['product_id' => $product->id, 'position' => 1, 'key' => 'size']);
    $thirdOption = ProductOption::factory()->create(['product_id' => $product->id, 'position' => 3, 'key' => 'milk']);
    $firstValue = ProductOptionValue::factory()->create(['option_id' => $firstOption->id, 'position' => 1]);
    $thirdValue = ProductOptionValue::factory()->create(['option_id' => $thirdOption->id, 'position' => 1]);
    ProductSku::factory()->create([
        'id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'option_value1_id' => $firstValue->id,
        'option_value2_id' => null,
        'option_value3_id' => $thirdValue->id,
    ]);

    $revision = app(ProductPayloadFactory::class)->forRevision($product, []);
    expect($revision->skus[0]->optionValueIds)->toBe([$firstValue->id, null, $thirdValue->id]);
});
