<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Till;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Till>
 */
class TillFactory extends Factory
{
    protected $model = Till::class;

    public function definition(): array
    {
        return [
            'till_code' => 'MAIN',
            'default_currency_code' => 'JPY',
            'variance_tolerance_amount' => 0,
            'is_active' => true,
            'device_id' => null,
            'current_session_id' => null,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
