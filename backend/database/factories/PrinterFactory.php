<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Printer;
use App\Omnify\Enums\PrinterConnectionTypeEnum;
use App\Omnify\Enums\PrinterCutTypeEnum;
use App\Omnify\Enums\PrinterRoleEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Printer Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * The default state produces a VALID printer: a network device on a private
 * LAN address holding a real role. Omnify's generated defaults were not usable
 * for this model (a street address in `address`, a sentence in `paper_width`,
 * and an empty `roles` array that BR-P02 forbids), so they are replaced here.
 *
 * @extends Factory<Printer>
 */
class PrinterFactory extends Factory
{
    protected $model = Printer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Printer '.fake()->unique()->numberBetween(1, 1000000),
            'roles' => [PrinterRoleEnum::KitchenPrinter->value],
            'connection_type' => PrinterConnectionTypeEnum::Network->value,
            // RFC1918 — the only range PrinterAddress accepts for network mode.
            'address' => '192.168.'.fake()->numberBetween(0, 255).'.'.fake()->numberBetween(2, 254).':9100',
            'paper_width' => 80,
            'cut_type' => PrinterCutTypeEnum::Full->value,
            'encoding' => 'shift_jis',
            'is_active' => true,
            'last_seen_at' => null,
            'notes' => null,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
        ];
    }

    /**
     * A printer serving several roles at once — the single-device small-shop
     * setup that workstation migration 013 introduced.
     *
     * @param  list<string>  $roles
     */
    public function withRoles(array $roles): static
    {
        return $this->state(fn (): array => ['roles' => $roles]);
    }

    public function receipt(): static
    {
        return $this->withRoles([PrinterRoleEnum::ReceiptPrinter->value]);
    }

    public function usb(): static
    {
        return $this->state(fn (): array => [
            'connection_type' => PrinterConnectionTypeEnum::Usb->value,
            'address' => '/dev/usb/lp0',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
