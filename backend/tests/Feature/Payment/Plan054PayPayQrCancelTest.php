<?php

/**
 * #1737 — nút "Đổi số tiền" phải THẬT SỰ huỷ mã QR, không chỉ gỡ panel.
 *
 * Trước bản này nút đó chỉ `setPaypayMint(null)` ở trình duyệt. Mã cũ vẫn sống
 * ở phía PayPay và vẫn quét trả được ~5 phút, trong khi trang đã ngừng poll.
 * Khách chuyển sang trả quầy rồi mã bị quét ⇒ tiền vào sổ qua cron 15 phút, sau
 * khi họ đã trả bằng đường khác. Thu hai lần mà không ai làm gì sai.
 *
 * Mọi đường MINT vốn đã an toàn (`createQrCode` resume hoặc invalidate). Thiếu
 * đúng một đường gọi việc huỷ mà không cần mint — nên đây kiểm CỔNG VÀO đó,
 * không kiểm lại logic huỷ.
 *
 * Ghi chú về phạm vi kiểm được: `PayPayQrCodeClient` là `final`, không có
 * interface, nên mọi lời gọi chạm nó là HTTP thật (xem đầu
 * `Plan054PayPayQrCodeServiceTest`). Vì thế các ca dưới đây kiểm những gì quyết
 * định được TRƯỚC khi chạm mạng, cộng một chốt cấu trúc cho phần còn lại.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Customer\PayPayPaymentService;
use Illuminate\Support\Str;

uses()->group('payment');

beforeEach(function () {
    config([
        'services.paypay.api_key' => 'a_key',
        'services.paypay.api_secret' => 'a_secret',
        'services.paypay.merchant_id' => '991602796635988897',
        'services.paypay.environment' => 'sandbox',
    ]);
});

/*
 * Helper RIÊNG cho file này. Pest không chia sẻ hàm giữa các file test, và
 * `Plan054PayPayQrCodeServiceTest` không được bảo đảm nạp trước — dựa vào thứ tự
 * nạp là để test đỏ theo cách phụ thuộc vào việc chạy file nào trước.
 */

/** Đơn mà PayPay chấp nhận: chi nhánh JPY, đang mở, chưa trả gì. */
function qrCancelOrder(array $orderOverrides = []): CustomerOrder
{
    $consoleOrganizationId = (string) Str::uuid();

    $organization = Organization::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'JPY',
    ]);

    $order = CustomerOrder::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 1000,
        'paid_amount' => 0,
    ], $orderOverrides));

    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $order->organization_id,
        'currency_code' => 'JPY',
    ]);

    return $order;
}

function qrCancelService(): PayPayPaymentService
{
    return app(PayPayPaymentService::class);
}

/** Thân một method của service, cho các chốt cấu trúc bên dưới. */
function qrCancelSourceOf(string $method): string
{
    $reflection = new ReflectionMethod(PayPayPaymentService::class, $method);
    $lines = file((string) $reflection->getFileName());

    return implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
}

it('#1737 không có mã sống ⇒ trả false và KHÔNG chạm PayPay', function () {
    // Người dùng bấm huỷ hai lần, hoặc mã vừa hết hạn. Cả hai là chuyện bình
    // thường, không phải lỗi — và quan trọng hơn: không được gọi mạng, vì lời
    // gọi đó sẽ là HTTP thật tới PayPay cho một mã không tồn tại.
    $order = qrCancelOrder();

    expect(qrCancelService()->cancelOutstandingQr(orderSnapshot($order)))->toBeFalse();
});

it('#1737 attempt ở trạng thái KẾT không phải mã sống', function () {
    // Terminal nghĩa là mã đã biến mất. Coi nó là mã sống thì lượt huỷ sẽ hỏi
    // PayPay về một id không ai còn ghi nợ được.
    $order = qrCancelOrder();

    PaymentAttempt::factory()->create([
        'customer_order_id' => $order->id,
        'provider_object_id' => 'tempoqr-'.str_replace('-', '', (string) Str::uuid()),
        'state' => PaymentAttemptStateEnum::Canceled->value,
    ]);

    expect(qrCancelService()->cancelOutstandingQr(orderSnapshot($order)))->toBeFalse();
});

