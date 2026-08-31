<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\PrintImageAsset;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Support\BusinessClock;

/**
 * #1957 mảnh B — chọn ảnh nào có hiệu lực cho một chi nhánh.
 *
 * ## Chi nhánh thắng brand, và chỉ có hai tầng
 *
 * Giống `TemplateResolver`: bản ghi đè của shop thắng bản của brand. Không có
 * tầng `system` cho ảnh — một logo mặc định toàn hệ thống là thứ không ai muốn
 * in ra, và để trống thì TR-05 đã lo (phiếu vẫn in, chỉ thiếu khối).
 *
 * ## `effective_from` được TÔN TRỌNG, và nó là giờ của CHI NHÁNH
 *
 * Một brand đổi logo cho đợt sale mùng 1 phải publish trước rồi hẹn ngày.
 *
 * `effective_from` là GIỜ TREO TƯỜNG CỦA CHI NHÁNH, không phải một mốc thời gian
 * tuyệt đối — hệt như `print_templates.effective_from`. HQ hẹn "2026-08-01 00:00"
 * thì quán Tokyo lật trước quán Hà Nội hai giờ. Nên so bằng
 * `BusinessClock::now($branchId)->format('Y-m-d H:i:s')`, KHÔNG bao giờ `now()`:
 * `now()` sẽ lật mọi chi nhánh theo đồng hồ máy chủ, đúng lớp lỗi #1091.
 */
final class PrintImageResolver
{
    /**
     * Ảnh đang có hiệu lực cho một `source` tại một chi nhánh, hoặc null.
     *
     * Trả `null` là kết quả HỢP LỆ, không phải lỗi — xem TR-05.
     */
    public function forBranch(string $branchId, string $source): ?PrintImageAsset
    {
        // Chuỗi wall-clock, không phải instant — xem docblock lớp.
        $now = BusinessClock::now($branchId)->format('Y-m-d H:i:s');

        $shop = $this->newest($source, PrintTemplateScope::Shop, ['branch_id' => $branchId], $now);
        if ($shop !== null) {
            return $shop;
        }

        $brandId = $this->brandIdForBranch($branchId);
        if ($brandId === null) {
            return null;
        }

        return $this->newest($source, PrintTemplateScope::Brand, ['brand_id' => $brandId], $now);
    }

    /**
     * Mọi ảnh có hiệu lực cho một chi nhánh, khoá theo `source`.
     *
     * @return array<string, PrintImageAsset>
     */
    public function allForBranch(string $branchId): array
    {
        $out = [];

        foreach ((array) config('print_blocks.image.sources', []) as $source) {
            $asset = $this->forBranch($branchId, (string) $source);
            if ($asset !== null) {
                $out[(string) $source] = $asset;
            }
        }

        return $out;
    }

    /**
     * `branches` KHÔNG có `brand_id` — nó mang `console_brand_id`, định danh do
     * Platform cấp. Phải đi vòng qua đó để ra khoá chính của `brands`.
     *
     * Viết thành hàm riêng vì đây là kiểu sai IM LẶNG: đọc thẳng
     * `$branch->brand_id` cho ra `null`, và `null` là một kết quả HỢP LỆ của
     * resolver (TR-05), nên tầng brand sẽ đơn giản là không bao giờ khớp và
     * không có gì đỏ ở đâu cả. `TemplateResolver` cũng đi đúng đường vòng này.
     */
    private function brandIdForBranch(string $branchId): ?string
    {
        $consoleBrandId = Branch::withTrashed()->whereKey($branchId)->value('console_brand_id');

        if (! $consoleBrandId) {
            return null;
        }

        return Brand::withTrashed()->where('console_brand_id', $consoleBrandId)->value('id');
    }

    /** @param  array<string, string|null>  $owner */
    private function newest(string $source, PrintTemplateScope $scope, array $owner, string $now): ?PrintImageAsset
    {
        $query = PrintImageAsset::query()
            ->where('source', $source)
            ->where('scope', $scope->value)
            ->where('status', 'published')
            // `effective_from` null = hiệu lực ngay khi publish. Phải viết tường
            // minh: `where('effective_from', '<=', $now)` một mình sẽ LOẠI mọi
            // hàng null, tức mọi ảnh publish theo cách thông thường.
            ->where(function ($q) use ($now) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now);
            });

        foreach ($owner as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query->orderByDesc('version')->first();
    }
}
