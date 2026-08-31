<?php

/**
 * OrderItemTopping Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Enums\OrderItemStatusEnum;
use App\Omnify\Modules\OrderItemTopping\Models\OrderItemToppingBaseModel;
use Database\Factories\OrderItemToppingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * OrderItemTopping — add project-specific model logic here.
 */
class OrderItemTopping extends OrderItemToppingBaseModel
{
    use HasFactory;

    /**
     * Plan-028 — add kitchen `status` to topping snapshots so KDS can track
     * per-topping preparation state (`is_blocked_by_toppings` in KdsItemResource).
     *
     * ⚠️ Override này THAY THẾ $fillable của base model — thêm cột mới vào
     * schema thì phải thêm cả ở đây, nếu không mass assignment lặng lẽ rơi
     * cột đó và DB default che mất lỗi (#2619 trả giá: waived_quantity
     * persist 0 trên mọi đường ghi trong khi pricer tính đúng).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_order_item_id',
        'topping_group_item_id',
        'product_sku_id',
        'quantity',
        'status',
        'unit_price',
        // #2619 — vết miễn free_up_to_n theo từng hàng.
        'waived_quantity',
        'note',
    ];

    /**
     * Plan-028 — cast status as the shared OrderItemStatusEnum.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => OrderItemStatusEnum::class,
        ]);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): OrderItemToppingFactory
    {
        return OrderItemToppingFactory::new();
    }
}
