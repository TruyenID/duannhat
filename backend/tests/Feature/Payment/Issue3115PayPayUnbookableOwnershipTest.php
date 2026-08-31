<?php

/**
 * #3115 — chuông "tiền PayPay không ghi sổ được" phải kêu theo QUYỀN SỞ HỮU,
 * không theo TIỀN TỐ tham chiếu.
 *
 * Sự cố 17/08 tại 人形町店 (`ORD-2026-0690`) không do lỗi này gây ra — đơn đó
 * chưa từng có `payment_attempts`, nên PayPay chưa từng cấp mã cho nó. Nhưng
 * lần truy vết ấy phơi ra chỗ này: `alarmOnUnbookableNotification()` chỉ kêu khi
 * tham chiếu bắt đầu bằng `tempoqr-`. Tiền tố đó sinh ra để lọc nhiễu — merchant
 * PayPay Live dùng chung với WooCommerce `menu.betoya.jp`, relay tee mọi
 * notification sang đây nên hàng loạt `pp_*` không phải của ta rơi vào
 * `paypay_no_matching_attempt` (đo 17/08: 32 sự kiện trong sáu giờ).
 *
 * Chỗ hỏng: `tempoqr-` chỉ là hình dạng của MỘT trong ba loại tham chiếu của
 * chính ta. Preauth/native payment dùng `merchantPaymentId()` = operation id đã
 * bỏ gạch; refund dùng `merchant_refund_id` = operation id. Cả hai là UUID trần.
 * Nên một attempt/refund CỦA TA đã terminal trong khi PayPay nói COMPLETED —
 * đúng hình dạng "tiền đã đi mà không gì ghi sổ" — đi qua chuông im lặng.
 *
 * Test đi qua ENDPOINT webhook có chữ ký (#2622), không gọi thẳng service: chữ
 * ký, phân giải connection và ánh xạ tham chiếu đều nằm trên đường thật.
 *
 * Rào chứng minh CẢ HAI CHIỀU: nó phải kêu cho hàng của ta, và phải IM cho
 * `pp_*` của WooCommerce — một cái chuông kêu oan sẽ bị tắt, không bị tranh luận.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentRefund;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses()->group('payment');

const ISSUE_3115_WEBHOOK_SECRET = 'paypay_whsec_3115';

beforeEach(function () {
    config([
        'services.paypay.api_key' => 'pp_key_dummy',
        'services.paypay.api_secret' => 'pp_secret_dummy',
        'services.paypay.webhook_secret' => ISSUE_3115_WEBHOOK_SECRET,
    ]);

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
    $provider = PaymentGatewayProvider::factory()->create([
        'code' => PaymentGatewayProviderCodeEnum::Paypay,
        'is_active' => true,
    ]);

    $this->organization = $organization;
    $this->brand = $brand;
    $this->branch = $branch;
    // `payment_attempts.customer_order_id` là NOT NULL — một lượt thu luôn thu
    // CHO một đơn. Đơn ở đây chỉ để hàng attempt tồn tại hợp lệ; không đường nào
    // trong các test này chạm tới tiền của nó.
    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 1340,
        'paid_amount' => 0,
    ]);
    $this->connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_branch_id' => null,
        'owner_scope' => 'hq',
        'environment' => PaymentGatewayEnvironmentEnum::Test,
        'merchant_account_id' => 'paypay_merchant_3115',
        'is_active' => true,
    ]);
});

/**
 * Một thông báo OPA Transaction ĐÃ KÝ, đi qua route webhook công khai, rồi trả
 * về hàng inbox nó tạo ra. Không có `Queue::fake()` ở đây thì job xử lý sẽ chạy
 * ngay và nuốt mất lượt `apply()` mà test muốn quan sát.
 */
function issue3115PostSignedNotification(string $reference): PaymentProviderEvent
{
    Queue::fake();

    $payload = json_encode([
        'notification_type' => 'Transaction',
        'notification_id' => 'ppevt_'.Str::random(12),
        'merchant_id' => 'paypay_merchant_3115',
        'order_id' => $reference,
        'merchant_order_id' => $reference,
        'state' => 'COMPLETED',
        'order_amount' => '1340',
    ], JSON_THROW_ON_ERROR);

    test()->call(
        'POST',
        '/api/v1/webhooks/payment/paypay',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAY_SIGNATURE' => hash_hmac('sha256', $payload, ISSUE_3115_WEBHOOK_SECRET),
        ],
        $payload,
    )->assertOk();

    return PaymentProviderEvent::query()
        ->where('provider_object_id', $reference)
        ->latest('received_at')
        ->firstOrFail();
}

/** Bắt dòng `error` mà không bịt phần còn lại của log điều phối. */
function issue3115SpyOnLog(): MockInterface
{
    $logger = Log::spy();
    $logger->shouldReceive('channel')->andReturnSelf();

    return $logger;
}

