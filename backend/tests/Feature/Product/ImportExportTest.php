<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Import\CategoryImporter;
use App\Services\Import\ImportResult;
use App\Services\Import\ProductSkuImporter;
use App\Services\Product\Contracts\ProductMutationFacade;
use Database\Seeders\IamSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";

    // The `Brand::created` hook auto-provisions a "combo" ProductType.
    // Tests assert exact counts on ProductType, so remove the auto-row.
    ProductType::query()->forceDelete();

    $this->actingAs($this->user);
});

// =========================================================================
//  Helpers
// =========================================================================

function createCsvFile(array $headers, array $rows = []): UploadedFile
{
    $content = "\xEF\xBB\xBF"; // UTF-8 BOM
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $content);
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

// =========================================================================
//  ProductType Import
// =========================================================================

describe('ProductType import', function () {
    it('imports product types from valid CSV', function () {
        $file = createCsvFile(
            ['id', 'code', 'name', 'description', 'product_form', 'has_recipe', 'is_inventory_tracked', 'icon', 'is_active'],
            [
                ['', 'BEV', 'Beverage', 'Drinks', 'physical', 'true', 'true', 'coffee', 'true'],
                ['', 'FOOD', 'Food', 'Food items', 'physical', 'true', 'false', 'utensils', 'true'],
            ],
        );

        $this->postJson("{$this->baseUrl}/product-types/import", ['file' => $file, 'brand_id' => $this->brand->id])
            ->assertOk()
            ->assertJsonPath('data.success_count', 2)
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.error_count', 0);

        expect(ProductType::where('organization_id', $this->orgId)->count())->toBe(2);
    });

    it('returns per-row errors for invalid data', function () {
        $file = createCsvFile(
            ['id', 'code', 'name', 'description', 'product_form', 'has_recipe', 'is_inventory_tracked', 'icon', 'is_active'],
            [
                ['', 'BEV', 'Beverage', 'Drinks', 'physical', 'true', 'true', 'coffee', 'true'],
                ['', '', '', '', '', '', '', '', ''], // missing name
            ],
        );

        $this->postJson("{$this->baseUrl}/product-types/import", ['file' => $file, 'brand_id' => $this->brand->id])
            ->assertOk()
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.error_count', 1)
            ->assertJsonPath('data.errors.0.row', 3); // row 3 = second data row
    });

    it('rejects CSV with wrong headers', function () {
        $file = createCsvFile(['wrong', 'headers']);

        $this->postJson("{$this->baseUrl}/product-types/import", ['file' => $file, 'brand_id' => $this->brand->id])
            ->assertStatus(422)
            ->assertJsonPath('data.error_count', 1);
    });

    it('updates existing product type when id is provided', function () {
        $pt = ProductType::factory()->create([
            'organization_id' => $this->orgId,
            'code' => 'BEV',
            'name' => 'Old Name',
            'brand_id' => $this->brand->id,
        ]);

        $file = createCsvFile(
            ['id', 'code', 'name', 'description', 'product_form', 'has_recipe', 'is_inventory_tracked', 'icon', 'is_active'],
            [
                [$pt->id, 'BEV', 'New Name', 'Updated', 'physical', 'true', 'true', 'coffee', 'true'],
            ],
        );

        $this->postJson("{$this->baseUrl}/product-types/import", ['file' => $file, 'brand_id' => $this->brand->id])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        expect($pt->fresh()->name)->toBe('New Name');
    });

    it('does not update a product type owned by another brand in the organization', function () {
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);
        $foreignType = ProductType::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign type',
        ]);

        $file = createCsvFile(
            ['id', 'code', 'name', 'description', 'product_form', 'has_recipe', 'is_inventory_tracked', 'icon', 'is_active'],
            [
                [$foreignType->id, 'FOREIGN', 'Compromised', '', 'physical', 'false', 'true', '', 'true'],
            ],
        );

        $this->postJson("{$this->baseUrl}/product-types/import", ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('data.success_count', 0)
            ->assertJsonPath('data.error_count', 1)
            ->assertJsonPath('data.errors.0.errors.0', 'id not found');

        expect($foreignType->fresh()->name)->toBe('Foreign type');
    });
});

// =========================================================================
//  Category Import
// =========================================================================

