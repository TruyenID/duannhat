<?php

namespace App\Observers;

use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuProductToppingItemOverride;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Catalog\CatalogRevisionService;

/**
 * Keeps the per-branch catalog revision current (#1092/#1095).
 *
 * Hooked as a MODEL observer rather than wired into MenuService's ~10 mutation
 * methods on purpose: the revision must reflect every writer, including
 * seeders, console commands, future endpoints, and direct model writes. Any of
 * those silently skipping the bump would let an offline device sell from a
 * catalog Cloud has no record of — and the verifier (#1096) would then reject
 * a perfectly honest order.
 *
 * Over-triggering is harmless: the service only mints a revision when the
 * price map actually changed (BR-CR02), and marks are flushed once per
 * transaction on COMMIT.
 */
class CatalogRevisionObserver
{
    public function __construct(private readonly CatalogRevisionService $revisions) {}

    public function saved(object $model): void
    {
        $this->mark($model);
    }

    public function deleted(object $model): void
    {
        $this->mark($model);
    }

    public function restored(object $model): void
    {
        $this->mark($model);
    }

    private function mark(object $model): void
    {
        match (true) {
            $model instanceof Menu => $this->revisions->markDirty($model->branch_id),
            $model instanceof MenuProduct => $this->revisions->markDirty(
                Menu::query()->whereKey($model->menu_id)->value('branch_id'),
            ),
            $model instanceof MenuProductSku => $this->revisions->markDirty(
                Menu::query()
                    ->whereIn('id', MenuProduct::query()->whereKey($model->menu_product_id)->select('menu_id'))
                    ->value('branch_id'),
            ),
            // A SKU price edit changes the effective menu price on every branch
            // whose menu row inherits it (is_price_overridden = false).
            $model instanceof ProductSku => $this->revisions->markProductDirty($model->product_id),

            // #1114 — topping config is priced money too. Each of these changes
            // what an offline device would charge, so each versions the catalog.
            $model instanceof ToppingGroup => $this->revisions->markToppingGroupDirty($model->id),
            $model instanceof ProductToppingGroup => $this->revisions->markProductDirty($model->product_id),
            $model instanceof ToppingGroupItem => $this->revisions->markToppingGroupDirty($model->topping_group_id),
            $model instanceof ToppingGroupItemSku => $this->revisions->markToppingGroupDirty(
                ToppingGroupItem::query()->whereKey($model->topping_group_item_id)->value('topping_group_id'),
            ),
            $model instanceof ProductToppingGroupItemOverride => $this->revisions->markProductDirty($model->product_id),

            // #1192 — the SHOP tier. This one was missing: a branch re-pricing
            // a topping on its own menu line changed what its POS charges
            // offline while leaving the catalog revision untouched, so the
            // snapshot kept quoting the HQ price and the verifier rejected the
            // branch's honest offline sales.
            $model instanceof MenuProductToppingItemOverride => $this->revisions->markDirty(
                Menu::query()
                    ->whereIn('id', MenuProduct::query()->whereKey($model->menu_product_id)->select('menu_id'))
                    ->value('branch_id'),
            ),

            // #1661 — ba tầng thuế cuối. Chúng KHÔNG phải bổ sung cho đủ bộ:
            // feed menu gộp bốn tầng vào một cột `menu_items.tax_type_id` và
            // chở thêm `effective_tax_rate` đi qua cả sáu, còn phiên bản của
            // feed đó CHÍNH LÀ catalog revision. Tầng nào không đánh dấu thì
            // workstation nhận 304 và in theo thuế suất cũ.
            //
            // Ba tầng còn lại KHÔNG có ở đây, và không cái nào là quên:
            //
            //  · tầng 2 (`menu_menu_sections`) — bảng khoá kép, không ghi qua
            //    model được (#1657) ⇒ không sự kiện nào bắn ra. Đánh dấu ở
            //    `App\Services\Catalog\MenuSectionPivotWriter`, chỗ ghi duy nhất.
            //  · tầng 5 (`shop_order_settings`) là bảng của Ordering và
            //  · tầng 6 + thuế suất (`tax_types`) là bảng của Pricing ⇒ Catalog
            //    không được đọc chúng. Mỗi module tự observe model của mình
            //    (`ShopOrderSettingObserver`, `TaxTypeObserver`) rồi báo qua cổng
            //    công bố `Catalog\Contracts\CatalogRevisionMarker`.
            $model instanceof Product => $this->revisions->markProductDirty($model->id),

            default => null,
        };
    }
}
