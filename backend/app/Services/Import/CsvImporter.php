<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abstract base for CSV import operations.
 *
 * Subclasses implement processRow() and getRequiredColumns() to define
 * entity-specific import behaviour. The base handles CSV parsing, UTF-8 BOM
 * handling, header validation, chunked DB transactions, and per-row error
 * collection.
 */
abstract class CsvImporter
{
    /**
     * Maximum number of rows allowed per import.
     */
    protected int $maxRows = 5000;

    /**
     * Number of rows processed per DB transaction.
     */
    protected int $chunkSize = 200;

    /**
     * Return the ordered list of CSV column headers for this entity.
     *
     * @return array<string>
     */
    abstract protected function getRequiredColumns(): array;

    /**
     * Process a single CSV row.
     *
     * Must return one of: 'created', 'updated', or throw to signal failure.
     * Validation errors should be returned via the $errors reference array
     * and the method should return null.
     *
     * @param  array<string, string>  $row  Associative array keyed by header
     * @param  int  $rowNumber  1-based row number (header = row 1)
     * @param  array<string>  $errors  Collect validation errors here
     * @return string|null 'created'|'updated' on success, null on validation failure
     */
    abstract protected function processRow(
        array $row,
        int $rowNumber,
        string $organizationId,
        string $brandId,
        array &$errors,
    ): ?string;

    /**
     * Hook called before processing starts. Useful for pre-loading lookup data.
     */
    protected function beforeImport(string $organizationId, string $brandId): void {}

    /**
     * Hook called after every row has been processed but before the enclosing
     * transaction is committed (or rolled back for a dry run).
     */
    protected function afterImport(string $organizationId, string $brandId): void {}

    /**
     * Import rows from an uploaded CSV file.
     */
    public function importFromFile(UploadedFile $file, string $organizationId, string $brandId, bool $dryRun = false): ImportResult
    {
        $rows = $this->parseCsv($file->getRealPath());

        return $this->importRows($rows, $organizationId, $brandId, $dryRun);
    }

    /**
     * Import from pre-parsed rows (useful for testing).
     *
     * plan-040 TI.6 (L7): the whole import runs inside a SINGLE transaction so
     * a hard (non-validation) failure mid-import leaves nothing committed
     * (all-or-nothing). Per-row VALIDATION failures (collected in `$errors` or
     * surfaced as a ValidationException by a service) remain partial-success —
     * the good rows still commit. Passing `$dryRun = true` validates every row
     * and then rolls the transaction back, committing nothing (preview mode).
     *
     * @param  array<int, array<string, string>>  $rows
     */
    public function importRows(array $rows, string $organizationId, string $brandId, bool $dryRun = false): ImportResult
    {
        // Validate row count
        if (count($rows) > $this->maxRows) {
            return new ImportResult(
                errorCount: 1,
                errors: [['row' => 0, 'errors' => ["CSV file exceeds maximum allowed rows ({$this->maxRows})"]]],
            );
        }

        // Validate headers
        if (empty($rows)) {
            return new ImportResult(
                errorCount: 1,
                errors: [['row' => 0, 'errors' => ['CSV file is empty']]],
            );
        }

        $headerValidation = $this->validateHeaders(array_keys($rows[0]));
        if ($headerValidation !== null) {
            return new ImportResult(
                errorCount: 1,
                errors: [['row' => 0, 'errors' => [$headerValidation]]],
            );
        }

        $this->beforeImport($organizationId, $brandId);

        $successCount = 0;
        $errorCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $allErrors = [];
        $currentRow = 0;

        // plan-040 TI.6 (L7): one transaction wraps the entire import. A
        // ValidationException from a row (e.g. a service-validation reject) is
        // caught and recorded so other rows still commit (partial success); any
        // other Throwable propagates and rolls the whole import back so no
        // partial chunk is ever left committed.
        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $currentRow = $index + 2; // +2: row 1 = header, data starts at row 2
                $rowErrors = [];

                try {
                    $result = $this->processRow($row, $currentRow, $organizationId, $brandId, $rowErrors);

                    if (! empty($rowErrors)) {
                        $errorCount++;
                        $allErrors[] = ['row' => $currentRow, 'errors' => $rowErrors];

                        continue;
                    }

                    $successCount++;
                    if ($result === 'created') {
                        $createdCount++;
                    } elseif ($result === 'updated') {
                        $updatedCount++;
                    }
                } catch (ValidationException $e) {
                    // Service-layer validation reject (plan-040 TI.2/H9) — treat
                    // like a per-row error so the rest of the import proceeds.
                    $errorCount++;
                    $allErrors[] = ['row' => $currentRow, 'errors' => Arr::flatten($e->errors())];
                }
            }

