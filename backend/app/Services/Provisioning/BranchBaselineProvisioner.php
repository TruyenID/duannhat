<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\Zone;
use App\Models\ZoneTemplate;
use App\Services\Shop\BrandTableDefaultsService;
use App\Services\Shop\ShopOrderSettingsService;

/**
 * Baseline của một BRANCH (shop) — xem {@see BrandBaselineProvisioner} cho lý do
 * chọn reconcile thay vì hook.
 *
 * Ranh giới với đường lazy-init đang có là cố ý và không được xoá nhoà:
 *
 *   · `Till` (`TillSessionService`) và `Warehouse` (`StockDeductionService`) vẫn
 *     `firstOrCreate` lúc dùng lần đầu. Chúng là hạ tầng, không mang quyết định nghiệp vụ nào
 *     trước lần dùng đầu tiên, nên tạo sẵn chỉ tổ đẻ hàng rác cho shop chưa mở.
 *   · `ShopOrderSetting` thì KHÔNG lazy được: nó chở currency, chế độ giá gồm
 *     thuế và loại thuế mặc định — tức là tiền. Nó phải có mặt trước giao dịch
 *     đầu tiên, không phải được dựng lên bởi giao dịch đó.
 */
final class BranchBaselineProvisioner
{
    public function __construct(
        private readonly ShopOrderSettingsService $orderSettings,
        private readonly BrandTableDefaultsService $tableDefaults,
    ) {}

    /** Chỉ ĐỌC. */
    public function plan(Branch $branch): BaselineReport
    {
        $report = new BaselineReport("branch:{$branch->slug}");

        foreach ($this->statuses($branch) as $key => $detail) {
            $detail === null ? $report->satisfied($key) : $report->missing($key, $detail);
        }

        return $report;
    }

    /** Đọc rồi SỬA. Idempotent. */
    public function ensure(Branch $branch): BaselineReport
    {
        $report = new BaselineReport("branch:{$branch->slug}");

        foreach ($this->statuses($branch) as $key => $detail) {
            if ($detail === null) {
                $report->satisfied($key);

                continue;
            }

            $applied = match ($key) {
                'branch.order_settings' => $this->applyOrderSettings($branch),
                'branch.floor_plan' => $this->applyFloorPlan($branch),
            };

            $applied
                ? $report->applied($key, $detail)
                : $report->skipped($key, $detail.' — không đủ tiền đề để dựng');
        }

        return $report;
    }

    /** @return array<string, string|null> */
    private function statuses(Branch $branch): array
    {
        return [
            'branch.order_settings' => $this->orderSettingsStatus($branch),
            'branch.floor_plan' => $this->floorPlanStatus($branch),
        ];
    }

    private function orderSettingsStatus(Branch $branch): ?string
    {
        return ShopOrderSetting::query()->where('branch_id', $branch->id)->exists()
            ? null
            : 'chưa có shop_order_settings';
    }

    private function applyOrderSettings(Branch $branch): bool
    {
        $organizationId = $this->resolveOrganizationId($branch);
        if ($organizationId === null) {
            return false;
        }

        // Chi nhánh chưa khai đơn vị tiền thì KHÔNG dựng cài đặt.
        //
        // Bản đầu ghi `?? 'JPY'`, và mặc định im lặng đó tệ hơn là không dựng
        // gì: một chi nhánh VN nhận cài đặt JPY sẽ hiện giá sai trên thực đơn
        // và trên đơn — lỗi đã xảy ra thật một lần. Tệ hơn nữa là readiness sẽ
        // báo XANH, tức cổng nói dối đúng chỗ nó phải cảnh báo.
        //
        // Trả `false` ⇒ `ensure()` ghi mục này là `skipped`, `ready` thành
        // false, và người vận hành thấy đúng thứ cần sửa: khai currency cho
        // chi nhánh.
        if (blank($branch->currency)) {
            return false;
        }

        ShopOrderSetting::create(array_merge(
            [
                'branch_id' => $branch->id,
                'organization_id' => $organizationId,
                'currency_code' => $branch->currency,
                'default_tax_type_id' => $this->brandStandardTaxTypeId($branch),
            ],
            // #2108 — mặc định lúc TẠO là chuyện của một nơi duy nhất: đường
            // funnel thật. Chép lại luật "JP ⇒ giá gồm thuế" vào đây là dựng
            // nguồn chân lý thứ hai để nó trôi lệch.
            $this->orderSettings->creationDefaults($branch),
        ));

        return true;
    }

    /**
     * Sơ đồ mặt bằng chỉ được dựng cho shop **chưa có zone nào**.
     *
     * Copy template là một quyết định của người vận hành — đã có sẵn màn hình
     * preview/apply cho việc đó (`Shop\TableDefaultsController`).
     * Baseline chỉ nhận phần không cần ai quyết: một shop vừa mở, chưa có gì,
     * thì bản mặc định của brand là điểm khởi đầu đúng. Shop đã có sơ đồ —
     * dù chỉ một zone — thì đây im lặng, quyền vẫn thuộc về người vận hành.
     */
    private function floorPlanStatus(Branch $branch): ?string
    {
        if (Zone::withTrashed()->where('branch_id', $branch->id)->exists()) {
            return null;
        }

        $brandId = $this->brandId($branch);
        if ($brandId === null) {
            return null;
        }

        $templates = ZoneTemplate::query()
            ->where('brand_id', $brandId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->count();

        return $templates === 0
            ? null
            : "chưa có zone nào; brand có {$templates} mẫu zone dùng được";
    }

    private function applyFloorPlan(Branch $branch): bool
    {
        $organizationId = $this->resolveOrganizationId($branch);
        if ($organizationId === null || $this->brandId($branch) === null) {
            return false;
        }

        $this->tableDefaults->apply($branch, $organizationId);

        return true;
    }

    private function brandStandardTaxTypeId(Branch $branch): ?string
    {
        $brandId = $this->brandId($branch);

        return $brandId === null
            ? null
            : TaxType::query()->where('brand_id', $brandId)->where('code', 'STANDARD')->value('id');
    }

    private function brandId(Branch $branch): ?string
    {
        return Brand::query()->where('console_brand_id', $branch->console_brand_id)->value('id');
    }

    /**
     * `branches` không có cột `organization_id`; suy từ zone của chi nhánh, rồi
     * đến Organization khớp `console_organization_id` của chính nó.
     */
    private function resolveOrganizationId(Branch $branch): ?string
    {
        return Zone::query()->where('branch_id', $branch->id)->value('organization_id')
            ?? Organization::query()
                ->where('console_organization_id', $branch->console_organization_id)
                ->value('id');
    }
}
