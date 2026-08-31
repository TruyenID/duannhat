<?php

/**
 * OrderPayment Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Enums\PaymentStatusEnum;
use App\Omnify\Modules\OrderPayment\Models\OrderPaymentBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\OrderPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * OrderPayment — add project-specific model logic here.
 */
class OrderPayment extends OrderPaymentBaseModel
{
    use AuditsActivity;
    use HasFactory;

    /**
     * Additional fillable attributes not in the Omnify base model.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->fillable = array_merge($this->fillable, [
            'expires_at',
            'idempotency_key',
            // #1156 — brand-level tender attribution (till_tender_types.tender_key).
            'tender_key',
        ]);
    }

    /**
     * Additional casts not in the Omnify base model.
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'expires_at' => 'datetime',
        ]);
    }

    /**
     * Money this order still holds, net of refunds, in currency units.
     *
     * A refund is TWO rows: the original keeps its +X and flips to `refunded`,
     * plus a new -X `succeeded` row. Both statuses must be summed so the pair
     * nets to 0.
     *
     * Summing `succeeded` ALONE drops the +X original and keeps the -X refund
     * row, so every refunded payment contributes -X instead of 0 — a phantom
     * credit that cancels out OTHER payments' real cash. That is #816, which
     * false-closed #547: refund one diner on a split bill and the guard reads
     * 0 while the other diner's cash is still in the drawer.
     *
     * Settlement payments (`metadata.settles_payment_id`) are excluded: they
     * are pinned to a DIFFERENT order's debt, so they must neither block nor
     * inflate the order they ride on. Same filter as
     * OrderPaymentService::updateOrderPaymentCache(), which is the source of
     * truth for `customer_orders.paid_amount`.
     */
    public static function netCollectedForOrder(string $orderId): float
    {
        return (float) static::query()
            ->where('customer_order_id', $orderId)
            ->whereNull('metadata->settles_payment_id')
            ->whereIn('status', [
                PaymentStatusEnum::Succeeded->value,
                PaymentStatusEnum::Refunded->value,
            ])
            ->sum('amount');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): OrderPaymentFactory
    {
        return OrderPaymentFactory::new();
    }
}
