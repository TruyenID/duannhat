<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\ProductOptionValuePayload;

final readonly class CreateProductOptionValueCommand extends MutationCommand
{
    public string $optionId;

    public string $brandId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $optionId, string $brandId, public ProductOptionValuePayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        $this->optionId = self::uuid($optionId, 'optionId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
