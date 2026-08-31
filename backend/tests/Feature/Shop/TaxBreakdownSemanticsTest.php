<?php

use App\Http\Resources\CustomerOrderResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Services\Customer\OrderPricingCalculator;
use Illuminate\Support\Str;

/**
 * Audit fixes 1.3 + 1.4 (2026-07-14).
 *
 * 1.3 — CustomerOrderResource.tax_breakdown `taxable` used to be the raw Σ
 * line subtotal (pre-discount, gross in included mode) while `tax` was
 * post-discount, so taxable × rate never matched tax and the semantics
 * disagreed with KioskOrderResource. `taxable` is now the discounted net
 * (net-of-tax in 内税 mode) + additive `service_charge_tax` residual field.
 *
 * 1.4 — OrderPricingCalculator::forOrder recomputed a FINALIZED order's
 * breakdown with the CURRENT service-charge settings; editing the rate after
 * checkout made Σ groups.tax disagree with the frozen order.tax_amount. The
 * sc tax is now the residual against the stored figure.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
});

function breakdownOrder(array $orderAttrs, array $lines): CustomerOrder
{
    $order = CustomerOrder::create(array_merge([
        'order_code' => 'TBD-'.Str::random(6), 'order_type' => 'takeaway', 'status' => 'open',
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0, 'opened_at' => now(),
        'branch_id' => test()->branch->id, 'brand_id' => test()->brand->id, 'organization_id' => test()->orgId,
    ], $orderAttrs));

    foreach ($lines as $line) {
        $sku = ProductSku::factory()->create(['selling_price' => $line['unit_price']]);
        // #2617 — original_unit_price NOT NULL; các dòng dựng tay ở đây không
        // mô phỏng khuyến mãi nên dấu vết là chính unit_price.
        $order->items()->create(array_merge(
            ['product_sku_id' => $sku->id, 'status' => 'served', 'original_unit_price' => $line['unit_price']],
            $line,
        ));
    }

    return $order->load('items');
}

it('1.3 — taxable is the discounted net base, not the raw pre-discount subtotal', function () {
    // 2 lines of ¥500 @10%, coupon ¥100 → net 900, group tax 90 (allocated 45/45).
    $order = breakdownOrder(
        ['subtotal' => 1000, 'discount_amount' => 100, 'tax_amount' => 90, 'total_amount' => 990],
        [
            ['quantity' => 1, 'unit_price' => 500, 'subtotal' => 500, 'tax_rate' => 10, 'tax_amount' => 45],
            ['quantity' => 1, 'unit_price' => 500, 'subtotal' => 500, 'tax_rate' => 10, 'tax_amount' => 45],
        ],
    );

    $payload = (new CustomerOrderResource($order))->resolve();

    expect($payload['tax_breakdown'])->toHaveCount(1);
    $row = $payload['tax_breakdown'][0];
    // Old (wrong): taxable = 1000 (pre-discount) while tax = 90 (post-discount).
    expect($row['rate'])->toBe(10.0)
        ->and($row['taxable'])->toBe(900.0)
        ->and($row['tax'])->toBe(90.0)
        ->and($payload['service_charge_tax'])->toBe(0.0);
});

it('1.3 — included mode: taxable is net of the extracted 内税, mirroring the engine TaxGroup', function () {
    // 2 gross lines of ¥540 @8% included → gross 1080, tax 80 (allocated 40/40).
    $order = breakdownOrder(
        ['is_tax_included' => true, 'subtotal' => 1080, 'tax_amount' => 80, 'total_amount' => 1080],
        [
            ['quantity' => 1, 'unit_price' => 540, 'subtotal' => 540, 'tax_rate' => 8, 'tax_amount' => 40],
            ['quantity' => 1, 'unit_price' => 540, 'subtotal' => 540, 'tax_rate' => 8, 'tax_amount' => 40],
        ],
    );

    $row = (new CustomerOrderResource($order))->resolve()['tax_breakdown'][0];

    // Old (wrong): taxable = 1080 (gross). Engine/Kiosk semantics: net = 1000.
    expect($row['taxable'])->toBe(1000.0)->and($row['tax'])->toBe(80.0);
});

it('1.3 — service_charge_tax exposes the reconciling residual, and is null for legacy unstamped orders', function () {
    // Stamped order with sc tax: lines carry 90, order.tax_amount = 95 (sc tax 5).
    $order = breakdownOrder(
        ['subtotal' => 1000, 'service_charge' => 50, 'tax_amount' => 95, 'total_amount' => 1145],
        [
            ['quantity' => 1, 'unit_price' => 500, 'subtotal' => 500, 'tax_rate' => 10, 'tax_amount' => 45],
            ['quantity' => 1, 'unit_price' => 500, 'subtotal' => 500, 'tax_rate' => 10, 'tax_amount' => 45],
        ],
    );
    $payload = (new CustomerOrderResource($order))->resolve();
    expect($payload['service_charge_tax'])->toBe(5.0)
        // Reconciliation contract: Σ breakdown.tax + service_charge_tax == order.tax_amount.
        ->and(collect($payload['tax_breakdown'])->sum('tax') + $payload['service_charge_tax'])->toBe(95.0);

    // Dòng KHÔNG có ảnh chụp: residual là "thiếu stamp", KHÔNG phải sc tax → null.
    //
    // #2411 — dựng TRONG BỘ NHỚ, không qua DB: `customer_order_items.tax_rate`
    // nay là NOT NULL nên hình dạng này không ghi xuống được nữa. Nhánh
    // `$fullyStamped` của resource thì vẫn sống (parity với engine Go, nơi
    // `order_items` của máy trạm vẫn nullable), nên nó vẫn phải có test — chỉ là
    // ở đúng tầng nó sống: resource chỉ đọc quan hệ `items` đã nạp.
    $legacy = new CustomerOrder([
        'subtotal' => 1000, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 100, 'total_amount' => 1100,
    ]);
    $legacy->setRelation('items', collect([
        new CustomerOrderItem([
            'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000,
            'tax_rate' => null, 'tax_amount' => 0, 'status' => 'served',
        ]),
    ]));
    expect((new CustomerOrderResource($legacy))->resolve()['service_charge_tax'])->toBeNull();
});

it('1.4 — a finalized order breakdown reconciles to the stored tax even after the sc tax rate changes', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'currency_code' => 'JPY',
        'service_charge_rate' => 10,
        'service_charge_tax_rate' => 10,
        'prices_include_tax' => false,
    ]);

    // Closed at checkout with: 2×¥1000 @10% (group tax 200) + sc ¥200 @10% (sc tax 20) → tax 220.
    $order = breakdownOrder(
        ['status' => 'closed', 'subtotal' => 2000, 'service_charge' => 200, 'tax_amount' => 220, 'total_amount' => 2420, 'closed_at' => now()],
        [
            ['quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000, 'tax_rate' => 10, 'tax_amount' => 100],
            ['quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000, 'tax_rate' => 10, 'tax_amount' => 100],
        ],
    );

    $calc = new OrderPricingCalculator;
    $setting = ShopOrderSetting::where('branch_id', $this->branch->id)->first();

    $before = $calc->forOrder($order, $setting);
    expect(collect($before->groups)->sum('tax'))->toBe(220.0)
        ->and($before->serviceChargeTax)->toBe(20.0)
        ->and($before->taxAmount)->toBe(220.0);

    // Admin edits the sc tax rate AFTER checkout (this used to re-derive sc
    // tax at 8% → Σ groups 216 ≠ stored 220).
    $setting->update(['service_charge_tax_rate' => 8]);
    $after = $calc->forOrder($order->fresh()->load('items'), $setting->fresh());

    expect(collect($after->groups)->sum('tax'))->toBe(220.0)
        ->and($after->serviceChargeTax)->toBe(20.0)
        ->and($after->taxAmount)->toBe(220.0);
});

it('B1 — included-mode residual larger than the service charge never produces a negative taxable', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'currency_code' => 'JPY',
        'service_charge_rate' => 5,
        'service_charge_tax_rate' => 10,
        'prices_include_tax' => true,
    ]);

    // Corrupted/legacy finalized order: line group extracts 80, stored tax is
    // 200 → residual 120 exceeds the ¥50 service charge.
    $order = breakdownOrder(
        [
            'status' => 'closed', 'is_tax_included' => true, 'closed_at' => now(),
            'subtotal' => 1080, 'service_charge' => 50, 'tax_amount' => 200, 'total_amount' => 1130,
        ],
        [
            ['quantity' => 1, 'unit_price' => 540, 'subtotal' => 540, 'tax_rate' => 8, 'tax_amount' => 40],
            ['quantity' => 1, 'unit_price' => 540, 'subtotal' => 540, 'tax_rate' => 8, 'tax_amount' => 40],
        ],
    );

    $pricing = (new OrderPricingCalculator)->forOrder(
        $order,
        ShopOrderSetting::where('branch_id', $this->branch->id)->first(),
    );

    foreach ($pricing->groups as $group) {
        expect($group->taxable)->toBeGreaterThanOrEqual(0.0);
    }
    // The reconciliation contract still holds exactly.
    expect(collect($pricing->groups)->sum('tax'))->toBe(200.0)
        ->and($pricing->taxAmount)->toBe(200.0);
});
