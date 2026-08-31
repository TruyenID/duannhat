<?php

namespace Database\Factories;

use App\Models\GenealogyLink;
use App\Models\MaterialLot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * GenealogyLink Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * Default state = production edge (parent_lot → child_lot via material_batch).
 * Use ->salesEdge() / ->disposalEdge() for the other shapes.
 *
 * @extends Factory<GenealogyLink>
 */
class GenealogyLinkFactory extends Factory
{
    protected $model = GenealogyLink::class;

    public function definition(): array
    {
        $parent = MaterialLot::query()->inRandomOrder()->first() ?? MaterialLot::factory()->create();
        $child = MaterialLot::factory()->create([
            'source' => 'production',
            'material_id' => $parent->material_id,
            'warehouse_id' => $parent->warehouse_id,
            'organization_id' => $parent->organization_id,
            'brand_id' => $parent->brand_id,
        ]);

        return [
            'parent_lot_id' => $parent->id,
            'child_lot_id' => $child->id,
            'customer_order_id' => null,
            'qty_consumed' => fake()->randomFloat(4, 10, 1000),
            'unit' => $parent->unit ?? 'kg',
            'consumed_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'source_event_type' => 'material_batch',
            'source_event_id' => (string) Str::uuid(),
        ];
    }

    public function salesEdge(?string $customerOrderId = null): static
    {
        return $this->state(fn () => [
            'child_lot_id' => null,
            'customer_order_id' => $customerOrderId ?? (string) Str::uuid(),
            'source_event_type' => 'customer_order',
        ]);
    }

    public function disposalEdge(): static
    {
        return $this->state(fn () => [
            'child_lot_id' => null,
            'customer_order_id' => null,
            'source_event_type' => 'disposal',
        ]);
    }
}
