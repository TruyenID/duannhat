<?php

declare(strict_types=1);

namespace App\Services\Promotion\Internal;

use App\Models\Branch;
use App\Models\MenuPromotion;
use App\Services\Promotion\Contracts\MenuDisplayPromotion;
use App\Services\Promotion\Contracts\MenuDisplayPromotions;
use App\Services\Promotion\MenuPromotionService;
use Carbon\CarbonImmutable;

/**
 * #962 — hiện thực {@see MenuDisplayPromotions}.
 *
 * Phân giải phạm vi vẫn do `MenuPromotionService` làm (một lời gọi Pricing →
 * Pricing); phần thêm ở đây là dựng `endsAt`, chép NGUYÊN từ
 * `CustomerMenuService::resolvePromotionEndsAt()` kể cả ca vắt qua nửa đêm.
 *
 * Múi giờ phân giải y hệt bản cũ — `branches.timezone`, rơi về
 * `config('app.default_branch_timezone')` khi không tra được chi nhánh. **Cố ý
 * KHÔNG** đổi sang `BusinessClock`: bản cũ không dùng nó, và một PR dời ranh
 * giới không phải chỗ đổi mốc thời gian mà đồng hồ đếm ngược của khách đang đọc.
 */
final class EloquentMenuDisplayPromotions implements MenuDisplayPromotions
{
    public function __construct(
        private readonly MenuPromotionService $promotions,
    ) {}

    public function forMenuItems(string $branchId, array $items): array
    {
        $resolved = $this->promotions->resolveActivePromotionsForMenu($branchId, $items);
        if ($resolved === []) {
            return [];
        }

        $timezone = $this->timezoneFor($branchId);

        $out = [];
        foreach ($resolved as $productId => $promotion) {
            $out[$productId] = $promotion instanceof MenuPromotion
                ? new MenuDisplayPromotion(
                    id: (string) $promotion->id,
                    name: $promotion->name,
                    discountPercent: (float) $promotion->discount_percent,
                    stackingMode: $promotion->stacking_mode,
                    endsAt: $this->endsAt($promotion, $timezone),
                )
                : null;
        }

        return $out;
    }

    private function timezoneFor(string $branchId): string
    {
        return Branch::find($branchId)?->timezone
            ?: (string) config('app.default_branch_timezone', 'Asia/Tokyo');
    }

    /**
     * Mốc "hết khuyến mãi" sắp tới: cái ĐẾN TRƯỚC giữa hết cửa sổ hôm nay và
     * `valid_until`. Không có cửa sổ theo giờ thì là `valid_until`.
     */
    private function endsAt(MenuPromotion $promotion, string $timezone): string
    {
        $validUntil = CarbonImmutable::instance($promotion->valid_until)->setTimezone($timezone);
        $to = $promotion->daily_time_to;
        if ($to === null) {
            return $validUntil->toIso8601String();
        }

        $now = CarbonImmutable::now($timezone);
        [$h, $m] = array_pad(array_map('intval', explode(':', $to)), 2, 0);
        $todayEnd = $now->setTime($h, $m, 0);
        // Cửa sổ vắt qua nửa đêm: mốc KẾT THÚC thuộc về ngày mai khi bây giờ đã
        // qua giờ kết thúc mà cửa sổ vẫn đang mở
        // (from = 21:00, to = 02:00, now = 23:30 → kết thúc 02:00 ngày mai).
        $from = $promotion->daily_time_from;
        if ($from !== null) {
            [$fh, $fm] = array_pad(array_map('intval', explode(':', $from)), 2, 0);
            $todayStart = $now->setTime($fh, $fm, 0);
            if ($todayEnd->lt($todayStart) && $now->gte($todayStart)) {
                $todayEnd = $todayEnd->addDay();
            }
        }

        return $todayEnd->lt($validUntil) ? $todayEnd->toIso8601String() : $validUntil->toIso8601String();
    }
}
