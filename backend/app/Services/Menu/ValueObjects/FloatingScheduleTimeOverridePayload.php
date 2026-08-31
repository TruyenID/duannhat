<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;

final readonly class FloatingScheduleTimeOverridePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public function __construct(public int $daysOfWeek, public string $startTime, public string $endTime)
    {
        if ($daysOfWeek < 1 || $daysOfWeek > 127 || $endTime <= $startTime) {
            throw new \InvalidArgumentException('Floating schedule time override is invalid.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
