<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TillTenderCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds the org-wide system tender categories that mirror the legacy
 * TillTenderSystemCategoryEnum values surfaced in shop UI: card / qr / emoney.
 *
 * Cash is intentionally NOT seeded as a UI-visible category — the
 * cash drawer is reconciled via the denomination counter on the
 * shift-close screen, not as a tender device row. The DB enum value
 * `cash` still exists in `till_tender_types.category` for historical
 * data and the cash anchor seeded by TillTenderTypeSeeder; the
 * reconciliation math reads it directly without needing a
 * `till_tender_categories` row.
 *
 * Idempotent on (organization_id, branch_id NULL, key). Runs BEFORE
 * TillTenderTypeSeeder so the tender-type validation in the controller
 * (which now resolves category against this table) has rows to find.
 */
class TillTenderCategorySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $orgs = Organization::all();
        if ($orgs->isEmpty()) {
            $this->command?->error('TillTenderCategorySeeder: No organization found. Run MockDataSeeder first.');

            return;
        }

        $rows = [
            ['key' => 'card', 'name' => 'Thẻ', 'sort_order' => 10],
            ['key' => 'qr', 'name' => 'QR', 'sort_order' => 20],
            ['key' => 'emoney', 'name' => 'Tiền điện tử', 'sort_order' => 30],
        ];

        $total = 0;
        foreach ($orgs as $org) {
            foreach ($rows as $row) {
                TillTenderCategory::updateOrCreate(
                    [
                        'organization_id' => $org->id,
                        'branch_id' => null,
                        'key' => $row['key'],
                    ],
                    [
                        'name' => $row['name'],
                        'sort_order' => $row['sort_order'],
                        'is_active' => true,
                        'is_system' => true,
                    ],
                );
                $total++;
            }
        }

        $this->command?->info("TillTenderCategorySeeder: {$total} system categories seeded across {$orgs->count()} orgs.");
    }
}
