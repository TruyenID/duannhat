<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\ProductPayload;

final readonly class CreateProductCommand extends MutationCommand
{
    public string $productId;

    public string $brandId;

    public string $definitionFingerprint;

    public function __construct(MutationContext $context, string $productId, string $brandId, public ProductPayload $payload, string $definitionFingerprint)
    {
        parent::__construct($context);
        $this->productId = self::uuid($productId, 'productId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->definitionFingerprint = self::verifiedFingerprint($definitionFingerprint, 'definitionFingerprint', $payload);
    }
}
