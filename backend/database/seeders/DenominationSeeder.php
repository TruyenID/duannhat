<?php

namespace Database\Seeders;

use App\Models\Denomination;
use App\Services\Omnify\DenominationService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Plan 030 — Seeds the global denomination master (organization_id NULL)
 * for JPY / VND / USD / EUR. Seeded global so every org/branch picks up
 * the same set without per-tenant duplication.
 *
 * Idempotent: matches on (organization_id, currency_code, value, kind).
 * Uses DenominationService::create/update so any Astrotomic translation
 * fields would persist (the schema has no translatable fields today, but
 * the convention applies).
 *
 *   php artisan db:seed --class=DenominationSeeder
 */
class DenominationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(DenominationService $service): void
    {
        $count = 0;
        foreach ($this->denominations() as $row) {
            $existing = Denomination::query()
                ->whereNull('organization_id')
                ->where('currency_code', $row['currency_code'])
                ->where('value', $row['value'])
                ->where('kind', $row['kind'])
                ->first();

            if ($existing) {
                $service->update($existing, $row);
            } else {
                $service->create($row);
            }
            $count++;
        }

        $this->command?->info("DenominationSeeder: {$count} denominations seeded.");
    }

    /**
     * @return list<array{currency_code: string, value: float, kind: string, label: ?string, sort_order: int, is_active: bool, organization_id: ?string}>
     */
    private function denominations(): array
    {
        $rows = [];

        // JPY — 4 notes + 6 coins (per real Japanese circulation incl. 2000 note).
        foreach ([10000, 5000, 2000, 1000] as $i => $v) {
            $rows[] = $this->row('JPY', $v, 'note', sort: $i);
        }
        foreach ([500, 100, 50, 10, 5, 1] as $i => $v) {
            $rows[] = $this->row('JPY', $v, 'coin', sort: $i);
        }

        // VND — 9 notes (no coins in normal use).
        foreach ([500000, 200000, 100000, 50000, 20000, 10000, 5000, 2000, 1000] as $i => $v) {
            $rows[] = $this->row('VND', $v, 'note', sort: $i);
        }

        // USD — 6 notes + 4 coins.
        foreach ([100, 50, 20, 10, 5, 1] as $i => $v) {
            $rows[] = $this->row('USD', $v, 'note', sort: $i);
        }
        foreach ([0.25, 0.10, 0.05, 0.01] as $i => $v) {
            $rows[] = $this->row('USD', $v, 'coin', sort: $i);
        }

        // EUR — 7 notes + 8 coins.
        foreach ([500, 200, 100, 50, 20, 10, 5] as $i => $v) {
            $rows[] = $this->row('EUR', $v, 'note', sort: $i);
        }
        foreach ([2, 1, 0.50, 0.20, 0.10, 0.05, 0.02, 0.01] as $i => $v) {
            $rows[] = $this->row('EUR', $v, 'coin', sort: $i);
        }

        return $rows;
    }

    /**
     * @return array{currency_code: string, value: float, kind: string, label: ?string, sort_order: int, is_active: bool, organization_id: ?string}
     */
    private function row(string $currency, float $value, string $kind, int $sort): array
    {
        return [
            'currency_code' => $currency,
            'value' => $value,
            'kind' => $kind,
            'label' => null,
            'sort_order' => $sort,
            'is_active' => true,
            'organization_id' => null,
        ];
    }
}
