<?php

declare(strict_types=1);

/**
 * #2866 — một đơn ĐANG GIỮ TIỀN không được phép xoá, bằng BẤT KỲ đường nào.
 *
 * ## Ca thật đứng sau bài này
 *
 * `ORD-2026-0018` trên production: thu ¥297 (Stripe `livemode`, charge tới giờ
 * vẫn `paid=true, refunded=false`), đơn `closed`, rồi hôm sau bị **xoá mềm** —
 * và lượt xoá nhả coupon nên total nhảy 297 → 1190. Kết quả: một khoản capture
 * thật ở PSP không còn bản ghi đơn nào để ánh xạ về, và con số trên bản ghi đó
 * đã đổi SAU khi khách trả tiền.
 *
 * ## Hai lỗ, nên hai chiều phải đo riêng
 *
 * 1. `WritesCustomerOrders::delete()` chỉ có rào **trạng thái** (`Open`) và rào
 *    **món đã phục vụ**. Không rào tiền. Mà `Open` KHÔNG bảo đảm đơn chưa có
 *    tiền: thu một phần rồi thêm món là chuyện thường ngày ở quầy.
 *
 * 2. Model **không có hook `deleting`** — chỉ `creating`/`saved`/`forceDeleting`.
 *    Nên mọi `$order->delete()` từ tinker, service khác, hay cascade đi vòng
 *    qua sạch biên ghi chuẩn. Đó là cách một đơn `closed` bị xoá dù rào nói chỉ
 *    `Open` mới được xoá.
 *
 * Bài `qua biên ghi` một mình KHÔNG đủ: nó xanh y hệt khi lỗ 2 còn nguyên.
 *
 * ## Rào sống ở MỘT chỗ: `CustomerOrder::deleting`
 *
 * Bản đầu đặt rào tiền ở CẢ biên ghi lẫn model. Đo lại thì bản ở biên ghi
 * **thừa** — gỡ nó ra, bộ này vẫn 5/5 xanh, vì `delete()` của biên ghi cuối
 * cùng cũng gọi `$order->delete()` nên hook model bắt được. Hai nguồn chân lý
 * cho cùng một câu hỏi thì sớm muộn cũng lệch nhau, nên chỉ giữ hook model.
 *
 * Hệ quả cần biết khi đọc bài đầu tiên: nó đi qua biên ghi, nhưng thứ CHẶN nó
 * là hook model — không phải một rào riêng ở biên ghi.
 *
 * ## Và phải biết IM
 *
 * Đơn nháp không tiền vẫn phải xoá được. Một rào chặn cả đường dọn hợp lệ sẽ bị
 * gỡ, và gỡ xong thì không còn rào nào.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Order\Internal\EloquentOrderPersistence;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $consoleOrgId = (string) Str::uuid();

    $this->organization = Organization::factory()->create(['console_organization_id' => $consoleOrgId]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $consoleOrgId,
        'slug' => 'del-guard-'.Str::random(6),
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
    ]);

    $this->makeOrder = fn (array $overrides = []): CustomerOrder => CustomerOrder::factory()->create(array_merge([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'order_code' => 'ORD-DEL-'.Str::random(5),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 1190,
        'total_amount' => 1190,
        'paid_amount' => 0,
    ], $overrides));

    $this->collect = function (CustomerOrder $order, float $amount): void {
        OrderPayment::factory()->create([
            'customer_order_id' => $order->id,
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'amount' => $amount,
            'status' => PaymentStatusEnum::Succeeded->value,
        ]);
    };
});

// =========================================================================
//  PHẢI CHẶN
// =========================================================================

it('#2866 chặn xoá qua biên ghi chuẩn khi đơn còn giữ tiền', function () {
    $order = ($this->makeOrder)();
    ($this->collect)($order, 297.0);

    // Đơn vẫn `open` — rào trạng thái sẵn có KHÔNG bắt được ca này, đúng hình
    // dạng "thu một phần rồi thêm món" ở quầy.
    expect($order->fresh()->status->value)->toBe('open');

    app(EloquentOrderPersistence::class)->delete($order);
})->throws(HttpException::class);

it('#2866 chặn cả khi gọi thẳng $order->delete() — đường vòng qua biên ghi', function () {
    // ĐÂY là chiều mà lỗ thật nằm ở: tinker, service khác, cascade đều đi lối
    // này. Không có hook `deleting` thì mọi rào ở biên ghi thành trang trí.
    $order = ($this->makeOrder)(['status' => 'closed', 'paid_amount' => 297]);
    ($this->collect)($order, 297.0);

    $threw = false;
    try {
        $order->delete();
    } catch (Throwable $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue()
        ->and(CustomerOrder::withTrashed()->find($order->id)->deleted_at)->toBeNull();
});

it('#2866 tiền hoàn HẾT thì hết chặn — dùng đúng vị ngữ ròng, không đếm dòng', function () {
    $order = ($this->makeOrder)();
    ($this->collect)($order, 297.0);

    // Một lần hoàn được ghi thành: dòng gốc chuyển `refunded` CỘNG một dòng ÂM
    // `succeeded`. Cách gộp tự chế (đếm dòng, hay đọc cột đệm `paid_amount`) sẽ
    // đọc ra tín dụng ma và chặn nhầm mãi mãi.
    OrderPayment::query()->where('customer_order_id', $order->id)
        ->update(['status' => PaymentStatusEnum::Refunded->value]);
    ($this->collect)($order, -297.0);

    expect(OrderPayment::netCollectedForOrder((string) $order->id))->toBe(0.0);

    $order->delete();

    expect(CustomerOrder::withTrashed()->find($order->id)->deleted_at)->not->toBeNull();
});

// =========================================================================
//  PHẢI IM
// =========================================================================

it('#2866 đơn nháp KHÔNG có tiền vẫn xoá được — rào không được chặn đường dọn hợp lệ', function () {
    $order = ($this->makeOrder)();

    app(EloquentOrderPersistence::class)->delete($order);

    expect(CustomerOrder::withTrashed()->find($order->id)->deleted_at)->not->toBeNull();
});

it('#2866 rào tiền KHÔNG lặp lại rào trạng thái ở tầng model', function () {
    // Cố ý: hook `deleting` chỉ canh vế TIỀN. Rào trạng thái/món-đã-phục-vụ là
    // luật quy trình của quầy và sống ở biên ghi; nhân đôi nó xuống model sẽ
    // làm gãy fixture, reseed và các đường dọn dữ liệu hợp lệ mà không bảo vệ
    // thêm được đồng nào.
    $order = ($this->makeOrder)(['status' => 'closed']);

    $order->delete();

    expect(CustomerOrder::withTrashed()->find($order->id)->deleted_at)->not->toBeNull();
});
