<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Order\Contracts\BrandOrderPolicyDefaults;
use App\Services\Order\Contracts\OrderPrepTimeDefaults;
use Illuminate\Support\Facades\Cache;

/**
 * plan-035 — resolves the effective order policy for a branch by merging
 * the brand-level defaults (BrandOrderPolicy) with the per-branch overrides
 * (ShopOrderSetting). A nullable column on ShopOrderSetting means
 * "inherit from brand"; an explicit bool wins.
 *
 * The resolved array is shaped so customer-web's brand context can drop it
 * straight into a JSON response — no further FE massaging needed.
 *
 * Cached per branch with a 5-minute TTL so the customer-web menu page
 * (which calls /api/v1/customer/branches/{slug} on every refresh) doesn't
 * pound the DB. Cache is invalidated explicitly when admin saves the brand
 * policy or the shop setting — registered in those models' booted hooks.
 */
class EffectiveOrderPolicyService
{
    public const CACHE_TTL_SECONDS = 300;

    /**
     * #1160 — fallback prep minutes per item when neither the shop nor the
     * brand picked a value. Kept deliberately small: the ETA is now a pure
     * `perItem x quantity` product, so a large default would promise absurd
     * waits for a big basket.
     *
     * #962 (7b) — the number itself moved to the published contract
     * `App\Services\Order\Contracts\OrderPrepTimeDefaults` so Organization can
     * read it without importing this service. This alias stays because it is
     * the name every Ordering caller (and its tests) already uses; it is an
     * alias, never a second value.
     */
    public const DEFAULT_PREP_MINUTES_PER_ITEM = OrderPrepTimeDefaults::MINUTES_PER_ITEM;

    /**
     * @return array{
     *     prep_before_payment: bool,
     *     customer_email_required: bool,
     *     phone_country: string,
     *     confirmation_timeout_minutes: int,
     *     payment_timeout_minutes: int,
     *     prep_minutes_per_item: int,
     *     source: array{prep_before_payment: string, customer_email_required: string, confirmation_timeout_minutes: string, prep_minutes_per_item: string}
     * }
     */
    public function resolve(Branch $branch): array
    {
        return Cache::remember(
            self::cacheKey($branch->id),
            self::CACHE_TTL_SECONDS,
            fn () => $this->resolveUncached($branch),
        );
    }

    public static function forgetForBranch(string $branchId): void
    {
        Cache::forget(self::cacheKey($branchId));
    }

    /**
     * Ngôn ngữ NHÃN IN đã resolve: shop override → brand default → locale của chi
     * nhánh → 'ja'.
     *
     * Ở đây chứ không ở nơi gọi (#1446). `PosInvoiceService` từng tự tra
     * `Brand` + `BrandOrderPolicy` để lấy `default_print_label_locale` — tức
     * module Ordering với thẳng vào ruột module Organization, thêm 2 cạnh xuyên
     * module và làm bánh cóc ranh giới (#962) kêu.
     *
     * Chuỗi shop→brand→branch vốn đã là việc của lớp này (nó merge
     * `BrandOrderPolicy` với `ShopOrderSetting` cho mọi khoá khác), nên chỗ đúng
     * là ở đây — người gọi chỉ hỏi một câu và nhận một câu trả lời.
     *
     * KHÔNG cache: khác `resolve()`, hàm này nhận sẵn `$setting` của người gọi
     * nên không có truy vấn nào để tiết kiệm ngoài một `value()` trên brand, và
     * cache theo branch sẽ sai khi người gọi truyền một setting khác.
     */
    public function printLabelLocale(?ShopOrderSetting $setting, ?Branch $branch): string
    {
        $brandDefault = null;
        if ($branch) {
            $brandId = Brand::where('console_brand_id', $branch->console_brand_id)->value('id');
            if ($brandId) {
                $brandDefault = app(BrandOrderPolicyDefaults::class)
                    ->forBrand((string) $brandId)['print_label_locale'];
            }
        }

        foreach ([$setting?->print_label_locale, $brandDefault, $branch?->locale] as $candidate) {
            $locale = is_string($candidate) ? strtolower(trim($candidate)) : '';
            if (in_array($locale, ['ja', 'en', 'vi'], true)) {
                return $locale;
            }
        }

        return 'ja';
    }

    /**
     * #491 — resolve the effective table status a paid table returns to:
     * shop override (`shop_order_settings.table_status_after_payment`) ??
     * HQ default (`brand_order_policies.default_table_status_after_payment`) ??
     * `free`. NULL on the shop row means "inherit HQ". Only `free`/`cleaning`
     * are valid; anything unexpected falls back to `free` (historical
     * behaviour). Single source of truth for every post-payment table
     * release point (OrderClosingService close, Stripe full + split-final).
     */
    public static function tableStatusAfterPayment(?string $branchId, ?string $brandId): string
    {
        $shop = $branchId
            ? ShopOrderSetting::where('branch_id', $branchId)
                ->value('table_status_after_payment')
            : null;

        $brand = $brandId
            ? app(BrandOrderPolicyDefaults::class)->forBrand($brandId)['table_status_after_payment']
            : null;

        $resolved = $shop ?? $brand ?? TableStatusEnum::Free->value;

        return in_array($resolved, [
            TableStatusEnum::Free->value,
            TableStatusEnum::Cleaning->value,
        ], true) ? $resolved : TableStatusEnum::Free->value;
    }

