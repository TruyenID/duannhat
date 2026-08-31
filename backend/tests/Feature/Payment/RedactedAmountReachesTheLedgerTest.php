<?php

/**
 * #3138 — SỐ TIỀN của một thông báo phải tra được TỪ SỔ, không phải từ cổng.
 *
 * Cái giá của việc thiếu nó đo được ở #3115: để trả lời đúng một câu — "có giao
 * dịch ¥1.340 nào không" — phải gọi PayPay retrieve **32 lần**. Sự cố tiền thật
 * mà thời gian điều tra phụ thuộc vào một hệ thống bên ngoài, đúng lúc quán
 * đang cần câu trả lời.
 *
 * Nên bài đầu tiên ở đây không chỉ khẳng định "số tiền có trong sổ" — nó khẳng
 * định luôn rằng **không lượt gọi cổng nào xảy ra** để có được số đó. Bỏ vế thứ
 * hai thì bài vẫn xanh trong một thế giới mà mỗi lần tra là một lần gọi PayPay,
 * tức xanh mà không phủ đúng thứ issue này sinh ra để sửa.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\Stripe\StripeLifecycleMapper;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;

uses()->group('payment');

const ISSUE_3138_WEBHOOK_SECRET = 'paypay_whsec_3138';

beforeEach(function () {
    config([
        'services.paypay.api_key' => 'pp_key_dummy',
        'services.paypay.api_secret' => 'pp_secret_dummy',
        'services.paypay.webhook_secret' => ISSUE_3138_WEBHOOK_SECRET,
    ]);

    $consoleOrganizationId = (string) Str::uuid();

    $organization = Organization::factory()->create(['console_organization_id' => $consoleOrganizationId]);
    $brand = Brand::factory()->create(['console_organization_id' => $consoleOrganizationId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'JPY',
    ]);
    $provider = PaymentGatewayProvider::factory()->create([
        'code' => PaymentGatewayProviderCodeEnum::Paypay,
        'is_active' => true,
    ]);

    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 1340,
        'paid_amount' => 0,
    ]);

    PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_branch_id' => null,
        'owner_scope' => 'hq',
        'environment' => PaymentGatewayEnvironmentEnum::Test,
        'merchant_account_id' => 'paypay_merchant_3138',
        'is_active' => true,
    ]);
});

/**
 * Gửi một thông báo OPA Transaction ĐÃ KÝ qua route webhook công khai.
 *
 * Đi qua endpoint chứ không gọi thẳng mapper: chữ ký, phân giải connection và
 * ánh xạ tham chiếu đều nằm trên đường thật, và #2622 đã cho thấy một trường có
 * thể đi hết đường service mà vẫn không bao giờ tới nơi qua HTTP.
 *
 * @param  array<string, mixed>  $extra
 */
function issue3138PostNotification(string $reference, array $extra): PaymentProviderEvent
{
    Queue::fake();

    $payload = json_encode([
        'notification_type' => 'Transaction',
        'notification_id' => 'ppevt_'.Str::random(12),
        'merchant_id' => 'paypay_merchant_3138',
        'order_id' => $reference,
        'merchant_order_id' => $reference,
        'state' => 'COMPLETED',
        ...$extra,
    ], JSON_THROW_ON_ERROR);

    test()->call(
        'POST',
        '/api/v1/webhooks/payment/paypay',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAY_SIGNATURE' => hash_hmac('sha256', $payload, ISSUE_3138_WEBHOOK_SECRET),
        ],
        $payload,
    )->assertOk();

    return PaymentProviderEvent::query()
        ->where('provider_object_id', $reference)
        ->latest('received_at')
        ->firstOrFail();
}

