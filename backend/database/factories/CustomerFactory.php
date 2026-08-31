<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Customer Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->paragraphs(3, true),
            'tax_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'note' => fake()->paragraphs(3, true),
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }

    /**
     * Self-registered customer with auth credentials (no brand/branch/org context).
     */
    public function selfRegistered(): static
    {
        return $this->state(fn () => [
            'password' => 'password',
            'email_verified_at' => now(),
            'address' => null,
            'tax_code' => null,
            'note' => null,
            'brand_id' => null,
            'branch_id' => null,
            'organization_id' => null,
        ]);
    }

    /**
     * Customer that has not yet verified their email.
     */
    public function unverified(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => null,
        ]);
    }
}
