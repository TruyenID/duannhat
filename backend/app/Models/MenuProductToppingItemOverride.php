<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shop-level topping extra_price / visibility override.
 * Scoped to a single MenuProduct (branch menu entry) so each branch
 * can customize independently without affecting HQ or other branches.
 *
 * Resolution priority (highest to lowest):
 *   1. menu_product_topping_item_overrides  (this model — shop level)
 *   2. product_topping_group_item_overrides (HQ per-product level)
 *   3. topping_group_item_skus.extra_price  (HQ base)
 */
class MenuProductToppingItemOverride extends Model
{
    use HasUuids;

    protected $table = 'menu_product_topping_item_overrides';

    protected $fillable = [
        'menu_product_id',
        'topping_group_id',
        'topping_group_item_id',
        'product_sku_id',
        'is_hidden',
        'override_price',
    ];

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'override_price' => 'decimal:2',
        ];
    }

    public function menuProduct(): BelongsTo
    {
        return $this->belongsTo(MenuProduct::class, 'menu_product_id');
    }

    public function toppingGroup(): BelongsTo
    {
        return $this->belongsTo(ToppingGroup::class, 'topping_group_id');
    }

    public function toppingGroupItem(): BelongsTo
    {
        return $this->belongsTo(ToppingGroupItem::class, 'topping_group_item_id');
    }

    public function productSku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'product_sku_id');
    }
}
