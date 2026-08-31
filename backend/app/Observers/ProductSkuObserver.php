<?php

namespace App\Observers;

use App\Models\ProductSku;
use App\Services\Product\MenuService;
use App\Services\Product\ValueObjects\CatalogSkuProjection;

/**
 * Observer for the ProductSku model.
 */
class ProductSkuObserver
{
    public function __construct(
        private readonly MenuService $menus,
    ) {}

    public function saving(ProductSku $sku): void
    {
        $sku->option_signature = ProductSku::computeOptionSignature(
            $sku->option_value1_id,
            $sku->option_value2_id,
            $sku->option_value3_id,
        );
    }

    /**
     * A newly created SKU joins the branch menus that already sell its product.
     *
     * The option-expand paths already called
     * {@see MenuService::syncNewSkusToMenuBranches()} explicitly, but plain
     * variant creation (`ProductSkuService::create()`) never did, so a SKU added
     * to a product that shops were already selling never reached their menus.
     * Menus carrying a `master_menu_id` only *looked* right: clone/sync reads
     * `product->skus` at clone time, so a menu built after the SKU picked it up
     * by accident, while `syncFromMaster()` only fills SKUs when the branch row
     * has none at all. A shop-owned menu has no master to sync from and so had
     * no repair path whatsoever — production 本郷店 sold a product with one of
     * its two variants invisible for three weeks (#2537).
     *
     * Hooking the model event covers every creation path at once, and repeats
     * are free: the sync skips a pair that already has a row, so the explicit
     * calls the expand paths still make are no-ops by the time they run.
     */
    public function created(ProductSku $sku): void
    {
        $this->menus->syncNewSkusToMenuBranches($sku->product_id, [
            new CatalogSkuProjection($sku->id, (string) ($sku->selling_price ?? 0)),
        ]);
    }

    public function updated(ProductSku $sku): void
    {
        if ($sku->wasChanged('selling_price')) {
            $this->menus->propagateNonOverriddenMenuPrice($sku);
        }
    }
}
