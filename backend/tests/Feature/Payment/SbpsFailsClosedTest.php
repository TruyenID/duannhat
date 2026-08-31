<?php

use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentGatewayProvider;
use App\Services\Payment\Gateway\Sbps\SbpsPaymentGateway;

/*
 * #1796 — SBPS phải TỪ CHỐI, và phải từ chối ở TẤT CẢ tám cửa.
 *
 * Đây là test cho một chỗ trống có tên, nên nó ghim đúng hai điều:
 *
 *   1. adapter tồn tại và cài đủ hợp đồng — để ngày hợp đồng SBPS về, người viết
 *      tiếp chỉ thay thân từng method, không phải dựng lại khung;
 *   2. **không method nào lỡ trả về một kết quả**. Một method quên `refuse()` sẽ
 *      trả `null` cho một kiểu không nullable ⇒ TypeError lúc chạy, ở giữa một
 *      lượt thanh toán thật. Test này bắt nó trước, ở đây.
 *
 * Không ghim thông điệp lỗi bằng chuỗi: nó sẽ đổi khi có hợp đồng. Ghim MÃ lỗi
 * và loại ngoại lệ — hai thứ mà chỗ gọi thật sự rẽ nhánh theo.
 */

it('#1796 cài đủ hợp đồng cổng thanh toán', function () {
    expect(app(SbpsPaymentGateway::class))->toBeInstanceOf(PaymentGatewayContract::class);
});

it('#1796 CHƯA được đăng ký làm driver — và đó là chủ đích', function () {
    // Bản đầu của issue này ĐÃ đăng ký, với lập luận "để thông điệp lỗi nói đúng
    // SBPS chưa tới lượt thay vì lẫn với một tên gõ sai".
    //
    // `PaymentGatewayRegistryBindingTest` đỏ và cho thấy lập luận đó NGƯỢC:
    // `configuredProviders()` nghĩa là "provider DÙNG ĐƯỢC", và danh sách ấy
    // được trích thẳng vào thông điệp của `UnsupportedPaymentGatewayProvider`.
    // Đăng ký `sbps` vào đó khiến mọi lỗi provider-lạ KHÁC kèm theo một câu nói
    // dối — rằng SBPS đang dùng được.
    //
    // Khi hợp đồng + IF spec về: thêm dòng đăng ký, và test này sẽ đỏ ở đúng chỗ
    // nhắc rằng phải cài thân adapter trước.
    expect(config('payments.gateway_drivers'))->not->toHaveKey('sbps');
});

it('#1796 TẤT CẢ tám method THỰC THI của hợp đồng đều từ chối', function () {
    // Gọi qua reflection với tham số giả: mọi method phải ném TRƯỚC khi chạm
    // vào tham số, nên tham số không hợp lệ cũng không sao — và đó chính là
    // điều cần chứng minh (không method nào lỡ làm gì với dữ liệu).
    //
    // #2938 — hợp đồng nay có 9 method, nhưng `identifyConnection` CỐ Ý đứng
    // ngoài luật "từ chối": nó chạy TRƯỚC khi xác minh chữ ký, trên payload
    // chưa tin được, ở một endpoint công khai. Ném ở đó sẽ biến mọi rác gửi tới
    // `POST /webhooks/payment/sbps` thành 500 — tự khai "lỗi của ta" cho traffic
    // giả mạo. `null` mới đúng, và resolver coi `null` là TỪ CHỐI fail-closed.
    // Nó có test riêng ở `WebhookConnectionIdentificationTest`.
    $gateway = new SbpsPaymentGateway;
    $methods = (new ReflectionClass(PaymentGatewayContract::class))->getMethods();

    expect($methods)->toHaveCount(9);

    $methods = array_values(array_filter(
        $methods,
        static fn (ReflectionMethod $m): bool => $m->getName() !== 'identifyConnection',
    ));

    expect($methods)->toHaveCount(8);

    $refused = [];
    foreach ($methods as $method) {
        try {
            // Tham số null cho một kiểu không nullable sẽ ném TypeError NẾU
            // method thật sự đọc tham số. Ta chấp nhận cả hai: điều cần là nó
            // KHÔNG trả về giá trị nào.
            $gateway->{$method->getName()}(...array_fill(0, $method->getNumberOfParameters(), null));
            $refused[$method->getName()] = 'TRẢ VỀ — không được phép';
        } catch (UnsupportedPaymentGatewayProvider $e) {
            $refused[$method->getName()] = 'từ chối: '.$e->provider->value;
        } catch (TypeError) {
            $refused[$method->getName()] = 'từ chối (đọc tham số trước)';
        }
    }

    foreach ($refused as $name => $outcome) {
        expect($outcome)->not->toBe('TRẢ VỀ — không được phép', "method {$name} trả về thay vì từ chối");
    }
});

it('#1796 ngoại lệ nêu ĐÚNG provider và mã lỗi ổn định', function () {
    // Gọi thẳng `refuse()` qua reflection, KHÔNG qua `capabilities(null)`.
    //
    // Bản đầu của test này gọi `capabilities(null)` với lập luận "method không
    // đọc tham số nên sẽ ném đúng ngoại lệ có nghĩa". SAI: PHP kiểm kiểu tham số
    // TRƯỚC khi vào thân, nên nó ném `TypeError` và không bao giờ chạm tới
    // `refuse()`. Đo mới biết.
    //
    // `refuse()` là điểm dồn duy nhất của cả tám method (test ở trên đã ghim
    // rằng không method nào trả về), nên kiểm ở đây là kiểm đúng bất biến mà
    // chỗ gọi thật sự phụ thuộc vào.
    $method = new ReflectionMethod(SbpsPaymentGateway::class, 'refuse');
    $method->setAccessible(true);

    try {
        $method->invoke(new SbpsPaymentGateway, 'corr-1');
        $thrown = null;
    } catch (UnsupportedPaymentGatewayProvider $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedPaymentGatewayProvider::class)
        ->and($thrown->provider)->toBe(PaymentGatewayProviderCodeEnum::Sbps)
        // Ghim MÃ lỗi, không ghim câu chữ: câu chữ sẽ đổi khi có hợp đồng, còn
        // mã là thứ chỗ gọi rẽ nhánh theo. Là `errorCode`, KHÔNG phải `code` —
        // `code` là thuộc tính protected của \Exception và đọc nó ném Error.
        ->and($thrown->errorCode)->toBe('PAYMENT_GATEWAY_PROVIDER_UNSUPPORTED');
});

it('#1796 liệt kê thứ CÒN THIẾU, không chỉ nói "chưa hỗ trợ"', function () {
    // Thông điệp "chưa hỗ trợ" không giúp ai. Danh sách này là thứ trả lời được
    // câu hỏi tiếp theo của người vận hành: "vậy cần gì để bật?"
    expect(SbpsPaymentGateway::MISSING_ARTIFACTS)->not->toBeEmpty();

    $joined = implode(' | ', SbpsPaymentGateway::MISSING_ARTIFACTS);
    expect($joined)->toContain('IF specification')
        // Mốc kết thúc partial sale/refund phải nằm trong danh sách: nó là ràng
        // buộc CÓ HẠN NGÀY, và người viết adapter phải chốt nó trước khi thiết kế.
        ->and($joined)->toContain('2026-09-30');
});
