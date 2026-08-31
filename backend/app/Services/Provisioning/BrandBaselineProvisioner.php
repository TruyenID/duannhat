<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\TaxType;
use App\Services\Notification\BrandReverbAppService;
use App\Services\Product\BrandCoreCatalogService;
use App\Services\Product\Contracts\ProductTaxStamp;
use App\Services\Tax\TaxTypeService;
use Database\Seeders\CatalogSnapshotSeeder;

/**
 * Baseline của một BRAND — dữ liệu gốc phải có trước khi brand bán được hàng.
 *
 * **Reconcile, không phải create-once.** Đây là điểm khác biệt duy nhất đáng kể
 * so với cái nó thay thế (#2320). `Brand::created` chỉ bắn MỘT lần, và không bắn
 * gì khi seed (`DatabaseSeeder` chạy dưới `WithoutModelEvents`) — nên mỗi mục
 * baseline lại phải đẻ thêm một seeder-quét-bù, và repo đã có đủ ba bản cài đặt
 * chồng nhau cho riêng tax type. Ở đây mỗi mục tự trả lời được "đã đúng chưa"
 * nên chạy lại bao nhiêu lần cũng hội tụ, và **vá được brand đã tồn tại** —
 * thứ một event hook không bao giờ làm được.
 *
 * Mỗi mục baseline là một cặp `<mục>Status()` / `apply<Mục>()`. Cặp đôi này là
 * cố ý: `plan()` chỉ gọi vế status (đọc thuần, an toàn để chạy trên production),
 * `ensure()` gọi vế apply cho đúng những mục đang thiếu. Một cờ `$dryRun` chảy
 * xuyên qua đường ghi thì rẻ hơn để viết và đắt hơn nhiều để tin.
 */
final class BrandBaselineProvisioner
{
    public function __construct(
        private readonly TaxTypeService $taxTypes,
        private readonly BrandReverbAppService $reverb,
        private readonly BrandCoreCatalogService $coreCatalog,
        private readonly ProductTaxStamp $productTaxStamp,
    ) {}

    /** Chỉ ĐỌC — dùng cho `--dry-run` và cho readiness. */
    public function plan(Brand $brand): BaselineReport
    {
        $report = new BaselineReport("brand:{$brand->slug}");

        $organizationId = $this->resolveOrganizationId($brand);
        if ($organizationId === null) {
            // Chưa đồng bộ org ⇒ mọi mục đều CHƯA BIẾT, không phải đã đúng.
            $report->skipped('brand.organization', 'brand chưa gắn organization nào — Platform chưa đồng bộ?');

            return $report;
        }

        foreach ($this->statuses($brand) as $key => $detail) {
            $detail === null ? $report->satisfied($key) : $report->missing($key, $detail);
        }

        return $report;
    }

    /** Đọc rồi SỬA những mục đang thiếu. Idempotent. */
    public function ensure(Brand $brand): BaselineReport
    {
        $report = new BaselineReport("brand:{$brand->slug}");

        $organizationId = $this->resolveOrganizationId($brand);
        if ($organizationId === null) {
            $report->skipped('brand.organization', 'brand chưa gắn organization nào — Platform chưa đồng bộ?');

            return $report;
        }

        foreach ($this->statuses($brand) as $key => $detail) {
            if ($detail === null) {
                $report->satisfied($key);

                continue;
            }

            match ($key) {
                'brand.tax_types' => $this->applyTaxTypes($brand, $organizationId),
                'brand.reverb' => $this->reverb->provision($brand),
                'brand.combo_catalog' => $this->coreCatalog->ensureCombo($brand),
                'brand.product_tax_stamp' => $this->applyProductTaxStamp($brand),
            };

            $report->applied($key, $detail);
        }

        return $report;
    }

    /**
     * CHỈ bộ ba loại thuế chuẩn — dùng khi phần còn lại của baseline chưa được
     * phép chạy.
     *
     * Người gọi duy nhất là {@see CatalogSnapshotSeeder}: nó
     * cần loại thuế tồn tại TRƯỚC vòng upsert để ánh xạ `products.tax_type_id`,
     * nhưng KHÔNG được để `ensure()` đầy đủ chạy ở đó — mục `combo` sẽ tạo một
     * `product_types` mã `combo` với id mới, rồi ảnh chụp upsert hàng `combo`
     * của chính nó với id nguồn và đụng unique (brand_id, code).
     *
     * Đây không phải đường vòng: vẫn là `ensure()` gọi xuống cùng một chỗ.
     */
    public function ensureTaxTypes(Brand $brand): void
    {
        $organizationId = $this->resolveOrganizationId($brand);
        if ($organizationId === null) {
            return;
        }

        $this->applyTaxTypes($brand, $organizationId);
    }

