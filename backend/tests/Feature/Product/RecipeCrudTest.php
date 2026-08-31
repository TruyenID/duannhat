<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
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

    $this->actingAs($this->user);
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists recipes for the user organization', function () {
        Recipe::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/recipes")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters recipes by material_id (output material)', function () {
        $materialA = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $materialB = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $recipeForA = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'material_id' => $materialA->id,
        ]);
        Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'material_id' => $materialB->id,
        ]);

        $this->getJson("{$this->baseUrl}/recipes?material_id={$materialA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recipeForA->id);

        // No param => both recipes returned (filter is opt-in).
        $this->getJson("{$this->baseUrl}/recipes")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('filters by is_active when the query param is present', function () {
        Recipe::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'is_active' => true,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);
        Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => false,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/recipes?is_active=1")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("{$this->baseUrl}/recipes?is_active=0")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('returns all recipes when is_active is omitted', function () {
        Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => true,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);
        Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => false,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/recipes")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a recipe with name', function () {
        $this->postJson("{$this->baseUrl}/recipes", [
            'organization_id' => $this->orgId,
            'name' => 'Chocolate Cake',
            'output_quantity' => 1,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chocolate Cake');

        $this->assertDatabaseHas('recipe_translations', [
            'name' => 'Chocolate Cake',
        ]);
    });

    it('auto-generates SKU when not provided', function () {
        $this->postJson("{$this->baseUrl}/recipes", [
            'organization_id' => $this->orgId,
            'name' => 'Auto SKU Recipe',
            'output_quantity' => 1,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])->assertCreated();

        $recipe = Recipe::whereTranslation('name', 'Auto SKU Recipe')->first();

        expect($recipe->sku)->not->toBeEmpty();
    });

    it('stores ingredients JSON', function () {
        // Plan-022 T18 — ingredients must now carry a real material_id and
        // belong to the same brand as the recipe.
        $flour = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $milk = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $ingredients = [
            ['type' => 'material', 'material_id' => $flour->id, 'quantity' => 500, 'unit' => 'g'],
            ['type' => 'material', 'material_id' => $milk->id, 'quantity' => 200, 'unit' => 'ml'],
        ];

        $this->postJson("{$this->baseUrl}/recipes", [
            'organization_id' => $this->orgId,
            'name' => 'Recipe With Ingredients',
            'output_quantity' => 1,
            'is_active' => true,
            'brand_id' => $this->brand->id,
            'ingredients' => $ingredients,
        ])->assertCreated();

        $recipe = Recipe::whereTranslation('name', 'Recipe With Ingredients')->first();

        expect($recipe->ingredients)->toBeArray()
            ->toHaveCount(2);
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a recipe with material relation', function () {
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $recipe = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'material_id' => $material->id,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/recipes/{$recipe->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $recipe->id)
            ->assertJsonStructure(['data' => ['id', 'name', 'sku', 'material']]);
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('updates recipe name and ingredients', function () {
        $recipe = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Old Recipe',
            'material_id' => null,
            'ingredients' => [],
            'brand_id' => $this->brand->id,
        ]);

        $butter = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $newIngredients = [
            ['type' => 'material', 'material_id' => $butter->id, 'quantity' => 100, 'unit' => 'g'],
        ];

        $this->putJson("{$this->baseUrl}/recipes/{$recipe->id}", [
            'name' => 'Updated Recipe',
            'ingredients' => $newIngredients,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Recipe');

        $recipe->refresh();

        expect($recipe->ingredients)->toBeArray()
            ->toHaveCount(1);
    });
});

// =========================================================================
//  Destroy
// =========================================================================

describe('destroy', function () {
    it('soft deletes a recipe', function () {
        $recipe = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->deleteJson("{$this->baseUrl}/recipes/{$recipe->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('recipes', ['id' => $recipe->id]);
    });
});

// =========================================================================
//  Restore
// =========================================================================

describe('restore', function () {
    it('restores a soft-deleted recipe', function () {
        $recipe = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);
        $recipe->delete();

        $this->postJson("{$this->baseUrl}/recipes/{$recipe->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $recipe->id);

        expect($recipe->fresh()->deleted_at)->toBeNull();
    });
});

// =========================================================================
//  Lookup
// =========================================================================

describe('lookup', function () {
    it('returns only active recipes', function () {
        Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => true,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);
        Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => false,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/recipes/lookup")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Bulk Delete
// =========================================================================

describe('bulk delete', function () {
    it('deletes multiple recipes', function () {
        $recipes = Recipe::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'material_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->postJson("{$this->baseUrl}/recipes/bulk-delete", [
            'ids' => $recipes->pluck('id')->toArray(),
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 3);

        foreach ($recipes as $recipe) {
            $this->assertSoftDeleted('recipes', ['id' => $recipe->id]);
        }
    });
});

// =========================================================================
//  Authentication
// =========================================================================

it('returns 401 when not authenticated', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/recipes")
        ->assertUnauthorized();
});

// =========================================================================
//  Org Isolation
// =========================================================================

it('returns 403 when showing another org recipe', function () {
    $recipe = Recipe::factory()->create([
        'organization_id' => fake()->uuid(),
        'material_id' => null,
    ]);

    $this->getJson("{$this->baseUrl}/recipes/{$recipe->id}")
        ->assertForbidden();
});

it('returns 403 when updating another org recipe', function () {
    $recipe = Recipe::factory()->create([
        'organization_id' => fake()->uuid(),
        'material_id' => null,
    ]);

    $this->putJson("{$this->baseUrl}/recipes/{$recipe->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

it('returns 403 when deleting another org recipe', function () {
    $recipe = Recipe::factory()->create([
        'organization_id' => fake()->uuid(),
        'material_id' => null,
    ]);

    $this->deleteJson("{$this->baseUrl}/recipes/{$recipe->id}")
        ->assertForbidden();
});

// =========================================================================
//  404 — Non-existent & Soft-deleted
// =========================================================================

it('returns 404 for non-existent recipe', function () {
    $this->getJson("{$this->baseUrl}/recipes/".fake()->uuid())
        ->assertNotFound();
});

it('returns 404 when showing a soft-deleted recipe', function () {
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'material_id' => null,
        'brand_id' => $this->brand->id,
    ]);
    $recipe->delete();

    $this->getJson("{$this->baseUrl}/recipes/{$recipe->id}")
        ->assertNotFound();
});
