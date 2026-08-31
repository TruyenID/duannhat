<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Peripheral-device seed for 本郷店 (Hongo / BETOYA-004) — the shop LAN that
 * currently runs the Glory YRT-R08-MN cash changer at 192.168.251.120.
 *
 * Called from BetoyaSeeder so a fresh production restore keeps the 釣銭機
 * registration; without this row the workstation answers
 * `503 no cash changer configured` and POS hides a working machine.
 *
 * Idempotent on (branch_id, name). Does not rotate `secret` on re-run.
 *
 * Run alone:
 *   php artisan db:seed --class=HongoPeripheralDeviceSeeder --force
 */
class HongoPeripheralDeviceSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'betoya')->first()
            ?? Organization::query()->first();
        if ($organization === null) {
            $this->command?->warn('No organization found — run the base seeders first. Skipping.');

            return;
        }

        $branch = Branch::query()->where('slug', 'hongo')->first();
        if ($branch === null) {
            $this->command?->warn('Branch [hongo] not found — Platform/Betoya catalog must seed it first. Skipping.');

            return;
        }

        foreach ($this->devices() as $row) {
            if (! in_array($row['type'], PeripheralDeviceService::ALLOWED_TYPES, true)) {
                $this->command?->error("Unknown peripheral type '{$row['type']}' — skipped.");

                continue;
            }

            PeripheralDevice::query()->updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'name' => $row['name'],
                ],
                [
                    'organization_id' => $organization->id,
                    'type' => $row['type'],
                    'is_active' => true,
                    'metadata' => $row['metadata'],
                    'secret' => PeripheralDevice::query()
                        ->where('branch_id', $branch->id)
                        ->where('name', $row['name'])
                        ->value('secret') ?? Str::random(40),
                ],
            );
        }

        $this->command?->info('Hongo peripheral devices seeded (Glory cash changer).');
    }

    /**
     * @return list<array{name: string, type: string, metadata: array<string, mixed>}>
     */
    private function devices(): array
    {
        return [
            [
                // Glory 釣銭機 via YRT-R08-MN adapter on the Hongo shop LAN.
                // Workstation resolves metadata.host[:port] per request
                // (see workstation cashChangerURL / glory.Client).
                'name' => 'Glory 釣銭機 01',
                'type' => 'coin_changer',
                'metadata' => [
                    'host' => '192.168.251.120',
                    'port' => 80,
                    'model' => 'RT-R08',
                    'note' => '本郷店 レジ横 YRT-R08-MN',
                ],
            ],
        ];
    }
}
