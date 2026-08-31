<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan 003 — Fix F3 CreateProductWithOptionsTest
 *
 * Covers the nested Shopify-style create endpoint introduced by the
 * F1/F2 fix: `POST /api/v1/hq/{brandSlug}/products` with `options[]`
 * + `skus[]` carrying `value_indices[]` so that a single transaction
 * persists the product, its options, its option values, and the
 * generated SKUs all together.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-nested',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

describe('happy path', function () {
    it('creates a product with 2 options × 2 values and 4 SKUs in one request', function () {
        $payload = [
            'name' => 'T-Shirt',
            'slug' => 'tshirt-'.Str::random(4),
            'product_type_id' => $this->productType->id,
            'status' => 'draft',
            'options' => [
                [
                    'key' => 'size',
                    'name' => 'Size',
                    'position' => 1,
                    'is_active' => true,
                    'values' => [
                        ['value' => 's', 'label' => 'S', 'position' => 1],
                        ['value' => 'm', 'label' => 'M', 'position' => 2],
                    ],
                ],
                [
                    'key' => 'color',
                    'name' => 'Color',
                    'position' => 2,
                    'is_active' => true,
                    'values' => [
                        ['value' => 'red', 'label' => 'Red', 'position' => 1],
                        ['value' => 'blue', 'label' => 'Blue', 'position' => 2],
                    ],
                ],
            ],
            'skus' => [
                ['value_indices' => [0, 0], 'cost_price' => 1000, 'is_cost_override' => true],
                ['value_indices' => [0, 1], 'cost_price' => 1100, 'is_cost_override' => true],
                ['value_indices' => [1, 0], 'cost_price' => 1050, 'is_cost_override' => true],
                ['value_indices' => [1, 1], 'cost_price' => 1150, 'is_cost_override' => true],
            ],
        ];

        $response = $this->actingAs($this->user)->postJson($this->base, $payload);

        $response->assertCreated()->assertJsonPath('data.name', 'T-Shirt');

        $productId = $response->json('data.id');
        $product = Product::with(['options.values', 'skus'])->findOrFail($productId);

        expect($product->options)->toHaveCount(2)
            ->and($product->options->firstWhere('position', 1)->key)->toBe('size')
            ->and($product->options->firstWhere('position', 2)->key)->toBe('color')
            ->and($product->options->firstWhere('position', 1)->values)->toHaveCount(2)
            ->and($product->options->firstWhere('position', 2)->values)->toHaveCount(2)
            ->and($product->skus)->toHaveCount(4);

        // Each SKU should have option_value1_id and option_value2_id resolved.
        $product->skus->each(function (ProductSku $sku) {
            expect($sku->option_value1_id)->not->toBeNull()
                ->and($sku->option_value2_id)->not->toBeNull()
                ->and($sku->option_value3_id)->toBeNull()
                ->and($sku->is_cost_override)->toBeTrue();
        });
    });

    it('preserves option positions even when posted out of order', function () {
        // Post option position=2 first, position=1 second. The service should
        // still write SKU column values to the correct option_value{N}_id slot
        // based on the option's `position` field, not the input order.
        $payload = [
            'name' => 'Reverse Order',
            'slug' => 'reverse-'.Str::random(4),
            'product_type_id' => $this->productType->id,
            'options' => [
                [
                    'key' => 'color',
                    'name' => 'Color',
                    'position' => 2,
                    'values' => [
                        ['value' => 'red', 'label' => 'Red'],
                    ],
                ],
                [
                    'key' => 'size',
                    'name' => 'Size',
                    'position' => 1,
                    'values' => [
                        ['value' => 's', 'label' => 'S'],
                    ],
                ],
            ],
            'skus' => [
                // value_indices follows input order: [color=Red, size=S]
                ['value_indices' => [0, 0], 'cost_price' => 999],
            ],
        ];

        $response = $this->actingAs($this->user)->postJson($this->base, $payload);
        $response->assertCreated();

        $productId = $response->json('data.id');
        $sku = ProductSku::where('product_id', $productId)->firstOrFail();

        // The Color option has position=2, so its value should land in
        // option_value2_id. The Size option has position=1, so its value
        // should land in option_value1_id. This proves the service walks
        // by `position`, not input order.
        $sizeOption = ProductOption::where('product_id', $productId)->where('position', 1)->firstOrFail();
        $colorOption = ProductOption::where('product_id', $productId)->where('position', 2)->firstOrFail();

        $sValue = ProductOptionValue::where('option_id', $sizeOption->id)->where('value', 's')->firstOrFail();
        $redValue = ProductOptionValue::where('option_id', $colorOption->id)->where('value', 'red')->firstOrFail();

        expect($sku->option_value1_id)->toBe($sValue->id)
            ->and($sku->option_value2_id)->toBe($redValue->id);
    });
});

