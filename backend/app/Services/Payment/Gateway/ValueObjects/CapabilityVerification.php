<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use App\Services\Payment\Gateway\Enums\CapabilityVerificationState;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class CapabilityVerification implements JsonSerializable
{
    /** @var list<CapabilityEvidence> */
    public array $evidence;

    /** @param list<CapabilityEvidence> $evidence */
    public function __construct(public CapabilityVerificationState $state, array $evidence)
    {
        foreach ($evidence as $item) {
            if (! $item instanceof CapabilityEvidence) {
                throw new InvalidArgumentException('Capability evidence contains an invalid value.');
            }
        }

        if ($state === CapabilityVerificationState::Verified && $evidence === []) {
            throw new InvalidArgumentException('A verified capability requires immutable evidence.');
        }

        $this->evidence = array_values($evidence);
    }

    /** @return array{state: string, evidence: list<CapabilityEvidence>} */
    public function jsonSerialize(): array
    {
        return ['state' => $this->state->value, 'evidence' => $this->evidence];
    }

    public function hasApplicableEvidence(DateTimeImmutable $operationStartedAt): bool
    {
        foreach ($this->evidence as $evidence) {
            if ($operationStartedAt >= $evidence->certifiedAt && $operationStartedAt < $evidence->reviewAt) {
                return true;
            }
        }

        return false;
    }
}
