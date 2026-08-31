<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\ProductTypePayload;

final readonly class ReviseProductTypeCommand extends MutationCommand
{
    public string $productTypeId;

    public string $brandId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $productTypeId, string $brandId, public ProductTypePayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        $this->productTypeId = self::uuid($productTypeId, 'productTypeId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
