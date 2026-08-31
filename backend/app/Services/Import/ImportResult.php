<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * DTO representing the result of a CSV import operation.
 *
 * @property-read int $successCount
 * @property-read int $errorCount
 * @property-read int $createdCount
 * @property-read int $updatedCount
 * @property-read array<int, array{row: int, errors: array<string>}> $errors
 */
final readonly class ImportResult
{
    /**
     * @param  array<int, array{row: int, errors: array<string>}>  $errors
     */
    public function __construct(
        public int $successCount = 0,
        public int $errorCount = 0,
        public int $createdCount = 0,
        public int $updatedCount = 0,
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errorCount > 0;
    }

    public function isCompleteFailure(): bool
    {
        return $this->successCount === 0 && $this->errorCount > 0;
    }

    /**
     * @return array{success_count: int, error_count: int, created_count: int, updated_count: int, errors: array<int, array{row: int, errors: array<string>}>}
     */
    public function toArray(): array
    {
        return [
            'success_count' => $this->successCount,
            'error_count' => $this->errorCount,
            'created_count' => $this->createdCount,
            'updated_count' => $this->updatedCount,
            'errors' => $this->errors,
        ];
    }
}
