<?php

/**
 * PostBranch Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PostBranch\Models\PostBranchBaseModel;
use Database\Factories\PostBranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PostBranch — add project-specific model logic here.
 */
class PostBranch extends PostBranchBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PostBranchFactory
    {
        return PostBranchFactory::new();
    }

    /**
     * Hai khoá của pivot phải fillable — generator KHÔNG đưa chúng vào
     * `$fillable` của base model.
     *
     * Hỏng theo kiểu im lặng: `updateOrCreate(['post_id' => …, 'branch_id' =>
     * …], …)` vẫn dùng chúng để TÌM, nhưng khi phải INSERT thì mass-assignment
     * loại cả hai khỏi câu lệnh, và cái nổ ra là "NOT NULL constraint failed:
     * post_branches.post_id" — ở đúng cột mình vừa truyền vào rõ ràng.
     *
     * `PointRewardBranch` đã vấp và ghi lại y hệt; giữ hai chỗ giống nhau để
     * người sau đọc một chỗ là hiểu cả hai.
     *
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'branch_id',
        'is_visible',
        // Base model đã có sẵn `toggled_by_id`, nhưng khai lại ở đây là BẮT
        // BUỘC: `$fillable` ghi đè chứ không cộng dồn, nên bỏ sót một cột ở
        // danh sách này là cột đó bị `fill()` loại và lặng lẽ lưu null (#1689).
        'toggled_by_id',
    ];
}
