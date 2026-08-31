<?php

namespace App\Services\Product\Results;

use App\Services\DomainMutation\MutationCommand;
use JsonSerializable;

final readonly class ProductSkuGenerationResult implements JsonSerializable
{
    public array $skuIds;

    public function __construct(array $skuIds)
    {
        $this->skuIds = array_map(static fn (string $id): string => MutationCommand::uuid($id, 'skuId'), array_values($skuIds));
    }

    public function jsonSerialize(): array
    {
        return ['sku_ids' => $this->skuIds];
    }
}
