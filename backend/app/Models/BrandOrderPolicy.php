<?php

/**
 * BrandOrderPolicy Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\BrandOrderPolicy\Models\BrandOrderPolicyBaseModel;
use Database\Factories\BrandOrderPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * BrandOrderPolicy — add project-specific model logic here.
 */
class BrandOrderPolicy extends BrandOrderPolicyBaseModel
{
    use HasFactory;

    /**
     * plan-037 — add brand-level default for confirmation timeout. Re-list
     * the omnify fields so mass-assign survives regen.
     */
    protected $fillable = [
        'default_prep_before_payment',
        'default_confirmation_timeout_minutes',
        // #1160 — brand default prep minutes per item; a shop may override it
        // (shop_order_settings.prep_minutes_per_item), NULL there = inherit.
        'default_prep_minutes_per_item',
        // #491 — HQ default table status after payment (free|cleaning).
        'default_table_status_after_payment',
        // #890 — HQ toggle: shop may edit HQ-origin tables.
        'allow_shop_edit_hq_tables',
        // #2879 — ngưỡng lệch tiền mặt trước khi đối soát 3 chân kêu. Thiếu
        // dòng này thì mass-assign nuốt giá trị IM LẶNG: brand đặt ngưỡng qua
        // form, DB vẫn giữ mặc định, và không gì đỏ.
        'cash_variance_tolerance_minor',
        'brand_id',
        'organization_id',
    ];

    protected static function newFactory(): BrandOrderPolicyFactory
    {
        return BrandOrderPolicyFactory::new();
    }

    /*
     * plan-035 — đổi chính sách ở cấp brand thì phải xoá cache của MỌI chi nhánh
     * thuộc brand đó.
     *
     * #962 — hai hook `saved`/`deleted` từng nằm ngay đây và gọi thẳng
     * `EffectiveOrderPolicyService::forgetForBrand()`, tức MODEL phụ thuộc SERVICE
     * của module khác — ngược chiều, và là thứ deptrac bắt được.
     *
     * Chúng đã chuyển sang `OrderServiceProvider::boot()`: bên SỞ HỮU cache tự đăng
     * ký lắng nghe model, thay vì model phải biết ai đang cache mình. Hành vi không
     * đổi — vẫn `saved` + `deleted`, vẫn bỏ qua khi `brand_id` rỗng.
     */
}
