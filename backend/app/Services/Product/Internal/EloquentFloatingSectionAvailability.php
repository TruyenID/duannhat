<?php

declare(strict_types=1);

namespace App\Services\Product\Internal;

use App\Services\Product\Contracts\FloatingSectionAvailability;
use App\Services\Product\Contracts\FloatingSectionSkuPrice;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * #1622 — bản cài duy nhất của vị ngữ "floating section đang phát sóng".
 *
 * `DB::table` ở đây là ĐÚNG CHỖ: đây là adapter của module SỞ HỮU các bảng
 * `floating_section*` (aggregate `menu`, khai trong `config/domain-mutation-guard.php`),
 * nên nó không vượt ranh giới nào. Cái sai trước #1622 không phải "dùng query
 * builder" mà là **ai** dùng nó — Pricing đọc thẳng bảng của Catalog.
 *
 * Toàn bộ luật cửa sổ nằm trong {@see applyLiveScheduleWindow}, và cả hai method
 * công khai đều đi qua nó. Đó là điểm của class này: thêm một chỗ gọi thứ ba
 * cũng không sinh thêm một bản sao thứ ba.
 */
final class EloquentFloatingSectionAvailability implements FloatingSectionAvailability
{
    public function liveSectionIds(string $branchId, ?CarbonImmutable $at = null): array
    {
        $now = $this->branchNow($branchId, $at);

        $ids = DB::table('floating_sections as fs')
            ->join('floating_section_schedules as fss', 'fss.floating_section_id', '=', 'fs.id')
            ->where('fs.branch_id', $branchId)
            ->where('fs.is_active', true)
            ->where('fss.is_active', true)
            ->whereNull('fs.deleted_at')
            ->whereNull('fss.deleted_at')
            ->tap(fn (Builder $q) => $this->applyLiveDateRange($q, 'fs', $now))
            ->tap(fn (Builder $q) => $this->applyLiveDateRange($q, 'fss', $now))
            ->tap(fn (Builder $q) => $this->applyLiveScheduleWindow($q, 'fss', $now))
            // Một section có nhiều lịch, và nhiều lịch có thể cùng khớp.
            ->distinct()
            ->pluck('fs.id')
            ->all();

        return array_values(array_map(strval(...), $ids));
    }

    public function livePricesForSkus(string $branchId, array $productSkuIds, ?CarbonImmutable $at = null): array
    {
        $productSkuIds = array_values(array_unique(array_filter($productSkuIds)));

        if ($productSkuIds === []) {
            return [];
        }

        $now = $this->branchNow($branchId, $at);

        $rows = DB::table('floating_section_product_skus as fsps')
            ->join('floating_section_products as fsp', 'fsp.id', '=', 'fsps.floating_section_product_id')
            ->join('floating_sections as fs', 'fs.id', '=', 'fsp.floating_section_id')
            ->join('floating_section_schedules as fss', 'fss.floating_section_id', '=', 'fs.id')
            ->where('fs.branch_id', $branchId)
            ->where('fs.is_active', true)
            ->where('fsp.is_active', true)
            ->where('fsps.is_active', true)
            ->where('fss.is_active', true)
            ->whereNull('fs.deleted_at')
            ->whereNull('fsp.deleted_at')
            ->whereNull('fsps.deleted_at')
            ->whereNull('fss.deleted_at')
            ->whereIn('fsps.product_sku_id', $productSkuIds)
            ->tap(fn (Builder $q) => $this->applyLiveDateRange($q, 'fs', $now))
            ->tap(fn (Builder $q) => $this->applyLiveDateRange($q, 'fss', $now))
            ->tap(fn (Builder $q) => $this->applyLiveScheduleWindow($q, 'fss', $now))
            ->get([
                'fsps.product_sku_id',
                'fsps.selling_price',
                'fs.id as floating_section_id',
                'fs.name',
                'fs.priority',
                'fsp.id as floating_section_product_id',
            ]);

        $prices = [];

        foreach ($rows as $row) {
            $prices[] = new FloatingSectionSkuPrice(
                skuId: (string) $row->product_sku_id,
                price: (float) $row->selling_price,
                floatingSectionId: (string) $row->floating_section_id,
                floatingSectionProductId: (string) $row->floating_section_product_id,
                sectionName: (string) $row->name,
                priority: (int) $row->priority,
            );
        }

        return $prices;
    }

