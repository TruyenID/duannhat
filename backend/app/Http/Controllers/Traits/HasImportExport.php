<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use App\Services\Export\CsvExporter;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Provides import/export/template actions for controllers.
 *
 * Controllers using this trait must implement getImporter() and getExporter().
 */
trait HasImportExport
{
    /**
     * Return the CsvImporter instance for this entity.
     */
    abstract protected function getImporter(): CsvImporter;

    /**
     * Return the CsvExporter instance for this entity.
     */
    abstract protected function getExporter(): CsvExporter;

    /**
     * Return the Eloquent model class this entity imports/exports. Used to
     * gate import/export against the resource policy (plan-040 C6).
     *
     * @return class-string
     */
    abstract protected function getModelClass(): string;

    /**
     * Import entities from an uploaded CSV file.
     */
    public function importCsv(Request $request): JsonResponse
    {
        // plan-040 TI.1 (C6): import writes rows — gate it on the resource's
        // create policy (previously absent, so any authenticated brand member
        // could bulk-write).
        $this->authorize('create', $this->getModelClass());

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $organizationId = $this->getOrganizationId();

        // plan-040 TI.1 (C6): brand_id is FORCED from the route-resolved brand
        // (ResolveBrandFromSlug middleware). A client-supplied brand_id in the
        // body is ignored so imported rows are always stamped with the caller's
        // brand and can never be smuggled into a foreign brand.
        $brandId = $request->attributes->get('brand_id');
        $result = $this->getImporter()->importFromFile($request->file('file'), $organizationId, $brandId);

        $statusCode = $result->isCompleteFailure() ? 422 : 200;

        return response()->json([
            'success' => ! $result->hasErrors(),
            'message' => $this->buildImportMessage($result),
            'data' => $result->toArray(),
        ], $statusCode);
    }

    /**
     * Export entities as a streamed CSV download.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        // plan-040 TI.1 (C6): gate export on the resource's viewAny policy.
        $this->authorize('viewAny', $this->getModelClass());

        $organizationId = $this->getOrganizationId();

        // plan-040 TI.4 (H11): pass the route-resolved brand into the export so
        // the exporter can scope to the caller's brand only.
        $filters = $request->all();
        $filters['brand_id'] = $request->attributes->get('brand_id');

        return $this->getExporter()->export($organizationId, $filters);
    }

    /**
     * Download a CSV template. Importers that need brand context (e.g.
     * Products needs real product_type_code / category_skus) read them
     * from the request — org_id is resolved by HasOrganizationContext,
     * brand_id is set by ResolveBrandFromSlug middleware on HQ routes.
     */
    public function importTemplate(Request $request): StreamedResponse
    {
        $organizationId = $this->getOrganizationId();
        $brandId = $request->attributes->get('brand_id');
        $csv = $this->getImporter()->generateTemplate($organizationId, $brandId);

        return response()->streamDownload(
            fn () => print ($csv),
            $this->getTemplateFilename().'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Return the template download filename (without extension).
     * Override in controller if needed.
     */
    protected function getTemplateFilename(): string
    {
        return 'import_template';
    }

    /**
     * Build a human-readable summary message from the import result.
     */
    private function buildImportMessage(ImportResult $result): string
    {
        $messages = [];

        if ($result->successCount > 0) {
            $messages[] = "Successfully processed {$result->successCount} rows";
            if ($result->createdCount > 0) {
                $messages[] = "Created: {$result->createdCount}";
            }
            if ($result->updatedCount > 0) {
                $messages[] = "Updated: {$result->updatedCount}";
            }
        }

        if ($result->errorCount > 0) {
            $messages[] = "Failed: {$result->errorCount}";
        }

        return implode('. ', $messages);
    }
}
