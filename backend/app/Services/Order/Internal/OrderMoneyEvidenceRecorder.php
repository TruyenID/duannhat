<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\OrderMoneyOverwrite;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * #2885 — người ghi DUY NHẤT của `order_money_overwrites`.
 *
 * ## Vì sao là một class riêng chứ không nằm trong controller
 *
 * `config/domain-mutation-guard.php` khai aggregate `order_money_evidence` và
 * lấy file này làm biên giới. Để lệnh ghi trong controller thì biên giới của
 * một bảng bằng chứng tiền sẽ là **tầng HTTP** — đúng chuyện #1666 đã phải gỡ
 * ra khỏi `ShopFaqController::visibility` để `post_branches` khai được chủ.
 * Một file có tên, một người ghi, cổng đọc được.
 *
 * ## Idempotency: bắt vi phạm UNIQUE, không `exists()` rồi `create()`
 *
 * `exists()` rồi mới ghi là một cửa sổ race: hai request song song cùng thấy
 * "chưa có" rồi cùng INSERT. Chỉ ràng buộc `(device_id, local_id)` ở tầng DB
 * mới quyết được, nên đường đúng là cứ ghi và bắt ngoại lệ.
 *
 * Và đây là **no-op tuyệt đối**, không phải `updateOrCreate`: nếu số gửi lên
 * khác số đã lưu thì có bug ở đầu kia, mà ghi đè sẽ xoá mất dấu vết của chính
 * bug đó. Model `OrderMoneyOverwrite` chặn thêm một lớp nữa (`updating`/
 * `deleting` ném) để một tính năng viết sau này không mở lại cửa ấy.
 *
 * ## Không nuốt lỗi khác
 *
 * Chỉ `UniqueConstraintViolationException` mới thành `false`. Mọi lỗi khác đi
 * tiếp lên thành 5xx **có chủ đích**: máy trạm chỉ đánh dấu `synced_at` sau khi
 * Cloud nhận, nên 5xx làm nó thử lại — còn nuốt lỗi ở đây sẽ đánh dấu đã đồng
 * bộ một dòng chưa bao giờ tới nơi, tức mất bằng chứng trong im lặng.
 */
final class OrderMoneyEvidenceRecorder
{
    /**
     * Mười một trường tiền của hợp đồng wire: `paid_locally` + năm cặp
     * local/cloud. Tất cả BẮT BUỘC, kể cả khi không đổi — một dòng bằng chứng
     * phải tự đứng được mà không join ngược về `customer_orders`, vì bảng ấy
     * đã bị ghi đè rồi và đó chính là sự kiện đang được ghi lại.
     *
     * Hằng này là NGUỒN CHÂN LÝ DUY NHẤT cho danh sách đó: controller sinh
     * rule `validate()` từ nó. Gõ lại danh sách ở hai chỗ là cách một trường
     * lặng lẽ mất rule rồi bị `validate()` strip đi (#2622).
     *
     * @var list<string>
     */
    public const MONEY_FIELDS = [
        'paid_locally',
        'total_amount_local',
        'total_amount_cloud',
        'subtotal_local',
        'subtotal_cloud',
        'tax_amount_local',
        'tax_amount_cloud',
        'service_charge_local',
        'service_charge_cloud',
        'discount_amount_local',
        'discount_amount_cloud',
    ];

    /**
     * Ghi MỘT dòng bằng chứng.
     *
     * `$deviceId` / `$branchId` / `$organizationId` đến từ device token, KHÔNG
     * từ payload — một thiết bị không ghi được bằng chứng sang chi nhánh khác.
     *
     * @param  array<string, mixed>  $row  một phần tử `overwrites[]` ĐÃ qua validate
     * @return bool `true` = ghi mới; `false` = đã có dòng mang đúng cặp khoá
     */
    public function record(string $deviceId, string $branchId, string $organizationId, array $row): bool
    {
        $attributes = [
            'device_id' => $deviceId,
            'branch_id' => $branchId,
            'organization_id' => $organizationId,
            'local_id' => (int) $row['local_id'],
            'order_id' => (string) $row['order_id'],
            // Parse tường minh rồi ép UTC: chuỗi đã kết thúc bằng `Z` nên
            // instant là xác định, và ép UTC ở đây khiến cột không bao giờ phụ
            // thuộc `app.timezone` của tiến trình đang chạy (#1091).
            'occurred_at' => CarbonImmutable::parse((string) $row['occurred_at'])->utc(),
        ];

        foreach (self::MONEY_FIELDS as $field) {
            $attributes[$field] = (int) $row[$field];
        }

        try {
            OrderMoneyOverwrite::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
