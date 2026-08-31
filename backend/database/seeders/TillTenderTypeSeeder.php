<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TillTenderType;
use App\Services\Omnify\TillTenderTypeService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Plan 030 — Seeds reconciliation tender masters for every organization.
 *
 * The vocabulary is COUNTRY-PARAMETRIZED (#1153/#1156): each organization
 * gets the brand set of its `operating_country` from
 * `config/tender_vocabulary.php` (JP: Stera-日計-shaped 17 rows · VN: cash,
 * credit, VietQR anchor→transfer, MoMo/ZaloPay/VNPAY/ShopeePay). Unknown or
 * missing country falls back to JP, matching the column default. Same
 * compliance-profile philosophy as #1153: one machinery, country only
 * changes the data.
 *
 * Idempotent on (organization_id, branch_id NULL, tender_key). Uses
 * TillTenderTypeService::create/update so the translatable `name` (ja/en/vi)
 * persists via the Astrotomic flushTranslations path (convention #3).
 */
class TillTenderTypeSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(TillTenderTypeService $service): void
    {
        $orgs = Organization::all();
        if ($orgs->isEmpty()) {
            $this->command?->error('TillTenderTypeSeeder: No organization found. Run MockDataSeeder first.');

            return;
        }

        $total = 0;
        foreach ($orgs as $org) {
            $country = strtoupper((string) ($org->operating_country ?: 'JP'));

            foreach ($this->tenderTypes($country) as $row) {
                $row['organization_id'] = $org->id;
                $row['branch_id'] = null;

                $existing = TillTenderType::query()
                    ->where('organization_id', $org->id)
                    ->whereNull('branch_id')
                    ->where('tender_key', $row['tender_key'])
                    ->first();

                if ($existing) {
                    $service->update($existing, $row);
                } else {
                    $service->create($row);
                }
                $total++;
            }
        }

        $this->command?->info("TillTenderTypeSeeder: {$total} tender types seeded across {$orgs->count()} orgs.");
    }

    /**
     * Vocabulary rows for one country, from config/tender_vocabulary.php.
     * Unknown country → JP (column default).
     *
     * @return list<array<string, mixed>>
     */
    private function tenderTypes(string $country): array
    {
        $vocabulary = config("tender_vocabulary.{$country}") ?? config('tender_vocabulary.JP');
        $currency = (string) $vocabulary['currency'];

        $rows = [];
        $sort = 0;
        foreach ($vocabulary['tenders'] as $tender) {
            $rows[] = [
                'tender_key' => $tender['key'],
                'name:ja' => $tender['ja'],
                'name:en' => $tender['en'],
                'name:vi' => $tender['vi'],
                'category' => $tender['category'],
                'parent_tender_key' => null,
                'currency_code' => $currency,
                'payment_method_code' => $tender['method'],
                'is_expected_anchor' => $tender['anchor'],
                'requires_terminal_total' => $tender['terminal'],
                'sort_order' => $sort++,
                'is_active' => true,
            ];
        }

        return $rows;
    }
}