    public static function forgetForBrand(string $brandId): void
    {
        // Invalidate every branch belonging to the brand. The mapping is via
        // `branches.console_brand_id = brands.console_brand_id`, NOT
        // brand_id. We materialise the affected branch ids here so the
        // cache forget calls stay one-by-one (no wildcard delete needed).
        Branch::query()
            ->whereHas('brand', fn ($q) => $q->where('brands.id', $brandId))
            ->pluck('id')
            ->each(fn ($id) => Cache::forget(self::cacheKey($id)));
    }

    /**
     * @return array{
     *     prep_before_payment: bool,
     *     customer_email_required: bool,
     *     phone_country: string,
     *     confirmation_timeout_minutes: int,
     *     payment_timeout_minutes: int,
     *     prep_minutes_per_item: int,
     *     source: array{prep_before_payment: string, customer_email_required: string, confirmation_timeout_minutes: string, prep_minutes_per_item: string}
     * }
     */
    private function resolveUncached(Branch $branch): array
    {
        $shopSetting = ShopOrderSetting::query()
            ->where('branch_id', $branch->id)
            ->first();

        $brandPolicy = app(BrandOrderPolicyDefaults::class)
            ->forBrand($branch->brand?->id);

        $prepShop = $shopSetting?->prep_before_payment;
        // Default to `true` (pay-first) when neither shop nor brand picked
        // a value — matches the historical takeaway flow where staff
        // confirms the order only after seeing payment at the counter.
        $prepBrand = $brandPolicy['prep_before_payment'] ?? true;

        $emailShop = $shopSetting?->customer_email_required;

        // plan-037 — confirmation step timeout. Shop override wins, else
        // brand default, else hardcoded 3 (matches column default).
        $confTimeoutShop = $shopSetting?->confirmation_timeout_minutes;
        $confTimeoutBrand = $brandPolicy['confirmation_timeout_minutes'] ?? 3;

        // plan-031 — payment countdown timeout (counter-pay takeaway).
        // Brand-level only (branches.takeaway_payment_timeout_minutes). FE
        // uses it together with confirmation timeout + prep_minutes to
        // floor the earliest schedulable pickup slot.
        $paymentTimeout = $branch->takeaway_payment_timeout_minutes
            ?? ($branch->brand?->takeaway_payment_timeout_minutes ?? 15);

        // #1160 — prep minutes per item. Shop override wins, else brand
        // default, else the constant. Both columns are nullable, so a brand
        // that never touched the setting falls through to the constant
        // instead of a 0 that would promise instant food.
        $prepMinutesShop = $shopSetting?->prep_minutes_per_item;
        $prepMinutesBrand = $brandPolicy['prep_minutes_per_item'];

        return [
            'prep_before_payment' => $prepShop ?? $prepBrand,
            'customer_email_required' => $emailShop ?? false,
            'phone_country' => self::deriveCountry($branch->locale),
            'confirmation_timeout_minutes' => (int) ($confTimeoutShop ?? $confTimeoutBrand),
            'payment_timeout_minutes' => (int) $paymentTimeout,
            'prep_minutes_per_item' => (int) ($prepMinutesShop
                ?? $prepMinutesBrand
                ?? self::DEFAULT_PREP_MINUTES_PER_ITEM),
            'source' => [
                'prep_before_payment' => $prepShop !== null ? 'shop' : 'brand',
                'customer_email_required' => $emailShop !== null ? 'shop' : 'default',
                'confirmation_timeout_minutes' => $confTimeoutShop !== null ? 'shop' : 'brand',
                'prep_minutes_per_item' => match (true) {
                    $prepMinutesShop !== null => 'shop',
                    $prepMinutesBrand !== null => 'brand',
                    default => 'default',
                },
            ],
        ];
    }

    /**
     * plan-035 — derive ISO 3166-1 alpha-2 country code from branches.locale.
     *
     *   `vi-VN` → `VN`, `ja-JP` → `JP`, `en-GB` → `GB`.
     *
     * Bare 2-letter locales without region are mapped via a small fallback
     * table so common cases (`vi` → VN) Just Work without admin touching
     * every existing branch. Otherwise default to `US` — safer for
     * libphonenumber-js to reject unknown numbers than crash on parse.
     */
    public static function deriveCountry(?string $locale): string
    {
        if (! $locale) {
            return 'US';
        }

        $parts = explode('-', $locale);
        if (count($parts) >= 2 && strlen($parts[1]) === 2) {
            return strtoupper($parts[1]);
        }

        return match (strtolower($parts[0])) {
            'vi' => 'VN',
            'ja' => 'JP',
            'en' => 'US',
            'zh' => 'CN',
            'ko' => 'KR',
            'th' => 'TH',
            'id' => 'ID',
            default => 'US',
        };
    }

    private static function cacheKey(string $branchId): string
    {
        return "branch:{$branchId}:effective_order_policy";
    }
}