    /**
     * Mọi mục baseline của brand: khoá ⇒ null (đã đúng) | mô tả cái đang thiếu.
     *
     * @return array<string, string|null>
     */
    private function statuses(Brand $brand): array
    {
        return [
            'brand.tax_types' => $this->taxTypesStatus($brand),
            'brand.reverb' => $this->reverbStatus($brand),
            'brand.combo_catalog' => $this->comboCatalogStatus($brand),
            'brand.product_tax_stamp' => $this->productTaxStampStatus($brand),
        ];
    }

    private function taxTypesStatus(Brand $brand): ?string
    {
        $codes = array_column(TaxTypeService::STANDARD_TYPES, 'code');

        $present = TaxType::query()
            ->where('brand_id', $brand->id)
            ->whereIn('code', $codes)
            ->pluck('code')
            ->all();

        $missing = array_values(array_diff($codes, $present));
        if ($missing !== []) {
            return count($missing).'/'.count($codes).' loại thuế chuẩn còn thiếu ('.implode(', ', $missing).')';
        }

        // Bất biến "đúng MỘT default mỗi brand" — một brand có đủ 3 loại nhưng
        // không loại nào mặc định thì tầng brand-default của TaxResolver rỗng,
        // và dòng đơn rơi xuống 0%. Thiếu default cũng là thiếu baseline.
        $defaults = TaxType::query()
            ->where('brand_id', $brand->id)
            ->where('is_default', true)
            ->count();

        return $defaults === 1 ? null : "có {$defaults} loại thuế mặc định (phải đúng 1)";
    }

    private function applyTaxTypes(Brand $brand, string $organizationId): void
    {
        $this->taxTypes->ensureStandardTypesForBrand($brand, $organizationId);
    }

    private function reverbStatus(Brand $brand): ?string
    {
        return filled($brand->reverb_app_id) ? null : 'chưa có Reverb app credentials';
    }

    private function comboCatalogStatus(Brand $brand): ?string
    {
        $exists = ProductType::query()
            ->where('brand_id', $brand->id)
            ->where('code', 'combo')
            ->exists();

        return $exists ? null : 'chưa có ProductType `combo`';
    }

    /**
     * Product chưa gắn loại thuế nào.
     *
     * Đóng dấu STANDARD lên các hàng đó là hành vi thừa kế từ `JapaneseTaxSeeder`
     * (đã gỡ ở #2320) và nó ĐÚNG cho product **chưa ai gán gì**: chiều sai là thu
     * vượt chứ không phải thu thiếu, và người vận hành sửa được.
     *
     * Cái seeder cũ làm sai là `update()` không có `whereNull` — nó dán đè cả
     * product đã được gán 軽減税率, biến 13 món mì/phở của Betoya từ 8% thành 10%
     * ở MỌI lượt seed. Lựa chọn đã có không bao giờ bị baseline ghi đè.
     */
    private function productTaxStampStatus(Brand $brand): ?string
    {
        $unstamped = $this->productTaxStamp->countMissing($brand->id);

        return $unstamped === 0 ? null : "{$unstamped} product chưa gắn loại thuế";
    }

    private function applyProductTaxStamp(Brand $brand): void
    {
        $standardId = TaxType::query()
            ->where('brand_id', $brand->id)
            ->where('code', 'STANDARD')
            ->value('id');

        if ($standardId === null) {
            return;
        }

        $this->productTaxStamp->stampMissing($brand->id, $standardId);
    }

    /**
     * Org của brand: org của một product bất kỳ (liên kết chắc chắn nhất), rồi
     * đến Organization khớp `console_organization_id`. Trùng thứ tự với
     * {@see TaxTypeService} để hai bên không bao giờ chọn ra hai org khác nhau.
     */
    private function resolveOrganizationId(Brand $brand): ?string
    {
        return Product::query()->where('brand_id', $brand->id)->value('organization_id')
            ?? Organization::query()
                ->where('console_organization_id', $brand->console_organization_id)
                ->value('id');
    }
}
