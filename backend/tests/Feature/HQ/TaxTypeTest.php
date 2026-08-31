<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * plan-043 T1.10 — TaxType HTTP stack (cloned from the ProductType template)
 * + Product write validation (endpoint #13 + A1 alcohol cross-check).
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-tax',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/tax-types";

    $this->actingAs($this->user);
});

/** Helper: create a tax type in the test brand/org. */
function taxTypeInBrand(array $overrides = []): TaxType
{
    return TaxType::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
    ], $overrides));
}

// =========================================================================
//  Happy path — CRUD
// =========================================================================

describe('index', function () {
    it('paginates tax types for the brand', function () {
        TaxType::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson($this->baseUrl)
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters by search on code and name', function () {
        taxTypeInBrand(['code' => 'FINDME', 'name' => 'Standard']);
        taxTypeInBrand(['code' => 'OTHER', 'name' => 'Reduced']);

        $this->getJson("{$this->baseUrl}?search=FINDME")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'FINDME');
    });

    it('filters by is_active', function () {
        taxTypeInBrand(['is_active' => true]);
        taxTypeInBrand(['is_active' => false]);

        $this->getJson("{$this->baseUrl}?is_active=1")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('does not show tax types from other brands or orgs', function () {
        taxTypeInBrand();
        TaxType::factory()->create(['organization_id' => (string) Str::uuid()]);

        $this->getJson($this->baseUrl)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('store', function () {
    it('creates a tax type with a translatable name and returns 201', function () {
        $payload = [
            'code' => 'STD',
            'ja' => ['name' => '標準税率'],
            'en' => ['name' => 'Standard'],
            'vi' => ['name' => 'Tiêu chuẩn'],
            'rate' => 10,
            'is_active' => true,
        ];

        $this->postJson($this->baseUrl, $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'STD')
            ->assertJsonPath('data.rate', '10.00');

        $taxType = TaxType::whereTranslation('name', '標準税率')->first();
        expect($taxType)->not->toBeNull();
        expect($taxType->organization_id)->toBe($this->orgId);
        expect($taxType->brand_id)->toBe($this->brand->id);
        expect($taxType->translate('en')->name)->toBe('Standard');
        expect($taxType->translate('vi')->name)->toBe('Tiêu chuẩn');
    });

    it('defaults is_default false and is_active true', function () {
        $this->postJson($this->baseUrl, [
            'code' => 'EXE',
            'en' => ['name' => 'Exempt'],
            'rate' => 0,
        ])->assertCreated()
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.is_active', true);
    });

    it('rejects a mis-shaped object `name` with 422, not a 500 (BUG-4 regression)', function () {
        // The correct contract is top-level locale keys ({ja:{name},…}); a client
        // that sends `name` as an object used to blow up the (string) cast in
        // withValidator with an "Array to string conversion" 500.
        $this->postJson($this->baseUrl, [
            'code' => 'BADNAME',
            'name' => ['ja' => 'x'],
            'rate' => 8,
        ])->assertStatus(422);
    });
});

describe('show', function () {
    it('returns a tax type with products_count', function () {
        $taxType = taxTypeInBrand();

        $this->getJson("{$this->baseUrl}/{$taxType->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $taxType->id)
            ->assertJsonStructure(['data' => ['id', 'code', 'name', 'rate', 'is_default', 'is_active', 'products_count']]);
    });
});

describe('update', function () {
    it('updates rates and returns 200', function () {
        $taxType = taxTypeInBrand(['rate' => 10]);

        $this->putJson("{$this->baseUrl}/{$taxType->id}", [
            'rate' => 8,
        ])->assertOk()
            ->assertJsonPath('data.rate', '8.00');

        expect((float) $taxType->fresh()->rate)->toBe(8.0);
    });

    it('cannot change the code via update', function () {
        $taxType = taxTypeInBrand(['code' => 'ORIGINAL']);

        $this->putJson("{$this->baseUrl}/{$taxType->id}", [
            'code' => 'CHANGED',
        ])->assertUnprocessable();

        expect($taxType->fresh()->code)->toBe('ORIGINAL');
    });
});

describe('destroy', function () {
    it('soft-deletes an unused tax type and returns 204', function () {
        $taxType = taxTypeInBrand();

        $this->deleteJson("{$this->baseUrl}/{$taxType->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('tax_types', ['id' => $taxType->id]);
    });
});

// =========================================================================
//  Lookup
// =========================================================================

describe('lookup', function () {
    it('returns only active types with id, code, name, rates and is_default', function () {
        taxTypeInBrand(['is_active' => true, 'code' => 'ACT', 'rate' => 8]);
        taxTypeInBrand(['is_active' => false, 'code' => 'INACT']);

        $response = $this->getJson("{$this->baseUrl}/lookup")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        expect($response->json('data.0'))
            ->toHaveKeys(['id', 'code', 'name', 'rate', 'is_default']);
        expect($response->json('data.0.code'))->toBe('ACT');
    });
});

// =========================================================================
//  is_default single-per-brand
// =========================================================================

describe('is_default enforcement', function () {
    it('setting is_default on create clears the previous brand default', function () {
        $old = taxTypeInBrand(['is_default' => true, 'code' => 'OLD']);

        $this->postJson($this->baseUrl, [
            'code' => 'NEW',
            'en' => ['name' => 'New Default'],
            'rate' => 10,
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_default', true);

        expect($old->fresh()->is_default)->toBeFalse();
        expect(TaxType::where('brand_id', $this->brand->id)->where('is_default', true)->count())->toBe(1);
    });

    it('setting is_default on update clears the previous brand default', function () {
        $old = taxTypeInBrand(['is_default' => true, 'code' => 'OLD']);
        $challenger = taxTypeInBrand(['is_default' => false, 'code' => 'NEW']);

        $this->putJson("{$this->baseUrl}/{$challenger->id}", ['is_default' => true])
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        expect($old->fresh()->is_default)->toBeFalse();
        expect(TaxType::where('brand_id', $this->brand->id)->where('is_default', true)->count())->toBe(1);
    });
});

// =========================================================================
//  Restore + toggle-status
// =========================================================================

describe('restore', function () {
    it('restores a soft-deleted tax type', function () {
        $taxType = taxTypeInBrand();
        $taxType->delete();

        $this->postJson("{$this->baseUrl}/{$taxType->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $taxType->id);

        expect($taxType->fresh()->deleted_at)->toBeNull();
    });
});

describe('toggle status', function () {
    it('flips is_active and removes the type from lookup', function () {
        $taxType = taxTypeInBrand(['is_active' => true, 'code' => 'TOG']);

        $this->postJson("{$this->baseUrl}/{$taxType->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect($taxType->fresh()->is_active)->toBeFalse();

        $this->getJson("{$this->baseUrl}/lookup")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

// =========================================================================
//  409 TAX_TYPE_IN_USE
// =========================================================================

describe('delete guard', function () {
    it('returns 409 when referenced by a product', function () {
        $taxType = taxTypeInBrand();
        Product::factory()->forBrand($this->brand)->create(['tax_type_id' => $taxType->id]);

        $this->deleteJson("{$this->baseUrl}/{$taxType->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'TAX_TYPE_IN_USE')
            ->assertJsonPath('meta.products', 1)
            ->assertJsonPath('meta.menu_products', 0)
            ->assertJsonPath('meta.branch_defaults', 0);
    });

    it('returns 409 when referenced by a menu-product override', function () {
        $taxType = taxTypeInBrand();
        $product = Product::factory()->forBrand($this->brand)->create();
        $menu = Menu::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        MenuProduct::factory()->create([
            'menu_id' => $menu->id,
            'product_id' => $product->id,
            'tax_type_id' => $taxType->id,
        ]);

        $this->deleteJson("{$this->baseUrl}/{$taxType->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'TAX_TYPE_IN_USE')
            ->assertJsonPath('meta.menu_products', 1);
    });

    it('returns 409 when set as a branch default', function () {
        $taxType = taxTypeInBrand();
        $branch = Branch::factory()->create(['console_organization_id' => $this->orgId]);
        ShopOrderSetting::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $branch->id,
            'default_tax_type_id' => $taxType->id,
        ]);

        $this->deleteJson("{$this->baseUrl}/{$taxType->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'TAX_TYPE_IN_USE')
            ->assertJsonPath('meta.branch_defaults', 1);
    });
});

describe('bulk delete', function () {
    it('deletes free types and reports in-use rows per row', function () {
        $free1 = taxTypeInBrand(['code' => 'FREE1']);
        $free2 = taxTypeInBrand(['code' => 'FREE2']);
        $inUse = taxTypeInBrand(['code' => 'USED']);
        Product::factory()->forBrand($this->brand)->create(['tax_type_id' => $inUse->id]);

        $response = $this->postJson("{$this->baseUrl}/bulk-delete", [
            'ids' => [$free1->id, $free2->id, $inUse->id],
        ])->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertSoftDeleted('tax_types', ['id' => $free1->id]);
        $this->assertSoftDeleted('tax_types', ['id' => $free2->id]);
        expect($inUse->fresh()->deleted_at)->toBeNull();

        $errors = $response->json('errors');
        expect($errors)->toHaveCount(1);
        expect($errors[0]['id'])->toBe($inUse->id);
        expect($errors[0]['code'])->toBe('TAX_TYPE_IN_USE');
    });
});

// =========================================================================
//  Validation
// =========================================================================

describe('validation', function () {
    it('rejects missing code / name / rates with 422', function () {
        $this->postJson($this->baseUrl, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'rate', 'ja.name']);
    });

    it('rejects a duplicate code within the same brand', function () {
        taxTypeInBrand(['code' => 'DUP']);

        $this->postJson($this->baseUrl, [
            'code' => 'DUP',
            'en' => ['name' => 'Dup'],
            'rate' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    });

    it('allows the same code on another brand', function () {
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);
        TaxType::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'code' => 'SHARED',
        ]);

        $this->postJson($this->baseUrl, [
            'code' => 'SHARED',
            'en' => ['name' => 'Shared'],
            'rate' => 10,
        ])->assertCreated();
    });

    it('rejects rates outside 0-100', function () {
        $this->postJson($this->baseUrl, [
            'code' => 'BAD',
            'en' => ['name' => 'Bad'],
            'rate' => 101,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['rate']);
    });
});

// =========================================================================
//  Authorization
// =========================================================================

describe('authorization', function () {
    it('returns 401 when unauthenticated', function () {
        Auth::forgetGuards();

        $this->getJson($this->baseUrl)->assertUnauthorized();
    });

    it('returns 403 for an HQ user of another org on every endpoint', function () {
        // A tax type owned by our brand, but the acting user belongs to a
        // different org with no role on this brand → ResolveBrandFromSlug 403.
        $taxType = taxTypeInBrand();

        $foreignOrg = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $foreignOrg,
            'console_organization_id' => $foreignOrg,
        ]);
        $foreignUser = User::factory()->create([
            'console_organization_id' => $foreignOrg,
        ]);
        grantOrgAccess($foreignUser, $foreignOrg);

        $this->actingAs($foreignUser);

        $this->getJson($this->baseUrl)->assertForbidden();
        $this->postJson($this->baseUrl, ['code' => 'X', 'en' => ['name' => 'X'], 'rate' => 10])->assertForbidden();
        $this->getJson("{$this->baseUrl}/{$taxType->id}")->assertForbidden();
        $this->putJson("{$this->baseUrl}/{$taxType->id}", ['rate' => 5])->assertForbidden();
        $this->deleteJson("{$this->baseUrl}/{$taxType->id}")->assertForbidden();
        $this->getJson("{$this->baseUrl}/lookup")->assertForbidden();
        $this->postJson("{$this->baseUrl}/bulk-delete", ['ids' => [$taxType->id]])->assertForbidden();
        $this->postJson("{$this->baseUrl}/{$taxType->id}/restore")->assertForbidden();
        $this->postJson("{$this->baseUrl}/{$taxType->id}/toggle-status")->assertForbidden();
    });

    it('returns 401 for a device token (SSO-only routes)', function () {
        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer not-a-valid-sso-token'])
            ->getJson($this->baseUrl)
            ->assertUnauthorized();
    });
});

// =========================================================================
//  Product write validation (endpoint #13 + A1)
// =========================================================================

describe('product tax_type_id validation', function () {
    beforeEach(function () {
        $this->productBase = "/api/v1/hq/{$this->brand->slug}/products";
        $this->stdType = TaxType::factory()->standard()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $this->reducedType = TaxType::factory()->reduced()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $this->exemptType = TaxType::factory()->exempt()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $this->productType = ProductType::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_active' => true,
        ]);
    });

    it('accepts a valid same-brand active tax type on update', function () {
        $product = Product::factory()->forBrand($this->brand)->create();

        $this->putJson("{$this->productBase}/{$product->id}", [
            'tax_type_id' => $this->reducedType->id,
        ])->assertOk();

        expect($product->fresh()->tax_type_id)->toBe($this->reducedType->id);
    });

    it('rejects a tax type from another brand with 422', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
        $foreignType = TaxType::factory()->standard()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'code' => 'FOREIGN',
        ]);
        $product = Product::factory()->forBrand($this->brand)->create();

        $this->putJson("{$this->productBase}/{$product->id}", [
            'tax_type_id' => $foreignType->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tax_type_id']);
    });

    it('rejects an inactive tax type on assignment with 422', function () {
        $inactive = TaxType::factory()->standard()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'code' => 'INACT',
            'is_active' => false,
        ]);
        $product = Product::factory()->forBrand($this->brand)->create();

        $this->putJson("{$this->productBase}/{$product->id}", [
            'tax_type_id' => $inactive->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tax_type_id']);
    });
});
