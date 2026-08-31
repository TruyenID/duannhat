<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Cái mà một module khác thật sự cần nói khi phát một thông báo (#1568).
 *
 * Đo trước khi thiết kế: bảy caller ngoài module Notifications đều truyền cùng
 * một bộ khoá — `type`, `template_key`, `params`, `actor`, `subject`,
 * `organization_id`, `idempotency_key`; một số thêm `priority`, hai observer
 * thêm `aggregation_key`. Không caller nào dùng `scheduled_for`, `audience_id`
 * hay `resolved_via`, nên object này không mang chúng.
 *
 * `aggregationKey` suýt bị bỏ sót: bản đo đầu chỉ nhìn 5 service và kết luận
 * "không ai dùng". Hai observer dùng nó, và dùng cho việc quan trọng — gộp 30
 * cảnh báo tồn kho của một kho thành MỘT dòng chuông (plan-023 M5 T5.11). Đo
 * thiếu một phần caller thì cổng sinh ra đã hẹp sai chỗ.
 *
 * `templateKey` mặc định `= type`, và mặc định đó là phần quan trọng: bảy caller
 * đo được **lúc #1568** đều đặt hai giá trị bằng nhau, nên bắt họ truyền lại là
 * tạo một tham số chờ bị truyền sai. Nó vẫn TỒN TẠI vì một sự kiện có thể
 * cần hai bản copy (#2754): "mở ca mà còn đơn treo" khi không thiếu đồng nào
 * đọc phải khác lúc đang mất tiền thật.
 *
 * Vì sao không tách bằng `type` (cách rẻ hơn): `type` là thứ MỌI bộ lọc khác ăn
 * theo — `NotificationService::DEFAULT_PRIORITIES` khai theo type, và bất cứ
 * preference/digest/watcher nào lọc theo type sẽ **im lặng bỏ sót** loại mới.
 * Đúng lớp lỗi "slug sai không ném lỗi, phân giải ra 0 người nhận" đã trả giá
 * bốn lần (#2451/#2456). Một sự kiện ⇒ một `type`; sắc thái copy đi riêng.
 */
final readonly class NotificationRequest
{
    /**
     * @param  array<string, mixed>  $params  dữ liệu điền vào template
     * @param  Model|null  $actor  ai gây ra việc này (null = hệ thống)
     * @param  Model|null  $subject  bản ghi mà thông báo nói về
     * @param  string|null  $priority  low|normal|high|urgent; null = mặc định theo type
     * @param  string|null  $aggregationKey  gộp nhiều thông báo thành một dòng chuông
     * @param  string|null  $templateKey  bản copy dùng để render; null = chính `type`
     */
    public function __construct(
        public string $type,
        public array $params,
        public string $organizationId,
        public ?Model $actor = null,
        public ?Model $subject = null,
        public ?string $idempotencyKey = null,
        public ?string $priority = null,
        public ?string $aggregationKey = null,
        public ?string $templateKey = null,
    ) {}

    /** Khoá template đã giải — `templateKey` nếu có, không thì `type`. */
    public function resolvedTemplateKey(): string
    {
        return $this->templateKey ?? $this->type;
    }
}
