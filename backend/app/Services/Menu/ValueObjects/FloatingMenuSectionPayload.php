<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class FloatingMenuSectionPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $name;

    public ?string $branchId;

    public function __construct(string $name, public int $position, public bool $active = true, ?string $branchId = null, public ?string $startDate = null, public ?string $endDate = null)
    {
        $this->name = MutationCommand::safeToken($name, 'name', 255);
        $this->branchId = MutationCommand::nullableUuid($branchId, 'branchId');
        if ($position < 0) {
            throw new \InvalidArgumentException('position cannot be negative.');
        }
        if (($startDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1)
            || ($endDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1)
            || ($startDate !== null && $endDate !== null && $startDate > $endDate)) {
            throw new \InvalidArgumentException('Floating section date window is invalid.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
