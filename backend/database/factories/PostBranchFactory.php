<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Post;
use App\Models\PostBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PostBranch Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PostBranch>
 */
class PostBranchFactory extends Factory
{
    protected $model = PostBranch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // #1727 — hai khoá của pivot là NOT NULL nhưng generator chỉ đưa vào
            // đây những property khai trong YAML, nên factory sinh ra không tạo
            // nổi một dòng. Cùng lỗi #1659 đã sửa cho `PointRewardBranchFactory`;
            // `PostBranch` ra đời ở #1684 và không được sửa theo.
            //
            // KHÔNG dùng `inRandomOrder()->first() ?? factory()`: bảng có
            // UNIQUE (post_id, branch_id), nên tái dùng dòng cha có thể bốc trúng
            // cùng một cặp và vi phạm ràng buộc.
            'post_id' => Post::factory(),
            'branch_id' => Branch::factory(),
            'is_visible' => fake()->boolean(),
        ];
    }
}
