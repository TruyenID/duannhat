<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure;

use App\Services\Customer\TaxResolver;
use App\Services\Tax\Contracts\MenuDisplayTaxRateBatch;

/**
 * #1596 — một lô hiển thị, tức là **một** `TaxResolver` với memo riêng.
 *
 * Xem {@see TaxResolverMenuDisplayRates} về chỗ đặt file, và
 * {@see MenuDisplayTaxRateBatch} về cạm bẫy tiền.
 *
 * CHUYỂN TIẾP, không tính lại: `rateForMenuLine` gọi thẳng
 * `TaxResolver::resolveRateForDisplayByIds()`, vốn đi qua đúng chuỗi tầng mà lối
 * gọi bằng model dùng. Không được cộng, làm tròn, hay chọn tầng ở lớp này.
 */
final class TaxResolverMenuDisplayRateBatch implements MenuDisplayTaxRateBatch
{
    private readonly TaxResolver $resolver;

    public function __construct(?TaxResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new TaxResolver;
    }

    public function rateForMenuLine(
        ?string $menuLineTaxTypeId,
        ?string $productTaxTypeId,
        string $branchId,
        string $brandId,
        ?string $menuId = null,
        ?string $menuSectionId = null,
    ): ?float {
        return $this->resolver->resolveRateForDisplayByIds(
            $menuLineTaxTypeId,
            $productTaxTypeId,
            $branchId,
            $brandId,
            $menuId,
            $menuSectionId,
        );
    }
}
