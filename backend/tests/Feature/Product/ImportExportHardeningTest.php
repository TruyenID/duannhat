<?php

// plan-040 Cluster I — Import/Export hardening (TI.1–TI.7).

use App\Models\Brand;
use App\Models\Material;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportResult;
use App\Services\Import\MaterialImporter;
use Database\Seeders\IamSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    // A second brand in the SAME org — used for foreign-brand / brand-scope tests.
    $this->otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";

    $this->actingAs($this->user);
});

/**
 * Build an in-memory CSV UploadedFile (mirrors the existing ImportExportTest
 * helper but uniquely named to avoid redeclaration).
 */
function planI040Csv(array $headers, array $rows = []): UploadedFile
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $tmpPath = tempnam(sys_get_temp_dir(), 'csvI040_');
    file_put_contents($tmpPath, $csv);

    return new UploadedFile($tmpPath, 'import.csv', 'text/csv', null, true);
}

const PLAN_I040_MATERIAL_HEADERS = ['id', 'sku', 'name', 'description', 'yield_quantity', 'yield_unit', 'calculated_cost', 'is_active'];
const PLAN_I040_RECIPE_HEADERS = ['id', 'sku', 'name', 'description', 'material_sku', 'is_active'];

// =========================================================================
//  TI.1 / C6 — authorization + forced brand_id
// =========================================================================

it('returns 403 when the user lacks create permission on import (C6)', function () {
    Gate::policy(Material::class, PlanI040DenyMaterialPolicy::class);

    $file = planI040Csv(PLAN_I040_MATERIAL_HEADERS, [
        ['', 'MA-100', 'Blocked', '', '1', 'SHOT', '1.00', 'true'],
    ]);

    $this->postJson("{$this->baseUrl}/materials/import", ['file' => $file, 'brand_id' => $this->brand->id])
        ->assertForbidden();

    expect(Material::where('organization_id', $this->orgId)->count())->toBe(0);
});

it('returns 403 when the user lacks viewAny permission on export (C6)', function () {
    Gate::policy(Material::class, PlanI040DenyMaterialPolicy::class);

    $this->get("{$this->baseUrl}/materials/export")->assertForbidden();
});

it('forbids a real shop-staff user from importing materials (plan-040 F2)', function () {
    // Real RBAC (no Gate override): shop-staff lacks material.create/import.
    $staff = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $staff->assignRole('shop-staff', $this->orgId);

    $file = planI040Csv(PLAN_I040_MATERIAL_HEADERS, [
        ['', 'MA-900', 'Blocked', '', '1', 'SHOT', '1.00', 'true'],
    ]);

    $this->actingAs($staff)
        ->postJson("{$this->baseUrl}/materials/import", ['file' => $file, 'brand_id' => $this->brand->id])
        ->assertForbidden();

    expect(Material::where('organization_id', $this->orgId)->count())->toBe(0);
});

it('stamps imported rows with the route brand, ignoring a foreign client brand_id (C6)', function () {
    $file = planI040Csv(PLAN_I040_MATERIAL_HEADERS, [
        ['', 'MA-200', 'Forced Brand', '', '1', 'SHOT', '5.00', 'true'],
    ]);

    // Client smuggles the OTHER brand's id in the body — it must be ignored.
    $this->postJson("{$this->baseUrl}/materials/import", ['file' => $file, 'brand_id' => $this->otherBrand->id])
        ->assertOk()
        ->assertJsonPath('data.created_count', 1);

    $material = Material::where('organization_id', $this->orgId)->where('sku', 'MA-200')->firstOrFail();
    expect($material->brand_id)->toBe($this->brand->id);
});

// =========================================================================
//  TI.2 / H9 — service validation on import
// =========================================================================

it('rejects a material row with an invalid yield shape (H9)', function () {
    // Produced material (yield_unit set) with yield_quantity 0 — the CRUD API
    // rejects this via validateYieldShape; the importer must too.
    $file = planI040Csv(PLAN_I040_MATERIAL_HEADERS, [
        ['', 'MA-300', 'Bad Yield', '', '0', 'KG', '5.00', 'true'],
    ]);

    $this->postJson("{$this->baseUrl}/materials/import", ['file' => $file, 'brand_id' => $this->brand->id])
        ->assertStatus(422)
        ->assertJsonPath('data.error_count', 1);

    expect(Material::where('organization_id', $this->orgId)->where('sku', 'MA-300')->exists())->toBeFalse();
});

it('rejects a recipe row whose output material belongs to another brand (H9)', function () {
    // Material lives in the OTHER brand; importing a recipe pointing at it under
    // THIS brand must be rejected by RecipeService::validateIngredientsAndOutput.
    Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->otherBrand->id,
        'sku' => 'MA-FOREIGN',
    ]);

    $file = planI040Csv(PLAN_I040_RECIPE_HEADERS, [
        ['', 'RE-300', 'Cross Brand', '', 'MA-FOREIGN', 'true'],
    ]);

    $this->postJson("{$this->baseUrl}/recipes/import", ['file' => $file, 'brand_id' => $this->brand->id])
        ->assertStatus(422)
        ->assertJsonPath('data.error_count', 1);

    expect(Recipe::where('organization_id', $this->orgId)->where('sku', 'RE-300')->exists())->toBeFalse();
});

// =========================================================================
//  TI.3 / H10 — CSV formula-injection escaping on export
// =========================================================================

