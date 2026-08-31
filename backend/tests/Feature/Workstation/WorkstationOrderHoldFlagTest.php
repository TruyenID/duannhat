<?php

/**
 * #2063 — cờ ĐANG TREO đi qua HTTP tới máy trạm, và nó VẮNG MẶT ở đường GHI.
 *
 * Test đi qua ENDPOINT, không qua service. Thêm một trường vào resource mà quên
 * nối vào controller thì mọi test service-level vẫn xanh trong khi tính năng
 * chết im lặng trên đường thật (#2622).
 *
 * Bẫy số 2 của issue nằm ở đây: `null` ≠ `false`. Gộp hai thứ đó thì sửa một
 * dòng món trên đơn treo là cờ tắt và hai nút in hiện lại.
 */

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Order\Internal\OrderHoldStamp;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses()->group('workstation');

beforeEach(function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $this->orgId = $orgId;
    $this->branch = Branch::factory()->create(['console_organization_id' => $orgId]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
    ]);

    $this->onAccount = PaymentMethod::factory()->create([
        'organization_id' => $orgId,
        'type' => 'on_account',
    ]);
});

function wsOrder(string $status, float $total, float $paid): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => $status,
        'total_amount' => $total,
        'paid_amount' => $paid,
    ]);
}

function wsPull(): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->wsToken])
        ->getJson('/api/v1/workstation/orders');
}

it('đường ĐỌC mang cờ treo cho đơn trả thiếu', function () {
    $order = wsOrder(CustomerOrderStatusEnum::Paying->value, 3000, 1000);

    $res = wsPull()->assertOk();
    $row = collect($res->json('data'))->firstWhere('id', (string) $order->id);

    expect($row)->not->toBeNull()
        ->and($row[OrderHoldStamp::ATTRIBUTE])->toBeTrue();
});

it('đơn GHI NỢ tuy `closed` vẫn mang cờ — nhánh nặng nhất', function () {
    $order = wsOrder(CustomerOrderStatusEnum::Closed->value, 3000, 3000);
    OrderPayment::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->onAccount->id,
        'amount' => 3000,
        'status' => PaymentStatusEnum::Succeeded->value,
    ]);

    $row = collect(wsPull()->assertOk()->json('data'))->firstWhere('id', (string) $order->id);

    expect($row[OrderHoldStamp::ATTRIBUTE])->toBeTrue();
});

it('CHIỀU PHẢI IM: đơn sạch mang cờ FALSE tường minh, không phải vắng mặt', function () {
    // Đơn đã đi qua đường đọc là đơn ĐÃ ĐƯỢC HỎI. `false` ở đây khác hẳn
    // "vắng mặt" ở bài dưới, và đó là toàn bộ điểm của bẫy số 2.
    $order = wsOrder(CustomerOrderStatusEnum::Closed->value, 3000, 3000);

    $row = collect(wsPull()->assertOk()->json('data'))->firstWhere('id', (string) $order->id);

    expect($row)->toHaveKey(OrderHoldStamp::ATTRIBUTE)
        ->and($row[OrderHoldStamp::ATTRIBUTE])->toBeFalse();
});

it('MẪU SỐ: cờ THẬT SỰ đến từ controller — gỡ nối thì bài trên đỏ', function () {
    // Không có bài này thì ba bài trên vẫn xanh nếu resource tự phát `false`
    // cho mọi đơn. Ở đây hai đơn khác nhau phải cho hai giá trị khác nhau
    // TRONG CÙNG một payload — điều mà một hằng số không làm được.
    $held = wsOrder(CustomerOrderStatusEnum::Paying->value, 3000, 1000);
    $clean = wsOrder(CustomerOrderStatusEnum::Closed->value, 3000, 3000);

    $rows = collect(wsPull()->assertOk()->json('data'))->keyBy('id');

    expect($rows[(string) $held->id][OrderHoldStamp::ATTRIBUTE])->toBeTrue()
        ->and($rows[(string) $clean->id][OrderHoldStamp::ATTRIBUTE])->toBeFalse();
});