function issue3115TerminalAttempt(string $reference, array $overrides = []): PaymentAttempt
{
    return PaymentAttempt::factory()->create(array_merge([
        'organization_id' => test()->organization->id,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'customer_order_id' => test()->order->id,
        'connection_id' => test()->connection->id,
        'provider' => PaymentGatewayProviderCodeEnum::Paypay->value,
        'environment' => 'test',
        'channel' => PaymentChannelEnum::CustomerWeb->value,
        'state' => PaymentAttemptStateEnum::Canceled->value,
        'operation' => 'sale',
        'currency' => 'JPY',
        'amount_minor' => 1340,
        'provider_object_id' => $reference,
        'version' => 1,
    ], $overrides));
}

/**
 * `error` được ghi HAI lần cho mỗi lượt: MoneyOrchestrationLog mirror mọi lỗi
 * tiền sang cả channel `payment_orchestration` lẫn channel mặc định, vì chỉ
 * channel sau mới tới được cảnh báo (#1244). `Log::spy()` với
 * `channel()->andReturnSelf()` gộp cả hai lên cùng một spy, nên số lần gọi là
 * bằng chứng duy nhất nhìn thấy được rằng mirror đã xảy ra.
 */
function issue3115ExpectAlarm(MockInterface $logger, string $reference, string $outcome): void
{
    $logger->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.paypay] paypay_qr_notification_unbookable'
            && $context['merchant_payment_id'] === $reference
            && $context['outcome'] === $outcome)
        ->twice();
}

it('kêu khi attempt preauth CỦA TA đã terminal mà PayPay nói COMPLETED', function () {
    // Tham chiếu preauth: `PayPayPaymentGateway::merchantPaymentId()` = operation
    // id bỏ gạch. Không có `tempoqr-`, nên rào cũ im — trong khi đây đúng là
    // trường hợp chuông sinh ra để kêu: đường phục hồi (thứ duy nhất ghi sổ một
    // khoản PayPay) không chạy lại cho attempt terminal.
    $reference = str_replace('-', '', (string) Str::uuid());
    issue3115TerminalAttempt($reference);

    $event = issue3115PostSignedNotification($reference);
    $logger = issue3115SpyOnLog();

    expect(app(ProviderEventApplicator::class)->apply((string) $event->id))
        // Chuỗi outcome không đổi: nó được lưu trên hàng inbox và đọc bởi mã
        // khác. Đây là thêm chuông, không phải đổi phán quyết.
        ->toBe('paypay_ignored_terminal');

    issue3115ExpectAlarm($logger, $reference, 'paypay_ignored_terminal');
});

it('kêu khi refund CỦA TA đã terminal mà thông báo refund vẫn tới', function () {
    // `merchant_refund_id` = operation id, cũng là UUID trần. Refund KHÔNG BAO
    // GIỜ mang tiền tố `tempoqr-`, nên trước bản vá này mọi
    // `paypay_ignored_terminal` của refund đều im lặng tuyệt đối.
    $reference = (string) Str::uuid();

    PaymentRefund::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'payment_attempt_id' => issue3115TerminalAttempt(str_replace('-', '', (string) Str::uuid()))->id,
        'connection_id' => $this->connection->id,
        'provider' => PaymentGatewayProviderCodeEnum::Paypay->value,
        'environment' => 'test',
        'state' => PaymentRefundStateEnum::Canceled->value,
        'currency' => 'JPY',
        'amount_minor' => 1340,
        'provider_refund_id' => $reference,
    ]);

    $event = issue3115PostSignedNotification($reference);
    $logger = issue3115SpyOnLog();

    expect(app(ProviderEventApplicator::class)->apply((string) $event->id))
        ->toBe('paypay_ignored_terminal');

    issue3115ExpectAlarm($logger, $reference, 'paypay_ignored_terminal');
});

it('vẫn kêu cho QR customer-web, hình dạng rào cũ đã bảo vệ', function () {
    // Chiều "không được làm hỏng cái đang đúng".
    $reference = PayPayQrCodeClient::merchantPaymentIdFor((string) Str::uuid());
    issue3115TerminalAttempt($reference, ['state' => PaymentAttemptStateEnum::Expired->value]);

    $event = issue3115PostSignedNotification($reference);
    $logger = issue3115SpyOnLog();

    expect(app(ProviderEventApplicator::class)->apply((string) $event->id))
        ->toBe('paypay_ignored_terminal');

    issue3115ExpectAlarm($logger, $reference, 'paypay_ignored_terminal');
});

it('IM LẶNG cho `pp_*` của WooCommerce, thứ không có hàng nào của ta khớp', function () {
    // Chiều thứ hai của rào. Merchant Live dùng chung: relay tee mọi notification
    // của `menu.betoya.jp` sang đây. Không có attempt/refund nào khớp và không có
    // tiền tố `tempoqr-` ⇒ không phải tiền của Tempo ⇒ không kêu. Kêu vì chúng là
    // dạy người vận hành tắt chuông, và chuông này canh tiền thật.
    $reference = 'pp_177716-fa9e';

    $event = issue3115PostSignedNotification($reference);
    $logger = issue3115SpyOnLog();

    expect(app(ProviderEventApplicator::class)->apply((string) $event->id))
        ->toBe('paypay_no_matching_attempt');

    $logger->shouldNotHaveReceived('error');
});
