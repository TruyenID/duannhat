<?php

declare(strict_types=1);

/**
 * #1659 — factory của bảng nối `point_reward_branches`.
 *
 * `FactoriesCanCreateRowsTest` đã phủ ca "tạo được MỘT dòng" cho mọi factory. Bài
 * này phủ thứ nó không phủ và là lý do factory này đi chệch quy ước chung: bảng có
 * `UNIQUE (point_reward_id, branch_id)`, nên nhiều dòng phải ra nhiều CẶP khác nhau.
 */

use App\Models\PointRewardBranch;
use Illuminate\Support\Facades\DB;

it('tạo được một dòng với đủ hai khoá ngoại', function () {
    $pivot = PointRewardBranch::factory()->create();

    expect($pivot->point_reward_id)->not->toBeNull()
        ->and($pivot->branch_id)->not->toBeNull();

    expect(DB::table('point_reward_branches')->count())->toBe(1);
});

it('tạo nhiều dòng KHÔNG đụng unique (point_reward_id, branch_id)', function () {
    // Đây là lý do factory dựng cha mới thay vì dùng lại `inRandomOrder()->first()`.
    // Với cách kia, ba lượt gọi có thể bốc trúng cùng một cặp và chết ở unique —
    // hỏng theo kiểu chỉ lộ ra khi ai đó cần nhiều hơn một dòng.
    PointRewardBranch::factory()->count(3)->create();

    $pairs = DB::table('point_reward_branches')
        ->get(['point_reward_id', 'branch_id'])
        ->map(fn ($r) => $r->point_reward_id.'|'.$r->branch_id)
        ->all();

    expect($pairs)->toHaveCount(3)
        ->and(array_unique($pairs))->toHaveCount(3);
});
