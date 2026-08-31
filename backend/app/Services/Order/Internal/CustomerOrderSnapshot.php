<?php

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Order\Contracts\OrderSnapshot;
use Carbon\CarbonInterface;

/** Immutable projection of a CustomerOrder row for consumers outside Ordering (#1544). */
final class CustomerOrderSnapshot implements OrderSnapshot
{
    private function __construct(
        private readonly string $id,
        private readonly string $organizationId,
        private readonly string $branchId,
        private readonly ?string $brandId,
        private readonly string $orderCode,
        private readonly string $status,
        private readonly float $totalAmount,
        private readonly float $paidAmount,
        private readonly int $version,
        private readonly ?CarbonInterface $paymentDueAt,
    ) {}

    public static function fromModel(CustomerOrder $order): self
    {
        return new self(
            (string) $order->id,
            (string) $order->organization_id,
            (string) $order->branch_id,
            $order->brand_id === null ? null : (string) $order->brand_id,
            (string) $order->order_code,
            (string) ($order->status instanceof \BackedEnum ? $order->status->value : $order->status),
            (float) $order->total_amount,
            (float) $order->paid_amount,
            (int) ($order->version ?? 0),
            $order->payment_due_at,
        );
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function branchId(): string
    {
        return $this->branchId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function brandId(): ?string
    {
        return $this->brandId;
    }

    public function orderCode(): string
    {
        return $this->orderCode;
    }

    public function totalAmount(): float
    {
        return $this->totalAmount;
    }

    public function paidAmount(): float
    {
        return $this->paidAmount;
    }

    public function paymentDueAt(): ?CarbonInterface
    {
        return $this->paymentDueAt;
    }
}
