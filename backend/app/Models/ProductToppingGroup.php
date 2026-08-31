<?php

/**
 * ProductToppingGroup Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ProductToppingGroup\Models\ProductToppingGroupBaseModel;
use Database\Factories\ProductToppingGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ProductToppingGroup — add project-specific model logic here.
 */
class ProductToppingGroup extends ProductToppingGroupBaseModel
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'product_id',
        'topping_group_id',
        'sort_order',
        // Per-attachment overrides — NULL inherits group default. Phase 2
        // customer-web computes effective min/max as
        //   COALESCE(attachment.min_select_override, group.min_select)
        //   COALESCE(attachment.max_select_override, group.max_select)
        'min_select_override',
        'max_select_override',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'min_select_override' => 'integer',
        'max_select_override' => 'integer',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ProductToppingGroupFactory
    {
        return ProductToppingGroupFactory::new();
    }
}
