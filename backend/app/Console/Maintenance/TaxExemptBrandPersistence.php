<?php

declare(strict_types=1);

namespace App\Console\Maintenance;

use App\Models\Brand;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Command-local persistence boundary for issue #1042's tax-exempt brand switch.
 *
 * The bulk product/menu_product rewrite is a one-shot catalog migration, not a
 * runtime path, so it is kept here instead of on ProductService/MenuService —
 * routing thousands of rows through per-model services would be orders of
 * magnitude slower and would fire product-changed side effects for a change
 * that deliberately touches only the tax-type assignment.
 *
 * This class is deliberately not container-bound. Runtime services must never
 * reuse maintenance-only write access (plan-047 T4.14).
 */
final class TaxExemptBrandPersistence
{
    /**
     * Point every product, menu override, and branch default of the brand at
     * the exempt (0/0) tax type. Idempotent — re-running writes the same state.
     *
     * @param  Collection<int, string>  $settingIds  ShopOrderSetting ids already scoped to this brand
     * @param  bool  $disableInclusiveLabel  also flip prices_include_tax=false on those branches
     */
    public function apply(Brand $brand, TaxType $exempt, Collection $settingIds, bool $disableInclusiveLabel): void
    {
        DB::transaction(function () use ($brand, $exempt, $settingIds, $disableInclusiveLabel): void {
            DB::table('products')
                ->where('brand_id', $brand->id)
                ->whereNull('deleted_at')
                ->update(['tax_type_id' => $exempt->id]);

            DB::table('menu_products')
                ->whereIn('product_id', DB::table('products')->where('brand_id', $brand->id)->pluck('id'))
                ->whereNotNull('tax_type_id')
                ->update(['tax_type_id' => null]);

            DB::table('tax_types')->where('brand_id', $brand->id)->update(['is_default' => false]);
            DB::table('tax_types')->where('id', $exempt->id)->update(['is_default' => true]);

            $settingUpdate = ['default_tax_type_id' => $exempt->id];
            if ($disableInclusiveLabel) {
                $settingUpdate['prices_include_tax'] = false;
            }

            ShopOrderSetting::query()->whereIn('id', $settingIds)->update($settingUpdate);
        });
    }
}
