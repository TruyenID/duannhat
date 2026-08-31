<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds tables for Hanoi branch.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=HanoiTablesSeeder
 */
class HanoiTablesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branch = Branch::where('slug', 'hanoi')->first();

        if (! $branch) {
            $this->command->error('Hanoi branch not found. Run HanoiBranchWithMenuSeeder first.');

            return;
        }

        // Get organization via console_organization_id
        $org = Organization::where('console_organization_id', $branch->console_organization_id)->first();
        if (! $org) {
            $this->command->error('Organization not found for Hanoi branch.');

            return;
        }

        $this->command->info("Creating tables for: {$branch->name}");

        // Create zones
        $zones = [
            ['name' => 'Tầng 1', 'code' => 'T1', 'display_order' => 1],
            ['name' => 'Tầng 2', 'code' => 'T2', 'display_order' => 2],
            ['name' => 'Sân thượng', 'code' => 'ST', 'display_order' => 3],
        ];

        foreach ($zones as $zoneData) {
            $zone = Zone::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'code' => $zoneData['code'],
                ],
                [
                    'name' => $zoneData['name'],
                    'display_order' => $zoneData['display_order'],
                    'organization_id' => $org->id,
                    'is_active' => true,
                ]
            );

            $this->command->info("✓ Zone: {$zone->name}");

            // Create tables for this zone
            $tableCount = match ($zoneData['code']) {
                'T1' => 10,      // 10 tables on floor 1
                'T2' => 8,       // 8 tables on floor 2
                'ST' => 5,       // 5 tables on rooftop
            };

            for ($i = 1; $i <= $tableCount; $i++) {
                $tableNumber = str_pad($i, 2, '0', STR_PAD_LEFT);
                $tableName = "{$zoneData['code']}-{$tableNumber}";

                $seatCount = match (true) {
                    $i <= 3 => 2,      // First 3 tables: 2 seats
                    $i <= 7 => 4,      // Next 4 tables: 4 seats
                    default => 6,      // Remaining: 6 seats
                };

                Table::firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'zone_id' => $zone->id,
                        'name' => $tableName,
                    ],
                    [
                        'seat_count' => $seatCount,
                        'status' => 'free',
                        'organization_id' => $org->id,
                        'code' => $tableName,
                        'is_active' => true,
                        'qr_token' => Str::random(32),
                    ]
                );
            }

            $this->command->info("  → Created {$tableCount} tables");
        }

        $totalTables = Table::where('branch_id', $branch->id)->count();
        $this->command->info("✅ Total tables for Hanoi branch: {$totalTables}");
    }
}
