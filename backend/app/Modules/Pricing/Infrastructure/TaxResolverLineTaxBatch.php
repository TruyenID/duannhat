<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure;

use App\Services\Customer\TaxResolution;
use App\Services\Customer\TaxResolver;
use App\Services\Order\Contracts\OrderLineTaxBatch;

/**
 * #962 · 7a-7 — một lô giải thuế, tức là **một** `TaxResolver` với memo riêng.
 *
 * Xem {@see TaxResolverLineTaxPricing} về chỗ đặt file, và
 * {@see OrderLineTaxBatch} về cạm bẫy tiền.
 *
 * CHUYỂN TIẾP, không tính lại: `resolveForLine` gọi thẳng
 * `TaxResolver::resolveForLineByIds()`, vốn đi qua đúng chuỗi tầng mà lối gọi
 * bằng model dùng. Không được cộng, làm tròn, hay chọn tầng ở lớp này.
 */
final class TaxResolverLineTaxBatch implements OrderLineTaxBatch
{
    private readonly TaxResolver $resolver;

    public function __construct(?TaxResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new TaxResolver;
    }

    public function resolveForLine(
        string $productId,
        ?string $productTaxTypeId,
        ?string $menuLineTaxTypeId,
        string $branchId,
        string $brandId,
        ?string $menuId = null,
        ?string $menuSectionId = null,
    ): TaxResolution {
        return $this->resolver->resolveForLineByIds(
            $productId,
            $productTaxTypeId,
            $menuLineTaxTypeId,
            $branchId,
            $brandId,
            $menuId,
            $menuSectionId,
        );
    }
}
