<?php

namespace App\Services\Payment\ProviderEvent;

use Illuminate\Support\Str;

/**
 * #2851 / #2864 — MỘT phép phân biệt duy nhất cho câu hỏi "PaymentIntent này
 * có phải của Tempo không", dùng chung cho tầng webhook lẫn tầng ingest sổ
 * đối soát. Hai bản chép tay của cùng một luật thì sớm muộn cũng lệch, và
 * lệch ở đây nghĩa là tiền vào nhầm sổ.
 *
 * **Vì sao câu hỏi này tồn tại.** Tài khoản Stripe của quán dùng CHUNG với
 * trang đặt món online riêng của họ (WooCommerce + PaymentPlugins), và Stripe
 * gửi MỌI sự kiện của tài khoản tới MỌI endpoint đã đăng ký — không có cách
 * nào đăng ký "chỉ sự kiện do tôi tạo".
 *
 * **Phép phân biệt.** Mọi đường tạo intent của Tempo đều đóng dấu id
 * `customer_orders` (UUID) vào `metadata.order_id` — kể cả nhánh split — và
 * `markOrderPaidFromIntent()` còn dựa vào chính nó để cứu ca con trỏ bị ghi
 * đè. Hệ đặt món kia dùng id SỐ của WooCommerce (`"177370"`).
 *
 * **Ba trạng thái, không phải hai.** `Unknown` (không có `metadata.order_id`)
 * cố ý tách khỏi `ForeignAccount` vì hai người gọi cần hai cách xử khác nhau:
 * tầng log xếp nó vào diện im lặng, còn tầng sổ tiền phải giữ hàng lại. Gộp
 * hai trạng thái này thành một boolean chính là chỗ một bên sẽ phải nói dối.
 *
 * Hướng sai của phép này là hướng AN TOÀN theo cả hai chiều: một intent lạ
 * tình cờ mang UUID bị xếp nhầm thành `Tempo`, chứ một intent của ta không
 * bao giờ bị xếp thành `ForeignAccount` — id đơn của Tempo luôn là UUID.
 */
enum StripeIntentOrigin
{
    /** `metadata.order_id` là UUID ⇒ id `customer_orders` ⇒ của Tempo. */
    case Tempo;

    /** `metadata.order_id` có mặt nhưng không phải UUID ⇒ của hệ khác. */
    case ForeignAccount;

    /** Không có `metadata.order_id` ⇒ không kết luận được. */
    case Unknown;

    /**
     * Phân loại từ giá trị thô của `metadata.order_id` trong snapshot Stripe.
     * Nhận `mixed` vì giá trị có thể tới từ mảng snapshot, từ đối tượng SDK,
     * hoặc vắng mặt hoàn toàn.
     */
    public static function fromOrderIdMetadata(mixed $orderId): self
    {
        $value = is_scalar($orderId) ? trim((string) $orderId) : '';

        if ($value === '') {
            return self::Unknown;
        }

        return Str::isUuid($value) ? self::Tempo : self::ForeignAccount;
    }
}
