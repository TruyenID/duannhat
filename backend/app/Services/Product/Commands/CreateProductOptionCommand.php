<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\ProductOptionPayload;

final readonly class CreateProductOptionCommand extends MutationCommand
{
    public string $productId;

    public string $brandId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $productId, string $brandId, public ProductOptionPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        $this->productId = self::uuid($productId, 'productId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