it('#1737 attempt KHÔNG PHẢI QR thì không đụng tới', function () {
    // Cùng đơn, một attempt Stripe đang sống. Thiếu bộ lọc tiền tố thì lượt huỷ
    // sẽ gọi endpoint mã của PayPay với `pi_…` — 404 rồi dead-letter.
    $order = qrCancelOrder();

    PaymentAttempt::factory()->create([
        'customer_order_id' => $order->id,
        'provider_object_id' => 'pi_3PlanZeroFiveFour',
        'state' => PaymentAttemptStateEnum::Processing->value,
    ]);

    expect(qrCancelService()->cancelOutstandingQr(orderSnapshot($order)))->toBeFalse();
});

it('#1737 KHÔNG gọi assertPayable — đơn đã đóng lại càng phải huỷ mã', function () {
    // Đây là chỗ dễ làm sai nhất: chép khuôn `createQrCode()` thì kéo theo cả
    // `assertPayable()`, và lúc đó một đơn vừa đóng hoặc chi nhánh vừa tắt
    // PayPay sẽ bị TỪ CHỐI huỷ — tức giữ đúng cái mã nguy hiểm ở đúng lúc nguy
    // hiểm nhất.
    $src = qrCancelSourceOf('cancelOutstandingQr');

    expect($src)->not->toContain('assertPayable');
});

it('#1737 HỎI PAYPAY trước khi xoá bất cứ thứ gì', function () {
    // Chốt quan trọng nhất của cả file, và là thứ bản đầu làm sai.
    //
    // Bản đầu gọi thẳng `invalidateOutstandingQr()`, vốn xoá + abandon mà không
    // hỏi PayPay câu nào. Chấp nhận được trên đường MINT (ngay sau đó có mã mới
    // thay thế); trên đường HUỶ thì không, vì `abandon` đánh attempt thành
    // `Canceled`, và `Canceled` không nằm trong `LIVE_ATTEMPT_STATES` ⇒ attempt
    // biến mất khỏi cả poll (`liveAttempt`), cả sweeper (`candidates()`), cả
    // webhook (`isTerminalAttemptState`). Khách quét xong mà huỷ chạy đúng lúc
    // thì tiền nằm ở PayPay, không sổ sách, không cảnh báo.
    //
    // Luật, nguyên văn `OrderPaymentOrchestrationCompat::retirePayPayQrAttempt`:
    // "Callers MUST have asked the provider first."
    $src = qrCancelSourceOf('cancelOutstandingQr');

    expect($src)->toContain('findPayment');
    expect(strpos($src, 'findPayment'))
        ->toBeLessThan((int) strpos($src, 'qrCodes->delete'));
});

it('#1737 KHÔNG dùng invalidateOutstandingQr — nó kết luận từ trạng thái local', function () {
    // Hàm đó tồn tại cho đường mint và cố ý không hỏi provider. Tái dùng nó ở
    // đây là tái nhập đúng lỗ mất tiền im lặng ở trên.
    $src = qrCancelSourceOf('cancelOutstandingQr');

    // Kiểm lời GỌI, không kiểm chữ: thân hàm có nhắc tên nó trong comment giải
    // thích vì sao không dùng, và ghi chú đó đáng giữ.
    expect(str_contains($src, '$this->invalidateOutstandingQr('))->toBeFalse();
});

it('#1737 một cú quét đang bay ⇒ TỪ CHỐI huỷ', function () {
    // Nguyên văn bài học của sweeper (`retireIfLapsed`):
    // "CREATED, WITH a payment id — a scan is in flight. Leave it alone."
    $src = qrCancelSourceOf('cancelOutstandingQr');

    expect($src)->toContain('paypay_payment_id');
    expect(strpos($src, 'paypay_payment_id'))
        ->toBeLessThan((int) strpos($src, 'qrCodes->delete'));
});

it('#1737 PayPay báo ĐÃ TRẢ ⇒ ghi sổ, tuyệt đối không đóng attempt', function () {
    // Đóng attempt của một mã đã thu tiền là biến khoản đó thành không ghi sổ
    // được — không đường nào chạm lại nó nữa.
    $src = qrCancelSourceOf('cancelOutstandingQr');

    expect($src)->toContain('recordPayPayPaymentByOrderId');
    expect(strpos($src, 'recordPayPayPaymentByOrderId'))
        ->toBeLessThan((int) strpos($src, 'qrCodes->delete'));
});

it('#1737 delete() trả false ⇒ KHÔNG đóng attempt', function () {
    // `PayPayQrCodeClient::delete()` không bao giờ ném — nó
    // `catch (\Throwable) { return false; }`. Nên giá trị trả về là tín hiệu
    // DUY NHẤT, và bỏ qua nó (như `invalidateOutstandingQr` đang làm) là đánh
    // attempt thành terminal trong khi mã vẫn quét được: vừa mất đường ghi sổ,
    // vừa còn nguyên mã sống.
    $src = qrCancelSourceOf('cancelOutstandingQr');

    expect($src)->toMatch('/\|\| ! \$this->qrCodes->delete\(/');
});