describe('Category import', function () {
    it('imports categories with parent relationships', function () {
        $file = createCsvFile(
            ['id', 'sku', 'name', 'slug', 'description', 'parent_sku', 'is_active'],
            [
                ['', 'C-001', 'Beverages', 'beverages', 'All drinks', '', 'true'],
                ['', 'C-002', 'Hot Drinks', 'hot-drinks', 'Hot beverages', 'C-001', 'true'],
            ],
        );

        $this->postJson("{$this->baseUrl}/categories/import", ['file' => $file, 'brand_id' => $this->brand->id])
            ->assertOk()
            ->assertJsonPath('data.success_count', 2)
            ->assertJsonPath('data.created_count', 2);

        $parent = Category::where('organization_id', $this->orgId)->where('sku', 'C-001')->first();
        $child = Category::where('organization_id', $this->orgId)->where('sku', 'C-002')->first();

        expect($child->parent_id)->toBe($parent->id);
    });

    it('does not discover or update a category owned by another brand', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $foreign = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'sku' => 'FOREIGN-CATEGORY',
            'name' => 'Foreign category',
        ]);
        $file = createCsvFile(
            ['id', 'sku', 'name', 'slug', 'description', 'parent_sku', 'is_active'],
            [[$foreign->id, $foreign->sku, 'Compromised', '', '', '', 'true']],
        );

        $this->postJson("{$this->baseUrl}/categories/import", ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.0.errors.0', 'id not found');

        expect($foreign->fresh()->name)->toBe('Foreign category');
    });

    it('does not resolve parent_sku from another brand', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $foreignParent = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'sku' => 'FOREIGN-PARENT',
        ]);
        $child = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'sku' => 'LOCAL-CHILD',
            'parent_id' => null,
        ]);
        $file = createCsvFile(
            ['id', 'sku', 'name', 'slug', 'description', 'parent_sku', 'is_active'],
            [[$child->id, $child->sku, 'Local child', '', '', $foreignParent->sku, 'true']],
        );

        $this->postJson("{$this->baseUrl}/categories/import", ['file' => $file])
            ->assertOk();

        expect($child->fresh()->parent_id)->toBeNull();
    });

    it('rolls back parent assignment during a dry run', function () {
        $parent = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'sku' => 'DRY-PARENT',
        ]);
        $child = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'sku' => 'DRY-CHILD',
            'parent_id' => null,
        ]);

        $result = app(CategoryImporter::class)->importRows([
            [
                'id' => $child->id,
                'sku' => $child->sku,
                'name' => 'Dry child',
                'slug' => '',
                'description' => '',
                'parent_sku' => $parent->sku,
                'is_active' => 'true',
            ],
        ], $this->orgId, $this->brand->id, dryRun: true);

        expect($result->updatedCount)->toBe(1)
            ->and($child->fresh()->parent_id)->toBeNull();
    });

    it('rolls back all rows when the parent pass hard-fails', function () {
        $realService = app(ProductMutationFacade::class);
        $service = Mockery::mock(ProductMutationFacade::class);
        $service->shouldReceive('createCategory')
            ->twice()
            ->andReturnUsing(fn ($command) => $realService->createCategory($command));
        $service->shouldReceive('reviseCategory')
            ->once()
            ->andThrow(new RuntimeException('parent pass failed'));
        $importer = new CategoryImporter($service);

        $result = $importer->importRows([
            ['id' => '', 'sku' => 'ROLLBACK-PARENT', 'name' => 'Parent', 'slug' => '', 'description' => '', 'parent_sku' => '', 'is_active' => 'true'],
            ['id' => '', 'sku' => 'ROLLBACK-CHILD', 'name' => 'Child', 'slug' => '', 'description' => '', 'parent_sku' => 'ROLLBACK-PARENT', 'is_active' => 'true'],
        ], $this->orgId, $this->brand->id);

        expect($result->errorCount)->toBe(1)
            ->and($result->successCount)->toBe(0)
            ->and(Category::whereIn('sku', ['ROLLBACK-PARENT', 'ROLLBACK-CHILD'])->exists())->toBeFalse();
    });
});

// =========================================================================
//  Material Import
// =========================================================================

describe('Material import', function () {
    it('imports materials from valid CSV', function () {
        $file = createCsvFile(
            ['id', 'sku', 'name', 'description', 'yield_quantity', 'yield_unit', 'calculated_cost', 'is_active'],
            [
                ['', 'M-001', 'Espresso Shot', 'Single shot', '1', 'SHOT', '15.00', 'true'],
            ],
        );

        $this->postJson("{$this->baseUrl}/materials/import", ['file' => $file, 'brand_id' => $this->brand->id])
            ->assertOk()
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.created_count', 1);

        expect(Material::where('organization_id', $this->orgId)->count())->toBe(1);
    });
});

