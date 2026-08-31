<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\BrandOrderPolicy;
use App\Services\Order\Contracts\BrandOrderPolicyDefaults;

/**
 * #962 — hiện thực Eloquent của {@see BrandOrderPolicyDefaults}. Đây là
 * chỗ DUY NHẤT ngoài Organization được phép đọc `brand_order_policies`.
 *
 * Một `first()` trên khoá `brand_id`, chép nguyên điều kiện WHERE từ ba chỗ nó
 * vừa rời đi — không gộp thêm, không lọc thêm.
 */
final class EloquentBrandOrderPolicyDefaults implements BrandOrderPolicyDefaults
{
    public function forBrand(?string $brandId): array
    {
        $policy = $brandId === null || $brandId === ''
            ? null
            : BrandOrderPolicy::query()->where('brand_id', $brandId)->first();

        return [
            'prep_before_payment' => $policy?->default_prep_before_payment === null
                ? null
                : (bool) $policy->default_prep_before_payment,
            'confirmation_timeout_minutes' => $policy?->default_confirmation_timeout_minutes === null
                ? null
                : (int) $policy->default_confirmation_timeout_minutes,
            'prep_minutes_per_item' => $policy?->default_prep_minutes_per_item === null
                ? null
                : (int) $policy->default_prep_minutes_per_item,
            'print_label_locale' => $policy?->default_print_label_locale === null
                ? null
                : (string) $policy->default_print_label_locale,
            'table_status_after_payment' => $policy?->default_table_status_after_payment === null
                ? null
                : (string) $policy->default_table_status_after_payment,
        ];
    }
}
