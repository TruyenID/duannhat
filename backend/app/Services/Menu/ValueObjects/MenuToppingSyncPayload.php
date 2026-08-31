<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class MenuToppingSyncPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /** @var list<MenuToppingOverridePayload> */
    public array $overrides;

    public function __construct(array $overrides)
    {
        foreach ($overrides as $override) {
            if (! $override instanceof MenuToppingOverridePayload) {
                throw new \InvalidArgumentException('overrides must contain MenuToppingOverridePayload values.');
            }
        }$this->overrides = MutationCommand::canonicalSet($overrides, static fn (MenuToppingOverridePayload $o): string => $o->toppingGroupId.'|'.$o->itemId.'|'.($o->skuId ?? ''), 'overrides');
    }

    public function jsonSerialize(): array
    {
        return ['overrides' => $this->overrides];
    }
}
