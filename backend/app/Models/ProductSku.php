<?php

/**
 * ProductSku Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Enums\ProductStatusEnum;
use App\Omnify\Modules\ProductSku\Models\ProductSkuBaseModel;
use App\Omnify\Traits\HasFiles;
use App\Services\Product\ProductService;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\ProductSkuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * ProductSku — add project-specific model logic here.
 */
class ProductSku extends ProductSkuBaseModel
{
    use HasFactory;
    use HasFiles;

    // name became translatable (product_sku_translations) — keep the base
    // product_skus.name column in sync so queries/feeds that read it directly
    // (menu search, workstation catalog) still work, exactly like Product.
    use PreservesTranslatableColumns;

    protected $useTranslationFallback = true;

    protected static function newFactory(): ProductSkuFactory
    {
        return ProductSkuFactory::new();
    }

    /**
     * All files in the `gallery` collection for this SKU, ordered by sort_order.
     */
    public function gallery(): MorphMany
    {
        return $this->files()
            ->where('collection', 'gallery')
            ->orderBy('sort_order');
    }

    /**
     * The main gallery image (sort_order = 0) — mirrors
     * {@see Product::galleryFirst()} but scoped to the SKU so variant
     * pickers can show a per-variant thumbnail without loading the
     * full gallery per SKU.
     */
    public function galleryFirst(): MorphOne
    {
        return $this->morphOne(File::class, 'fileable')
            ->ofMany(['sort_order' => 'min'], fn ($q) => $q->where('collection', 'gallery'));
    }

    /**
     * Compute the order-independent option signature from the 3 FK columns.
     *
     * NULLs are excluded, remaining IDs are sorted alphabetically, then
     * joined with "|".  Sorting makes the signature stable across option
     * position reorders so that swapping display order never recreates SKUs.
     *
     * Examples:
     *   (A, B, null) → "A|B"  (or "B|A" if B < A)
     *   (null, null, null) → ""   (default / no-option SKU)
     */
    public static function computeOptionSignature(
        ?string $val1Id,
        ?string $val2Id,
        ?string $val3Id,
    ): string {
        $ids = array_values(array_filter([$val1Id, $val2Id, $val3Id]));
        sort($ids);

        return implode('|', $ids);
    }

    /**
     * Whether this SKU may be added to a customer order (#902).
     *
     * A SKU is sellable only when it is itself active AND its parent product
     * is in the Active lifecycle state. draft / pending / approved (approved
     * but never activated) / inactive (paused) / rejected products must never
     * be ordered — this is the canonical "sellable" definition shared with
     * {@see ProductService::lookup()} and every
     * menu-authorization query.
     *
     * Relies on the `product` relation being loaded by the caller; a null
     * product is treated as NOT sellable (defensive).
     */
    public function isSellable(): bool
    {
        if (! (bool) $this->is_active) {
            return false;
        }

        return $this->product?->status === ProductStatusEnum::Active;
    }

    /**
     * Human-readable variant label composed from the SKU's option values —
     * e.g. "Ít / Nongs" — the SAME convention the HQ product screen and the
     * order line-item renderer use (option_value1/2/3 labels joined). The
     * `product_skus.name` column is usually null for option-based variants, so
     * relying on it makes menus show a raw SKU code (or "Default"); this
     * accessor is the single source of truth for the variant name.
     *
     * Falls back to the SKU's own name, then null when the SKU carries no
     * options at all (a simple, non-variant SKU).
     *
     * Requires optionValue1/2/3 to be loaded by the caller.
     */
    public function getVariantLabelAttribute(): ?string
    {
        $label = collect([
            $this->optionValue1?->label,
            $this->optionValue2?->label,
            $this->optionValue3?->label,
        ])->filter(fn ($v) => is_string($v) && $v !== '')->implode(' / ');

        return $label !== '' ? $label : ($this->name ?: null);
    }
}
