<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\VerifiedObjectRegistry;
use App\Services\Order\ValueObjects\TrustedOrderSnapshot;

/** Internal trust-boundary command emitted only after catalog, tax, and promotion resolution. */
final readonly class PersistResolvedOrderCommand extends MutationCommand implements \JsonSerializable
{
    public string $orderId;

    public string $branchId;

    public string $snapshotFingerprint;

    private function __construct(MutationContext $context, string $orderId, string $branchId, public TrustedOrderSnapshot $snapshot, string $snapshotFingerprint)
    {
        parent::__construct($context);
        $snapshot->assertTrusted();
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->branchId = self::uuid($branchId, 'branchId');
        if ($this->orderId !== $snapshot->orderId
            || $this->branchId !== $snapshot->branchId
            || $context->organizationId !== $snapshot->organizationId
            || $context->actorId !== $snapshot->actorId
            || $context->correlationId !== $snapshot->correlationId
            || $context->idempotencyKeyHash !== $snapshot->idempotencyKeyHash
            || $context->expectedVersion !== $snapshot->expectedVersion) {
            throw new \InvalidArgumentException('Resolved order persistence identity does not match the trusted snapshot request.');
        }
        $this->snapshotFingerprint = self::verifiedFingerprint($snapshotFingerprint, 'snapshotFingerprint', $snapshot);
    }

    public static function fromTrustedSnapshot(MutationContext $context, string $orderId, string $branchId, TrustedOrderSnapshot $snapshot, string $snapshotFingerprint): self
    {
        $command = new self($context, $orderId, $branchId, $snapshot, $snapshotFingerprint);
        VerifiedObjectRegistry::derive($command, $snapshot, 'order.trusted_snapshot', 'order.persist_resolved');

        return $command;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'order.persist_resolved');
    }

    public function jsonSerialize(): array
    {
        return [
            'context' => $this->context,
            'order_id' => $this->orderId,
            'branch_id' => $this->branchId,
            'snapshot' => $this->snapshot,
            'snapshot_fingerprint' => $this->snapshotFingerprint,
        ];
    }
}
