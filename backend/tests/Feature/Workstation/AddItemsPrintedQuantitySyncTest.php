<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Str;

/**
 * #2622 (tầng 1 của #2551) — `customer_order_items.printed_quantity`: máy trạm
 * báo số đơn vị của CHÍNH dòng đó đã gửi bếp (mirror của
 * `order_items.printed_quantity` phía máy trạm, migration 034) qua sync-UP
 * addItems → `transportWorkstationSyncItems`.
 *
 * Hợp đồng:
 *   - payload mang printed_quantity ⇒ cột Cloud KHỚP số máy trạm;
 *   - payload THIẾU field (build máy trạm cũ) ⇒ dòng mới giữ 0, dòng có sẵn
 *     GIỮ giá trị đã báo trước đó — không lỗi, không reset;
 *   - giá trị dị dạng clamp về [0, quantity] — transport hội tụ chứ không
 *     từ chối, một bookkeeping field không được dead-letter lô item mang tiền.
 *
 * Tầng này CHƯA nới luật gộp cùng SKU của Cloud (#S2 của #2551) — cột chỉ là
 * năng lực nhận số.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->taxType = TaxType::factory()->standard()->asDefault()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'service_charge_rate' => 0,
        'currency_code' => 'JPY',
        'prices_include_tax' => false,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->service = app(CustomerOrderService::class);
});

function pqsOrder(): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'PQS-'.Str::random(6),
        'order_type' => 'spot',
        'status' => 'open',
        'subtotal' => 0,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

function pqsSku(float $sellingPrice = 500): ProductSku
{
    $sku = ProductSku::factory()->create(['selling_price' => $sellingPrice]);
    $sku->product->update([
        'tax_type_id' => test()->taxType->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    return $sku;
}

it('mirrors the workstation-reported printed_quantity onto the Cloud line', function () {
    $order = pqsOrder();
    $sku = pqsSku();
    $itemId = (string) Str::uuid();

    test()->service->syncWorkstationItems($order, [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 5,
        'printed_quantity' => 2,
    ]], [0 => 500.0]);

    $line = CustomerOrderItem::findOrFail($itemId);
    expect((int) $line->printed_quantity)->toBe(2)
        ->and((float) $line->quantity)->toBe(5.0);
});

it('keeps printed_quantity at 0 when the payload does not carry the field (old workstation build)', function () {
    $order = pqsOrder();
    $sku = pqsSku();
    $itemId = (string) Str::uuid();

    test()->service->syncWorkstationItems($order, [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 3,
    ]], [0 => 500.0]);

    expect((int) CustomerOrderItem::findOrFail($itemId)->printed_quantity)->toBe(0);
});

it('updates printed_quantity on a BR-OI06 merge re-sync of an existing line', function () {
    // Máy trạm fire dòng ở qty 2 rồi khách gọi thêm → merge lên 5; item_add
    // re-read đẩy lên (quantity=5, printed_quantity=2). Cloud phải thấy đúng
    // phần chênh chưa in trên chính dòng đó.
    $order = pqsOrder();
    $sku = pqsSku();
    $itemId = (string) Str::uuid();

    test()->service->syncWorkstationItems($order, [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'printed_quantity' => 2,
    ]], [0 => 500.0]);

    test()->service->syncWorkstationItems($order->refresh(), [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 5,
        'printed_quantity' => 2,
    ]], [0 => 500.0]);

    $line = CustomerOrderItem::findOrFail($itemId);
    expect((float) $line->quantity)->toBe(5.0)
        ->and((int) $line->printed_quantity)->toBe(2);
});

it('does not reset a previously-reported printed_quantity when a later payload omits the field', function () {
    // Ca hỗn hợp thật: dòng đã báo printed=2, sau đó một build cũ (hoặc một
    // op không mang field) re-sync cùng dòng — số đã báo phải SỐNG SÓT.
    $order = pqsOrder();
    $sku = pqsSku();
    $itemId = (string) Str::uuid();

    test()->service->syncWorkstationItems($order, [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'printed_quantity' => 2,
    ]], [0 => 500.0]);

    test()->service->syncWorkstationItems($order->refresh(), [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 4,
    ]], [0 => 500.0]);

    $line = CustomerOrderItem::findOrFail($itemId);
    expect((float) $line->quantity)->toBe(4.0)
        ->and((int) $line->printed_quantity)->toBe(2);
});

it('clamps a negative printed_quantity to 0 instead of rejecting the batch', function () {
    $order = pqsOrder();
    $sku = pqsSku();
    $itemId = (string) Str::uuid();

    test()->service->syncWorkstationItems($order, [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 3,
        'printed_quantity' => -5,
    ]], [0 => 500.0]);

    expect((int) CustomerOrderItem::findOrFail($itemId)->printed_quantity)->toBe(0);
});

it('clamps printed_quantity above quantity down to quantity (unprinted delta can never go negative)', function () {
    $order = pqsOrder();
    $sku = pqsSku();
    $itemId = (string) Str::uuid();

    test()->service->syncWorkstationItems($order, [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 3,
        'printed_quantity' => 99,
    ]], [0 => 500.0]);

    expect((int) CustomerOrderItem::findOrFail($itemId)->printed_quantity)->toBe(3);
});

it('accepts the addItems endpoint without printed_quantity and leaves the column at 0', function () {
    // Đường HTTP thật (build máy trạm cũ): thiếu field không được lỗi.
    $order = pqsOrder();
    $sku = pqsSku();
    $itemId = (string) Str::uuid();

    $res = $this->postJson("/api/v1/workstation/orders/{$order->id}/items", [
        'items' => [[
            'id' => $itemId,
            'product_sku_id' => $sku->id,
            'quantity' => 2,
        ]],
    ], ['Authorization' => 'Bearer '.$this->wsToken]);

    $res->assertOk();
    expect((int) CustomerOrderItem::findOrFail($itemId)->printed_quantity)->toBe(0);
});

it('carries printed_quantity through the addItems endpoint itself', function () {
    // Ca này ghim đúng chỗ suýt lọt: `$request->validate()` STRIP mọi key không
    // có rule. Thiếu `items.*.printed_quantity` trong danh sách rule thì tầng
    // service không bao giờ thấy field — mọi test service-level vẫn xanh, và
    // tính năng chết im lặng trên đường HTTP thật.
    $order = pqsOrder();
    $sku = pqsSku();
    $itemId = (string) Str::uuid();

    $res = $this->postJson("/api/v1/workstation/orders/{$order->id}/items", [
        'items' => [[
            'id' => $itemId,
            'product_sku_id' => $sku->id,
            'quantity' => 5,
            'printed_quantity' => 3,
        ]],
    ], ['Authorization' => 'Bearer '.$this->wsToken]);

    $res->assertOk();
    expect((int) CustomerOrderItem::findOrFail($itemId)->printed_quantity)->toBe(3);
});

it('clamps printed_quantity to the line quantity through the endpoint', function () {
    // Cận trên nằm trong payload (`quantity`), không nằm trong rule — nên rule
    // chỉ chặn số âm, còn clamp là việc của service. Ghim cả hai đầu ở đây để
    // ai đó thêm `max:` vào rule sẽ thấy test này vẫn phải xanh.
    $order = pqsOrder();
    $sku = pqsSku();
    $itemId = (string) Str::uuid();

    $res = $this->postJson("/api/v1/workstation/orders/{$order->id}/items", [
        'items' => [[
            'id' => $itemId,
            'product_sku_id' => $sku->id,
            'quantity' => 2,
            'printed_quantity' => 99,
        ]],
    ], ['Authorization' => 'Bearer '.$this->wsToken]);

    $res->assertOk();
    expect((int) CustomerOrderItem::findOrFail($itemId)->printed_quantity)->toBe(2);
});
