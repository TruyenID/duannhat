<?php

namespace App\Services\Kds;

use App\Exceptions\KdsRuleViolation;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Order\ValueObjects\ActingDeviceTenancy;
use BackedEnum;
use Illuminate\Support\Facades\Cache;

class KdsBusinessRules
{
    private const THROTTLE_TTL_SECONDS = 3;

    private const MARK_SERVED_MIN_SECONDS_AFTER_READY = 30;

    public function assertOrderActive(CustomerOrder $order): void
    {
        $status = $this->resolveStatusValue($order->status);
        $finalized = [
            CustomerOrderStatusEnum::Voided->value,
            CustomerOrderStatusEnum::Closed->value,
        ];
        if (in_array($status, $finalized, true)) {
            throw new KdsRuleViolation(
                'KDS_E001',
                "Đơn này đã đóng (status={$status}), không thể bump item.",
                [
                    'order_id' => $order->id,
                    'order_status' => $status,
                    'finalized_at' => $order->updated_at?->toIso8601String(),
                ],
            );
        }
    }

    /**
     * KDS_E002 — forward-only precondition for a mark-* operation.
     *
     * The underlying CustomerOrderService allows free transitions between any
     * active statuses, so without this guard `mark-preparing` could silently
     * drag a `ready`/`served` item backward, and `mark-ready` could skip
     * `preparing` or resurrect a `served` item. Each operation must therefore
     * assert the item is currently in the exact predecessor status the client
     * contract (`allowed_transitions`) advertises:
     *   pending → mark-preparing, preparing → mark-ready, ready → mark-served.
     */
    public function assertForwardTransition(CustomerOrderItem $item, OrderItemStatusEnum $expectedFrom, OrderItemStatusEnum $to): void
    {
        $current = $this->resolveStatusValue($item->status);
        if ($current !== $expectedFrom->value) {
            throw new KdsRuleViolation(
                'KDS_E002',
                "Invalid transition: item is {$current}, must be {$expectedFrom->value} before {$to->value}.",
                [
                    'item_id' => $item->id,
                    'current_status' => $current,
                    'expected_status' => $expectedFrom->value,
                    'target_status' => $to->value,
                ],
            );
        }
    }

    public function assertMarkServedAllowed(CustomerOrderItem $item): void
    {
        if ($item->ready_at === null) {
            throw new KdsRuleViolation(
                'KDS_E002',
                'Item chưa ready, không thể mark-served. Mark-ready trước.',
                ['item_id' => $item->id, 'item_status' => $this->resolveStatusValue($item->status)],
            );
        }
        $elapsed = $item->ready_at->diffInSeconds(now());
        if ($elapsed < self::MARK_SERVED_MIN_SECONDS_AFTER_READY) {
            throw new KdsRuleViolation(
                'KDS_E003',
                "Vừa mark-ready {$elapsed}s trước, đợi đủ ".self::MARK_SERVED_MIN_SECONDS_AFTER_READY.'s.',
                [
                    'item_id' => $item->id,
                    'ready_at' => $item->ready_at->toIso8601String(),
                    'elapsed_seconds' => $elapsed,
                ],
            );
        }
    }

    public function assertNotThrottled(CustomerOrderItem $item, string $deviceId): void
    {
        $key = "kds:throttle:{$item->id}:{$deviceId}";
        if (Cache::has($key)) {
            throw new KdsRuleViolation(
                'KDS_E005',
                'Quá nhanh, đợi '.self::THROTTLE_TTL_SECONDS.'s.',
                [
                    'item_id' => $item->id,
                    'device_id' => $deviceId,
                    'retry_after_seconds' => self::THROTTLE_TTL_SECONDS,
                ],
            );
        }
        Cache::put($key, 1, self::THROTTLE_TTL_SECONDS);
    }

    /**
     * #962 — nhận `ActingDeviceTenancy` (org + branch của thiết bị) thay cho
     * `App\Models\Device`. Quy tắc này chưa bao giờ cần gì hơn hai chuỗi đó, và
     * type-hint model của module khác là toàn bộ cạnh Ordering→PlatformIntegration.
     */
    public function assertBranchOwnership(CustomerOrder $order, ActingDeviceTenancy $device): void
    {
        // #845 defense-in-depth: an order belongs to the device only when BOTH
        // organization and branch match. Scoping by branch alone let a device
        // paired to a cross-tenant branch mutate that tenant's orders.
        if ((string) $order->branch_id !== (string) $device->branchId
            || (string) $order->organization_id !== (string) $device->organizationId) {
            throw new KdsRuleViolation(
                'KDS_E006',
                'Đơn không thuộc branch của KDS device.',
                [
                    'order_id' => $order->id,
                    'order_branch_id' => (string) $order->branch_id,
                    'device_branch_id' => (string) $device->branchId,
                    'order_organization_id' => (string) $order->organization_id,
                    'device_organization_id' => (string) $device->organizationId,
                ],
            );
        }
    }

    /**
     * Block parent-item ready transition when any of its toppings are still
     * unready (not in 'ready' or 'served'). Returns silently for items with
     * no toppings.
     */
    public function assertToppingsParentReady(CustomerOrderItem $item): void
    {
        $toppings = $item->orderItemToppings ?? collect();
        if ($toppings->isEmpty()) {
            return;
        }
        $unready = $toppings->filter(function ($t) {
            $status = $this->resolveStatusValue($t->status ?? '');

            return ! in_array($status, ['ready', 'served'], true);
        })->values();
        if ($unready->isNotEmpty()) {
            throw new KdsRuleViolation(
                'KDS_E004',
                'Cannot mark parent ready while toppings are not ready.',
                [
                    'unready_topping_count' => $unready->count(),
                    'unready_topping_ids' => $unready->pluck('id')->all(),
                ],
            );
        }
    }

    private function resolveStatusValue(mixed $status): string
    {
        return $status instanceof BackedEnum ? $status->value : (string) $status;
    }
}