// =========================================================================
//  Recipe Import
// =========================================================================

describe('Recipe import', function () {
    it('imports recipes with material reference', function () {
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'M-001',
            'brand_id' => $this->brand->id,
        ]);

        $file = createCsvFile(
            ['id', 'sku', 'name', 'description', 'material_sku', 'is_active'],
            [
                ['', 'R-001', 'Latte Recipe', 'Standard latte', 'M-001', 'true'],
            ],
        );

        $this->postJson("{$this->baseUrl}/recipes/import", ['file' => $file, 'brand_id' => $this->brand->id])
            ->assertOk()
            ->assertJsonPath('data.success_count', 1);

        $recipe = Recipe::where('organization_id', $this->orgId)->first();
        expect($recipe->material_id)->toBe($material->id);
    });

    it('fails when material_sku not found', function () {
        $file = createCsvFile(
            ['id', 'sku', 'name', 'description', 'material_sku', 'is_active'],
            [
                ['', 'R-001', 'Latte Recipe', 'Standard latte', 'NONEXISTENT', 'true'],
            ],
        );

        $this->postJson("{$this->baseUrl}/recipes/import", ['file' => $file, 'brand_id' => $this->brand->id])
            ->assertStatus(422)
            ->assertJsonPath('data.error_count', 1);
    });

    it('does not discover recipes or materials owned by another brand', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $foreignMaterial = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'sku' => 'FOREIGN-MATERIAL',
        ]);
        $foreignRecipe = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'sku' => 'FOREIGN-RECIPE',
            'name' => 'Foreign recipe',
        ]);
        $file = createCsvFile(
            ['id', 'sku', 'name', 'description', 'material_sku', 'is_active'],
            [[$foreignRecipe->id, $foreignRecipe->sku, 'Compromised', '', $foreignMaterial->sku, 'true']],
        );

        $this->postJson("{$this->baseUrl}/recipes/import", ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.0.errors.0', "material_sku '{$foreignMaterial->sku}' not found");

        expect($foreignRecipe->fresh()->name)->toBe('Foreign recipe');
    });
});

// =========================================================================
//  Product Import
// =========================================================================

describe('Product import', function () {
    function skuImportRow(array $overrides = []): array
    {
        return array_replace([
            'id' => '', 'product_sku' => 'local-product', 'sku' => 'LOCAL-SKU', 'name' => 'Local SKU',
            'option1' => '', 'value1' => '', 'option2' => '', 'value2' => '', 'option3' => '', 'value3' => '',
            'recipe_sku' => '', 'recipe_multiplier' => '1', 'selling_price' => '100', 'cost_price' => '0',
            'is_cost_override' => 'false', 'is_active' => 'true',
        ], $overrides);
    }

    it('does not discover foreign product or SKU identifiers and does not treat foreign SKU codes as collisions', function () {
        $localType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        Product::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'product_type_id' => $localType->id, 'slug' => 'local-product',
        ]);
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $foreignType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $otherBrand->id]);
        $foreignProduct = Product::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $otherBrand->id,
            'product_type_id' => $foreignType->id, 'slug' => 'foreign-product',
        ]);
        $foreignSku = ProductSku::factory()->create([
            'product_id' => $foreignProduct->id, 'sku' => 'SHARED-SKU', 'name' => 'Foreign SKU',
        ]);
        $importer = app(ProductSkuImporter::class);

        $foreignIdResult = $importer->importRows([
            skuImportRow(['id' => $foreignSku->id, 'sku' => 'SHARED-SKU']),
        ], $this->orgId, $this->brand->id);
        $foreignProductResult = $importer->importRows([
            skuImportRow(['product_sku' => 'foreign-product', 'sku' => 'OTHER-SKU']),
        ], $this->orgId, $this->brand->id);
        $sameCodeResult = $importer->importRows([
            skuImportRow(['sku' => 'SHARED-SKU']),
        ], $this->orgId, $this->brand->id);

        expect($foreignIdResult->errors[0]['errors'][0])->toBe("ID {$foreignSku->id} not found")
            ->and($foreignProductResult->errors[0]['errors'][0])->toBe("product_sku 'FOREIGN-PRODUCT' not found")
            ->and($sameCodeResult->createdCount)->toBe(1)
            ->and($foreignSku->fresh()->name)->toBe('Foreign SKU')
            ->and(ProductSku::where('sku', 'SHARED-SKU')->count())->toBe(2);
    });

    it('preserves imported decimal prices inside the selected brand', function () {
        $localType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'product_type_id' => $localType->id,
            'slug' => 'local-product',
        ]);

        $result = app(ProductSkuImporter::class)->importRows([
            skuImportRow([
                'sku' => 'DECIMAL-SKU',
                'selling_price' => '1234.56',
                'cost_price' => '78.90',
                'is_cost_override' => 'true',
            ]),
        ], $this->orgId, $this->brand->id);

        $sku = ProductSku::where('sku', 'DECIMAL-SKU')->firstOrFail();
        expect($result->createdCount)->toBe(1)
            ->and($sku->selling_price)->toBe('1234.56')
            ->and($sku->cost_price)->toBe('78.90')
            ->and($sku->product->brand_id)->toBe($this->brand->id);
    });
});

