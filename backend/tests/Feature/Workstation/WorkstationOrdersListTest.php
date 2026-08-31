<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Organization;
use App\Models\TaxType;
use Illuminate\Support\Str;

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

    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('requires a device token', function () {
    $this->getJson('/api/v1/workstation/orders')->assertUnauthorized();
});

it('returns orders for the device branch only', function () {
    CustomerOrder::factory()
        ->count(3)
        ->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
        ]);

    // Different branch (same org) → should NOT appear.
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    CustomerOrder::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('count', 3);
});

it('filters by `since` param', function () {
    CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'created_at' => now()->subDays(60),
    ]);
    CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'created_at' => now()->subDays(5),
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders?since='.urlencode(now()->subDays(30)->toIso8601String()));

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('respects `limit` param', function () {
    CustomerOrder::factory()->count(10)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders?limit=5');

    $response->assertOk()->assertJsonCount(5, 'data');
});

it('embeds nested order items', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
    CustomerOrderItem::factory()->count(2)->create([
        'customer_order_id' => $order->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(2, 'data.0.items');
});

// plan-043 T3.2 — the pull-DOWN order feed must carry the stamped per-line
// consumption-tax snapshot (tax_type_id / tax_rate / tax_amount /
// rebuilds its local tax state after a re-pair/crash without recomputing.
// Keys are additive; old workstations ignore the extra fields.
it('embeds the per-line tax snapshot + order-level is_tax_included', function () {
    $taxType = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'is_tax_included' => true,
        'tax_amount' => 640,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'tax_type_id' => $taxType->id,
        'tax_rate' => 8.00,
        'tax_amount' => 640,
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_tax_included', true)
        ->assertJsonPath('data.0.items.0.tax_type_id', $taxType->id)
        ->assertJsonPath('data.0.items.0.tax_rate', '8.00')
        ->assertJsonPath('data.0.items.0.tax_amount', '640.00');
});

// plan-038 T1.1 — `?id=` projection for workstation force-pull-on-demand.

it('returns a single order when filtered by `id`', function () {
    $orders = CustomerOrder::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
    $target = $orders->random();
    CustomerOrderItem::factory()->count(2)->create([
        'customer_order_id' => $target->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders?id='.$target->id);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id)
        ->assertJsonCount(2, 'data.0.items')
        ->assertJsonPath('cursor_field', 'id')
        ->assertJsonPath('count', 1);
});

it('returns empty data when `id` does not exist in the branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $foreign = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders?id='.$foreign->id);

    $response->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('count', 0);
});

it('rejects combining `id` with `since` or `updated_since`', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders?id='.Str::uuid().'&since='.urlencode(now()->toIso8601String()));

    $response->assertStatus(422);
});

it('rejects malformed `id` with 422', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders?id=not-a-uuid');

    $response->assertStatus(422);
});
