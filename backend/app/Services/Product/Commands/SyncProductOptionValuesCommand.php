<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\ProductOptionPayload;

final readonly class SyncProductOptionValuesCommand extends MutationCommand
{
    public string $brandId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $brandId, public ProductOptionPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        if ($payload->values === []) {
            throw new \InvalidArgumentException('Option value sync requires values.');
        }
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
