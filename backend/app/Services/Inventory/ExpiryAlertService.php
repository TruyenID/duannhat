<?php

namespace App\Services\Inventory;

use App\Models\Brand;
use App\Models\ExpiryAlert;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Omnify\Enums\MaterialLotStatusEnum;
use Illuminate\Support\Carbon;

/**
 * ExpiryAlertService
 *
 * Plan-017 Tier 1.C. Walks active MaterialLots, fires one alert per
 * (lot, threshold) per crossing — idempotent via firstOrCreate on the
 * unique index `(material_lot_id, threshold_days)`. Re-running the scan
 * the same day inserts zero new rows.
 *
 * Notification dispatch is wired via plan-008 notification platform.
 * When a new ExpiryAlert is created, a `material_lot.expiring`
 * notification is dispatched to brand-admin + warehouse-manager users.
 */
class ExpiryAlertService
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * plan-040 TF.5 (M17/L4): include the expires-today threshold (0) so a lot
     * expiring on the branch's local "today" fires an alert — unified with the
     * AutoExpireMaterialLots boundary (which flips lots once expiry < today).
     *
     * @var array<int, int>
     */
    private const DEFAULT_THRESHOLDS = [7, 3, 1, 0];

    /**
     * Scan every active lot and fire missing alerts.
     *
     * @return array{alerts_created: int, lots_scanned: int}
     */
    public function scan(?Carbon $today = null): array
    {
        $defaultTz = config('app.default_branch_timezone', 'Asia/Tokyo');
        $referenceToday = ($today ?? Carbon::today($defaultTz))->copy()->startOfDay();
        $created = 0;
        $scanned = 0;

        // plan-040 TF.9 (L12): chunk + eager-load `warehouse` (+ branch for the
        // timezone) and `material` so per-lot threshold/tz/notification
        // resolution neither N+1s nor loads the whole active-lot table at once.
        MaterialLot::query()
            ->with([
                'material:id,name,expiry_alert_thresholds',
                'warehouse:id,name,branch_id,organization_id',
                'warehouse.branch:id,timezone',
            ])
            ->where('status', MaterialLotStatusEnum::Active->value)
            ->whereNotNull('expiry_date')
            // Coarse pre-filter widened by a day so a lot sitting on a
            // non-default branch's day boundary isn't dropped before the
            // per-branch timezone check below (plan-040 TF.6).
            ->where('expiry_date', '>=', $referenceToday->copy()->subDay()->toDateString())
            ->chunkById(500, function ($lots) use (&$created, &$scanned, $today, $defaultTz, $referenceToday) {
                foreach ($lots as $lot) {
                    $scanned++;

                    // plan-040 TF.6 (M18): resolve "today" in the lot's branch
                    // timezone. An explicit `$today` (tests/manual) overrides.
                    $branchToday = $today !== null
                        ? $referenceToday
                        : Carbon::today($lot->warehouse?->branch?->timezone ?: $defaultTz);

                    $thresholds = $this->resolveThresholds($lot->material);
                    // Compare as calendar dates (re-parsed in the same tz) so a
                    // branch-tz midnight isn't offset by hours against the
                    // app-tz-parsed expiry — that drift mis-rounds daysUntil.
                    $branchTodayDate = Carbon::parse($branchToday->toDateString());
                    $expiryDate = Carbon::parse(Carbon::parse($lot->expiry_date)->toDateString());
                    $daysUntilExpiry = (int) $branchTodayDate->diffInDays($expiryDate, false);

                    foreach ($thresholds as $thresholdDays) {
                        if ($daysUntilExpiry !== $thresholdDays) {
                            continue;
                        }

                        $alert = ExpiryAlert::firstOrCreate(
                            [
                                'material_lot_id' => $lot->id,
                                'threshold_days' => $thresholdDays,
                            ],
                            [
                                'fired_at' => now(),
                            ]
                        );

                        if ($alert->wasRecentlyCreated) {
                            $created++;
                            $this->dispatchExpiryNotification($lot, $thresholdDays, $daysUntilExpiry);
                        }
                    }
                }
            });

        return [
            'alerts_created' => $created,
            'lots_scanned' => $scanned,
        ];
    }

    private function dispatchExpiryNotification(MaterialLot $lot, int $thresholdDays, int $daysUntilExpiry): void
    {
        try {
            $warehouse = $lot->warehouse;
            if ($warehouse === null) {
                return;
            }

            $brand = $this->brandForOrganization($lot->organization_id);
            if ($brand === null) {
                return;
            }

            $params = [
                'lot_code' => $lot->lot_code,
                'material_name' => $lot->material?->name ?? '(unknown)',
                'warehouse_name' => $warehouse->name ?? '(unknown)',
                'expiry_date' => $lot->expiry_date,
                'days_until_expiry' => $daysUntilExpiry,
                'threshold_days' => $thresholdDays,
            ];

            // plan-040 TF.1 (H14): người nhận = vai warehouse-manager trong phạm vi
            // kho của lô (trước là pluck `role_user_pivots` toàn org, cap 50 — sai
            // đối tượng + cắt cụt im lặng).
            $this->notifications->toRole(
                new NotificationRequest(
                    type: 'material_lot.expiring',
                    params: $params,
                    organizationId: (string) $lot->organization_id,
                    subject: $lot,
                    idempotencyKey: "material_lot.expiring:{$lot->id}:{$thresholdDays}",
                ),
                role: 'warehouse_manager',
                scopeKey: 'warehouse_id',
                scopeId: (string) $warehouse->getKey(),
                brand: $brand,
            );
        } catch (\Throwable $e) {
            \Log::warning('expiry-alert-notification: dispatch failed', [
                'lot_id' => $lot->id,
                'threshold' => $thresholdDays,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map an Organization to its Brand via the console_organization_id mirror —
     * mirrors StockAlertNotificationObserver so the role audience can resolve.
     */
    private function brandForOrganization(string $organizationId): ?Brand
    {
        $consoleOrgId = Organization::query()
            ->whereKey($organizationId)
            ->value('console_organization_id');

        return $consoleOrgId === null
            ? null
            : Brand::query()->where('console_organization_id', $consoleOrgId)->first();
    }

    /**
     * @return array<int, int>
     */
    private function resolveThresholds(?Material $material): array
    {
        if ($material === null) {
            return self::DEFAULT_THRESHOLDS;
        }

        $custom = $material->expiry_alert_thresholds;
        if (! is_array($custom) || $custom === []) {
            return self::DEFAULT_THRESHOLDS;
        }

        // plan-040 TF.5 (M17): keep 0 (expires-today) as a valid threshold.
        return array_values(array_filter(
            array_map('intval', $custom),
            fn (int $d) => $d >= 0,
        ));
    }
}
