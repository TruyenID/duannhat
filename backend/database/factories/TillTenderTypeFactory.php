<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\TillTenderType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TillTenderType>
 */
class TillTenderTypeFactory extends Factory
{
    protected $model = TillTenderType::class;

    public function definition(): array
    {
        return [
            'tender_key' => 'tender_'.Str::lower(Str::random(8)),
            'name' => 'Test Tender',
            'category' => 'cash',
            'parent_tender_key' => null,
            'currency_code' => 'JPY',
            'payment_method_code' => null,
            'is_expected_anchor' => false,
            'requires_terminal_total' => false,
            'sort_order' => 0,
            'is_active' => true,
            'branch_id' => null,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }

    public function cash(): static
    {
        return $this->state(fn () => [
            'tender_key' => 'cash',
            'name' => 'Cash',
            'category' => 'cash',
            'payment_method_code' => 'cash',
            'is_expected_anchor' => true,
        ]);
    }

    public function credit(): static
    {
        return $this->state(fn () => [
            'tender_key' => 'credit',
            'name' => 'Credit',
            'category' => 'card',
            'payment_method_code' => 'card',
            'is_expected_anchor' => true,
            'requires_terminal_total' => true,
        ]);
    }

    public function qrBrand(string $key, string $name): static
    {
        return $this->state(fn () => [
            'tender_key' => $key,
            'name' => $name,
            'category' => 'qr',
            'payment_method_code' => null,
            'is_expected_anchor' => false,
        ]);
    }
}
