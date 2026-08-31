<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\VariantUnitPayload;

final readonly class CreateVariantUnitCommand extends MutationCommand
{
    public string $unitId;

    public string $productSkuId;

    public string $brandId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $unitId, string $productSkuId, string $brandId, public VariantUnitPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        $this->unitId = self::uuid($unitId, 'unitId');
        $this->productSkuId = self::uuid($productSkuId, 'productSkuId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
