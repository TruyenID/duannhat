<?php

namespace App\Services\Payment\ProviderEvent;

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * #2893 — the Stripe account our API key IS.
 *
 * ## Vì sao khái niệm này phải có tên
 *
 * Một `acct_…` trong `payment_gateway_connections.merchant_account_id` có thể
 * là hai thứ khác hẳn nhau:
 *
 *  - **tài khoản kết nối** (Connect) — gọi API phải kèm `Stripe-Account`;
 *  - **chính tài khoản của ta** — gọi API mà kèm `Stripe-Account` là sai, vì
 *    ta không "đóng vai" chính mình.
 *
 * Nhìn vào chuỗi `acct_…` KHÔNG phân biệt được hai ca đó. Phép phân biệt duy
 * nhất là so với tài khoản mà `STRIPE_SECRET` xác thực thành — tức
 * `STRIPE_ACCOUNT_ID`. Trước #2893 câu hỏi này không tồn tại vì hàng
 * connection thật mang nhãn nội bộ `orchestrator:customer-web:{org}`, không
 * khớp `^acct_`, nên mọi lượt gọi đều rơi vào nhánh "platform" một cách tình
 * cờ. Đóng dấu định danh THẬT của PSP lên hàng đó (việc #2893 phải làm để đối
 * soát payout không mơ hồ khi tài khoản dùng chung — #2864) làm sự tình cờ ấy
 * biến mất, nên chỗ dựa phải là một giá trị khai báo được.
 *
 * ## Không đặt được thì KHÔNG đoán
 *
 * `accountId()` trả `null` khi env rỗng hoặc không đúng dạng `acct_…`. Mọi nơi
 * gọi phải hiểu `null` là "chưa biết" và giữ nguyên hành vi trước #2893 — chứ
 * không phải "không có tài khoản nào". Đoán ở đây là đoán tiền của ai.
 */
final class StripePlatformAccount
{
    /**
     * Tài khoản Stripe mà `STRIPE_SECRET` xác thực thành, hoặc `null` khi chưa
     * khai (hoặc khai sai dạng).
     */
    public static function accountId(): ?string
    {
        $accountId = trim(self::config('services.stripe.account_id'));

        return preg_match('/^acct_[A-Za-z0-9_]+$/', $accountId) === 1
            ? $accountId
            : null;
    }

    /** `true` chỉ khi tham chiếu merchant CHÍNH LÀ tài khoản của ta. */
    public static function isPlatformAccount(string $merchantAccountReference): bool
    {
        $accountId = self::accountId();

        return $accountId !== null && trim($merchantAccountReference) === $accountId;
    }

    /**
     * Môi trường suy từ khoá bí mật đang dùng — cùng phép đo mà
     * {@see LegacyGlobalStripeConnection} vẫn dùng, giữ ở MỘT chỗ để hai bên
     * không trôi khỏi nhau.
     */
    public static function environment(): PaymentGatewayEnvironmentEnum
    {
        return str_contains(self::config('services.stripe.secret'), '_live_')
            ? PaymentGatewayEnvironmentEnum::Live
            : PaymentGatewayEnvironmentEnum::Test;
    }

    /**
     * `StripeConnectScope` được gọi từ cả test đơn vị THUẦN — không boot
     * framework, không có binding `config` — nên `config()` ở đó ném
     * `BindingResolutionException`. Không có cấu hình thì câu trả lời đúng là
     * "chưa biết" (chuỗi rỗng ⇒ `accountId()` trả null ⇒ hành vi trước #2893),
     * chứ không phải một ngoại lệ ở giữa đường đọc phí.
     */
    private static function config(string $key): string
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return '';
        }

        /** @var Repository $config */
        $config = $container->make('config');

        return (string) $config->get($key, '');
    }
}
