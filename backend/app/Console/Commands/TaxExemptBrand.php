<?php

namespace App\Console\Commands;

use App\Console\Maintenance\TaxExemptBrandPersistence;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Catalog\CatalogRevisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * issue #1042 (option A) — make a brand's catalog TAX-EXEMPT (0%) so the
 * `prices_include_tax` toggle becomes a PURE display label, WITHOUT touching any
 * price. The shop enters the final price (税込) and handles tax manually; with
 * every product at 非課税 (0/0), both toggle modes charge exactly the entered
 * price and 0 tax — ON just labels it "Đã gồm thuế" (税込) and OFF "Chưa gồm
 * thuế" (税抜). No engine change, no money change.
 *
 * What it does (idempotent — re-running is a no-op):
 *   1. Every non-deleted Product on the brand → tax_type_id = EXEMPT.
 *   2. Every MenuProduct tax_type_id override on the brand → null (inherit).
 *   3. The brand's EXEMPT type becomes is_default (so new products inherit 0%).
 *   4. Every branch's ShopOrderSetting.default_tax_type_id → EXEMPT.
 *   5. --off also flips prices_include_tax = 0 (the "Chưa gồm thuế" label) on
 *      every branch of the brand; omit it to leave each branch's label as-is.
 *
 * PRICES ARE NEVER TOUCHED — only the tax-type assignment. Reversible: re-run
 * `php artisan provisioning:reconcile --brand=<slug>` / re-assign the
 * reduced+standard types to restore rates.
 */
#[Signature('catalog:tax-exempt-brand {--brand=betoya : Brand slug} {--off : Also set prices_include_tax=false (Chưa gồm thuế label) on every branch} {--dry-run : Preview without writing}')]
#[Description('Make a brand tax-exempt (0%) so the tax toggle is a pure display label — prices untouched (issue #1042 option A)')]
class TaxExemptBrand extends Command
{
    public function handle(): int
    {
        $slug = (string) $this->option('brand');
        $dryRun = (bool) $this->option('dry-run');

        $brand = Brand::query()->where('slug', $slug)->first();
        if (! $brand) {
            $this->error("Brand '{$slug}' not found.");

            return self::FAILURE;
        }

        $exempt = TaxType::query()
            ->where('brand_id', $brand->id)
            ->where('is_active', true)
            ->where('rate', 0)
            ->first();
        if (! $exempt) {
            $this->error("Brand '{$slug}' has no active 非課税 (0/0) tax type. Seed one first: php artisan provisioning:reconcile --brand={$slug}.");

            return self::FAILURE;
        }

        // Brand-safe branch scope: the ShopOrderSettings whose default_tax_type_id
        // is one of THIS brand's tax types (never touches another brand's branches,
        // even in the same organization). tax_types are brand-scoped.
        $brandTaxTypeIds = TaxType::query()->where('brand_id', $brand->id)->pluck('id');
        $settingIds = ShopOrderSetting::query()
            ->whereIn('default_tax_type_id', $brandTaxTypeIds)
            ->pluck('id');

        $productCount = DB::table('products')->where('brand_id', $brand->id)->whereNull('deleted_at')->count();
        $menuOverrides = DB::table('menu_products')
            ->whereIn('product_id', DB::table('products')->where('brand_id', $brand->id)->pluck('id'))
            ->whereNotNull('tax_type_id')->count();

        $this->info("Brand: {$brand->slug} | EXEMPT type: {$exempt->code} ({$exempt->id})");
        $this->line("  products → EXEMPT: {$productCount}");
        $this->line("  menu-product tax overrides → null: {$menuOverrides}");
        $this->line('  brand default + '.$settingIds->count().' branch defaults → EXEMPT');
        if ($this->option('off')) {
            $this->line('  --off: prices_include_tax → false ("Chưa gồm thuế") on '.$settingIds->count().' branches');
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing written.');

            return self::SUCCESS;
        }

        (new TaxExemptBrandPersistence)->apply($brand, $exempt, $settingIds, (bool) $this->option('off'));

        // #1278 — apply() writes with the query builder, so no model event fires
        // and CatalogRevisionObserver never bumps. The catalog revision is the
        // immutable price map offline orders are verified against, and it CARRIES
        // TAX (`tax` => menu_products.tax_type_id, the column apply() just
        // nulled). Without this, every workstation in the brand keeps pricing
        // offline orders at the pre-exemption rate.
        //
        // It self-heals — the snapshot hash changes, so catalog:rebuild-revisions
        // mints a new one at 03:40 (#1255) — but a whole day of offline sales at
        // the old rate is not an acceptable gap for a command whose only job is
        // changing tax.
        $revisions = app(CatalogRevisionService::class);
        $bumped = 0;
        foreach (Branch::query()->where('console_brand_id', $brand->console_brand_id)->pluck('id') as $branchId) {
            if ($revisions->bumpFor((string) $branchId) !== null) {
                $bumped++;
            }
        }

        $this->newLine();
        $this->info('Done — the brand is now tax-exempt (0%). The toggle is a pure label; prices unchanged.');
        $this->line("Catalog revisions bumped: {$bumped} branch(es) — offline pricing follows immediately.");

        return self::SUCCESS;
    }
}
