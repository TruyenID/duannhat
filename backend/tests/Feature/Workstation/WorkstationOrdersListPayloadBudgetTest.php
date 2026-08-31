<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #2713 — `GET /workstation/orders` is the workstation's 5 s kitchen tick, the
 * hottest HTTP+DB path during service. It used to embed the FULL
 * `ProductSkuResource` → `ProductResource` per line, and `ProductResourceBase`
 * serializes `thumbnail`, `gallery` and `translations` UNCONDITIONALLY (no
 * `whenLoaded`) — `thumbnail` is a MorphOne, `gallery` a MorphMany and
 * `translations` an Astrotomic relation, so each one lazy-loads a query per
 * distinct SKU/product and then ships bytes the client throws away.
 *
 * The workstation decodes exactly three fields off the nested SKU —
 * `workstation/internal/service/sync_pull.go:598-604` (`cloudProductSkuStub`:
 * `name`, `sku`, `product.name`) — consumed by `nameFromStub` (:610) via
 * `resolveMenuItemName` (:844) at the item upsert (:1208).
 *
 * These tests assert the ABSENCE of the dropped blob, not the presence of the
 * order: a "call the endpoint, see an order" test stays green while the payload
 * still dumps galleries.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);

    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

/**
 * Build $orderCount orders, each carrying $linesPerOrder lines on their OWN
 * SKU + product. Distinct products matter: the lazy relations the old payload
 * touched are cached per model instance, so a fixture that reuses one product
 * would measure one query where production pays N.
 */
function ws2713Seed(int $orderCount, int $linesPerOrder): void
{
    foreach (range(1, $orderCount) as $i) {
        $order = CustomerOrder::factory()->create([
            'organization_id' => test()->orgId,
            'brand_id' => test()->brand->id,
            'branch_id' => test()->branch->id,
            'status' => 'open',
        ]);

        foreach (range(1, $linesPerOrder) as $j) {
            CustomerOrderItem::factory()->create([
                'customer_order_id' => $order->id,
                'product_sku_id' => ProductSku::factory()->create()->id,
                'quantity' => 1,
            ]);
        }
    }
}

function ws2713Get(): array
{
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $response = test()->getJson(
        // urlencode: an ISO-8601 offset carries a `+`, which decodes to a
        // space in a query string and 422s the `date` rule. Go escapes it for
        // the same reason (sync_pull.go:650).
        '/api/v1/workstation/orders?updated_since='.urlencode(now()->subDay()->toIso8601String()),
        ['Authorization' => 'Bearer '.test()->wsToken],
    );

    $response->assertOk();

    return [
        'response' => $response,
        'queries' => $queries,
        'bytes' => strlen($response->getContent()),
        'json' => $response->json(),
    ];
}

it('ships only the three SKU fields the workstation decodes — no product blob', function () {
    ws2713Seed(orderCount: 1, linesPerOrder: 1);

    $result = ws2713Get();
    $item = $result['json']['data'][0]['items'][0];

    // The nested SKU is exactly `cloudProductSkuStub` (sync_pull.go:598-604).
    expect(array_keys($item['product_sku']))
        ->toEqualCanonicalizing(['name', 'sku', 'product']);
    expect(array_keys($item['product_sku']['product']))
        ->toEqualCanonicalizing(['name']);

    // The blob `ProductResourceBase` used to force onto every 5 s tick.
    $raw = $result['response']->getContent();
    expect(str_contains($raw, 'gallery'))->toBeFalse('gallery is never decoded by the workstation');
    expect(str_contains($raw, 'thumbnail'))->toBeFalse('thumbnail is never decoded by the workstation');
    expect(str_contains($raw, 'translations'))->toBeFalse('translations are never decoded by the workstation');
    expect(str_contains($raw, 'cost_price'))->toBeFalse('SKU cost price must not leave Cloud on an order feed');
});

it('ships toppings flat, without the duplicate Omnify relation and its product blob', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'open',
    ]);
    $line = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => ProductSku::factory()->create()->id,
        'quantity' => 1,
    ]);

    $group = ToppingGroup::factory()->create(['modifier_type' => 'add']);
    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $line->id,
        'topping_group_item_id' => ToppingGroupItem::factory()->create([
            'topping_group_id' => $group->id,
            'product_id' => Product::factory()->active()->create()->id,
        ])->id,
        'product_sku_id' => ProductSku::factory()->create()->id,
        'quantity' => 1,
    ]);

    $result = ws2713Get();
    $item = $result['json']['data'][0]['items'][0];

    // The flat array is what Go decodes (`cloudOrderItemPayload.Toppings`,
    // sync_pull.go:572) — it must survive.
    expect($item['toppings'])->toHaveCount(1);
    foreach (['id', 'topping_group_id', 'topping_group_name', 'topping_group_item_id',
        'product_sku_id', 'name', 'modifier_type', 'quantity', 'unit_price',
        'waived_quantity', 'note'] as $field) {
        expect($item['toppings'][0])->toHaveKey($field);
    }

    // The raw Omnify relation is a duplicate of it and drags a second
    // ProductResource per topping. Nothing in the Go tree decodes it.
    expect($item)->not->toHaveKey('orderItemToppings');

    $raw = $result['response']->getContent();
    expect(str_contains($raw, 'gallery'))->toBeFalse('a topping must not drag a product gallery onto the tick');
    expect(str_contains($raw, 'thumbnail'))->toBeFalse('a topping must not drag a product thumbnail onto the tick');
    expect(str_contains($raw, 'translations'))->toBeFalse('a topping must not drag product translations onto the tick');
});

it('keeps every field the Go pull decodes off an order line', function () {
    ws2713Seed(orderCount: 1, linesPerOrder: 1);

    $item = ws2713Get()['json']['data'][0]['items'][0];

    // Field-for-field with `cloudOrderItemPayload` (sync_pull.go:511-572).
    foreach ([
        'id', 'product_sku_id', 'quantity', 'unit_price', 'subtotal',
        'topping_subtotal', 'original_unit_price', 'price_source', 'note',
        'status', 'served_at', 'voided_at', 'updated_at', 'tax_type_id',
        'tax_rate', 'tax_amount', 'refund_of_item_id', 'refunded_quantity',
        'product_sku',
    ] as $field) {
        expect($item)->toHaveKey($field);
    }
});

it('serves the feed without lazy-loading a single relation', function () {
    ws2713Seed(orderCount: 3, linesPerOrder: 2);

    // Scoped ON/OFF inside the test: `preventLazyLoading` is global static
    // state, and leaving it armed leaks into every test that runs after this
    // one in the same process.
    Model::preventLazyLoading();

    try {
        $result = ws2713Get();
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($result['json']['count'])->toBe(3);
});

it('holds a flat query budget as lines are added', function () {
    // 3 orders x 2 lines = 6 distinct SKUs + 6 distinct products. The old
    // payload paid 4 lazy queries per line on top of the eager set
    // (sku.translations, product.translations, product.thumbnail,
    // product.gallery); the slim one pays a fixed eager set.
    ws2713Seed(orderCount: 3, linesPerOrder: 2);

    $result = ws2713Get();

    expect($result['queries'])->toBeLessThanOrEqual(20);
    expect($result['bytes'])->toBeLessThanOrEqual(20000);
});
