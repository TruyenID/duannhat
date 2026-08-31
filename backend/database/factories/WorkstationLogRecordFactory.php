<?php

namespace Database\Factories;

use App\Models\WorkstationLogRecord;
use App\Omnify\Enums\WorkstationLogLevelEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * WorkstationLogRecord Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<WorkstationLogRecord>
 */
class WorkstationLogRecordFactory extends Factory
{
    protected $model = WorkstationLogRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => (string) Str::uuid(),
            'local_id' => fake()->numberBetween(1, 1000),
            'branch_id' => (string) Str::uuid(),
            'organization_id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            // Mốc mặc định nằm TRONG cửa sổ giữ 14 ngày, có chủ đích: một
            // `fake()->dateTime()` rơi ngẫu nhiên tới ~30 năm trước, nên mọi
            // test về hạn giữ sẽ tự "đúng" mà không chứng minh gì.
            'logged_at' => CarbonImmutable::now('UTC')->subHour(),
            'level' => WorkstationLogLevelEnum::Warn->value,
            // Một message THẬT, có khai trong
            // `docs/reference/workstation-log-allowlist.md`. `fake()->sentence()`
            // sinh ra chuỗi không bao giờ qua được allowlist, nên fixture đọc
            // lên sẽ mâu thuẫn với đường thật.
            'message' => 'sync push failed',
            'attrs' => ['id' => 1, 'entity' => 'payment', 'retryable' => false],
        ];
    }
}