describe('selling_price (issue #875)', function () {
    it('persists selling_price per variant SKU and keeps cost_price at 0', function () {
        // The create form sends the menu price as selling_price with cost_price
        // fixed at 0 and is_cost_override=false (cost auto-derived later).
        $payload = [
            'name' => 'Priced Tee',
            'slug' => 'priced-'.Str::random(4),
            'product_type_id' => $this->productType->id,
            'options' => [[
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                ],
            ]],
            'skus' => [
                ['value_indices' => [0], 'selling_price' => 1500, 'cost_price' => 0, 'is_cost_override' => false],
                ['value_indices' => [1], 'selling_price' => 1800, 'cost_price' => 0, 'is_cost_override' => false],
            ],
        ];

        $response = $this->actingAs($this->user)->postJson($this->base, $payload);
        $response->assertCreated();

        $productId = $response->json('data.id');
        $skus = ProductSku::where('product_id', $productId)->get();

        expect($skus)->toHaveCount(2);
        $skus->each(function (ProductSku $sku) {
            expect((float) $sku->cost_price)->toBe(0.0)
                ->and($sku->is_cost_override)->toBeFalse();
        });
        expect($skus->pluck('selling_price')->map(fn ($p) => (float) $p)->sort()->values()->all())
            ->toBe([1500.0, 1800.0]);
    });

    it('persists selling_price for a non-variant (options-less) quick create', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Single SKU',
            'slug' => 'single-'.Str::random(4),
            'product_type_id' => $this->productType->id,
            'skus' => [
                ['selling_price' => 2500, 'cost_price' => 0, 'is_cost_override' => false],
            ],
        ]);
        $response->assertCreated();

        $sku = ProductSku::where('product_id', $response->json('data.id'))->firstOrFail();
        expect((float) $sku->selling_price)->toBe(2500.0)
            ->and((float) $sku->cost_price)->toBe(0.0);
    });

    it('mirrors selling_price from cost_price when selling_price is omitted (legacy fallback)', function () {
        // Backwards compat: older clients that only send cost_price must still
        // get a non-zero menu price via the createSku mirror.
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Legacy Priced',
            'slug' => 'legacy-'.Str::random(4),
            'product_type_id' => $this->productType->id,
            'options' => [[
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [['value' => 's', 'label' => 'S']],
            ]],
            'skus' => [
                ['value_indices' => [0], 'cost_price' => 1200, 'is_cost_override' => true],
            ],
        ]);
        $response->assertCreated();

        $sku = ProductSku::where('product_id', $response->json('data.id'))->firstOrFail();
        expect((float) $sku->selling_price)->toBe(1200.0)
            ->and((float) $sku->cost_price)->toBe(1200.0);
    });
});

