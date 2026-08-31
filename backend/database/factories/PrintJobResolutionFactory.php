<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PrintJob;
use App\Models\PrintJobResolution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PrintJobResolution Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PrintJobResolution>
 */
class PrintJobResolutionFactory extends Factory
{
    protected $model = PrintJobResolution::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'print_job_id' => PrintJob::query()->inRandomOrder()->first()?->id ?? PrintJob::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            // Cột này được cast sang PrintJobResolutionKind, nên giá trị
            // ngẫu nhiên sẽ ném "not a valid backing value" ngay khi đọc.
            'resolution' => fake()->randomElement(['printed_by_hand', 'discarded']),
            'reason' => fake()->sentence(),
            'resolved_by_id' => User::query()->inRandomOrder()->first()?->id,
            'resolved_at' => fake()->dateTime(),
        ];
    }
}
