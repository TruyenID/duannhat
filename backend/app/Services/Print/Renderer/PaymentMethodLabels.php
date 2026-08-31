<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 3 (#1934) — bản đối ứng của `paymentMethodLabels` +
 * `localizePaymentLabel` (workstation `print_shift_report_i18n.go`).
 *
 * Dòng 支払方法 trên phiếu 精算 là chỗ DUY NHẤT tên phương thức thanh toán được
 * dịch. Mọi chỗ khác — kể cả dòng `payments` của họ bill — in NGUYÊN VĂN tên do
 * quán cấu hình, có chủ đích: phiếu bán hàng phải khớp với báo cáo quán đang
 * dùng. Phiếu 精算 thì ngược lại, nó là chứng từ đối soát nội bộ nên đọc được ở
 * ngôn ngữ người đóng ca là đúng.
 *
 * ── Ví điện tử thương hiệu CỐ Ý vắng mặt ─────────────────────────────────
 *
 * Bảng dưới chỉ có các mã TỔNG QUÁT mà POS phát ra. `paypay`, `linepay`… không
 * có ở đây vì chúng là danh từ riêng: dịch "PayPay" sang tiếng Nhật không có
 * nghĩa gì, và một bản dịch tự chế sẽ làm phiếu lệch khỏi bảng sao kê của chính
 * nhà cung cấp ví. Chúng rơi xuống `payment_methods.name` đồng bộ từ Cloud.
 *
 * ── Thang ba bậc, và bậc cuối KHÔNG phải chuỗi rỗng ──────────────────────
 *
 * mã có trong bảng + có locale ấy → tên Cloud → chính cái mã. Bậc cuối trả về
 * mã thô thay vì rỗng là quyết định: một dòng tiền không nhãn trên phiếu đối
 * soát là một khoản không ai truy được, còn `on_account` in thô thì xấu nhưng
 * vẫn truy được.
 */
final class PaymentMethodLabels
{
    /**
     * Khoá theo mã đã hạ chữ thường, rồi theo locale — chép NGUYÊN từ Go.
     *
     * @var array<string, array<string, string>>
     */
    private const LABELS = [
        'cash' => ['ja' => '現金', 'en' => 'Cash', 'vi' => 'Tien mat'],
        'card' => ['ja' => 'カード', 'en' => 'Card', 'vi' => 'The'],
        'credit_card' => ['ja' => 'クレジットカード', 'en' => 'Credit card', 'vi' => 'The tin dung'],
        'credit' => ['ja' => 'クレジットカード', 'en' => 'Credit card', 'vi' => 'The tin dung'],
        'debit_card' => ['ja' => 'デビットカード', 'en' => 'Debit card', 'vi' => 'The ghi no'],
        'qr' => ['ja' => 'QRコード', 'en' => 'QR code', 'vi' => 'Ma QR'],
        'qr_code' => ['ja' => 'QRコード', 'en' => 'QR code', 'vi' => 'Ma QR'],
        'e_wallet' => ['ja' => '電子マネー', 'en' => 'E-wallet', 'vi' => 'Vi dien tu'],
        'e_money' => ['ja' => '電子マネー', 'en' => 'E-money', 'vi' => 'Tien dien tu'],
        'emoney' => ['ja' => '電子マネー', 'en' => 'E-money', 'vi' => 'Tien dien tu'],
        'bank_transfer' => ['ja' => '銀行振込', 'en' => 'Bank transfer', 'vi' => 'Chuyen khoan'],
        'transfer' => ['ja' => '振込', 'en' => 'Transfer', 'vi' => 'Chuyen khoan'],
        'on_account' => ['ja' => '掛売', 'en' => 'On account', 'vi' => 'Ghi no'],
        'voucher' => ['ja' => '商品券', 'en' => 'Voucher', 'vi' => 'Phieu mua hang'],
        'points' => ['ja' => 'ポイント', 'en' => 'Points', 'vi' => 'Diem'],
        'point' => ['ja' => 'ポイント', 'en' => 'Points', 'vi' => 'Diem'],
    ];

    /**
     * Nhãn hiển thị cho MỘT dòng phương thức thanh toán.
     *
     * @param  string  $cloudName  `payment_methods.name` đồng bộ từ Cloud
     */
    public static function localize(string $locale, string $code, string $cloudName): string
    {
        $key = strtolower(trim($code));

        // Go tra bằng `m[locale]` THÔ, không chuẩn hoá locale ở đây — một
        // locale lạ trượt bảng và rơi xuống tên Cloud, chứ KHÔNG rơi về ja.
        // Khác với `ShiftLabels::forLocale` ngay bên cạnh, nên chép đúng: tên
        // quán cấu hình là bản dự phòng tốt hơn một bản dịch tiếng Nhật mà
        // người đọc không đọc được.
        if (isset(self::LABELS[$key][$locale]) && self::LABELS[$key][$locale] !== '') {
            return self::LABELS[$key][$locale];
        }

        if (trim($cloudName) !== '') {
            return $cloudName;
        }

        return $code;
    }

    /**
     * Hình dạng bảng để cổng parity so với Go.
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        return self::LABELS;
    }
}
