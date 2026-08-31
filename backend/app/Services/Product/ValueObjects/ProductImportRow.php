<?php

namespace App\Services\Product\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class ProductImportRow implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $productId;

    public function __construct(public int $rowNumber, string $productId, public ProductPayload $payload)
    {
        if ($rowNumber < 1) {
            throw new InvalidArgumentException('rowNumber must be positive.');
        }

        $this->productId = MutationCommand::uuid($productId, 'productId');
    }

    public function jsonSerialize(): array
    {
        return ['row_number' => $this->rowNumber, 'product_id' => $this->productId, 'payload' => $this->payload];
    }
}