// =========================================================================
//  Export
// =========================================================================

describe('Export', function () {
    it('exports product types as streaming CSV', function () {
        ProductType::factory()->count(3)->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $this->get("{$this->baseUrl}/product-types/export")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('exports categories as streaming CSV', function () {
        Category::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->get("{$this->baseUrl}/categories/export")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('exports materials as streaming CSV', function () {
        Material::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $this->get("{$this->baseUrl}/materials/export")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });
});

// =========================================================================
//  Import Template
// =========================================================================

describe('Import template', function () {
    it('returns product types import template CSV', function () {
        $this->get("{$this->baseUrl}/product-types/import/template")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('returns categories import template CSV', function () {
        $this->get("{$this->baseUrl}/categories/import/template")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('returns materials import template CSV', function () {
        $this->get("{$this->baseUrl}/materials/import/template")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('returns recipes import template CSV', function () {
        $this->get("{$this->baseUrl}/recipes/import/template")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('returns products import template CSV', function () {
        $this->get("{$this->baseUrl}/products/import/template")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('returns SKUs import template CSV', function () {
        // SKUs replaced the legacy /variants endpoint — skus/import/template
        // is the canonical route under the brand-scoped HQ namespace.
        $this->get("{$this->baseUrl}/skus/import/template")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });
});

// =========================================================================
//  Authentication
// =========================================================================

describe('Authentication', function () {
    it('returns 401 for unauthenticated import request', function () {
        // Reset auth
        $this->app['auth']->forgetGuards();

        $file = createCsvFile(
            ['id', 'code', 'name', 'description', 'product_form', 'has_recipe', 'is_inventory_tracked', 'icon', 'is_active'],
            [['', 'BEV', 'Beverage', '', 'physical', 'true', 'true', '', 'true']],
        );

        $this->postJson("{$this->baseUrl}/product-types/import", ['file' => $file])
            ->assertUnauthorized();
    });

    it('returns 401 for unauthenticated export request', function () {
        $this->app['auth']->forgetGuards();

        $this->getJson("{$this->baseUrl}/product-types/export")
            ->assertUnauthorized();
    });

    it('returns 401 for unauthenticated template request', function () {
        $this->app['auth']->forgetGuards();

        $this->getJson("{$this->baseUrl}/product-types/import/template")
            ->assertUnauthorized();
    });
});

// =========================================================================
//  ImportResult DTO
// =========================================================================

describe('ImportResult', function () {
    it('correctly reports complete failure', function () {
        $result = new ImportResult(successCount: 0, errorCount: 3, errors: [
            ['row' => 2, 'errors' => ['error 1']],
            ['row' => 3, 'errors' => ['error 2']],
            ['row' => 4, 'errors' => ['error 3']],
        ]);

        expect($result->isCompleteFailure())->toBeTrue();
        expect($result->hasErrors())->toBeTrue();
    });

    it('correctly reports partial success', function () {
        $result = new ImportResult(successCount: 2, errorCount: 1);

        expect($result->isCompleteFailure())->toBeFalse();
        expect($result->hasErrors())->toBeTrue();
    });

    it('serializes to array correctly', function () {
        $result = new ImportResult(successCount: 5, errorCount: 0, createdCount: 3, updatedCount: 2);

        expect($result->toArray())->toBe([
            'success_count' => 5,
            'error_count' => 0,
            'created_count' => 3,
            'updated_count' => 2,
            'errors' => [],
        ]);
    });
});

// =========================================================================
//  File Validation
// =========================================================================

describe('File validation', function () {
    it('rejects import without file', function () {
        $this->postJson("{$this->baseUrl}/product-types/import", ['brand_id' => $this->brand->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    });

    it('rejects import with non-CSV file', function () {
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $this->postJson("{$this->baseUrl}/product-types/import", ['file' => $file, 'brand_id' => $this->brand->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    });
});
