<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
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

it('creates a Cloud order from workstation sync UP payload', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/orders', [
        'order_type' => 'takeaway',
        'customer_takeaway_name' => 'Anh Tuấn',
        'customer_takeaway_phone' => '0901234567',
        'note' => 'Không hành',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'order_code', 'status']]);

    expect(CustomerOrder::where('id', $response->json('data.id'))->exists())->toBeTrue();
});

// plan-043 T3.2 — the sync-UP response now serializes the full
// CustomerOrderResource so it carries is_tax_included + ledger tax_amount +
// (empty here) items[] with per-line tax. The store path creates an order
// shell (no items), so items is []. id/order_code/status stay present. Keys
// are additive — old workstations ignore them.
it('serializes is_tax_included + items on the sync-UP response', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/orders', [
        'order_type' => 'takeaway',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'order_code', 'status', 'is_tax_included', 'tax_amount', 'items']]);

    expect($response->json('data.items'))->toBe([]);
});

it('persists branch_id and organization_id from the device on the created order', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/orders', [
        'order_type' => 'takeaway',
    ]);

    $response->assertCreated();

    $order = CustomerOrder::find($response->json('data.id'));
    expect($order->branch_id)->toBe($this->branch->id)
        ->and($order->organization_id)->toBe($this->orgId);
});

it('leaves created_by_id null (workstation has no end-user actor)', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/orders', [
        'order_type' => 'takeaway',
    ]);

    $response->assertCreated();

    $order = CustomerOrder::find($response->json('data.id'));
    // created_by_id semantically references users.id (see StockTransaction
    // belongsTo, HQ/Shop controllers). Workstation sync UP has no human
    // actor — null matches CustomerQrOrderService behaviour.
    expect($order->created_by_id)->toBeNull();
});

it('returns the same order id on retry with the same Idempotency-Key', function () {
    $idemKey = (string) Str::uuid();

    $first = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => $idemKey,
    ])->postJson('/api/v1/workstation/orders', ['order_type' => 'takeaway']);

    $second = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => $idemKey,
    ])->postJson('/api/v1/workstation/orders', ['order_type' => 'takeaway']);

    $first->assertCreated();
    $second->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(CustomerOrder::count())->toBe(1);
});

it('returns 422 when order_type is missing', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders', [])
        ->assertStatus(422);
});

it('returns 401 without auth', function () {
    $this->postJson('/api/v1/workstation/orders', ['order_type' => 'takeaway'])
        ->assertUnauthorized();
});

it('returns 403 when kiosk device hits /workstation/orders', function () {
    $kioskToken = Str::random(64);
    Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $kioskToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
        ->postJson('/api/v1/workstation/orders', ['order_type' => 'takeaway'])
        ->assertForbidden();
});
