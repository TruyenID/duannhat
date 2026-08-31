<?php

/**
 * PointReward Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PointReward\Models\PointRewardBaseModel;
use Database\Factories\PointRewardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * PointReward — add project-specific model logic here.
 */
class PointReward extends PointRewardBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PointRewardFactory
    {
        return PointRewardFactory::new();
    }

    /**
     * Các chi nhánh ĐÃ CÓ Ý KIẾN về phần thưởng này (#1514).
     *
     * Chi nhánh không có dòng pivot nghĩa là **còn bật** (BR-PRB01). Nên đừng
     * đọc quan hệ này rồi coi nó là "danh sách chi nhánh đang phục vụ": nó là
     * danh sách chi nhánh đã từng bấm công tắc, và thường là rỗng.
     *
     * Khai tay ở model editable chứ không nhờ generator. Nếu sau này generator
     * có sinh belongsToMany cho pivot, đừng gọi `parent::` rồi bồi thêm —
     * `withPivot` là cộng dồn và tên cột nó phát ra cho pivot có thể sai (xem
     * CLAUDE.md, lỗi generator #1).
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'point_reward_branches')
            ->withPivot('is_available')
            ->withTimestamps();
    }

    /**
     * Số lượt đổi còn lại, hoặc `null` khi phần thưởng không giới hạn.
     *
     * Chặn dưới ở 0: `redeemed_count` có thể vượt `stock_quantity` khi HQ hạ
     * tồn kho xuống dưới số đã phát ra, và một con số âm hiện trên thẻ của
     * khách thì không giải thích được.
     */
    public function remainingStock(): ?int
    {
        if ($this->stock_quantity === null) {
            return null;
        }

        return max(0, (int) $this->stock_quantity - (int) $this->redeemed_count);
    }

    /** BR-PR05 — hết hàng vẫn nằm trong catalog, chỉ khoá nút đổi. */
    public function isOutOfStock(): bool
    {
        return $this->remainingStock() === 0;
    }
}
