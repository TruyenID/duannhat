<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CatalogRevision;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CatalogRevision Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<CatalogRevision>
 */
class CatalogRevisionFactory extends Factory
{
    protected $model = CatalogRevision::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // #1132 — catalog_revisions carries a UNIQUE (branch_id, revision)
            // index, same shape as menus and payment_policy_revisions: a caller
            // pins branch_id and lets the factory draw the revision, so two rows
            // for one branch collided roughly once every thousand full-suite
            // runs. unique() makes it structurally impossible; the range is wide
            // so a 7k-test run cannot exhaust the pool.
            //
            // Widening the range is safe even though `revision` is the monotonic
            // per-branch counter behind the #1092 offline price map: nothing
            // asserts on its magnitude, only on its ordering
            // (`$after->revision === $before->revision + 1`), and those tests go
            // through CatalogRevisionService, which derives the next number as
            // `max(revision) + 1` — it never assumes a small one.
            'revision' => fake()->unique()->numberBetween(1, 1_000_000),
            'snapshot_hash' => fake()->sentence(),
            'snapshot' => [],
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
