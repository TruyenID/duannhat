<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Str;

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
    ]);

    $this->actingAs($this->user);
});

function csvRows(string $body): array
{
    // Strip UTF-8 BOM then parse with fgetcsv so quoted fields containing
    // newlines (e.g. multi-line descriptions) count as one row, not many.
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

describe('Product import template', function () {
    it('returns a template populated with real codes from the current brand', function () {
        // Brand factory creates an incidental ProductType — wipe so the
        // importer's "first product type by created_at" lookup picks ours.
        ProductType::query()->delete();
        ProductType::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'code' => 'REALTYPE',
            'is_active' => true,
        ]);
        Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'sku' => 'REALCAT',
            'parent_id' => null,
        ]);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/import/template")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $response->streamedContent();
        // Sample rows must reference real product_type_code + category SKU,
        // not the legacy `BEV`/`C-001` placeholders.
        expect($body)->toContain('REALTYPE');
        expect($body)->toContain('REALCAT');
        expect($body)->not->toContain('BEV');
        expect($body)->not->toContain('C-001');
    });
});

describe('Product export filters', function () {
    it('exports every product when no filter is given', function () {
        Product::factory()->count(5)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $rows = csvRows($response->streamedContent());
        expect($rows)->toHaveCount(6); // 1 header + 5 data rows
    });

    it('narrows export by `search` to matching products only', function () {
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'name' => 'Phở Bò',
        ]);
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'name' => 'Bún Chả',
        ]);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export?search=Ph%E1%BB%9F")
            ->assertOk();

        $rows = csvRows($response->streamedContent());
        expect($rows)->toHaveCount(2); // header + 1 match
        expect($rows[1])->toContain('Phở Bò'); // name column
    });

    it('narrows export by `status`', function () {
        Product::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'status' => 'active',
        ]);
        Product::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'status' => 'draft',
        ]);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export?status=draft")
            ->assertOk();

        expect(csvRows($response->streamedContent()))->toHaveCount(3); // header + 2 draft
    });

    it('narrows export by `is_hidden=true`', function () {
        Product::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'is_hidden' => true,
        ]);
        Product::factory()->count(4)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'is_hidden' => false,
        ]);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export?is_hidden=true")
            ->assertOk();

        expect(csvRows($response->streamedContent()))->toHaveCount(3); // header + 2 hidden
    });

    it('narrows export by `category_id` via pivot', function () {
        $cat = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $inCat = Product::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);
        foreach ($inCat as $p) {
            $p->categories()->attach($cat->id);
        }

        Product::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export?category_id={$cat->id}")
            ->assertOk();

        expect(csvRows($response->streamedContent()))->toHaveCount(3); // header + 2
    });

    it('exports only the selected ids when `ids[]` is passed', function () {
        $all = Product::factory()->count(5)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);
        $picked = $all->take(2)->pluck('id')->all();
        $qs = http_build_query(['ids' => $picked]);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export?{$qs}")
            ->assertOk();

        expect(csvRows($response->streamedContent()))->toHaveCount(3); // header + 2
    });

    it('ignores other filters when `ids[]` is sent (hard scope)', function () {
        $picked = Product::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'status' => 'draft',
        ])->pluck('id')->all();
        $qs = http_build_query(['ids' => $picked, 'status' => 'active']);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export?{$qs}")
            ->assertOk();

        // ids wins → the 2 drafts are exported, status=active ignored.
        expect(csvRows($response->streamedContent()))->toHaveCount(3);
    });

    it('exports only the requested page when `page` + `per_page` are given', function () {
        Product::factory()->count(7)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        // per_page=3 → page 1 = 3 rows, page 3 = 1 row (rows 7, leftover)
        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export?page=1&per_page=3")
            ->assertOk();
        expect(csvRows($response->streamedContent()))->toHaveCount(4); // header + 3

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export?page=3&per_page=3")
            ->assertOk();
        expect(csvRows($response->streamedContent()))->toHaveCount(2); // header + 1
    });

    it('scopes export to the brand resolved from the URL slug (multi-brand org)', function () {
        // Two brands sharing the same org.
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);

        Product::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id, // exported brand
        ]);
        Product::factory()->count(5)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $otherBrand->id, // sibling brand — must NOT appear
        ]);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export")
            ->assertOk();

        expect(csvRows($response->streamedContent()))->toHaveCount(4); // header + 3
    });

    it('combines multiple filters (AND semantics)', function () {
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'name' => 'Match Active',
            'status' => 'active',
        ]);
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'name' => 'Match Draft',
            'status' => 'draft',
        ]);
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'name' => 'Other',
            'status' => 'active',
        ]);

        $response = $this->get("/api/v1/hq/{$this->brand->slug}/products/export?search=Match&status=active")
            ->assertOk();

        $rows = csvRows($response->streamedContent());
        expect($rows)->toHaveCount(2); // header + 1 row
        expect($rows[1])->toContain('Match Active');
    });
});
