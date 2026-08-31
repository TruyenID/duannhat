<?php

declare(strict_types=1);

/**
 * #1278 — `catalog:tax-exempt-brand` changes a whole brand's tax types with
 * query-builder writes, so no model event fires and CatalogRevisionObserver
 * never bumps.
 *
 * That matters because the catalog revision is the immutable price map offline
 * orders are verified against, and it carries tax: the snapshot's `tax` field is
 * `menu_products.tax_type_id`, the exact column the command nulls. Without a
 * bump, every workstation in the brand keeps pricing offline orders at the
 * pre-exemption rate.
 *
 * It self-healed within a day — the snapshot hash changes, so
 * catalog:rebuild-revisions mints a new one at 03:40 (#1255) — but a day of
 * offline sales at the old rate is not an acceptable gap for a command whose
 * only job is changing tax.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CatalogRevision;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\TaxType;
use Illuminate\Support\Str;

it('bumps the catalog revision so offline pricing follows the exemption', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId, 'is_active' => true]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    // The command needs an exempt (0%) tax type on the brand to switch to.
    $standard = TaxType::factory()->create([
        'brand_id' => $brand->id, 'rate' => 10, 'organization_id' => $orgId,
    ]);
    TaxType::factory()->create([
        'brand_id' => $brand->id, 'rate' => 0, 'organization_id' => $orgId,
    ]);

    // A branch with no menu produces an empty snapshot and mints nothing, so the
    // fixture needs a real catalog line — otherwise the test passes or fails for
    // a reason that has nothing to do with the bump.
    $type = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $product = Product::factory()->active()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id,
        'product_type_id' => $type->id, 'tax_type_id' => $standard->id,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true,
    ]);
    $menu = Menu::factory()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id,
        'branch_id' => $branch->id, 'status' => 'Active',
    ]);
    $line = MenuProduct::factory()->create([
        'menu_id' => $menu->id, 'product_id' => $product->id,
        'is_active' => true, 'tax_type_id' => $standard->id,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $line->id, 'product_sku_id' => $sku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    $before = CatalogRevision::query()->where('branch_id', $branch->id)->max('revision');

    $this->artisan('catalog:tax-exempt-brand', ['--brand' => $brand->slug])
        ->assertSuccessful();

    $after = CatalogRevision::query()->where('branch_id', $branch->id)->max('revision');

    // A bump, not merely "a revision exists" — the branch may have had one
    // already, and the point is that this command moves it.
    expect($after)->not->toBeNull(
        'no catalog revision exists for the branch after making its brand tax-exempt; '
        .'workstations would keep pricing offline orders at the old rate until the nightly sweep',
    );

    if ($before !== null) {
        expect((int) $after)->toBeGreaterThan(
            (int) $before,
            'the revision did not move, so the tax change never reached the offline price map',
        );
    }
});
