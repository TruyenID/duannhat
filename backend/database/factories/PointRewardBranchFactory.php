<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\PointReward;
use App\Models\PointRewardBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PointRewardBranch Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PointRewardBranch>
 */
class PointRewardBranchFactory extends Factory
{
    protected $model = PointRewardBranch::class;

    /**
     * Define the model's default state.
     *
     * #1659 — hai khoá ngoại phải do factory cấp. Generator chỉ đưa `is_available`
     * vào định nghĩa, còn `point_reward_id`/`branch_id` là NOT NULL, nên factory cũ
     * chết ngay ở ràng buộc và `FactoriesCanCreateRowsTest` đỏ trên `dev`.
     *
     * **Cố ý KHÔNG dùng `inRandomOrder()->first() ?? factory()`** như phần lớn
     * factory khác trong repo: bảng này có `UNIQUE (point_reward_id, branch_id)`,
     * nên dùng lại dòng cha đã có thì `->count(2)->create()` có thể bốc trúng cùng
     * một cặp và vi phạm unique. Luôn dựng cha mới thì mỗi lượt gọi ra một cặp khác
     * nhau — đắt hơn một chút, nhưng đúng ở mọi số lượng.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'point_reward_id' => PointReward::factory(),
            'branch_id' => Branch::factory(),
            'is_available' => fake()->boolean(),
        ];
    }
}
