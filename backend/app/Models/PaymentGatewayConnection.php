<?php

/**
 * PaymentGatewayConnection Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PaymentGatewayConnection\Models\PaymentGatewayConnectionBaseModel;
use Database\Factories\PaymentGatewayConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PaymentGatewayConnection — add project-specific model logic here.
 */
class PaymentGatewayConnection extends PaymentGatewayConnectionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentGatewayConnectionFactory
    {
        return PaymentGatewayConnectionFactory::new();
    }

    /**
     * UUID dùng cho "chủ sở hữu là HQ" trong {@see $owner_branch_key}.
     *
     * Không phải một chi nhánh có thật, và cố ý không tra được ra hàng nào —
     * nó chỉ là chỗ đứng để một khoá UNIQUE có giá trị so sánh thay vì NULL.
     */
    public const HQ_OWNER_BRANCH_KEY = '00000000-0000-0000-0000-000000000000';

    /**
     * #3074 — đóng dấu `owner_branch_key` từ `owner_branch_id` ở MỘT chỗ.
     *
     * Cột đó không mang thông tin mới: nó luôn bằng `owner_branch_id`, hoặc
     * {@see HQ_OWNER_BRANCH_KEY} khi cột kia NULL. Nó tồn tại vì MySQL cho phép
     * NHIỀU NULL trong một unique index, mà mọi hàng HQ đều để branch NULL
     * (production: 4/4) — nên khoá tự nhiên dựng trên cột nullable sẽ không
     * chặn được hàng HQ thứ hai, tức đúng lỗ #3070 vẫn mở.
     *
     * Đặt ở `saving` chứ không bắt từng đường ghi tự nhớ: bốn chỗ tạo connection
     * nằm rải ở bootstrap Stripe, bootstrap PayPay, tầng lưu cấu hình và tầng
     * policy. Một cột khoá mà bốn nơi phải nhớ điền là một cột sẽ bị quên, và
     * quên ở đây nghĩa là hàng mới trượt khỏi ràng buộc duy nhất.
     *
     * Chỉ an toàn vì cả bốn đường đều đi qua Eloquent — đã đo, không có
     * `DB::table(...)->insert()` nào vào bảng này. Thêm một đường ghi thô là
     * vô hiệu hoá chỗ này, nên đừng thêm.
     */
    protected static function booted(): void
    {
        static::saving(function (self $connection): void {
            $connection->owner_branch_key = $connection->owner_branch_id === null
                ? self::HQ_OWNER_BRANCH_KEY
                : (string) $connection->owner_branch_id;
        });
    }
}
