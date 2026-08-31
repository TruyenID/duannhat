<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\VariantUnitPayload;

final readonly class ReviseVariantUnitCommand extends MutationCommand
{
    public string $unitId;

    public string $brandId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $unitId, string $brandId, public VariantUnitPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        $this->unitId = self::uuid($unitId, 'unitId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