it('prefixes a formula-injection cell with a single quote on export (H10)', function () {
    Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => '=cmd|/c calc',
        'sku' => '+SKU',
    ]);

    $content = $this->get("{$this->baseUrl}/materials/export")
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain("'=cmd|/c calc")
        ->and($content)->toContain("'+SKU")
        ->and($content)->not->toContain(',=cmd'); // raw (unescaped) form absent
});

// =========================================================================
//  TI.4 / H11 — brand-scoped export query
// =========================================================================

it('exports only the route brand materials (H11)', function () {
    Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Brand A Mat',
        'sku' => 'AA-1',
    ]);
    Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->otherBrand->id,
        'name' => 'Brand B Mat',
        'sku' => 'BB-1',
    ]);

    $content = $this->get("{$this->baseUrl}/materials/export")
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('AA-1')
        ->and($content)->not->toContain('BB-1');
});

// =========================================================================
//  TI.5 / M15 — SKU prefix alignment + numbering
// =========================================================================

it('generates MA-/RE- prefixed SKUs that increment without restarting (M15)', function () {
    $file = planI040Csv(PLAN_I040_MATERIAL_HEADERS, [
        ['', '', 'Auto One', '', '1', 'SHOT', '1.00', 'true'],
        ['', '', 'Auto Two', '', '1', 'SHOT', '1.00', 'true'],
    ]);

    $this->postJson("{$this->baseUrl}/materials/import", ['file' => $file, 'brand_id' => $this->brand->id])
        ->assertOk()
        ->assertJsonPath('data.created_count', 2);

    $skus = Material::where('organization_id', $this->orgId)->pluck('sku')->all();
    expect($skus)->toContain('MA-001')->toContain('MA-002');

    $recipeFile = planI040Csv(PLAN_I040_RECIPE_HEADERS, [
        ['', '', 'Auto Recipe', '', '', 'true'],
    ]);

    $this->postJson("{$this->baseUrl}/recipes/import", ['file' => $recipeFile, 'brand_id' => $this->brand->id])
        ->assertOk()
        ->assertJsonPath('data.created_count', 1);

    expect(Recipe::where('organization_id', $this->orgId)->value('sku'))->toBe('RE-001');
});

// =========================================================================
//  TI.6 / L7 — all-or-nothing on a hard failure
// =========================================================================

it('rolls back the whole import when a row hard-fails mid-import (L7)', function () {
    $orgId = $this->orgId;
    $brandId = $this->brand->id;

    $importer = new class extends CsvImporter
    {
        protected function getRequiredColumns(): array
        {
            return ['name'];
        }

        protected function processRow(array $row, int $rowNumber, string $organizationId, string $brandId, array &$errors): ?string
        {
            if ($row['name'] === 'BOOM') {
                throw new RuntimeException('hard failure');
            }

            Material::factory()->create([
                'organization_id' => $organizationId,
                'brand_id' => $brandId,
                'name' => $row['name'],
            ]);

            return 'created';
        }
    };

    $result = $importer->importRows(
        [['name' => 'ok-row'], ['name' => 'BOOM']],
        $orgId,
        $brandId,
    );

    expect($result)->toBeInstanceOf(ImportResult::class)
        ->and($result->errorCount)->toBe(1)
        ->and($result->successCount)->toBe(0)
        // The first (good) row must NOT be committed — all-or-nothing.
        ->and(Material::where('organization_id', $orgId)->count())->toBe(0);
});

it('commits nothing in dry-run mode while still reporting success counts (L7)', function () {
    $file = planI040Csv(PLAN_I040_MATERIAL_HEADERS, [
        ['', 'MA-DRY', 'Dry Run', '', '1', 'SHOT', '1.00', 'true'],
    ]);

    $importer = app(MaterialImporter::class);
    $result = $importer->importFromFile($file, $this->orgId, $this->brand->id, dryRun: true);

    expect($result->successCount)->toBe(1)
        ->and($result->createdCount)->toBe(1)
        ->and(Material::where('organization_id', $this->orgId)->where('sku', 'MA-DRY')->exists())->toBeFalse();
});

// =========================================================================
//  TI.7 / L8 — strict numeric parsing
// =========================================================================

it('rejects a "1,5" thousands/locale numeric value instead of coercing to 1.0 (L8)', function () {
    $file = planI040Csv(PLAN_I040_MATERIAL_HEADERS, [
        ['', 'MA-NUM', 'Bad Number', '', '1,5', 'SHOT', '5.00', 'true'],
    ]);

    $this->postJson("{$this->baseUrl}/materials/import", ['file' => $file, 'brand_id' => $this->brand->id])
        ->assertStatus(422)
        ->assertJsonPath('data.error_count', 1);

    expect(Material::where('organization_id', $this->orgId)->where('sku', 'MA-NUM')->exists())->toBeFalse();
});

it('rejects a negative numeric value on import (L8)', function () {
    $file = planI040Csv(PLAN_I040_MATERIAL_HEADERS, [
        ['', 'MA-NEG', 'Negative Cost', '', '1', 'SHOT', '-5.00', 'true'],
    ]);

    $this->postJson("{$this->baseUrl}/materials/import", ['file' => $file, 'brand_id' => $this->brand->id])
        ->assertStatus(422)
        ->assertJsonPath('data.error_count', 1);

    expect(Material::where('organization_id', $this->orgId)->where('sku', 'MA-NEG')->exists())->toBeFalse();
});

/**
 * Deny-all policy used to assert the import/export authorize() gates fire.
 */
class PlanI040DenyMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }
}
