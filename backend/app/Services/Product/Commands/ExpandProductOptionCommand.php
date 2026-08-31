<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\ProductOptionPayload;

final readonly class ExpandProductOptionCommand extends MutationCommand
{
    public string $productId;

    public string $brandId;

    public string $defaultValueId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $productId, string $brandId, public ProductOptionPayload $payload, string $defaultValueId, public bool $generateCombinations, string $payloadFingerprint)
    {
        parent::__construct($context);
        $this->productId = self::uuid($productId, 'productId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->defaultValueId = self::uuid($defaultValueId, 'defaultValueId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
