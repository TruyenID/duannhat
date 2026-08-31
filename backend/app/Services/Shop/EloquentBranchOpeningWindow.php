<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Services\Order\Contracts\BranchOpeningWindow;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * #962 — hiện thực của {@see BranchOpeningWindow}, chuyển tiếp nguyên vẹn sang
 * `BranchOpeningHours`.
 *
 * Ba method, ba lời gọi, không thêm gì. Toàn bộ luật múi giờ (#1091) và luật
 * "chưa khai `weekly_hours` thì luôn mở" ở lại một chỗ; adapter này chỉ đổi
 * static call thành một cổng tiêm được.
 */
final class EloquentBranchOpeningWindow implements BranchOpeningWindow
{
    public function isOpenAt(Branch $branch, DateTimeInterface $instant): bool
    {
        return BranchOpeningHours::isOpenAt($branch, $instant);
    }

    public function closingAt(Branch $branch, DateTimeInterface $instant): ?CarbonImmutable
    {
        return BranchOpeningHours::closingAt($branch, $instant);
    }

    public function nextOpeningAt(Branch $branch, DateTimeInterface $instant): ?CarbonImmutable
    {
        return BranchOpeningHours::nextOpeningAt($branch, $instant);
    }
}