describe('validation', function () {
    it('returns 422 when a SKU selling_price is negative', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Bad Price',
                'product_type_id' => $this->productType->id,
                'skus' => [
                    ['selling_price' => -1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['skus.0.selling_price']);
    });

    it('returns 422 when an option key is not lowercase alphanumeric', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Bad Key',
                'product_type_id' => $this->productType->id,
                'options' => [[
                    'key' => 'Size!',  // uppercase + symbol — invalid
                    'name' => 'Size',
                    'position' => 1,
                    'values' => [['value' => 's', 'label' => 'S']],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options.0.key']);
    });

    it('returns 422 when more than 3 options are submitted', function () {
        $options = [];
        foreach (['a', 'b', 'c', 'd'] as $i => $key) {
            $options[] = [
                'key' => $key,
                'name' => strtoupper($key),
                'position' => ($i % 3) + 1,
                'values' => [['value' => 'v', 'label' => 'V']],
            ];
        }

        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Too Many',
                'product_type_id' => $this->productType->id,
                'options' => $options,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options']);
    });

    it('returns 422 when an option position is outside 1..3', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Bad Pos',
                'product_type_id' => $this->productType->id,
                'options' => [[
                    'key' => 'size',
                    'name' => 'Size',
                    'position' => 5,
                    'values' => [['value' => 's', 'label' => 'S']],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options.0.position']);
    });

    it('returns 422 when an option has zero values', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Empty Values',
                'product_type_id' => $this->productType->id,
                'options' => [[
                    'key' => 'size',
                    'name' => 'Size',
                    'position' => 1,
                    'values' => [],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options.0.values']);
    });
});

describe('service-level validation', function () {
    it('rolls back the entire create when a SKU references a value_index that does not exist', function () {
        $before = Product::count();

        $payload = [
            'name' => 'Bad Index',
            'slug' => 'badidx-'.Str::random(4),
            'product_type_id' => $this->productType->id,
            'options' => [[
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                ],
            ]],
            'skus' => [
                ['value_indices' => [99]],  // out of bounds
            ],
        ];

        $response = $this->actingAs($this->user)->postJson($this->base, $payload);

        // The service throws InvalidArgumentException which Laravel renders
        // as a 500 by default. We assert NOT 201 + DB unchanged.
        expect($response->status())->not->toBe(201);
        expect(Product::count())->toBe($before);
    });

    it('rolls back when a SKU has fewer value_indices than the number of options', function () {
        $before = Product::count();

        $payload = [
            'name' => 'Short Indices',
            'slug' => 'short-'.Str::random(4),
            'product_type_id' => $this->productType->id,
            'options' => [
                [
                    'key' => 'size',
                    'name' => 'Size',
                    'position' => 1,
                    'values' => [['value' => 's', 'label' => 'S']],
                ],
                [
                    'key' => 'color',
                    'name' => 'Color',
                    'position' => 2,
                    'values' => [['value' => 'red', 'label' => 'Red']],
                ],
            ],
            'skus' => [
                ['value_indices' => [0]],  // only one index, but two options
            ],
        ];

        $response = $this->actingAs($this->user)->postJson($this->base, $payload);
        expect($response->status())->not->toBe(201);
        expect(Product::count())->toBe($before);
    });
});

describe('authorization', function () {
    it('returns 401 when unauthenticated for the nested-create flow', function () {
        $this->postJson($this->base, [
            'name' => 'Guest',
            'product_type_id' => $this->productType->id,
            'options' => [[
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'values' => [['value' => 's', 'label' => 'S']],
            ]],
            'skus' => [['value_indices' => [0]]],
        ])->assertUnauthorized();
    });
});

describe('backwards compat', function () {
    it('still creates a default SKU when neither options nor skus are provided', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Quick Add',
            'slug' => 'quick-'.Str::random(4),
            'product_type_id' => $this->productType->id,
        ]);

        $response->assertCreated();
        $productId = $response->json('data.id');

        expect(ProductOption::where('product_id', $productId)->count())->toBe(0)
            ->and(ProductSku::where('product_id', $productId)->count())->toBe(1);
    });
});
