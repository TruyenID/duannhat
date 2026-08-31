<?php

declare(strict_types=1);

namespace App\Services\Tax\Internal;

use App\Models\TaxType;
use App\Services\Tax\Contracts\TaxTypeDirectory;
use App\Services\Tax\Contracts\TaxTypeIdentity;

/**
 * #962 — bản cài Eloquent của {@see TaxTypeDirectory}. Thuộc Pricing (module sở
 * hữu `TaxType`), nên nó ĐƯỢC PHÉP chạm model; cái không được chạm model là
 * hợp đồng, và hợp đồng nằm ở `App\Services\Tax\Contracts`.
 *
 * Bốn truy vấn dưới đây được chép NGUYÊN HÌNH DẠNG từ chỗ chúng vừa rời đi
 * (`MenuService::assignableTaxTypeId`, `EloquentProductPersistence`,
 * `ProductImporter::beforeImport`/`getSampleRows`) — kể cả `when($brandId)` và
 * thứ tự `orderBy('created_at')`. Ranh giới không phải chỗ để sửa hành vi.
 */
final class EloquentTaxTypeDirectory implements TaxTypeDirectory
{
    public function findAssignable(string $taxTypeId, ?string $brandId = null): ?TaxTypeIdentity
    {
        $type = TaxType::query()
            ->where('id', $taxTypeId)
            ->where('is_active', true)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->first(['id', 'code']);

        return $type === null ? null : $this->toIdentity($type);
    }

    public function belongsToBrand(string $taxTypeId, string $organizationId, string $brandId): bool
    {
        return TaxType::query()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->whereKey($taxTypeId)
            ->exists();
    }

    public function activeByCodeForBrand(string $organizationId, string $brandId): array
    {
        return TaxType::query()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('is_active', true)
            ->get(['id', 'code'])
            ->mapWithKeys(fn (TaxType $type) => [
                strtoupper(trim((string) $type->code)) => $this->toIdentity($type),
            ])
            ->all();
    }

    public function firstActiveCodeForBrand(string $organizationId, ?string $brandId = null): ?string
    {
        $code = TaxType::query()
            ->where('organization_id', $organizationId)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->where('is_active', true)
            ->orderBy('created_at')
            ->value('code');

        return $code === null ? null : (string) $code;
    }

    private function toIdentity(TaxType $type): TaxTypeIdentity
    {
        return new TaxTypeIdentity((string) $type->getKey(), (string) $type->code);
    }
}
