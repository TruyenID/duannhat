<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * Seeds zones and tables for all active branches.
 * Creates realistic restaurant floor layouts with multiple zones
 * (Indoor, Terrace, VIP, Counter) and tables with varying capacities.
 *
 * Usage:
 *   php artisan db:seed --class=BranchTableSeeder
 *   # or in docker:
 *   docker compose exec app php artisan db:seed --class=BranchTableSeeder
 */
class BranchTableSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command->warn('No organization found. Run MockDataSeeder first.');

            return;
        }

        $branches = Branch::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->whereNotNull('id')
            ->get();

        if ($branches->isEmpty()) {
            $this->command->warn('No active branches found.');

            return;
        }

        $this->command->info("Organization: {$org->name}");
        $this->command->info("Seeding tables for {$branches->count()} branch(es)...");

        foreach ($branches as $branch) {
            $this->seedBranchFloor($org->id, $branch);
        }

        $totalTables = Table::count();
        $this->command->info("✓ Total tables seeded: {$totalTables}");
    }

    private function seedBranchFloor(string $orgId, Branch $branch): void
    {
        $zones = [
            [
                'code' => 'INDOOR',
                'name' => '店内',
                'name_vn' => 'Khu trong nhà',
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
                'name_vn' => 'Sân hiên',
                'order' => 2,
                'tables' => [
                    ['prefix' => 'T', 'count' => 4, 'seats' => 4],
                ],
            ],
            [
                'code' => 'VIP',
                'name' => 'VIP',
                'name_vn' => 'Phòng VIP',
                'order' => 3,
                'tables' => [
                    ['prefix' => 'V', 'count' => 2, 'seats' => 8],
                ],
            ],
            [
                'code' => 'COUNTER',
                'name' => 'カウンター',
                'name_vn' => 'Quầy bar',
                'order' => 4,
                'tables' => [
                    ['prefix' => 'K', 'count' => 8, 'seats' => 1],
                ],
            ],
        ];

        $tableStatuses = ['free', 'free', 'free', 'occupied', 'reserved', 'cleaning'];
        $branchTableCount = 0;

        foreach ($zones as $z) {
            $zone = Zone::firstOrCreate(
                ['branch_id' => $branch->id, 'code' => $z['code']],
                [
                    'organization_id' => $orgId,
                    'name' => $z['name'],
                    'display_order' => $z['order'],
                ]
            );

            foreach ($z['tables'] as $tg) {
                for ($i = 1; $i <= $tg['count']; $i++) {
                    $code = "{$tg['prefix']}{$i}";
                    $status = $tableStatuses[array_rand($tableStatuses)];

                    Table::firstOrCreate(
                        ['branch_id' => $branch->id, 'code' => $code],
                        [
                            'organization_id' => $orgId,
                            'zone_id' => $zone->id,
                            'seat_count' => $tg['seats'],
                            'status' => $status,
                            'qr_token' => bin2hex(random_bytes(32)),
                        ]
                    );

                    $branchTableCount++;
                }
            }
        }

        $this->command->info("  ✓ {$branch->name}: {$branchTableCount} tables across ".count($zones).' zones');
    }
}
