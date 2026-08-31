<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\DomainMutation\VerifiedObjectRegistry;
use App\Services\Order\Contracts\OrderPricingResolutionPort;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\ValueObjects\OrderLinePayload;

/** Internal command emitted after menu, pricing, tax and promotion resolution. */
final readonly class PersistResolvedOrderItemsCommand extends MutationCommand
{
    public string $orderId;

    public ?string $itemId;

    public string $resolvedLineFingerprint;

    private function __construct(MutationContext $context, string $orderId, public OrderItemMutation $operation, public OrderLinePayload $resolvedLine, string $resolvedLineFingerprint, ?string $itemId = null)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::nullableUuid($itemId, 'itemId');
        $this->resolvedLineFingerprint = self::verifiedFingerprint($resolvedLineFingerprint, 'resolvedLineFingerprint', $resolvedLine);
    }

    public static function fromPricingResolver(OrderPricingResolutionPort $resolver, VerificationAuthority $authority, MutationContext $context, string $orderId, OrderItemMutation $operation, OrderLinePayload $resolvedLine, string $resolvedLineFingerprint, ?string $itemId = null): self
    {
        $command = new self($context, $orderId, $operation, $resolvedLine, $resolvedLineFingerprint, $itemId);
        VerifiedObjectRegistry::seal($command, $resolver, $authority, 'order.persist_resolved_items', OrderPricingResolutionPort::class);

        return $command;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'order.persist_resolved_items');
    }
}
