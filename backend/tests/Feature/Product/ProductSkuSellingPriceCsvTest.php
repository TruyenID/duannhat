<?php

// issue #875 — SKU CSV export/import must carry selling_price (the menu price),
// not just cost_price (which now defaults to 0 and is auto-derived from recipe).

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use App\Services\Export\ProductSkuExporter;
use App\Services\Import\ProductSkuImporter;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);

    $this->actingAs($this->user);
});

function skuCsvRows(string $body): array
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

it('exports a selling_price column populated with the SKU menu price', function () {
    ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'sku' => 'PV-SELL-1',
        'selling_price' => 1800,
        'cost_price' => 0,
        'is_cost_override' => false,
    ]);

    $content = $this->get("/api/v1/hq/{$this->brand->slug}/skus/export")
        ->assertOk()
        ->streamedContent();

    $rows = skuCsvRows($content);
    $header = $rows[0];

    // The header must expose selling_price, and its cell must carry the real
    // menu price (not the 0 cost_price).
    expect($header)->toContain('selling_price');

    $priceIndex = array_search('selling_price', $header, true);
    $dataRow = collect($rows)->firstWhere(fn ($r) => ($r[2] ?? null) === 'PV-SELL-1');

    expect($dataRow)->not->toBeNull()
        ->and((float) $dataRow[$priceIndex])->toBe(1800.0);
});

it('keeps exporter headers and importer required columns in lock-step', function () {
    // CsvImporter::validateHeaders does a strict ordered `!==` comparison, so a
    // drift between these two lists silently breaks every SKU import round-trip.
    $exporter = new ReflectionClass(ProductSkuExporter::class);
    $getHeaders = $exporter->getMethod('getHeaders');
    $getHeaders->setAccessible(true);

    $importer = new ReflectionClass(ProductSkuImporter::class);
    $getRequired = $importer->getMethod('getRequiredColumns');
    $getRequired->setAccessible(true);

    $headers = $getHeaders->invoke($exporter->newInstanceWithoutConstructor());
    $required = $getRequired->invoke($importer->newInstanceWithoutConstructor());

    expect($headers)->toBe($required)
        ->and($headers)->toContain('selling_price');
});
