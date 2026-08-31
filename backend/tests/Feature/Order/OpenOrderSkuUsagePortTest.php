<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ToppingGroupItem;
use App\Services\Order\Contracts\OpenOrderSkuUsage;
use App\Services\Order\Contracts\OrderStatusVocabulary;
use Illuminate\Support\Str;

/**
 * #1622 — cổng Ordering công bố cho Catalog (plan-042: chặn xoá SKU đang bán).
 *
 * Ghim hai chiều hỏng ngược nhau, cả hai đều **im lặng**:
 *   - bỏ sót topping ⇒ xoá được một SKU đang là topping của đơn đang mở;
 *   - lọc sai trạng thái ⇒ chặn xoá vĩnh viễn vì một đơn ĐÃ ĐÓNG từ năm ngoái.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->usage = app(OpenOrderSkuUsage::class);

    $this->order = function (string $status): CustomerOrder {
        return CustomerOrder::create([
            'order_code' => 'T-'.Str::random(6),
            'order_type' => 'spot',
            'status' => $status,
            'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0,
            'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0,
            'opened_at' => now(),
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
    };

    $this->lineFor = function (CustomerOrder $order, ProductSku $sku): CustomerOrderItem {
        return CustomerOrderItem::create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $sku->id,
            'quantity' => 1,
            'unit_price' => 100,
            'original_unit_price' => 100,
            'subtotal' => 100,
            'tax_rate' => 0,
        ]);
    };
});

it('có binding thật, không phải interface rỗng', function () {
    expect($this->usage)->toBeInstanceOf(OpenOrderSkuUsage::class);
});

it('danh sách rỗng → false, không chạm DB', function () {
    expect($this->usage->anyOpenOrderUsesSkus([]))->toBeFalse();
});

it('SKU là DÒNG MÓN của đơn đang mở → true', function () {
    $sku = ProductSku::factory()->create();
    ($this->lineFor)(($this->order)(OrderStatusVocabulary::OPEN[0]), $sku);

    expect($this->usage->anyOpenOrderUsesSkus([(string) $sku->id]))->toBeTrue();
});

/**
 * Nửa dễ quên nhất: một SKU có thể vào đơn với tư cách **topping**, không phải
 * dòng món. Bỏ truy vấn thứ hai thì cổng trả `false` và Catalog cho xoá một SKU
 * đang nằm trong đơn khách đang ăn.
 */
it('SKU là TOPPING của đơn đang mở → true', function () {
    $dish = ProductSku::factory()->create();
    $topping = ProductSku::factory()->create();
    $line = ($this->lineFor)(($this->order)(OrderStatusVocabulary::OPEN[0]), $dish);

    // `topping_group_item_id` NOT NULL — một topping trên đơn luôn đến từ một
    // dòng nhóm topping của catalog. Lưu ý `topping_group_items` khoá theo
    // `product_id`, KHÔNG phải `product_sku_id` (liên kết SKU nằm ở bảng riêng
    // `topping_group_item_skus`); SKU thực sự được chọn nằm ở
    // `order_item_toppings.product_sku_id`, và đó mới là thứ cổng này tra.
    $groupItem = ToppingGroupItem::factory()->create();

    OrderItemTopping::create([
        'customer_order_item_id' => $line->id,
        'topping_group_item_id' => $groupItem->id,
        'product_sku_id' => $topping->id,
        'quantity' => 1,
        'unit_price' => 50,
    ]);

    expect($this->usage->anyOpenOrderUsesSkus([(string) $topping->id]))->toBeTrue();
});

/**
 * Chiều ngược lại: lọc trạng thái quá rộng thì một đơn ĐÃ ĐÓNG cũng chặn xoá,
 * và SKU cũ không bao giờ dọn được. Cũng im lặng — chỉ khác là người dùng bị
 * chặn thay vì mất dữ liệu.
 */
it('đơn ĐÃ ĐÓNG không chặn — trạng thái phải nằm trong OrderStatusVocabulary::OPEN', function () {
    $closed = collect(['closed', 'voided', 'expired'])
        ->first(fn (string $s): bool => ! in_array($s, OrderStatusVocabulary::OPEN, true));

    expect($closed)->not->toBeNull('không tìm được trạng thái đóng nào ngoài danh sách OPEN');

    $sku = ProductSku::factory()->create();
    ($this->lineFor)(($this->order)($closed), $sku);

    expect($this->usage->anyOpenOrderUsesSkus([(string) $sku->id]))->toBeFalse();
});

it('SKU không liên quan → false', function () {
    $sold = ProductSku::factory()->create();
    $untouched = ProductSku::factory()->create();
    ($this->lineFor)(($this->order)(OrderStatusVocabulary::OPEN[0]), $sold);

    expect($this->usage->anyOpenOrderUsesSkus([(string) $untouched->id]))->toBeFalse();
});
