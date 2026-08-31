<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'console_brand_id',
        'console_organization_id',
        'name',
        'slug',
        'description',
        'logo_url',
        'customer_header_logo_url',
        'customer_order_logo_url',
        // #2047 — id File thay cho URL tuyệt đối; hai cột `*_logo_url` ở trên
        // là đường cũ, giữ lại tới khi drop ở một PR riêng.
        'customer_header_logo_file_id',
        'customer_order_logo_file_id',
        'customer_order_subtitle',
        // #1772 — ảnh nền thẻ thành viên theo hạng, {tier_key: file_id}.
        'customer_tier_card_backgrounds',
        // #1152 — インボイス T+13 (brand default; branch may override).
        'invoice_registration_number',
        'is_active',
        'primary_color',
        'secondary_color',
        'accent_color',
        'text_color',
        'reverb_app_id',
        'reverb_app_key',
        'reverb_app_secret',
        'reverb_allowed_origins',
        'reverb_provisioned_at',
        'cart_timeout_minutes',
        'takeaway_payment_timeout_minutes',
        // #1674 — cặp tỉ lệ tích điểm ("<amount> tiền = <points> điểm").
        'point_earn_amount',
        'point_earn_points',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reverb_app_secret' => 'encrypted',
            'reverb_allowed_origins' => 'array',
            'reverb_provisioned_at' => 'datetime',
            // #1772 — cột json. Model editable này KHÔNG kế thừa casts của base
            // (nó ghi đè cả `$fillable` lẫn `casts()`), nên thiếu dòng này thì
            // map ảnh nền đọc ra là chuỗi JSON thô và ghi vào là chuỗi "Array".
            'customer_tier_card_backgrounds' => 'array',
            'takeaway_payment_timeout_minutes' => 'integer',
            'point_earn_amount' => 'decimal:2',
            'point_earn_points' => 'integer',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'console_brand_id', 'console_brand_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'brand_id');
    }

    /*
     * #962 — deliberately NO inverse relations to Menu, Material, Recipe or
     * BrandOrderPolicy here. Brand is the tenancy anchor: every domain declares
     * `brand_id` and points AT it, which is why the deptrac layer exists at all.
     * Declaring the hasMany back down turned that one-way anchor into
     * TenancyKernel → Catalog / Inventory / Organization edges, and none of the
     * four had a single caller (`$brand->menus`, `->materials`, `->recipes`,
     * `->orderPolicy` — zero hits across app/, tests/, database/, routes/).
     * The foreign keys are untouched; read them from the owning side
     * (`Menu::brand()`, `EffectiveOrderPolicyService`, …).
     *
     * `products()` and `categories()` stay: BrandScopedApiTest asserts on both.
     */
}
