<?php

namespace App\Services\Product\Results;

use InvalidArgumentException;

final readonly class ProductImportResult
{
    /** @var non-empty-list<ProductImportRowResult> */
    public array $rows;

    /** @param list<ProductImportRowResult> $rows */
    public function __construct(array $rows)
    {
        if ($rows === []) {
            throw new InvalidArgumentException('Import result requires row outcomes.');
        }

        $rowNumbers = [];

        foreach ($rows as $row) {
            if (! $row instanceof ProductImportRowResult) {
                throw new InvalidArgumentException('rows must contain ProductImportRowResult values.');
            }

            if (isset($rowNumbers[$row->rowNumber])) {
                throw new InvalidArgumentException('Import result row numbers must be unique.');
            }

            $rowNumbers[$row->rowNumber] = true;
        }

        $this->rows = array_values($rows);
    }
}
