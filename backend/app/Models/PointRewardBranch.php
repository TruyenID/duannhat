<?php

/**
 * PointRewardBranch Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PointRewardBranch\Models\PointRewardBranchBaseModel;
use Database\Factories\PointRewardBranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PointRewardBranch — add project-specific model logic here.
 */
class PointRewardBranch extends PointRewardBranchBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PointRewardBranchFactory
    {
        return PointRewardBranchFactory::new();
    }

    /**
     * Hai cột khoá ngoại phải tự thêm — generator chỉ đưa `is_available` vào
     * `$fillable` của base model.
     *
     * Hỏng theo kiểu im lặng: `updateOrCreate(['point_reward_id' => …,
     * 'branch_id' => …], …)` vẫn dùng chúng để TÌM, nhưng khi phải INSERT thì
     * mass-assignment loại cả hai khỏi câu lệnh, và cái nổ ra là "NOT NULL
     * constraint failed" ở một cột mà mình vừa truyền vào rõ ràng.
     *
     * @var list<string>
     */
    protected $fillable = [
        'point_reward_id',
        'branch_id',
        'is_available',
        // Base model đã có sẵn `toggled_by_id`, nhưng khai lại ở đây là BẮT
        // BUỘC: `$fillable` ghi đè chứ không cộng dồn, nên bỏ sót một cột ở
        // danh sách này là cột đó bị `fill()` loại và lặng lẽ lưu null (#1723).
        'toggled_by_id',
    ];
}