            $this->afterImport($organizationId, $brandId);

            if ($dryRun) {
                // Validation-only preview — discard every write.
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            // Hard failure — roll the whole import back (all-or-nothing).
            DB::rollBack();

            return new ImportResult(
                errorCount: 1,
                errors: [['row' => $currentRow, 'errors' => ['Import aborted (no rows committed): '.$e->getMessage()]]],
            );
        }

        return new ImportResult(
            successCount: $successCount,
            errorCount: $errorCount,
            createdCount: $createdCount,
            updatedCount: $updatedCount,
            errors: $allErrors,
        );
    }

    /**
     * Generate a CSV template string with headers and optional sample rows.
     *
     * Subclasses can override `getSampleRows()` to inject brand-specific
     * sample data (real product_type_code / category_skus values) so the
     * file is copy-paste-import-able instead of failing with placeholder
     * codes the user has to translate.
     */
    public function generateTemplate(?string $organizationId = null, ?string $brandId = null): string
    {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

        fputcsv($output, $this->getRequiredColumns());

        foreach ($this->getSampleRows($organizationId, $brandId) as $sample) {
            fputcsv($output, $sample);
        }

        rewind($output);

        return stream_get_contents($output);
    }

    /**
     * Override to provide sample rows for the template. Receives the
     * resolved org/brand so subclasses can look up real codes (product
     * types, categories) instead of returning fixed placeholders.
     *
     * @return array<int, array<int, string>>
     */
    protected function getSampleRows(?string $organizationId = null, ?string $brandId = null): array
    {
        return [];
    }

    /**
     * Parse a CSV file into an array of associative rows.
     *
     * @return array<int, array<string, string>>
     */
    protected function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        // Skip UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Read headers
        $headers = fgetcsv($handle);
        if ($headers === false || $headers === null) {
            fclose($handle);

            return [];
        }

        $headers = array_map('trim', $headers);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 1 && ($data[0] === null || $data[0] === '')) {
                continue; // Skip empty rows
            }

            // Pad or trim data to match header count
            $data = array_pad($data, count($headers), '');
            $data = array_slice($data, 0, count($headers));

            $rows[] = array_combine($headers, $data);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Validate that CSV headers match the expected columns.
     *
     * @return string|null Error message or null on success
     */
    protected function validateHeaders(array $headers): ?string
    {
        $expected = $this->getRequiredColumns();
        $trimmed = array_map('trim', $headers);

        if ($trimmed !== $expected) {
            return 'Invalid CSV headers. Please use the import template.';
        }

        return null;
    }

    /**
     * Parse a boolean-like CSV value.
     */
    protected function parseBoolean(string $value, bool $default = false): bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['true', '1', 'yes', 'y'], true);
    }

    /**
     * plan-040 TI.7 (L8): strictly parse a numeric CSV cell. An empty cell
     * falls back to `$default`; a value that is not a clean number (e.g.
     * `"1,5"`, `"abc"`) or — when `$allowNegative` is false — a negative number
     * is REJECTED via the `$errors` ref instead of being silently coerced to
     * `(float) "1,5" === 1.0`. Returns null on rejection so the caller can
     * short-circuit the row.
     *
     * @param  array<string>  $errors
     */
    protected function parseStrictNumeric(
        string $value,
        string $field,
        array &$errors,
        float $default = 0.0,
        bool $allowNegative = false,
    ): ?float {
        $value = trim($value);

        if ($value === '') {
            return $default;
        }

        if (! is_numeric($value)) {
            $errors[] = "{$field} must be a valid number (got '{$value}').";

            return null;
        }

        $number = (float) $value;

        if (! $allowNegative && $number < 0) {
            $errors[] = "{$field} must not be negative.";

            return null;
        }

        return $number;
    }
}
