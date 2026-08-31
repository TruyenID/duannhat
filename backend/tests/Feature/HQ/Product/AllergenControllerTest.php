<?php

use App\Models\Allergen;
use App\Models\Brand;
use App\Models\Material;
use App\Models\User;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";

    $this->actingAs($this->user);
});

// =========================================================================
//  Index (list)
// =========================================================================

describe('index', function () {
    it('lists allergens for the user organization', function () {
        Allergen::factory()->count(3)->create([
            'organization_id' => $this->orgId,
        ]);

        $this->getJson("{$this->baseUrl}/allergens")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('returns translated name for the current locale', function () {
        $allergen = Allergen::factory()->create([
            'organization_id' => $this->orgId,
            'code' => 'milk_custom',
        ]);

        // Persist all 3 locales via Astrotomic sidecar.
        $allergen->translateOrNew('ja')->name = '牛乳';
        $allergen->translateOrNew('en')->name = 'Milk';
        $allergen->translateOrNew('vi')->name = 'Sữa';
        $allergen->save();

        // `?locale=ja` routes through SetLocale middleware and flips the
        // request locale so Astrotomic resolves translated fields to JP.
        $response = $this->getJson("{$this->baseUrl}/allergens?locale=ja")
            ->assertOk();

        // Find the row (list may include other random-factory rows if any)
        $row = collect($response->json('data'))
            ->firstWhere('code', 'milk_custom');

        expect($row)->not->toBeNull();
        expect($row['name'])->toBe('牛乳');
    });
});

// =========================================================================
//  Store (create)
// =========================================================================

describe('store', function () {
    it('creates an allergen and persists all three translations', function () {
        $response = $this->postJson("{$this->baseUrl}/allergens", [
            'code' => 'milk_custom',
            'name:ja' => '牛乳',
            'name:en' => 'Milk',
            'name:vi' => 'Sữa',
            'jurisdiction' => 'jp',
            'severity' => 'mandatory',
            'is_active' => true,
        ])->assertCreated();

        $allergenId = $response->json('data.id');

        $this->assertDatabaseHas('allergens', [
            'id' => $allergenId,
            'code' => 'milk_custom',
            'jurisdiction' => 'jp',
        ]);

        foreach (['ja' => '牛乳', 'en' => 'Milk', 'vi' => 'Sữa'] as $locale => $name) {
            $this->assertDatabaseHas('allergen_translations', [
                'allergen_id' => $allergenId,
                'locale' => $locale,
                'name' => $name,
            ]);
        }
    });

    it('validates that name is required', function () {
        $this->postJson("{$this->baseUrl}/allergens", [
            'code' => 'no_name',
            'jurisdiction' => 'jp',
            'severity' => 'mandatory',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('rejects an uppercase code (rule is lowercase/digits/underscore only)', function () {
        $this->postJson("{$this->baseUrl}/allergens", [
            'code' => 'MILK',
            'name' => 'Milk',
            'jurisdiction' => 'jp',
            'severity' => 'mandatory',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    });

    it('accepts a lowercase code with digits and underscore', function () {
        $this->postJson("{$this->baseUrl}/allergens", [
            'code' => 'tree_nuts_2',
            'name' => 'Tree nuts',
            'jurisdiction' => 'jp',
            'severity' => 'mandatory',
        ])->assertCreated();
    });

    it('validates jurisdiction enum', function () {
        $this->postJson("{$this->baseUrl}/allergens", [
            'code' => 'bad_jur',
            'name' => 'Bad jurisdiction',
            'jurisdiction' => 'aq',
            'severity' => 'mandatory',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['jurisdiction']);
    });

    it('rejects duplicate (code, jurisdiction) pair', function () {
        Allergen::factory()->create([
            'organization_id' => $this->orgId,
            'code' => 'milk',
            'jurisdiction' => 'jp',
        ]);

        $this->postJson("{$this->baseUrl}/allergens", [
            'code' => 'milk',
            'name' => 'Milk duplicate',
            'jurisdiction' => 'jp',
            'severity' => 'mandatory',
            'is_active' => true,
        ])->assertUnprocessable();
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('updates only the provided locale and leaves the others untouched', function () {
        $allergen = Allergen::factory()->create([
            'organization_id' => $this->orgId,
            'code' => 'milk_custom',
        ]);

        $allergen->translateOrNew('ja')->name = '牛乳';
        $allergen->translateOrNew('en')->name = 'Milk';
        $allergen->translateOrNew('vi')->name = 'Sữa';
        $allergen->save();

        $this->putJson("{$this->baseUrl}/allergens/{$allergen->id}", [
            'name:en' => 'Milk (updated)',
        ])->assertOk();

        $this->assertDatabaseHas('allergen_translations', [
            'allergen_id' => $allergen->id,
            'locale' => 'en',
            'name' => 'Milk (updated)',
        ]);

        $this->assertDatabaseHas('allergen_translations', [
            'allergen_id' => $allergen->id,
            'locale' => 'ja',
            'name' => '牛乳',
        ]);

        $this->assertDatabaseHas('allergen_translations', [
            'allergen_id' => $allergen->id,
            'locale' => 'vi',
            'name' => 'Sữa',
        ]);
    });
});

// =========================================================================
//  Destroy (soft delete)
// =========================================================================

describe('destroy', function () {
    it('soft-deletes an unused allergen and excludes it from list', function () {
        $allergen = Allergen::factory()->create([
            'organization_id' => $this->orgId,
        ]);

        $this->deleteJson("{$this->baseUrl}/allergens/{$allergen->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('allergens', ['id' => $allergen->id]);

        $response = $this->getJson("{$this->baseUrl}/allergens")->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->not->toContain($allergen->id);
    });

    it('returns 409 ALLERGEN_IN_USE when still referenced by a material', function () {
        $allergen = Allergen::factory()->create(['organization_id' => $this->orgId]);
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $material->allergens()->attach($allergen->id);

        $this->deleteJson("{$this->baseUrl}/allergens/{$allergen->id}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'ALLERGEN_IN_USE')
            ->assertJsonPath('used_by.0.id', $material->id);
    });
});

// =========================================================================
//  Authentication / Authorization
// =========================================================================

it('returns 401 when not authenticated', function () {
    auth()->forgetGuards();
    app('auth')->forgetGuards();

    $this->postJson("{$this->baseUrl}/allergens", [
        'code' => 'auth_test',
        'name' => 'Auth',
        'jurisdiction' => 'jp',
        'severity' => 'mandatory',
        'is_active' => true,
    ])->assertUnauthorized();
});

it('returns 403 when updating an allergen in another org', function () {
    $allergen = Allergen::factory()->create([
        'organization_id' => fake()->uuid(),
    ]);

    $this->putJson("{$this->baseUrl}/allergens/{$allergen->id}", [
        'name:en' => 'Hacked',
    ])->assertForbidden();
});

it('returns 403 when deleting an allergen in another org', function () {
    $allergen = Allergen::factory()->create([
        'organization_id' => fake()->uuid(),
    ]);

    $this->deleteJson("{$this->baseUrl}/allergens/{$allergen->id}")
        ->assertForbidden();
});
