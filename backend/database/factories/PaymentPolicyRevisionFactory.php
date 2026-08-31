<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentPolicyRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PaymentPolicyRevision Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PaymentPolicyRevision>
 */
class PaymentPolicyRevisionFactory extends Factory
{
    protected $model = PaymentPolicyRevision::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            // #1132 — payment_policy_revisions carries a UNIQUE (branch_id, revision) index, and
            // same shape as catalog_revisions: tests pin branch_id and let the
            // factory draw the revision, so two rows collided
            // roughly once every thousand full-suite runs. Same latent flake
            // that fired for menus; unique() makes it structurally impossible.
            'revision' => fake()->unique()->numberBetween(1, 1_000_000),
            'ownership_revision' => fake()->words(3, true),
            'snapshot_hash' => fake()->sentence(),
            'snapshot' => [],
            'effective_option_count' => fake()->numberBetween(0, 100),
            'source' => fake()->sentence(),
            'published_at' => fake()->dateTime(),
        ];
    }
}
