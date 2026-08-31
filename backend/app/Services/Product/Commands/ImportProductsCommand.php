<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\ValueObjects\ProductImportPayload;

final readonly class ImportProductsCommand extends MutationCommand
{
    public string $brandId;

    public string $sourceName;

    public string $sourceFingerprint;

    public function __construct(
        MutationContext $context,
        string $brandId,
        string $sourceName,
        public ProductImportPayload $payload,
        string $sourceFingerprint,
        public bool $dryRun = false,
    ) {
        parent::__construct($context);
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->sourceName = self::safeToken($sourceName, 'sourceName', 128);
        $this->sourceFingerprint = self::verifiedFingerprint($sourceFingerprint, 'sourceFingerprint', $payload);
    }
}
