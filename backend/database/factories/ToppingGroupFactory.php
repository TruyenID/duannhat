<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\ToppingGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ToppingGroup Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ToppingGroup>
 */
class ToppingGroupFactory extends Factory
{
    protected $model = ToppingGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(2),
            'min_select' => 0,
            'max_select' => null,
            // max_qty_per_item / available_from|to|days were removed from the
            // topping_groups schema (deferred to Phase 2+ per ToppingGroup.yaml;
            // dropped by migration 2000_02_13_000000_alter_topping_groups). Code
            // reads them with a `?? 1`/null default, so they are NOT columns.
            'sort_order' => 0,
            'is_active' => true,
            'brand_id' => fn () => Brand::factory()->create()->id,
            'organization_id' => fn () => Organization::factory()->create()->id,
        ];
    }

    /**
     * Set name translations for all locales (ja/en/vi).
     */
    public function withTranslations(string $ja = '', string $en = '', string $vi = ''): static
    {
        return $this->afterCreating(function (ToppingGroup $group) use ($ja, $en, $vi) {
            $group->translateOrNew('ja')->name = $ja ?: fake()->sentence(2);
            $group->translateOrNew('en')->name = $en ?: fake()->sentence(2);
            $group->translateOrNew('vi')->name = $vi ?: fake()->sentence(2);
            $group->save();
        });
    }
}
