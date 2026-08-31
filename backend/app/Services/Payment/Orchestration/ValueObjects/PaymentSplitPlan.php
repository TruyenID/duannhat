<?php

namespace App\Services\Payment\Orchestration\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\Order\Enums\OrderSplitMode;

final readonly class PaymentSplitPlan implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /** @var non-empty-list<PaymentAllocationPayload> */
    public array $allocations;

    public function __construct(public OrderSplitMode $strategy, public int $expectedSubtotalMinor, public int $expectedTotalMinor, array $allocations)
    {
        if ($expectedSubtotalMinor < 0 || $expectedTotalMinor < 1 || $expectedSubtotalMinor > $expectedTotalMinor || $allocations === []) {
            throw new \InvalidArgumentException('Split plan totals and allocations are invalid.');
        }
        foreach ($allocations as $allocation) {
            if (! $allocation instanceof PaymentAllocationPayload) {
                throw new \InvalidArgumentException('allocations must contain PaymentAllocationPayload values.');
            }
        }
        $this->allocations = MutationCommand::canonicalSet($allocations, static fn (PaymentAllocationPayload $allocation): string => $allocation->allocationId, 'allocations');
        if (array_sum(array_map(static fn (PaymentAllocationPayload $allocation): int => $allocation->amountMinor, $this->allocations)) !== $expectedTotalMinor) {
            throw new \InvalidArgumentException('Split allocations must equal the expected total.');
        }
        foreach ($this->allocations as $allocation) {
            if ($strategy === OrderSplitMode::Even && $allocation->personIndex === null) {
                throw new \InvalidArgumentException('Per-person splits require a person index.');
            }
            if ($strategy === OrderSplitMode::ByItems && $allocation->orderItemIds === []) {
                throw new \InvalidArgumentException('Item splits require exact order item identities.');
            }
            if ($strategy !== OrderSplitMode::ByItems && $allocation->orderItemIds !== []) {
                throw new \InvalidArgumentException('Only item splits may carry order item identities.');
            }
        }
        // #2860 — `Even` gộp hai case cũ `Equal` + `ByPeople`, nên nó mang CẢ HAI
        // bất biến, không phải union lỏng hơn. Hai case ấy chưa bao giờ khác
        // nhau về ý nghĩa: một allocation là một SUẤT của một người, nên "chỉ số
        // người là tập duy nhất" và "các suất chỉ lệch nhau phần dư làm tròn" là
        // hai cách nói cùng một điều. Người trả hộ hai suất thì ghi hai
        // allocation, không phải một allocation gấp đôi.
        if ($strategy === OrderSplitMode::Even) {
            $amounts = array_map(static fn (PaymentAllocationPayload $allocation): int => $allocation->amountMinor, $this->allocations);
            if (max($amounts) - min($amounts) > 1) {
                throw new \InvalidArgumentException('Even split allocations may differ only by the rounding remainder.');
            }
            MutationCommand::canonicalSet($this->allocations, static fn (PaymentAllocationPayload $allocation): string => (string) $allocation->personIndex, 'person allocations');
        }
        if ($strategy === OrderSplitMode::ByItems) {
            $seenItems = [];
            foreach ($this->allocations as $allocation) {
                foreach ($allocation->orderItemIds as $itemId) {
                    if (isset($seenItems[$itemId])) {
                        throw new \InvalidArgumentException('An order item cannot belong to multiple split allocations.');
                    }
                    $seenItems[$itemId] = true;
                }
            }
        }
    }

    public function jsonSerialize(): array
    {
        return ['strategy' => $this->strategy->value, 'expected_subtotal_minor' => $this->expectedSubtotalMinor, 'expected_total_minor' => $this->expectedTotalMinor, 'allocations' => $this->allocations];
    }
}
