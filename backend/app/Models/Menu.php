<?php

/**
 * Menu Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Menu\Models\MenuBaseModel;
use App\Traits\AuditsActivity;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Menu — add project-specific model logic here.
 *
 * @property int|null $cart_timeout_minutes
 */
class Menu extends MenuBaseModel
{
    use AuditsActivity;
    use HasFactory;
    use PreservesTranslatableColumns;

    protected $useTranslationFallback = true;

    protected $fillable = [
        'name',
        'description',
        'valid_from',
        'valid_to',
        'priority',
        'status',
        'service_type',
        'rejection_reason',
        'created_by_id',
        'approved_by_id',
        'approved_at',
        'rejected_by_id',
        'rejected_at',
        'is_master',
        'last_synced_at',
        'master_menu_id',
        'organization_id',
        'brand_id',
        'branch_id',
        'cart_timeout_minutes',
        // #1218 tier 3 — whole-menu tax type. This class replaces the generated
        // $fillable rather than extending it, so a new schema property does NOT
        // become assignable on its own: without this line `update(['tax_type_id'
        // => …])` is silently dropped by mass-assignment protection and the
        // endpoint returns 200 having changed nothing.
        'tax_type_id',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MenuFactory
    {
        return MenuFactory::new();
    }

    /**
     * Thứ tự section trong menu — phần DUY NHẤT còn phải khai tay.
     *
     * Trước đây relation này được dựng lại TỪ ĐẦU thay vì gọi `parent::`, vì
     * generator suy danh sách `withPivot` từ TÊN ASSOCIATION nên base phát
     * `withPivot('tax_type')` — một cột không tồn tại (cột thật là
     * `tax_type_id`). `withPivot` là cộng dồn, nên kế thừa sẽ giữ nguyên tên
     * hỏng và mọi truy vấn qua relation này chết ("no such column:
     * menu_menu_sections.tax_type", 68 test đỏ).
     *
     * Omnify 6.0.1 (upstream omnify-go#156) phát đúng tên cột, tức điều kiện
     * thoát mà chính ghi chú cũ đặt ra đã đạt. Nên giờ kế thừa `parent::` và
     * chỉ thêm đúng phần base không có: sắp theo `display_order`.
     */
    public function menuSections(): BelongsToMany
    {
        return parent::menuSections()->orderByPivot('display_order');
    }

    /**
     * All non-deleted schedule rows for this menu (unfiltered by is_active).
     * Used by getCurrentMenu() whereDoesntHave check — SoftDeletes global
     * scope is active, so this relation already excludes soft-deleted rows.
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(MenuSchedule::class, 'menu_id');
    }

    /**
     * Active, non-deleted schedule windows ordered for priority resolution.
     * Used by getCurrentMenu() orWhereHas check and eager-load in API responses.
     * Lower priority number = higher priority; created_at breaks ties deterministically.
     */
    public function activeSchedules(): HasMany
    {
        return $this->hasMany(MenuSchedule::class, 'menu_id')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('priority')
            ->orderBy('created_at');
    }
}
