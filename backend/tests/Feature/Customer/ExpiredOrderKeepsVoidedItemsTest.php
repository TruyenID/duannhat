<?php

/*
 * Đơn takeaway quá hạn thanh toán bị auto-cancel → MỌI line bị void. Nếu
 * detail endpoint vẫn lọc line voided thì `items` rỗng: customer-web mất tên
 * món + ảnh trên card lịch sử, và nút "Đặt lại" không còn SKU nào để dựng lại
 * giỏ. Với đơn đã chết (expired/cancelled/voided) line phải được giữ nguyên;
 * đơn còn sống thì line voided vẫn phải ẩn (khách không trả tiền cho món staff
 * đã gỡ).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
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

    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);
});

function makeOrderWithVoidedItem(string $status): CustomerOrder
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'order_type' => 'takeaway',
        'status' => $status,
        'subtotal' => 1000,
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'subtotal' => 1000,
        'status' => 'voided',
    ]);

    return $order;
}

it('keeps voided lines on an expired order so the history card + reorder still work', function () {
    $order = makeOrderWithVoidedItem('expired');

    $this->getJson("/api/v1/customer/orders/{$order->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.product_sku_id', $this->sku->id);
});

it('still hides voided lines on a live order', function () {
    $order = makeOrderWithVoidedItem('open');

    $this->getJson("/api/v1/customer/orders/{$order->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data.items');
});
