<?php

namespace App\Services\Product\Results;

use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class ProductImportRowResult
{
    public ?string $productId;

    public ?string $errorCode;

    public function __construct(public int $rowNumber, ?string $productId, public bool $imported, ?string $errorCode)
    {
        if ($rowNumber < 1 || ($imported && ($productId === null || $errorCode !== null)) || (! $imported && $errorCode === null)) {
            throw new InvalidArgumentException('Import row outcome is inconsistent.');
        }

        $this->productId = $productId === null ? null : MutationCommand::uuid($productId, 'productId');
        $this->errorCode = $errorCode === null ? null : MutationCommand::safeToken($errorCode, 'errorCode', 100);
    }
}
