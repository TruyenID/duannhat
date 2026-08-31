<?php

declare(strict_types=1);

/**
 * #1611 — con trỏ intent thuộc bảng của PAYMENTS, không thuộc cột trên bảng của
 * Ordering.
 *
 * `customer_orders.stripe_payment_intent_id` là dữ liệu của Payments nằm nhờ
 * trên hàng của Ordering, và tên cột còn nhúng luôn tên MỘT gateway — mai có
 * PayPay/Terminal/konbini thì thêm cột nữa. `order_payment_intents` (#1637)
 * khoá theo `(provider, intent_id)` nên nhiều gateway sống chung được.
 *
 * Giai đoạn hiện tại là *migrate* của expand → migrate → contract: **ghi cả
 * hai chỗ, đọc chỗ mới**. Cột cũ vẫn được ghi cố ý — bỏ dual-write cùng lúc với
 * chuyển đọc là gộp hai bước có thể hỏng độc lập vào một lần deploy.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPaymentIntent;
use App\Models\Organization;
use App\Services\Payment\Internal\OrderPaymentIntentPointer;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->pointer = app(OrderPaymentIntentPointer::class);
});

function pointerOrder(string $orgId, string $branchId, string $brandId): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'brand_id' => $brandId,
    ]);
}

it('#1611: tra được cả hai chiều — đơn → intent và intent → đơn', function () {
    $order = pointerOrder($this->orgId, $this->branch->id, $this->brand->id);

    $this->pointer->stamp((string) $order->id, $this->orgId, 'pi_abc123');

    expect($this->pointer->forOrder((string) $order->id))->toBe('pi_abc123')
        ->and($this->pointer->orderIdFor('pi_abc123'))->toBe((string) $order->id);
});

it('#1611: idempotent — đóng dấu lại cùng intent không sinh dòng thứ hai', function () {
    $order = pointerOrder($this->orgId, $this->branch->id, $this->brand->id);

    $this->pointer->stamp((string) $order->id, $this->orgId, 'pi_same');
    $this->pointer->stamp((string) $order->id, $this->orgId, 'pi_same');

    expect(OrderPaymentIntent::where('customer_order_id', $order->id)->count())->toBe(1);
});

/**
 * Một đơn chỉ có MỘT intent đang hiệu lực — `#1637` đặt unique trên
 * `customer_order_id` chính vì thế. Mint lại thì con trỏ đi theo cái mới, chứ
 * không được sinh dòng thứ hai rồi để hai con trỏ cùng trỏ về một đơn.
 */
it('#1611: mint lại thì con trỏ đi theo intent MỚI, vẫn một dòng', function () {
    $order = pointerOrder($this->orgId, $this->branch->id, $this->brand->id);

    $this->pointer->stamp((string) $order->id, $this->orgId, 'pi_old');
    $this->pointer->stamp((string) $order->id, $this->orgId, 'pi_new');

    expect(OrderPaymentIntent::where('customer_order_id', $order->id)->count())->toBe(1)
        ->and($this->pointer->forOrder((string) $order->id))->toBe('pi_new')
        ->and($this->pointer->orderIdFor('pi_old'))->toBeNull();
});

it('#1611: hai gateway cùng dùng một intent id vẫn phân biệt được', function () {
    $a = pointerOrder($this->orgId, $this->branch->id, $this->brand->id);
    $b = pointerOrder($this->orgId, $this->branch->id, $this->brand->id);

    // Unique là `(provider, intent_id)`, KHÔNG phải `intent_id` — #1637 đổi nó
    // đúng vì lý do này.
    $this->pointer->stamp((string) $a->id, $this->orgId, 'shared-id', 'stripe');
    $this->pointer->stamp((string) $b->id, $this->orgId, 'shared-id', 'paypay');

    expect($this->pointer->orderIdFor('shared-id', 'stripe'))->toBe((string) $a->id)
        ->and($this->pointer->orderIdFor('shared-id', 'paypay'))->toBe((string) $b->id);
});

it('#1611: đơn chưa có con trỏ thì trả null, không nổ', function () {
    $order = pointerOrder($this->orgId, $this->branch->id, $this->brand->id);

    expect($this->pointer->forOrder((string) $order->id))->toBeNull()
        ->and($this->pointer->orderIdFor('pi_không_tồn_tại'))->toBeNull();
});
