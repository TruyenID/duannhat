<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class FloatingProductOrderPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /** @var list<string> */
    public array $menuProductIds;

    /** @param list<string> $menuProductIds */
    public function __construct(array $menuProductIds)
    {
        if ($menuProductIds === []) {
            throw new \InvalidArgumentException('Floating product order cannot be empty.');
        }
        $this->menuProductIds = MutationCommand::uniqueOrdered(array_map(static fn (string $id): string => MutationCommand::uuid($id, 'menuProductId'), $menuProductIds), static fn (string $id): string => $id, 'menuProductIds');
    }

    public function jsonSerialize(): array
    {
        return ['menu_product_ids' => $this->menuProductIds];
    }
}
