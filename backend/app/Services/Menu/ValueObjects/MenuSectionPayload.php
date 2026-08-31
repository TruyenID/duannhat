<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class MenuSectionPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $sectionId;

    public string $name;

    /** @var list<MenuItemPayload> */
    public array $items;

    /** @param list<MenuItemPayload> $items */
    public function __construct(string $sectionId, string $name, public int $position, array $items)
    {
        if ($position < 0) {
            throw new InvalidArgumentException('position cannot be negative.');
        }

        foreach ($items as $item) {
            if (! $item instanceof MenuItemPayload) {
                throw new InvalidArgumentException('items must contain MenuItemPayload values.');
            }
        }

        $this->sectionId = MutationCommand::uuid($sectionId, 'sectionId');
        $this->name = MutationCommand::safeToken($name, 'name', 255);
        $this->items = MutationCommand::canonicalSet($items, static fn (MenuItemPayload $item): string => $item->productId.'|'.($item->skuId ?? ''), 'items');
    }

    public function jsonSerialize(): array
    {
        return ['section_id' => $this->sectionId, 'name' => $this->name, 'position' => $this->position, 'items' => $this->items];
    }
}
