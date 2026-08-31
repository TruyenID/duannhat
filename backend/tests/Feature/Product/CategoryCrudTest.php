<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
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
    it('lists categories for the user organization', function () {
        Category::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/categories")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters categories by parent_id', function () {
        $parent = Category::factory()->create([
            'organization_id' => $this->orgId,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);
        Category::factory()->create([
            'organization_id' => $this->orgId,
            'parent_id' => $parent->id,
            'brand_id' => $this->brand->id,
        ]);
        Category::factory()->create([
            'organization_id' => $this->orgId,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/categories?parent_id={$parent->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters categories by search', function () {
        Category::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Food Items',
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);
        Category::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Beverages',
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/categories?search=Food")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Food Items');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('preserves image_url through the typed mutation boundary', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'name' => 'Pictured',
            'image_url' => 'https://cdn.example.test/category.png',
        ])->assertCreated()->assertJsonPath('data.image_url', 'https://cdn.example.test/category.png');

        $this->assertDatabaseHas('categories', ['image_url' => 'https://cdn.example.test/category.png']);
    });

    it('creates a category with name', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'name' => 'Appetizers',
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Appetizers');

        $this->assertDatabaseHas('categories', [
            'name' => 'Appetizers',
            'organization_id' => $this->orgId,
        ]);
    });

    it('auto-generates SKU when not provided', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'name' => 'Auto SKU Category',
            'brand_id' => $this->brand->id,
        ])->assertCreated();

        $category = Category::where('name', 'Auto SKU Category')->first();

        expect($category->sku)->not->toBeEmpty();
    });

    it('validates that parent_id exists', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'name' => 'Orphan',
            'parent_id' => fake()->uuid(),
            'brand_id' => $this->brand->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    });

    // =========================================================================
    //  Translatable priority fallback — ja → en → vi
    //
    // Users can submit only 1 or 2 locales; the base `name` column mirrors
    // the first non-empty value in priority order so lookups and search
    // keep working even when the default locale wasn't filled.
    // =========================================================================

    it('derives base name from ja when only ja.name is provided', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'ja' => ['name' => 'ぜんさい'],
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'ぜんさい');

        $this->assertDatabaseHas('categories', ['name' => 'ぜんさい']);
    });

    it('derives base name from en when only en.name is provided', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'en' => ['name' => 'Appetizers'],
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Appetizers');

        $this->assertDatabaseHas('categories', ['name' => 'Appetizers']);
    });

    it('derives base name from vi when only vi.name is provided', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'vi' => ['name' => 'Khai vị'],
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Khai vị');

        $this->assertDatabaseHas('categories', ['name' => 'Khai vị']);
    });

    it('prefers en over vi when ja is empty', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'en' => ['name' => 'Appetizers'],
            'vi' => ['name' => 'Khai vị'],
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Appetizers');
    });

    it('prefers ja when all three locales are provided', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'ja' => ['name' => 'ぜんさい'],
            'en' => ['name' => 'Appetizers'],
            'vi' => ['name' => 'Khai vị'],
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'ぜんさい');
    });

    it('rejects when neither top-level nor any locale name is provided', function () {
        $this->postJson("{$this->baseUrl}/categories", [
            'brand_id' => $this->brand->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ja.name']);
    });

    it('falls back to the priority-derived name when requesting a locale without a translation', function () {
        // User fills only EN. The base column mirrors EN (priority order
        // ja → en → vi, EN is the first non-empty). Requests coming in
        // with Accept-Language: ja or vi should not see null — Astrotomic
        // property fallback returns the base column value.
        $created = $this->postJson("{$this->baseUrl}/categories", [
            'en' => ['name' => 'Appetizers'],
            'brand_id' => $this->brand->id,
        ])->assertCreated()->json('data');

        $id = $created['id'];

        $this->getJson("{$this->baseUrl}/categories/{$id}", ['Accept-Language' => 'ja'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Appetizers');

        $this->getJson("{$this->baseUrl}/categories/{$id}", ['Accept-Language' => 'vi'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Appetizers');
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a category with parent and children counts', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonStructure(['data' => ['id', 'name', 'children_count', 'products_count']]);
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('updates image_url and removes an explicitly cleared locale', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
            'image_url' => 'https://cdn.example.test/old.png',
            'en' => ['name' => 'English'],
            'ja' => ['name' => '日本語'],
        ]);

        $this->putJson("{$this->baseUrl}/categories/{$category->id}", [
            'image_url' => 'https://cdn.example.test/new.png',
            'en' => null,
            'ja' => ['name' => '日本語'],
        ])->assertOk()->assertJsonPath('data.image_url', 'https://cdn.example.test/new.png');

        $category->refresh();
        expect($category->translate('en', false))->toBeNull()
            ->and($category->image_url)->toBe('https://cdn.example.test/new.png');
    });

    it('updates the category name', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Old Name',
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->putJson("{$this->baseUrl}/categories/{$category->id}", [
            'name' => 'New Name',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    });

    it('prevents setting own id as parent (circular reference)', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->putJson("{$this->baseUrl}/categories/{$category->id}", [
            'parent_id' => $category->id,
        ])
            ->assertStatus(422);
    });
});

// =========================================================================
//  Destroy
// =========================================================================

describe('destroy', function () {
    it('soft deletes a category', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->deleteJson("{$this->baseUrl}/categories/{$category->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    });
});

// =========================================================================
//  Restore
// =========================================================================

describe('restore', function () {
    it('restores a soft-deleted category', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);
        $category->delete();

        $this->postJson("{$this->baseUrl}/categories/{$category->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id);

        expect($category->fresh()->deleted_at)->toBeNull();
    });
});

// =========================================================================
//  Lookup
// =========================================================================

describe('lookup', function () {
    it('returns only active categories', function () {
        Category::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => true,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);
        Category::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => false,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/categories/lookup")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Bulk Delete
// =========================================================================

describe('bulk delete', function () {
    it('deletes multiple categories', function () {
        $categories = Category::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'parent_id' => null,
            'brand_id' => $this->brand->id,
        ]);

        $this->postJson("{$this->baseUrl}/categories/bulk-delete", [
            'ids' => $categories->pluck('id')->toArray(),
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 3);

        foreach ($categories as $category) {
            $this->assertSoftDeleted('categories', ['id' => $category->id]);
        }
    });
});

// =========================================================================
//  Authentication
// =========================================================================

it('returns 401 when not authenticated', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/categories")
        ->assertUnauthorized();
});

// =========================================================================
//  Org Isolation
// =========================================================================

it('returns 403 when showing another org category', function () {
    $otherOrgId = fake()->uuid();
    Organization::factory()->create(['id' => $otherOrgId]);
    $category = Category::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => Brand::factory()->create()->id,
        'parent_id' => null,
    ]);

    $this->getJson("{$this->baseUrl}/categories/{$category->id}")
        ->assertForbidden();
});

it('returns 403 when updating another org category', function () {
    $otherOrgId = fake()->uuid();
    Organization::factory()->create(['id' => $otherOrgId]);
    $category = Category::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => Brand::factory()->create()->id,
        'parent_id' => null,
    ]);

    $this->putJson("{$this->baseUrl}/categories/{$category->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

it('returns 403 when deleting another org category', function () {
    $otherOrgId = fake()->uuid();
    Organization::factory()->create(['id' => $otherOrgId]);
    $category = Category::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => Brand::factory()->create()->id,
        'parent_id' => null,
    ]);

    $this->deleteJson("{$this->baseUrl}/categories/{$category->id}")
        ->assertForbidden();
});

// =========================================================================
//  404 — Non-existent & Soft-deleted
// =========================================================================

it('returns 404 for non-existent category', function () {
    $this->getJson("{$this->baseUrl}/categories/".fake()->uuid())
        ->assertNotFound();
});

it('returns 404 when showing a soft-deleted category', function () {
    $category = Category::factory()->create([
        'organization_id' => $this->orgId,
        'parent_id' => null,
        'brand_id' => $this->brand->id,
    ]);
    $category->delete();

    $this->getJson("{$this->baseUrl}/categories/{$category->id}")
        ->assertNotFound();
});