it('ghi số tiền của thông báo vào sổ mà KHÔNG gọi cổng', function () {
    // Vế thứ hai là vế đắt: bắt mọi lượt gọi PayPay thành lỗi. Nếu ở đâu đó
    // đường ghi lén hỏi cổng để lấy số tiền thì bài này đỏ chứ không xanh nhầm.
    $this->mock(PayPayQrCodeClient::class, function ($mock) {
        $mock->shouldNotReceive('getPaymentDetails');
        $mock->shouldNotReceive('createQrCode');
    });

    $event = issue3138PostNotification('tempoqr-'.Str::random(10), ['order_amount' => '1340']);

    expect($event->redacted_payload)->toHaveKey('amount_minor')
        // SỐ NGUYÊN, không phải chuỗi `'1340'` mà OPA gửi. Một sổ có cùng số
        // tiền dưới hai hình dạng là một sổ tra không ra.
        ->and($event->redacted_payload['amount_minor'])->toBe(1340);
});

it('KHÔNG bịa số 0 khi thông báo không nói số tiền', function () {
    // Đây là ca dễ làm sai nhất, và làm sai theo chiều tệ nhất: một `?? 0` ở
    // đường đọc sẽ ghi "giao dịch này ¥0" vào đúng cuốn sổ người ta tin lúc đối
    // soát. Ô trống nói "không biết"; số 0 nói "không có đồng nào".
    $event = issue3138PostNotification('tempoqr-'.Str::random(10), []);

    expect($event->redacted_payload)->not->toHaveKey('amount_minor')
        ->and($event->redacted_payload)->not->toHaveKey('currency');
});

it('chỉ ghi currency khi payload NÓI RA', function () {
    $withCode = issue3138PostNotification('tempoqr-'.Str::random(10), [
        'order_amount' => '1340',
        'currency' => 'jpy',
    ]);

    // Chuẩn hoá về chữ hoa — OPA gửi chữ thường, và hai hình dạng cho một mã
    // tiền tệ là cùng một lỗi tra-không-ra như trên.
    expect($withCode->redacted_payload['currency'])->toBe('JPY');

    // Còn khi payload im lặng thì im theo. OPA hôm nay là Nhật, JPY — nhưng
    // suy ra 'JPY' từ chỗ payload không nói là bịa, và một mã bịa sẽ được tin y
    // hệt một mã thật.
    $silent = issue3138PostNotification('tempoqr-'.Str::random(10), ['order_amount' => '1340']);

    expect($silent->redacted_payload)->not->toHaveKey('currency');
});

it('CHẶN hai hình dạng lệch ngay tại từ vựng biên', function () {
    // `ALLOWED_KEYS` là từ vựng dùng chung; luật chung của nó nhận cả `'1340'`
    // lẫn `1340`. #2860 cho thấy bảy cách viết cho ba khái niệm sống được nhiều
    // tháng mà không gì đỏ — nên hai khoá tiền có luật riêng, và đây là bài
    // chứng minh luật đó tồn tại.
    expect(fn () => new RedactedData(['amount_minor' => '1340']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new RedactedData(['currency' => 'jpy']))
        ->toThrow(InvalidArgumentException::class);

    expect((new RedactedData(['amount_minor' => 1340, 'currency' => 'JPY']))->jsonSerialize())
        ->toBe(['amount_minor' => 1340, 'currency' => 'JPY']);
});

it('Stripe ghi số ĐÃ UỶ QUYỀN, không phải số đã capture', function () {
    // Hai số này khác nhau ở đúng ca cần điều tra. Uỷ quyền ¥1.340 rồi capture
    // 0 thì `amount_received` = 0, và một sổ ghi ¥0 trả lời SAI cho câu duy
    // nhất người ta hỏi khi khách nói đã bị trừ tiền.
    $mapper = new StripeLifecycleMapper;
    $connection = new GatewayConnectionData(
        '0198f608-84ce-7629-b653-00dc291475a1',
        PaymentGatewayProviderCodeEnum::Stripe,
        PaymentGatewayEnvironmentEnum::Test,
        'acct_1ConnectTest001',
        1,
    );

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_3138_authorized_not_captured',
        'object' => 'payment_intent',
        'amount' => 1340,
        'amount_received' => 0,
        'currency' => 'jpy',
        'status' => 'requires_capture',
        'capture_method' => 'manual',
    ]);

    $summary = $mapper->mapPaymentIntent($intent, $connection)->summary->jsonSerialize();

    expect($summary['amount_minor'])->toBe(1340)
        ->and($summary['currency'])->toBe('JPY');
});
