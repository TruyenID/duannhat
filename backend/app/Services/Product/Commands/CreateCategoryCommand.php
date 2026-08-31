<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\CategoryPayload;

final readonly class CreateCategoryCommand extends MutationCommand
{
    public string $categoryId;

    public string $brandId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $categoryId, string $brandId, public CategoryPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        $this->categoryId = self::uuid($categoryId, 'categoryId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
