<?php

namespace Database\Factories;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Menu Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
{
    protected $model = Menu::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'brand_id' => (string) Str::uuid(),
            'branch_id' => (string) Str::uuid(),
            'name' => fake()->sentence(3),
            'description' => fake()->paragraphs(3, true),
            // No validity window by default. Tests and callers that exercise
            // scheduled menus set explicit bounds; random independent dates
            // can otherwise produce an already-expired or inverted menu.
            'valid_from' => null,
            'valid_to' => null,
            // #1132 — menus carry a UNIQUE (branch_id, priority) index, and a
            // test that builds two menus for one branch (dine-in + takeaway is
            // the common shape) drew the same number roughly once every
            // thousand runs. unique() makes the flake structurally impossible;
            // the range is wide so a full-suite run cannot exhaust the pool.
            // Relative order between two factory menus was already random and
            // stays random — anything that depends on it must set priority.
            'priority' => fake()->unique()->numberBetween(1, 1_000_000),
            'status' => fake()->randomElement(['Draft', 'Pending', 'Approved', 'Active', 'Inactive', 'Rejected']),
            'rejection_reason' => fake()->paragraphs(3, true),
            'created_by_id' => (string) Str::uuid(),
            'approved_by_id' => (string) Str::uuid(),
            'approved_at' => fake()->dateTime(),
            'rejected_by_id' => (string) Str::uuid(),
            'rejected_at' => fake()->dateTime(),
            // #1146 — NEVER randomize a column that policies/resolvers gate on:
            // MenuPolicy::shopView denies master menus, so a coin-flip here made
            // shop-surface tests fail 50% of runs (bit PosMenuProductsToppingTest
            // for real). Branch menu is the common case; use ->master() when a
            // test genuinely needs the master catalog.
            'is_master' => false,
            'last_synced_at' => fake()->dateTime(),
            'master_menu_id' => Menu::query()->inRandomOrder()->first()?->id,
        ];
    }

    /** A brand master catalog menu (MenuPolicy::shopView denies these on shop surfaces). */
    public function master(): static
    {
        return $this->state(fn () => ['is_master' => true]);
    }
}
