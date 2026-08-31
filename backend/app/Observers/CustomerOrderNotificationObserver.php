<?php

namespace App\Observers;

use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\NotificationRule;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;

/**
 * Fires `order.status_changed` on every CustomerOrder status transition.
 * Silent-fail — notification failures never break order mutations.
 *
 * Plan-023 M1: recipient resolution is `Audience::byRole('shop-manager')
 * ->scopedTo($order->branch)`. The pre-M1 cap-20 union fallback was removed
 * in T1.3, and the frozen copy kept for replaying it is gone too (#2413) —
 * production measurement showed the only divergence left was the old
 * resolver ignoring branch scope, not a risk in the engine.
 *
 * No-op when the order has no branch OR the brand cannot be resolved —
 * there is no usable scope for an audience.
 */
class CustomerOrderNotificationObserver
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function updated(CustomerOrder $order): void
    {
        // Plan-023 M7 T7.13 — see StockAlertNotificationObserver. The
        // seeded system rule for order status changes takes over once
        // NOTIFICATION_USE_RULES=true, but only when a live rule with a
        // resolvable audience actually covers this emitter — otherwise the
        // emitter keeps working so the flag can't create a coverage gap.
        if (config('notifications.use_rules')
            && NotificationRule::hasActiveCoverage('CustomerOrder', 'model.updated', (string) $order->organization_id)) {
            return;
        }

        if (! $order->wasChanged('status')) {
            return;
        }

        try {
            if ($order->branch === null) {
                return;
            }

            $brand = $this->brandForOrganization($order->organization_id);
            if ($brand === null) {
                return;
            }

            $newStatus = $order->status instanceof \BackedEnum
                ? $order->status->value
                : (string) $order->status;

            $params = [
                'order_code' => $order->order_code ?? $order->id,
                'new_status' => $newStatus,
                'shop_name' => $order->branch->name ?? '(unknown)',
            ];
            $actor = auth()->user() instanceof User ? auth()->user() : null;

            $this->notifications->toRole(
                new NotificationRequest(
                    type: 'order.status_changed',
                    params: $params,
                    organizationId: (string) $order->organization_id,
                    actor: $actor,
                    subject: $order,
                    idempotencyKey: "order.status_changed:{$order->id}:{$newStatus}",
                    // Plan-023 M5 T5.11 — gộp các lần đổi cùng trạng thái trong
                    // cùng chi nhánh thành một dòng chuông mỗi lần lật.
                    aggregationKey: "order.status_changed:branch:{$order->branch_id}:status:{$newStatus}",
                ),
                // #2450 — đơn hàng thuộc về quản lý quán, VÀ về admin tổ chức.
                // Không phải luật "cấp cao hơn nhận hết" (thế thì admin lãnh cả
                // thông báo dành cho shop-staff); đây là một quyết định riêng
                // cho SỰ KIỆN này, và nó khớp với tầng policy — `ChecksShopContext
                // ::isShopManager()` đã coi org-admin là quản lý quán từ trước.
                //
                // Vì sao cần: Platform cấp vai theo `service_role`, và ở một
                // doanh nghiệp chỉ có chủ quán thì người duy nhất là `admin`.
                // Đo trên production 2026-08-11 sau khi vá #2460: `org-admin` ở
                // một chi nhánh ra 1 người, `shop-manager` ra 0 — nên chỉ hỏi
                // shop-manager là không bao giờ báo cho ai.
                role: ['shop-manager', 'org-admin'],
                scopeKey: 'branch_id',
                scopeId: (string) $order->branch->getKey(),
                brand: $brand,
            );
        } catch (\Throwable $e) {
            \Log::warning('order-notification: dispatch failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

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
