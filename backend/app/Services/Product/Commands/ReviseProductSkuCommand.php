<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\ProductSkuPayload;

final readonly class ReviseProductSkuCommand extends MutationCommand
{
    public string $brandId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $brandId, public ProductSkuPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
