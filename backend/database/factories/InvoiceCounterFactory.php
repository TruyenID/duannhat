<?php

namespace Database\Factories;

use App\Models\InvoiceCounter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * InvoiceCounter Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<InvoiceCounter>
 */
class InvoiceCounterFactory extends Factory
{
    protected $model = InvoiceCounter::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => (string) Str::uuid(),
            // char(6) — YYYYMM. Bộ đếm khoá theo (chi nhánh × tháng), nên
            // giá trị placeholder phải là một tháng thật chứ không phải chuỗi ngẫu nhiên.
            'year_month' => fake()->dateTimeBetween('-2 years', 'now')->format('Ym'),
            'last_seq' => fake()->numberBetween(1, 1000),
        ];
    }
}
