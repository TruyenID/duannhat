<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * plan-043 T2.6 — Product CSV round-trip for the `tax_type_code` column:
 * export and import.
 */
beforeEach(function () {
    $sharedOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $sharedOrgId,
        'console_organization_id' => $sharedOrgId,
    ]);
    $this->orgId = $sharedOrgId;

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $sharedOrgId,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $sharedOrgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'DRINK',
        'is_active' => true,
    ]);

    $this->standardTax = TaxType::factory()->standard()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
    ]);
    $this->reducedTax = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
    ]);
    $this->exemptTax = TaxType::factory()->exempt()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";

    $this->actingAs($this->user);
});

/**
 * Parse a streamed CSV body into rows (strip BOM, honour quoted fields).
 *
 * @return array<int, array<int, string>>
 */
function taxCsvRows(string $body): array
{
    $body = ltrim($body, "\xEF\xBB\xBF");
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $body);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

function taxCsvUpload(array $headers, array $rows): UploadedFile
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $tmpPath = tempnam(sys_get_temp_dir(), 'csv_');
    file_put_contents($tmpPath, $csv);

    return new UploadedFile($tmpPath, 'import.csv', 'text/csv', null, true);
}
