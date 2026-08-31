<?php

declare(strict_types=1);

use App\Services\Order\Enums\OrderSplitMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #2860 — viết lại giá trị chia bill đã lưu về MỘT từ vựng canonical.
 *
 * `even` · `by_items` · `by_amount`. Chi tiết vì sao đúng ba giá trị đó và vì
 * sao là `even` chứ không phải `equal`: {@see OrderSplitMode}.
 *
 * # Vì sao phải viết lại dữ liệu, không phải chỉ đọc rộng ra
 *
 * Ruling #2188: dữ liệu cũ **backfill lần cuối rồi xoá lệnh**, không sống chung
 * với một nhánh `??`. Nếu để `by_people` nằm lại trong cột thì mọi chỗ đọc mãi
 * mãi phải biết hai tên, và cái "mãi mãi" đó chính là thứ đẻ ra bảy cách viết
 * cho ba khái niệm.
 *
 * Chuẩn hoá ở BIÊN VÀO (`OrderSplitMode::fromWire()`) chỉ lo phần tương lai —
 * thiết bị fleet chưa cập nhật vẫn gửi tên cũ. Migration này lo phần quá khứ.
 * Hai việc khác nhau, cần cả hai.
 *
 * # Ba chỗ, không phải một
 *
 * Cùng một từ vựng nằm ở ba nơi vì lịch sử đặt tên khác nhau:
 *
 *   `customer_orders.split_mode`             — chế độ cả bàn thoả thuận
 *   `order_payments.metadata->split_mode`    — chế độ của TỪNG khoản trả (POS)
 *   `order_payments.metadata->split_type`    — y hệt, nhưng đường Stripe/PayPay
 *                                              đặt tên trường khác
 *
 * Bỏ sót chỗ thứ ba là thứ dễ xảy ra nhất: nó không tên `split_mode` nên grep
 * theo tên cột không thấy.
 *
 * # Idempotent
 *
 * Mỗi `UPDATE` có `WHERE` bám đúng giá trị cũ, nên chạy lại không đổi gì thêm.
 * Cần vậy vì đường deploy chạy `migrate --force` không người trông, và một
 * migration dữ liệu chạy hai lần phải cho cùng kết quả.
 *
 * # Không có `down()` khôi phục
 *
 * Ánh xạ này **mất thông tin theo thiết kế**: `equal` và `by_people` đều thành
 * `even`, nên không có đường về phân biệt lại hai tên đó — và cũng không nên
 * có, vì chúng chưa bao giờ khác nhau về nghĩa. Viết một `down()` đoán bừa một
 * chiều sẽ tạo ra dữ liệu trông như thật mà sai.
 */
return new class extends Migration
{
    /**
     * tên cũ => canonical. Trùng nguồn với {@see OrderSplitMode::fromWire()};
     * migration khai lại tường minh vì nó phải đọc được như một hồ sơ của
     * chính lượt chuyển đổi này, kể cả sau khi normalizer bị xoá.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'equal' => 'even',
        'by_people' => 'even',
        'split_even' => 'even',
        'custom' => 'by_amount',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('customer_orders')
                ->where('split_mode', $old)
                ->update(['split_mode' => $new]);
        }

        // `metadata` là cột JSON. Dùng cú pháp JSON-path của Laravel
        // (`metadata->split_mode`) chứ KHÔNG viết `JSON_UNQUOTE`/`JSON_SET` tay:
        // Laravel biên dịch nó theo từng driver, còn `JSON_UNQUOTE` chỉ có ở
        // MySQL — DB test chạy SQLite, nên bản viết tay sẽ nổ ở đúng chỗ lẽ ra
        // phải chứng minh migration này đúng.
        //
        // Cập nhật theo JSON-path cũng giữ nguyên mọi khoá khác của blob. Đọc-
        // sửa-ghi cả blob trong PHP sẽ đua với các lượt ghi đang chạy, và đường
        // deploy có thể rơi vào giữa giờ phục vụ.
        foreach (['split_mode', 'split_type'] as $key) {
            foreach (self::MAP as $old => $new) {
                DB::table('order_payments')
                    ->where("metadata->{$key}", $old)
                    ->update(["metadata->{$key}" => $new]);
            }
        }
    }

    public function down(): void
    {
        // Cố ý rỗng — xem docblock. Ánh xạ nhiều-về-một không có nghịch đảo.
    }
};