it('#1737 xoá theo codeId của PayPay, không theo merchantPaymentId', function () {
    // Cái này e2e mới lòi ra và nó là gốc rễ: `deleteQRCode` nhận `codeId` do
    // PayPay sinh, còn `merchantPaymentId` là id của mình. Đưa nhầm thì PayPay
    // trả 404 và `delete()` — vốn nuốt mọi throwable — trả `false` trong im
    // lặng. Nghĩa là trước bản này KHÔNG lời gọi `deleteQRCode` nào từng xoá
    // được gì, kể cả đường re-mint, nên lời hứa "Only ONE code per order is
    // ever scannable" mới chỉ đúng trong DB của mình.
    //
    // `codeId` chỉ xuất hiện đúng một lần — trong phản hồi `create` — nên nó
    // phải được nhớ lại lúc đó thì mới có mà dùng.
    $cancel = qrCancelSourceOf('cancelOutstandingQr');
    $invalidate = qrCancelSourceOf('invalidateOutstandingQr');
    $mint = qrCancelSourceOf('createQrCode');

    expect($cancel)->toContain('recallCodeId');
    expect($invalidate)->toContain('recallCodeId');
    expect($mint)->toContain("'code_id' => \$code['code_id']");

    // Không nhớ được thì THÀ ĐỪNG XOÁ còn hơn xoá nhầm id rồi đóng attempt.
    expect($cancel)->toContain('$codeId === null');
});

it('#1737 đóng attempt bằng LÝ DO THẬT, không phải qr_create_failed', function () {
    // `abandonPayPayQrAttempt` ghi raw status `qr_create_failed` — đúng cho
    // đường nó sinh ra, sai ở đây: việc tạo mã không hỏng, khách bấm nút.
    // Docblock của `retirePayPayQrAttempt` đòi phân biệt được "whether the code
    // died in our hands or in the customer's".
    $cancel = qrCancelSourceOf('cancelOutstandingQr');
    $retire = qrCancelSourceOf('retireCancelledQr');

    expect($cancel)->toContain('qr_cancelled_by_customer');
    expect(str_contains($cancel.$retire, 'abandonPayPayQrAttempt'))->toBeFalse();
    expect($retire)->toContain('retirePayPayQrAttempt');
    expect($retire)->toContain('PayPayQrSplitIntent::forget');
});

it('#1737 kiểm mã sống TRƯỚC khi phân giải kết nối', function () {
    // Thứ tự này là thứ giữ cho ca "không có gì để huỷ" không chạm DB kết nối
    // và không ném khi chi nhánh chưa cấu hình PayPay.
    $src = qrCancelSourceOf('cancelOutstandingQr');

    expect(strpos($src, 'liveAttempt'))
        ->toBeLessThan((int) strpos($src, 'PaymentGatewayConnection::query'));
});

it('#1737 merchant_payment_id lệch ⇒ no-op, không chạm PayPay', function () {
    // `liveAttempt()` luôn lấy attempt MỚI NHẤT. Thiếu guard này thì một lượt
    // huỷ đến muộn (khách bấm "Đổi số tiền" rồi mint lại ngay) sẽ giết đúng mã
    // vừa mint mà khách đang nhìn. Nếu guard hỏng, dòng dưới sẽ gọi HTTP thật.
    $order = qrCancelOrder();

    PaymentAttempt::factory()->create([
        'customer_order_id' => $order->id,
        'provider_object_id' => 'tempoqr-thecodethatislive',
        'state' => PaymentAttemptStateEnum::Processing->value,
    ]);

    expect(qrCancelService()->cancelOutstandingQr(
        orderSnapshot($order),
        'tempoqr-somebodyelsescode',
    ))->toBeFalse();
});

it('#1737 endpoint huỷ tồn tại và LUÔN 204, kể cả khi không có gì để huỷ', function () {
    $order = qrCancelOrder();

    $this->deleteJson("/api/v1/customer/orders/{$order->id}/paypay-qr")
        ->assertNoContent();
});

it('#1737 đơn không tồn tại ⇒ 404, không phải 204', function () {
    $this->deleteJson('/api/v1/customer/orders/'.Str::uuid().'/paypay-qr')
        ->assertNotFound();
});
