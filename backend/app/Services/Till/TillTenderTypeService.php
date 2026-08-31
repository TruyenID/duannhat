<?php

declare(strict_types=1);

namespace App\Services\Till;

use App\Models\OrderPayment;
use App\Models\TillTenderType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * #1917 — đọc từ vựng tender CẤP TỔ CHỨC và đếm mức sử dụng của nó.
 *
 * Tách khỏi `TenderTypeController` (#1881) vì `HqControllerArchTest` cấm HQ
 * controller chạm Eloquent: *"every read or write must go through a Service"*.
 * Trước bản tách này controller dựng thẳng `TillTenderType::query()` và
 * `OrderPayment::query()`.
 *
 * ── Tách khỏi {@see Contracts\OrgTenderVocabulary}, có chủ đích ───────────
 *
 * Cổng kia trả lời MỘT câu hỏi cho PlatformIntegration: "khoá này có trong từ
 * vựng đang hoạt động không". Doc của nó nói thẳng rằng một cổng đọc rộng hơn
 * *"là một câu hỏi khác, chưa ai cần"*. Giờ có người cần — nhưng người cần là
 * màn quản trị HQ, không phải PlatformIntegration, nên nó là service riêng chứ
 * không phải nới cổng kia ra cho hai người dùng có nhu cầu khác nhau.
 *
 * ── Hai ràng buộc LOAD-BEARING, đừng đánh rơi khi đọc lướt ────────────────
 *
 * 1. `branch_id IS NULL` — chỉ hàng cấp tổ chức. Ghi đè của chi nhánh thuộc
 *    tầng shop (`ShopTillTenderActivationController`); trộn hai tầng vào một
 *    danh sách là để HQ sửa được thứ nó không sở hữu.
 * 2. Mọi phép đếm payment **phải** có điều kiện tổ chức. Thiếu nó thì một tổ
 *    chức khác dùng cùng khoá — `cash` là khoá phổ biến nhất có thể có — sẽ
 *    chặn thao tác xoá/đổi nhóm ở đây, và người vận hành không có cách nào
 *    nhìn ra tại sao. `HqTenderVocabularyTest` có ca riêng ghim việc này.
 */
final class TillTenderTypeService
{
    /**
     * Từ vựng cấp tổ chức, theo thứ tự hiển thị.
     *
     * @return Collection<int, TillTenderType>
     */
    public function listForOrganization(string $organizationId, bool $includeInactive = false): Collection
    {
        return $this->orgScope($organizationId)
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('tender_key')
            ->get();
    }

    /**
     * Một hàng cấp tổ chức theo id, hoặc ném `ModelNotFoundException`.
     *
     * Đi qua `orgScope` chứ không `findOrFail` trần: một id thuộc tổ chức khác
     * phải ra 404, không phải ra hàng của người ta.
     */
    public function findForOrganizationOrFail(string $organizationId, string $id): TillTenderType
    {
        return $this->orgScope($organizationId)->findOrFail($id);
    }

    /** Số payment của tổ chức này đang tham chiếu một khoá tender. */
    public function usageCount(string $organizationId, string $tenderKey): int
    {
        return OrderPayment::query()
            ->where('organization_id', $organizationId)
            ->where('tender_key', $tenderKey)
            ->count();
    }

    /**
     * Đếm theo lô cho màn danh sách — một truy vấn thay vì N.
     *
     * @param  list<string>  $tenderKeys
     * @return array<string, int>
     */
    public function usageCounts(string $organizationId, array $tenderKeys): array
    {
        if ($tenderKeys === []) {
            return [];
        }

        return OrderPayment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('tender_key', $tenderKeys)
            ->selectRaw('tender_key, COUNT(*) as c')
            ->groupBy('tender_key')
            ->pluck('c', 'tender_key')
            ->map(static fn ($c): int => (int) $c)
            ->all();
    }

    /**
     * @return Builder<TillTenderType>
     */
    private function orgScope(string $organizationId)
    {
        return TillTenderType::query()
            ->where('organization_id', $organizationId)
            // Chỉ hàng cấp tổ chức. Ghi đè của chi nhánh thuộc tầng shop.
            ->whereNull('branch_id');
    }
}
