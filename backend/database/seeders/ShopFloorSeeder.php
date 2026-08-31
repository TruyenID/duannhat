<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use App\Omnify\Enums\DeviceStatusEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds zones, tables, and TMS devices for all active branches.
 *
 * Usage:
 *   php artisan db:seed --class=ShopFloorSeeder
 *   # or in docker:
 *   docker compose exec app php artisan db:seed --class=ShopFloorSeeder
 */
class ShopFloorSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command->warn('No organization found. Login via SSO first.');

            return;
        }

        $branches = Branch::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->get();

        if ($branches->isEmpty()) {
            $this->command->warn('No branches found.');

            return;
        }

        $this->command->info("Org: {$org->id} — {$branches->count()} branch(es)");

        foreach ($branches as $branch) {
            $this->seedZones($org->id, $branch);
            $this->seedDevices($org->id, $branch);
        }

        $this->command->info('Shop floor seeding complete.');
    }

    private function seedZones(string $orgId, Branch $branch): void
    {
        $zones = [
            [
                'code' => 'INDOOR',
                'name' => '店内',
                'order' => 1,
                'tables' => [
                    ['prefix' => 'A', 'count' => 6, 'seats' => 4],
                    ['prefix' => 'B', 'count' => 4, 'seats' => 2],
                    ['prefix' => 'C', 'count' => 2, 'seats' => 6],
                ],
            ],
            [
                'code' => 'TERRACE',
                'name' => 'テラス',
                'order' => 2,
                'tables' => [
                    ['prefix' => 'T', 'count' => 4, 'seats' => 4],
                ],
            ],
            [
                'code' => 'VIP',
                'name' => 'VIP',
                'order' => 3,
                'tables' => [
                    ['prefix' => 'V', 'count' => 2, 'seats' => 8],
                ],
            ],
            [
                'code' => 'COUNTER',
                'name' => 'カウンター',
                'order' => 4,
                'tables' => [
                    ['prefix' => 'K', 'count' => 5, 'seats' => 1],
                ],
            ],
        ];

        $statuses = ['free', 'free', 'free', 'free', 'occupied', 'occupied', 'reserved', 'cleaning'];
        $tableCount = 0;

        foreach ($zones as $z) {
            $zone = Zone::firstOrCreate(
                ['branch_id' => $branch->id, 'code' => $z['code']],
                [
                    'organization_id' => $orgId,
                    'name' => $z['name'],
                    'display_order' => $z['order'],
                    'is_active' => true,
                ]
            );

            foreach ($z['tables'] as $tt) {
                for ($i = 1; $i <= $tt['count']; $i++) {
                    $code = sprintf('%s-%02d', $tt['prefix'], $i);
                    Table::firstOrCreate(
                        ['branch_id' => $branch->id, 'code' => $code],
                        [
                            'organization_id' => $orgId,
                            'zone_id' => $zone->id,
                            'seat_count' => $tt['seats'],
                            'status' => $statuses[array_rand($statuses)],
                            'qr_token' => Str::random(32),
                            'is_active' => true,
                        ]
                    );
                    $tableCount++;
                }
            }
        }

        $this->command->info("  {$branch->name}: 4 zones, {$tableCount} tables");
    }

    private function seedDevices(string $orgId, Branch $branch): void
    {
        $devices = [
            ['name' => 'TMS-受付', 'type' => 'tms'],
            ['name' => 'TMS-フロア', 'type' => 'tms'],
            ['name' => 'Kiosk', 'type' => 'kiosk'],
            ['name' => 'Workstation-POS', 'type' => 'workstation'],
            ['name' => 'KDS-キッチン', 'type' => 'kds'],
            ['name' => 'Handy-1', 'type' => 'handy'],
            ['name' => 'Handy-2', 'type' => 'handy'],
        ];

        foreach ($devices as $d) {
            Device::firstOrCreate(
                ['branch_id' => $branch->id, 'name' => $d['name']],
                [
                    'organization_id' => $orgId,
                    'type' => $d['type'],
                    'status' => DeviceStatusEnum::PendingActivation->value,
                    'pairing_code' => strtoupper(Str::random(6)),
                    'pairing_expires_at' => now()->addHours(24),
                ]
            );
        }

        $this->command->info("  {$branch->name}: 2 TMS + 1 Kiosk + 1 Workstation + 1 KDS + 2 Handy devices (pending activation)");
    }
}
