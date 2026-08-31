<?php

/**
 * A dine-in QR order must remember the language the guest ordered in.
 *
 * The workstation auto-prints the kitchen + hold slips from the sync loop,
 * where there is no request (and therefore no Accept-Language) to read — so
 * the locale has to travel ON the order: stamped here at create, exposed by
 * `CustomerOrderResource`, mirrored into the workstation's local SQLite by the
 * pull, and finally used as the print locale.
 */

use App\Http\Resources\CustomerOrderResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->zone = Zone::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'locale-test-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    $this->sku = ProductSku::factory()->create();
});

it('stamps the ordering locale on a dine-in QR order', function () {
    $this->withHeaders(['Accept-Language' => 'ja'])
        ->postJson('/api/v1/customer/tables/locale-test-token/orders', [
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        ])
        ->assertStatus(201);

    expect(CustomerOrder::query()->latest('created_at')->first()->customer_locale)->toBe('ja');
});

it('stamps each supported locale from Accept-Language', function (string $header, string $expected) {
    $this->withHeaders(['Accept-Language' => $header])
        ->postJson('/api/v1/customer/tables/locale-test-token/orders', [
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        ])
        ->assertStatus(201);

    expect(CustomerOrder::query()->latest('created_at')->first()->customer_locale)->toBe($expected);
})->with([
    'japanese' => ['ja', 'ja'],
    'english with region' => ['en-US,en;q=0.9', 'en'],
    'vietnamese' => ['vi', 'vi'],
]);

it('exposes customer_locale through CustomerOrderResource', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'customer_locale' => 'ja',
    ]);

    $payload = (new CustomerOrderResource($order))->toArray(Request::create('/'));

    expect($payload['customer_locale'])->toBe('ja');
});
