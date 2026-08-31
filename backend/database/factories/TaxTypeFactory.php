<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\TaxType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * TaxType Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<TaxType>
 */
class TaxTypeFactory extends Factory
{
    protected $model = TaxType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'name' => fake()->sentence(3),
            // Realistic consumption-tax rates (0–100, 2dp) — the raw omnify
            // default (1–10000) is meaningless for a percentage.
            'rate' => fake()->randomElement([10, 8, 0]),
            'is_default' => false,
            'is_active' => true,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
        ];
    }

    /**
     * 標準税率 — STANDARD 10%. Loại mặc định của bộ seed Nhật.
     */
    public function standard(): static
    {
        return $this->state(fn () => [
            'code' => 'STANDARD',
            'rate' => 10,
        ]);
    }

    /**
     * 軽減税率 — REDUCED 8% (thực phẩm). Ngữ cảnh 店内/持ち帰り là chuyện của
     * MENU (thực đơn mang về mang override REDUCED), không phải của tax type:
     * một tax type là MỘT thuế suất kể từ #1099.
     */
    public function reduced(): static
    {
        return $this->state(fn () => [
            'code' => 'REDUCED',
            'rate' => 8,
        ]);
    }

    /**
     * 非課税 — EXEMPT 0%.
     */
    public function exempt(): static
    {
        return $this->state(fn () => [
            'code' => 'EXEMPT',
            'rate' => 0,
        ]);
    }

    /** Mark as the brand's single default type (resolver tier 4). */
    public function asDefault(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
