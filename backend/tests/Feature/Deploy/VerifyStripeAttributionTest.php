<?php

declare(strict_types=1);

use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Settlement\SettlementAttributionMigrator;

/**
 * #2969 — rào cho TIỀN ĐỀ ÂM THẦM của #2893.
 *
 * Rào phải biết KÊU và biết IM, và ở đây chiều IM quan trọng bất thường: nó
 * chạy trên **đường deploy production**, nên kêu oan một lần là chặn đứng một
 * lượt phát hành — và phản ứng sẽ là gỡ rào, không phải sửa cấu hình.
 */
function stripeAttrSynthetic(): void
{
    // Chỉ cần đúng hàng mang id ấy tồn tại — rào hỏi `exists()`, không đọc cột.
    // Dùng factory chứ không INSERT thô: bảng này có ~20 cột bắt buộc và một
    // danh sách gõ tay sẽ mục ruỗng ngay lượt regen kế tiếp.
    PaymentGatewayConnection::factory()->create([
        'id' => SettlementAttributionMigrator::RETIRED_CONNECTION_ID,
        'merchant_account_id' => 'orchestrator:internal-label',
        'environment' => 'live',
        'is_active' => true,
    ]);
}

it('#2969 KÊU: biến rỗng trong khi connection tổng hợp còn tồn tại', function () {
    // Đây là trạng thái tiền ĐANG đi sai: webhook rơi về lưới cuối và ghi vào
    // connection tổng hợp, chủ sở hữu không thấy gì, và không có gì đỏ.
    config(['services.stripe.account_id' => null]);
    stripeAttrSynthetic();

    expect(fn () => $this->artisan('deploy:verify-stripe-attribution'))
        ->toThrow(RuntimeException::class);
});

it('#2969 IM: biến đã đặt', function () {
    // Đặt biến là đủ để hàng MỚI đi đúng chỗ. Hàng cũ chưa dọn KHÔNG phải lý do
    // chặn deploy — đó là việc ops chạy một lần, có dry-run, và chặn deploy vì
    // nó là phạt nhầm người.
    config(['services.stripe.account_id' => 'acct_LIVE123']);
    stripeAttrSynthetic();

    $this->artisan('deploy:verify-stripe-attribution')->assertSuccessful();
});

it('#2969 IM: hàng tổng hợp đã NGƯNG DÙNG — rào tự hết vai', function () {
    // Ca này giữ cho rào không thành tiếng ồn vĩnh viễn, và nó suýt sai.
    //
    // `--apply` cho hàng tổng hợp nghỉ bằng `is_active=false` và **KHÔNG xoá**
    // — nó là chủ sở hữu lịch sử của các bản ghi tiền, và
    // `payment_settlements.connection_id` còn khoá ngoại vào nó. Bản đầu của
    // rào hỏi `exists()`, tức sẽ kêu VĨNH VIỄN kể cả sau khi ops đã dọn xong.
    //
    // Một rào không bao giờ hết vai là một rào sắp bị tắt — và lúc bị tắt thì
    // nó thôi canh cả những thứ khác.
    config(['services.stripe.account_id' => null]);

    PaymentGatewayConnection::factory()->create([
        'id' => SettlementAttributionMigrator::RETIRED_CONNECTION_ID,
        'environment' => 'live',
        'is_active' => false,
    ]);

    $this->artisan('deploy:verify-stripe-attribution')->assertSuccessful();
});

it('#2969 giá trị SAI DẠNG cũng bị coi là chưa đặt', function () {
    // Ca thật, không phải giả định: dán nhầm secret key vào ô account id là lỗi
    // cấu hình thường gặp, và nó nguy hiểm hơn để trống — nhìn vào `.env` thấy
    // "có giá trị" nên người ta tin là đã xong.
    //
    // Rào phải hỏi qua `StripePlatformAccount::accountId()` (có khớp
    // `^acct_…`), KHÔNG đọc thẳng config. Đọc thẳng config thì chuỗi này qua
    // được, và deploy xanh trong khi tiền vẫn đi sai chỗ.
    config(['services.stripe.account_id' => 'sk_live_khongphaiaccountid']);
    stripeAttrSynthetic();

    expect(fn () => $this->artisan('deploy:verify-stripe-attribution'))
        ->toThrow(RuntimeException::class);
});