    /**
     * Giờ tường của CHI NHÁNH. `$at` do chỗ gọi đưa vẫn được ép về múi giờ chi
     * nhánh: một instant UTC và cùng instant đó ở Tokyo chọn ra hai "hôm nay"
     * khác nhau, mà cửa sổ khuyến mãi tính theo hôm nay của quán (#1091).
     */
    private function branchNow(string $branchId, ?CarbonImmutable $at): CarbonImmutable
    {
        $timezone = BusinessClock::timezoneForBranch($branchId);

        return ($at ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
    }

    /**
     * `start_date`/`end_date` bao trùm ngày nghiệp vụ hiện tại; null = không giới hạn.
     */
    private function applyLiveDateRange(BuilderContract $query, string $alias, CarbonImmutable $now): void
    {
        $today = $now->toDateString();

        $query
            ->where(fn ($q) => $q->whereNull("{$alias}.start_date")->orWhere("{$alias}.start_date", '<=', $today))
            ->where(fn ($q) => $q->whereNull("{$alias}.end_date")->orWhere("{$alias}.end_date", '>=', $today));
    }

    /**
     * LUẬT CỬA SỔ — bản duy nhất. Ba nhánh, và nhánh thứ ba là chỗ dễ mất nhất:
     *
     * 1. **Trong ngày**: hôm nay nằm trong mặt nạ thứ, và `start_time <= now <= end_time`.
     * 2. **Qua nửa đêm, phần trước 24:00**: lịch có `start_time > end_time` (ví dụ
     *    22:00→02:00) và bây giờ đã qua `start_time` ⇒ đang trong ca của HÔM NAY.
     * 3. **Qua nửa đêm, phần sau 00:00**: cùng loại lịch, nhưng bây giờ là rạng
     *    sáng — ca đang chạy là ca mở từ **HÔM QUA**, nên phải khớp mặt nạ thứ của
     *    hôm qua (`$previousDayMask`), không phải của hôm nay. Bỏ nhánh này thì
     *    quán mở xuyên đêm mất khuyến mãi mỗi ngày từ 00:00 tới giờ đóng.
     */
    private function applyLiveScheduleWindow(BuilderContract $query, string $alias, CarbonImmutable $now): void
    {
        $time = $now->format('H:i:s');
        $todayMask = 1 << $now->dayOfWeek;
        $previousDayMask = 1 << $now->subDay()->dayOfWeek;

        $query->where(function ($window) use ($alias, $time, $todayMask, $previousDayMask) {
            $window->where(function ($sameDay) use ($alias, $time, $todayMask) {
                $sameDay->whereRaw("({$alias}.days_of_week & ?) > 0", [$todayMask])
                    ->where(function ($hours) use ($alias, $time) {
                        $hours->where(function ($normal) use ($alias, $time) {
                            $normal->whereColumn("{$alias}.start_time", '<=', "{$alias}.end_time")
                                ->where("{$alias}.start_time", '<=', $time)
                                ->where("{$alias}.end_time", '>=', $time);
                        })->orWhere(function ($beforeMidnight) use ($alias, $time) {
                            $beforeMidnight->whereColumn("{$alias}.start_time", '>', "{$alias}.end_time")
                                ->where("{$alias}.start_time", '<=', $time);
                        });
                    });
            })->orWhere(function ($afterMidnight) use ($alias, $time, $previousDayMask) {
                $afterMidnight->whereRaw("({$alias}.days_of_week & ?) > 0", [$previousDayMask])
                    ->whereColumn("{$alias}.start_time", '>', "{$alias}.end_time")
                    ->where("{$alias}.end_time", '>=', $time);
            });
        });
    }
}
