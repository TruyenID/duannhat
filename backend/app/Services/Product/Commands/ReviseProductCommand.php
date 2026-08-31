<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\RevisionPayloadMode;
use App\Services\Product\ValueObjects\ProductPayload;

final readonly class ReviseProductCommand extends MutationCommand
{
    public string $productId;

    public string $brandId;

    public string $revisionFingerprint;

    public function __construct(MutationContext $context, string $productId, string $brandId, public ProductPayload $payload, string $revisionFingerprint, public RevisionPayloadMode $mode = RevisionPayloadMode::FullReplacement)
    {
        parent::__construct($context);
        $this->productId = self::uuid($productId, 'productId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->revisionFingerprint = self::verifiedFingerprint($revisionFingerprint, 'revisionFingerprint', $payload);
    }
}
