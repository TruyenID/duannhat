<?php

/**
 * #2063 — "đơn treo" là điều kiện để CHẶN in biên lai / hoá đơn đỏ.
 *
 * Sai theo chiều nào cũng đắt, và hai chiều đắt khác nhau:
 *
 *   - bỏ sót treo ⇒ quán phát một tờ giấy nói "đã nhận tiền" cho đơn chưa cầm
 *     đồng nào — đúng sự cố mở ra issue;
 *   - treo oan ⇒ đơn đã trả đủ không in được hoá đơn, và khách đứng ở quầy.
 *
 * Nên bộ test này có cả hai, và bài mẫu số ở cuối giữ cho số 0 là một khẳng
 * định.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Order\Internal\OrderHoldStatus;
use Illuminate\Support\Str;

uses()->group('order');

beforeEach(function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $this->orgId = $orgId;
    $this->brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->onAccount = PaymentMethod::factory()->create([
        'organization_id' => $orgId,
        'type' => 'on_account',
    ]);
    $this->cash = PaymentMethod::factory()->create([
        'organization_id' => $orgId,
        'type' => 'cash',
    ]);
});

function heldOrder(string $status, float $total, float $paid): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => $status,
        'total_amount' => $total,
        'paid_amount' => $paid,
    ]);
}

function payOrder(CustomerOrder $order, PaymentMethod $method, float $amount, array $extra = []): OrderPayment
{
    return OrderPayment::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'status' => PaymentStatusEnum::Succeeded->value,
    ], $extra));
}

function holdStatus(): OrderHoldStatus
{
    return app(OrderHoldStatus::class);
}

it('đơn TRẢ THIẾU (paying, paid < total) là treo', function () {
    $order = heldOrder(CustomerOrderStatusEnum::Paying->value, 3000, 1000);

    expect(holdStatus()->forOrders([$order]))->toHaveKey((string) $order->id);
});

it('đơn GHI NỢ vẫn `closed` nhưng LÀ treo — nhánh nặng nhất của issue', function () {
    // `on_account` cộng thẳng vào `paid_amount` nên đơn đóng bình thường và mọi
    // màn hình đọc ra "Hoàn thành". Đây chính là tờ giấy không được phép in.
    $order = heldOrder(CustomerOrderStatusEnum::Closed->value, 3000, 3000);
    payOrder($order, $this->onAccount, 3000);

    expect(holdStatus()->forOrders([$order]))->toHaveKey((string) $order->id);
});

it('CHIỀU PHẢI IM: đơn trả ĐỦ bằng tiền mặt KHÔNG treo', function () {
    // Treo oan thì khách đứng ở quầy mà không có hoá đơn.
    $order = heldOrder(CustomerOrderStatusEnum::Closed->value, 3000, 3000);
    payOrder($order, $this->cash, 3000);

    expect(holdStatus()->forOrders([$order]))->toBe([]);
});

it('CHIỀU PHẢI IM: nợ ĐÃ TẤT TOÁN thì thôi treo', function () {
    // Nếu không bù trừ settlement, một đơn đã thu nợ xong sẽ treo vĩnh viễn và
    // không bao giờ in được hoá đơn.
    $order = heldOrder(CustomerOrderStatusEnum::Closed->value, 3000, 3000);
    $debt = payOrder($order, $this->onAccount, 3000);

    // Khoản thu nợ cưỡi trên một đơn KHÁC (#821 A4), chỉ trỏ ngược bằng metadata.
    $other = heldOrder(CustomerOrderStatusEnum::Closed->value, 3000, 3000);
    payOrder($other, $this->cash, 3000, [
        'metadata' => ['settles_payment_id' => (string) $debt->id],
    ]);

    expect(holdStatus()->forOrders([$order]))->toBe([]);
});

it('nợ đã hoàn TOÀN BỘ thì thôi treo — nợ là số RÒNG (#821 A6)', function () {
    $order = heldOrder(CustomerOrderStatusEnum::Closed->value, 3000, 3000);
    $debt = payOrder($order, $this->onAccount, 3000, ['status' => PaymentStatusEnum::Refunded->value]);
    payOrder($order, $this->onAccount, -3000, ['refund_of_id' => $debt->id]);

    expect(holdStatus()->forOrders([$order]))->toBe([]);
});

it('nợ hoàn MỘT PHẦN vẫn treo — phần còn lại không được biến mất', function () {
    // Loại cả dòng gốc lẫn dòng đảo sẽ làm 4.999.000 rơi khỏi mọi báo cáo.
    $order = heldOrder(CustomerOrderStatusEnum::Closed->value, 5000, 5000);
    $debt = payOrder($order, $this->onAccount, 5000, ['status' => PaymentStatusEnum::Refunded->value]);
    payOrder($order, $this->onAccount, -1000, ['refund_of_id' => $debt->id]);

    expect(holdStatus()->forOrders([$order]))->toHaveKey((string) $order->id);
});

it('MẪU SỐ: lô nhiều đơn — chỉ đơn treo có mặt, đơn sạch KHÔNG', function () {
    // Không có bài này thì mọi bài trên xanh kể cả khi hàm LUÔN trả về mọi id.
    $held = heldOrder(CustomerOrderStatusEnum::Paying->value, 3000, 1000);
    $clean = heldOrder(CustomerOrderStatusEnum::Closed->value, 3000, 3000);
    payOrder($clean, $this->cash, 3000);

    $out = holdStatus()->forOrders([$held, $clean]);

    expect($out)->toHaveKey((string) $held->id)
        ->and($out)->not->toHaveKey((string) $clean->id);
});

it('tập rỗng ⇒ rỗng, và KHÔNG hỏi DB', function () {
    expect(holdStatus()->forOrders([]))->toBe([]);
});
