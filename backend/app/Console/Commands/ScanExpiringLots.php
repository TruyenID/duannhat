<?php

namespace App\Console\Commands;

use App\Services\Inventory\ExpiryAlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Plan-017 Tier 1.C — daily 07:00 Asia/Tokyo expiry sweep.
 *
 * Scans every active MaterialLot, compares expiry_date to today, and
 * inserts one ExpiryAlert row per (lot, threshold) crossing — idempotent
 * via the unique index on (material_lot_id, threshold_days). Re-running
 * the same day inserts zero rows.
 *
 * Wire-up:
 *   - Schedule: routes/console.php registers `material-lots:scan-expiring`
 *     daily at 07:00 Asia/Tokyo.
 *   - Manual run for ops investigation:
 *       docker compose exec -T app php artisan material-lots:scan-expiring
 */
#[Signature('material-lots:scan-expiring')]
#[Description('Scan active material lots and fire expiry alerts at the configured day-out thresholds.')]
class ScanExpiringLots extends Command
{
    public function handle(ExpiryAlertService $service): int
    {
        $result = $service->scan();

        $this->info(sprintf(
            'Scanned %d active lots; created %d new alerts.',
            $result['lots_scanned'],
            $result['alerts_created'],
        ));

        return self::SUCCESS;
    }
}
