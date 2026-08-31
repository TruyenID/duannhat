<?php

declare(strict_types=1);

namespace App\Services\Export;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Abstract base for CSV export operations.
 *
 * Subclasses define getQuery(), getHeaders(), and mapRow() for entity-specific
 * export behaviour. The base streams CSV rows in chunks to keep memory usage
 * constant regardless of dataset size.
 */
abstract class CsvExporter
{
    /**
     * Chunk size for streaming.
     */
    protected int $chunkSize = 500;

    /**
     * Return the Eloquent query for the export dataset.
     */
    abstract protected function getQuery(string $organizationId, array $filters = []): Builder;

    /**
     * Return the CSV header row.
     *
     * @return array<string>
     */
    abstract protected function getHeaders(): array;

    /**
     * Map an Eloquent model to a CSV row array.
     *
     * @return array<int, string|int|float|null>
     */
    abstract protected function mapRow(mixed $model): array;

    /**
     * Return the filename prefix for the downloaded file.
     */
    abstract protected function getFilenamePrefix(): string;

    /**
     * Export as a streamed CSV download.
     */
    public function export(string $organizationId, array $filters = []): StreamedResponse
    {
        $query = $this->getQuery($organizationId, $filters);
        $headers = $this->getHeaders();
        $filename = $this->getFilenamePrefix().'_'.date('Y-m-d_His').'.csv';

        // chunkById() forces orderBy('id') + its own pagination, so it would
        // silently override a getQuery() that set limit/offset/orderBy for the
        // "export current page" scope. We pick the strategy based on whether
        // the subclass already asked for a bounded slice.
        $hasBoundedSlice = $query->getQuery()->limit !== null
            || $query->getQuery()->offset !== null;

        return response()->streamDownload(
            function () use ($query, $headers, $hasBoundedSlice): void {
                $output = fopen('php://output', 'w');

                // UTF-8 BOM for Excel compatibility
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, $headers);

                if ($hasBoundedSlice) {
                    // Honor the limit/offset/orderBy the subclass set.
                    // Dataset is bounded (one UI page) so memory is fine.
                    foreach ($query->get() as $item) {
                        fputcsv($output, $this->escapeRow($this->mapRow($item)));
                    }

                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                } else {
                    // Unbounded → stream with chunkById to keep memory constant.
                    $query->orderBy('id')->chunkById($this->chunkSize, function ($items) use ($output): void {
                        foreach ($items as $item) {
                            fputcsv($output, $this->escapeRow($this->mapRow($item)));
                        }

                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                    });
                }

                fclose($output);
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Apply CSV-injection escaping to every cell of a mapped row.
     *
     * @param  array<int, string|int|float|null>  $row
     * @return array<int, string|int|float|null>
     */
    private function escapeRow(array $row): array
    {
        return array_map(fn ($cell) => $this->escapeCell($cell), $row);
    }

    /**
     * plan-040 TI.3 (H10): defend against CSV formula injection. A cell whose
     * value begins with a formula trigger (`=`, `+`, `-`, `@`) or a control
     * char (tab, carriage return) is prefixed with a single quote so a
     * spreadsheet treats it as literal text instead of evaluating it.
     */
    private function escapeCell(string|int|float|null $cell): string|int|float|null
    {
        if (! is_string($cell) || $cell === '') {
            return $cell;
        }

        if (in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$cell;
        }

        return $cell;
    }
}
