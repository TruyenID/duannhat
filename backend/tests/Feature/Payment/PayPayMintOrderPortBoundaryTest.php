<?php

declare(strict_types=1);

/**
 * #1603 (#962 · 7a-1) — đường mint QR PayPay thôi cầm model của Ordering.
 *
 * Trước đây `createQrCode()` tự `CustomerOrder::lockForUpdate()->findOrFail()`
 * ngay giữa `DB::transaction`, rồi đưa **dòng model đã khoá** cho
 * `preparePayPayQrAttempt()`. Ghi chú cũ của issue kết luận không chuyển được vì
 * mắt đó "cố ý giữ model". Đo lại thì không phải: method nhận nó chỉ đọc **bốn
 * vô hướng** (`id`, `organization_id`, `branch_id`, `total_amount`), và cả bốn
 * đã có trên `OrderSnapshot` — hợp đồng Ordering công bố từ #1544.
 *
 * Bài test này ghim hai thứ tách rời nhau, vì chúng hỏng theo hai kiểu khác nhau:
 *
 *  1. **Ranh giới** — chữ ký nhận ảnh chụp, không nhận model. Một lần "tiện tay"
 *     đổi ngược lại sẽ không làm test hành vi nào đỏ.
 *  2. **Cái khoá vẫn còn** — và đây là chỗ phải cẩn thận: engine test là SQLite,
 *     nơi `SQLiteGrammar::compileLock()` trả **chuỗi rỗng**. SQL sinh ra KHÔNG
 *     bao giờ chứa `for update`, dù code có khoá hay không. Một bài assert
 *     `for update` trong query log sẽ xanh/đỏ vì lý do không liên quan (đã trả
 *     giá ở #1630). Nên cái khoá được ghim bằng **quét nguồn**, và giả định về
 *     SQLite được ghim riêng để nếu engine đổi thì người sửa biết chuyển sang
 *     ghim bằng SQL thật.
 */

use App\Models\CustomerOrder;
use App\Services\Customer\PayPayPaymentService;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Contracts\OrderSnapshot;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use Illuminate\Support\Facades\DB;

it('nhận ảnh chụp đơn, không nhận model, ở mắt chuẩn bị attempt', function () {
    $parameter = (new ReflectionMethod(OrderPaymentOrchestrationCompat::class, 'preparePayPayQrAttempt'))
        ->getParameters()[0];

    $type = $parameter->getType();

    expect($type)->not->toBeNull();
    expect((string) $type)->toBe(OrderSnapshot::class,
        'preparePayPayQrAttempt() phải nhận ảnh chụp Ordering công bố. Nhận lại '
        .'`CustomerOrder` là dựng lại cạnh Payments → Ordering mà #1603 vừa gỡ — '
        .'và không một test hành vi nào sẽ đỏ vì chuyện đó.'
    );
});

it('lấy dòng đã khoá qua cổng của Ordering, không tự khoá model', function () {
    $source = file_get_contents(app_path('Services/Customer/PayPayPaymentService.php'));
    $createQrCode = substr($source, (int) strpos($source, 'function createQrCode('));
    $createQrCode = substr($createQrCode, 0, (int) strpos($createQrCode, "\n    private ") ?: null);

    expect(str_contains($createQrCode, '$this->orders->findForSettlement('))->toBeTrue(
        'Đường mint phải khoá-rồi-đọc qua OrderQueryPort::findForSettlement().'
    );

    expect(str_contains($createQrCode, 'CustomerOrder::lockForUpdate('))->toBeFalse(
        'Khoá thẳng trên model là quay lại chỗ cũ: Payments tự cầm hàng của Ordering.'
    );
});

it('cổng khoá-rồi-đọc thật sự có gọi lockForUpdate', function () {
    // Ghim ở ADAPTER, vì đó là nơi cái khoá thật sự sống. Bỏ `lockForUpdate()`
    // khỏi đây thì hai request mint song song trên cùng một đơn lại đi cạnh nhau
    // và cả hai cùng thấy `outstanding` như nhau — đúng cái mà lock này chặn.
    $source = file_get_contents(app_path('Services/Order/Internal/EloquentOrderQuery.php'));
    $method = substr($source, (int) strpos($source, 'function findForSettlement('));

    expect(str_contains(substr($method, 0, 400), '->lockForUpdate()'))->toBeTrue(
        'findForSettlement() mất cái khoá thì mọi chỗ gọi nó — tất toán VÀ mint QR — '
        .'im lặng thành đọc không khoá.'
    );
});

it('SQLite KHÔNG sinh mệnh đề khoá — vì sao ba bài trên quét nguồn', function () {
    // Giả định làm cho ba bài trên phải quét nguồn thay vì đọc query log. Engine
    // test đổi sang thứ có compileLock() thật thì bài này đỏ, và người sửa biết
    // rằng lúc đó ghim bằng SQL sẽ mạnh hơn quét chữ.
    $sql = DB::table('customer_orders')->where('id', 'x')->lockForUpdate()->toSql();

    expect(str_contains(strtolower($sql), 'for update'))->toBeFalse(
        'Engine test giờ SINH mệnh đề khoá ('.$sql.'). Từ đây ghim cái khoá bằng SQL '
        .'thật sẽ mạnh hơn quét nguồn — sửa ba bài trên (#1630, #1603).'
    );
});

it('đơn đã xoá mềm không mint được QR — ngữ nghĩa của findOrFail cũ được giữ', function () {
    // `findForSettlement` đi qua model builder nên global scope soft-delete vẫn
    // áp — giống hệt `CustomerOrder::lockForUpdate()->findOrFail()` cũ, và KHÁC
    // `OrderRowLock::lockForUpdate()` vốn cố ý dùng query thô để khoá được cả
    // dòng đã xoá mềm. Chọn nhầm cái kia ở đây là lặng lẽ đổi tập đơn mint được.
    $order = CustomerOrder::factory()->create();
    $port = app(OrderQueryPort::class);

    expect($port->findForSettlement((string) $order->organization_id, (string) $order->id))
        ->not->toBeNull();

    $order->delete();

    expect($port->findForSettlement((string) $order->organization_id, (string) $order->id))
        ->toBeNull('Đơn xoá mềm phải KHÔNG khớp — nếu khớp, một đơn đã xoá lại mint được QR.');
});

it('service dựng được từ container sau khi thêm tham số cổng', function () {
    // Thêm một tham số BẮT BUỘC vào constructor: nếu còn chỗ nào `new
    // PayPayPaymentService(...)` bằng tay thì nó chết ngay lúc chạy, không phải
    // lúc phân tích. Đã grep thấy rỗng; bài này giữ cho nó rỗng.
    expect(app(PayPayPaymentService::class))->toBeInstanceOf(PayPayPaymentService::class);
});
