<?php

namespace App\Services\Product\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use InvalidArgumentException;

final readonly class ProductImportPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /** @var non-empty-list<ProductImportRow> */
    public array $rows;

    /** @param list<ProductImportRow> $rows */
    public function __construct(array $rows)
    {
        if ($rows === []) {
            throw new InvalidArgumentException('Product import requires at least one row.');
        }

        $rowNumbers = [];
        $productIds = [];

        foreach ($rows as $row) {
            if (! $row instanceof ProductImportRow) {
                throw new InvalidArgumentException('rows must contain ProductImportRow values.');
            }

            if (isset($rowNumbers[$row->rowNumber])) {
                throw new InvalidArgumentException('Import row numbers must be unique.');
            }
            if (isset($productIds[$row->productId])) {
                throw new InvalidArgumentException('Import product IDs must be unique.');
            }

            $rowNumbers[$row->rowNumber] = true;
            $productIds[$row->productId] = true;
        }

        $rows = array_values($rows);
        usort($rows, static fn (ProductImportRow $left, ProductImportRow $right): int => $left->rowNumber <=> $right->rowNumber);
        $this->rows = $rows;
    }

    public function jsonSerialize(): array
    {
        return ['rows' => $this->rows];
    }
}
