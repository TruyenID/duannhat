<?php

namespace App\Observers;

use App\Models\Brand;
use App\Models\NotificationRule;
use App\Models\Organization;
use App\Models\StockAlert;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;

/**
 * Fires a `stock.alert.low` / `stock.alert.out` notification when a new
 * StockAlert row is created. Silent-fail on any throwable — notification
 * failures must never block the inventory write.
 *
 * Plan-023 M1: recipient resolution is `Audience::byRole('warehouse_manager')
 * ->scopedTo($warehouse)`. The pre-M1 cap-50 union fallback was removed in
 * T1.3, and the frozen copy kept for replaying it is gone too (#2413) — the
 * rollout audit it fed had nothing left to say: 0 diverging stock_alert rows
 * on production over 30 days.
 *
 * If the alert has no warehouse OR the brand cannot be resolved, the
 * observer silently no-ops — there is no usable scope for an audience.
 */
class StockAlertNotificationObserver
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function created(StockAlert $alert): void
    {
        // Plan-023 M7 T7.13 — when the rule engine has been flipped on
        // in prod (after the parallel-shadow parity gate), the seeded
        // system rule for stock alerts takes over the dispatch path.
        // Short-circuit here so we don't double-send — but ONLY when an
        // active rule with a resolvable audience actually covers this
        // emitter. Otherwise flipping the flag would silently disable a
        // working emitter with no live replacement (plan-023 audit crit).
        if (config('notifications.use_rules')
            && NotificationRule::hasActiveCoverage('StockAlert', 'model.created', (string) $alert->organization_id)) {
            return;
        }

        try {
            $alertTypeValue = $alert->alert_type instanceof \BackedEnum
                ? $alert->alert_type->value
                : (string) $alert->alert_type;
            $type = $alertTypeValue === 'out_of_stock'
                ? 'stock.alert.out'
                : 'stock.alert.low';

            if ($alert->warehouse === null) {
                return;
            }

            $brand = $this->brandForOrganization($alert->organization_id);
            if ($brand === null) {
                return;
            }

            // Plan-024 — `min_stock` is nullable on StockAlert when the
            // alert was fired by the allow-negative shortage path on a
            // StockLevel with no configured threshold. Coercing null to
            // 0.0 would render misleading "Min: 0" copy; omit instead.
            $params = [
                'warehouse_name' => $alert->warehouse->name ?? '(unknown)',
                'item_name' => $alert->productSku?->name
                    ?? $alert->material?->name
                    ?? '(unknown item)',
                'current_quantity' => (float) $alert->current_quantity,
            ];
            if ($alert->min_stock !== null) {
                $params['min_stock'] = (float) $alert->min_stock;
            }

            $this->notifications->toRole(
                new NotificationRequest(
                    type: $type,
                    params: $params,
                    organizationId: (string) $alert->organization_id,
                    subject: $alert,
                    idempotencyKey: "{$type}:{$alert->id}",
                    // Plan-023 M5 T5.11 — khoá gộp hộp thư. Gộp mọi cảnh báo
                    // low/out của một kho thành MỘT dòng chuông, để kho 30 nguyên
                    // liệu không ping 30 lần.
                    aggregationKey: "{$type}:warehouse:{$alert->warehouse_id}",
                ),
                role: 'warehouse_manager',
                scopeKey: 'warehouse_id',
                scopeId: (string) $alert->warehouse->getKey(),
                brand: $brand,
            );
        } catch (\Throwable $e) {
            \Log::warning('stock-alert-notification: dispatch failed', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map an Organization to its Brand via the console_organization_id
     * mirror. Returns the first brand — emitters in this codebase are
     * single-brand-per-org-scoped.
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
}
