<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/*
 * Plan 007 (Decision 12 / NOTES 2026-04-20) — walk-in partial-payment black hole.
 *
 * The pos-web PaymentDialog blocks a shortfall payment on a walk-in order
 * (customer_id = null) in the UI. This asserts the backend enforces the same
 * rule server-side so a direct-API / non-UI client cannot orphan the balance:
 * the outstanding-debt pipe is keyed on customer_id, so a walk-in partial would
 * strand the money forever with no way to reconcile it. Orders WITH a customer
 * and split-bill installments stay unaffected.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'walkin-guard-shop',
        'is_active' => true,
    ]);
    Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
        'auto_approve_stock_out' => true,
    ]);
    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);
    $pt = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 3000,
        'is_active' => true,
    ]);
    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

function seedWalkinCheckoutOrder(int $total, ?string $customerId = null): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-WG-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => $total,
        'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => $customerId,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => $total,
        'original_unit_price' => $total,
        'subtotal' => $total,
        'status' => 'served',
        'served_at' => now(),
        'tax_rate' => 0,
    ]);

    return $order;
}

it('rejects a partial payment on a walk-in order (customer_id = null) with a structured 422', function () {
    $order = seedWalkinCheckoutOrder(3000, customerId: null);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 2000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'walkin_partial_payment_blocked')
        ->assertJsonPath('outstanding_amount', '3000.00');

    // No orphaned payment row, order untouched.
    expect($order->fresh()->payments()->count())->toBe(0);
    expect($order->fresh()->status->value)->toBe('checkout');
    expect((float) $order->fresh()->paid_amount)->toBe(0.0);
});

it('accepts full payment on a walk-in order and closes it', function () {
    $order = seedWalkinCheckoutOrder(3000, customerId: null);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');
    expect((float) $order->paid_amount)->toBe(3000.0);
});

it('still allows a partial payment when the order has a customer_id (debt is recoverable)', function () {
    $order = seedWalkinCheckoutOrder(3000, customerId: $this->customer->id);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 2000,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('paying');
    expect((float) $order->paid_amount)->toBe(2000.0);
});

it('#2856 — khách đặt split_mode LÊN ĐƠN KHÔNG gỡ được rào walk-in', function () {
    // `POST /customer/orders/{id}/split-mode` công khai có chủ đích, nên cột
    // `orders.split_mode` là thứ KHÁCH đặt được. Nó từng là một vế của rào này,
    // tức một thao tác của khách âm thầm gỡ một luật TIỀN — và không ai ở quầy
    // thấy điều đó đã xảy ra.
    //
    // Rào tồn tại vì khoản thiếu trên đơn KHÔNG có `customer_id` không có khoá
    // nào tra lại được: `GET /customers/{id}/outstanding` là cơ chế duy nhất làm
    // nợ nổi lại, và đơn walk-in không có khách để tra. Docblock gọi đúng tên nó
    // là "black hole" và "corrupt shift reconciliation".
    // `by_people` CHỈ tồn tại trong từ vựng của đơn — chính là giá trị mà
    // `POST /customer/orders/{id}/split-mode` nhận, tức thứ khách đặt được thật.
    $order = seedWalkinCheckoutOrder(3000, customerId: null);
    $order->forceFill(['split_mode' => 'by_people', 'split_people_count' => 2])->saveQuietly();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 2000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'walkin_partial_payment_blocked');

    expect($order->fresh()->payments()->count())->toBe(0);
});

it('#2856 — khoản thanh toán TỰ KHAI là phần chia bill thì vẫn được nhận', function () {
    // Chiều phải IM, và nó là lý do vế payment-level ở lại: chia bill vốn là
    // trả thiếu THEO THIẾT KẾ. Không có bài này thì bản sửa trên có thể siết
    // luôn cả luồng chia bill hợp lệ và quán không thu được từng khách.
    //
    // Tín hiệu đến từ CHÍNH giao dịch, do POS gửi — khách không đặt được.
    //
    // Dùng `equal` có chủ ý: nó CHỈ hợp lệ ở tầng thanh toán
    // (`OrderPaymentStoreRequest`: equal|by_items|by_amount) và KHÔNG nằm trong
    // từ vựng của endpoint đặt cột trên đơn (by_people|by_items|custom). Nên bài
    // này chứng minh miễn trừ đến từ TỪ VỰNG THANH TOÁN, không phải vô tình
    // trùng tên với thứ khách đặt được.
    $order = seedWalkinCheckoutOrder(3000, customerId: null);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1500,
            'tendered_amount' => 1500,
            'metadata' => ['split_mode' => 'equal', 'bill_index' => 1, 'total_bills' => 2],
        ])
        ->assertSuccessful();

    expect($order->fresh()->payments()->count())->toBe(1);
});
