<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MaterialLot Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MaterialLot>
 */
class MaterialLotFactory extends Factory
{
    protected $model = MaterialLot::class;

    private const SUPPLIER_POOL = [
        '日本製粉',
        'メイトーフード',
        'カゴメ',
        '日清製粉',
        '味の素',
    ];

    public function definition(): array
    {
        $material = Material::query()->inRandomOrder()->first() ?? Material::factory()->create();
        $warehouse = Warehouse::query()->inRandomOrder()->first() ?? Warehouse::factory()->create();
        $organization = Organization::query()->inRandomOrder()->first() ?? Organization::factory()->create();
        $brand = Brand::query()->inRandomOrder()->first() ?? Brand::factory()->create();

        $sku = $material->sku ?? 'GEN';
        $receivedAt = fake()->dateTimeBetween('-3 months', 'now');
        $receivedQty = fake()->randomFloat(4, 1000, 100000);

        return [
            'lot_code' => sprintf('L-%s-%s-%03d', strtoupper($sku), $receivedAt->format('Ymd'), fake()->unique()->numberBetween(1, 999)),
            'material_id' => $material->id,
            'warehouse_id' => $warehouse->id,
            'source' => 'inbound',
            'status' => 'active',
            'supplier_name' => fake()->randomElement(self::SUPPLIER_POOL),
            'supplier_lot_code' => fake()->bothify('SUP-####-??'),
            'received_at' => $receivedAt,
            'expiry_date' => fake()->dateTimeBetween('+1 month', '+12 months')->format('Y-m-d'),
            'received_qty' => $receivedQty,
            'qty_on_hand' => $receivedQty,
            'unit' => $material->base_unit ?? 'kg',
            'cost_per_unit' => fake()->randomFloat(4, 100, 5000),
            'coa_urls' => null,
            'quarantine_reason' => null,
            'effective_allergens' => [],
            'produced_by_batch_id' => null,
            'organization_id' => $organization->id,
            'brand_id' => $brand->id,
        ];
    }

    public function quarantined(string $reason = 'QC hold pending lab result'): static
    {
        return $this->state(fn () => [
            'status' => 'quarantined',
            'quarantine_reason' => $reason,
        ]);
    }

    public function depleted(): static
    {
        return $this->state(fn () => [
            'status' => 'depleted',
            'qty_on_hand' => 0,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'expiry_date' => fake()->dateTimeBetween('-6 months', '-1 day')->format('Y-m-d'),
        ]);
    }

    public function expiringSoon(int $daysOut = 3): static
    {
        return $this->state(fn () => [
            'expiry_date' => now()->addDays($daysOut)->format('Y-m-d'),
        ]);
    }

    public function fromBatch(MaterialBatch $batch): static
    {
        return $this->state(fn () => [
            'source' => 'production',
            'lot_code' => $batch->batch_code,
            'produced_by_batch_id' => $batch->id,
            'supplier_name' => null,
            'supplier_lot_code' => null,
            'received_at' => $batch->completed_at ?? now(),
            'material_id' => $batch->material_id,
            'warehouse_id' => $batch->warehouse_id,
            'received_qty' => $batch->actual_yield ?? $batch->planned_yield,
            'qty_on_hand' => $batch->actual_yield ?? $batch->planned_yield,
        ]);
    }
}
