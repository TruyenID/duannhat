<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Branch;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gộp readiness của một brand và các chi nhánh của nó thành MỘT câu trả lời (#2357).
 *
 * Tách khỏi `HQ\BrandReadinessController` vì rào #1920: controller HQ không được
 * truy vấn Eloquent trực tiếp — điều kiện phạm vi (`organization_id`,
 * `branch_id`) sống ở tầng service, và một controller tự đi hỏi DB là chỗ đúng
 * để làm rơi nó mà không gì đỏ.
 *
 * Bản đầu (#2344) đặt `Branch::query()` thẳng trong controller. `arch-gate` của
 * CI **pass** vì job đó chỉ chạy `tests/Feature/Architecture` — thư mục
 * `tests/Arch/` chỉ chạy ở full suite. Nên PR xanh, merge, và `dev` đỏ ở cổng
 * sau. Ghi lại vì cái sai không phải "quên luật" mà là **tin dấu xanh của một
 * cổng hẹp hơn mình tưởng**.
 *
 * CHỈ ĐỌC. Gọi `plan()`, không bao giờ `ensure()` — xem `BrandReadinessController`.
 */
final class ReadinessService
{
    public function __construct(
        private readonly BrandBaselineProvisioner $brands,
        private readonly BranchBaselineProvisioner $branches,
    ) {}

    /**
     * @return array{ready: bool, checks: list<array{subject: string, key: string, state: string, detail: string}>}
     */
    public function forBrand(Brand $brand): array
    {
        $reports = [$this->brands->plan($brand)];

        foreach ($this->activeShopsOf($brand) as $branch) {
            $reports[] = $this->branches->plan($branch);
        }

        $checks = [];
        $ready = true;

        foreach ($reports as $report) {
            // Sẵn sàng = MỌI chủ thể sẵn sàng. Một chi nhánh thiếu cài đặt đơn
            // hàng là brand chưa bán được ở chi nhánh đó, nên không có lý do gì
            // để tổng hợp thành "gần đúng".
            $ready = $ready && $report->isReady();

            foreach ($report->entries() as $entry) {
                $checks[] = [
                    'subject' => $report->subject,
                    'key' => $entry['key'],
                    'state' => $entry['state'],
                    'detail' => $entry['detail'],
                ];
            }
        }

        return ['ready' => $ready, 'checks' => $checks];
    }

    /**
     * Chi nhánh đang hoạt động của brand.
     *
     * Phạm vi đi theo `console_brand_id` — cùng khoá mà mọi đường shop-scoped
     * khác dùng. Đây chính là dòng mà rào #1920 đòi phải nằm trong service.
     *
     * @return Collection<int, Branch>
     */
    private function activeShopsOf(Brand $brand)
    {
        return Branch::query()
            ->where('console_brand_id', $brand->console_brand_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
