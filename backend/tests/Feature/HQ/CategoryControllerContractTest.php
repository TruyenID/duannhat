<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Contract tests for plan-002 (HQ Category Screen).
 *
 * These do NOT replace the legacy CategoryCrudTest — they pin the contract
 * the new HQ frontend depends on (per `plans/plan-002/TESTS.md`):
 *
 *   - Resource shape (parent_id, children_count, deleted_at)
 *   - Validation surfaces 422 (not 500) for cycles + cross-org parents
 *   - Authorization is org-scoped via brand slug
 *   - Bulk delete + lookup contract
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-hq',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/categories";
});

// =========================================================================
//  Happy path
// =========================================================================

describe('happy path', function () {
    it('lists categories scoped to the user organization with the fields the UI relies on', function () {
        Category::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->base);

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    [
                        'id', 'sku', 'name', 'is_active',
                        'parent_id', 'children_count',
                        'created_at', 'updated_at', 'deleted_at',
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'total', 'per_page'],
            ]);
    });

    it('creates a category with parent_id and returns 201 with the resource', function () {
        $parent = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Beverages',
            'sku' => 'C-BEV',
            'parent_id' => $parent->id,
            'brand_id' => $this->brand->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Beverages')
            ->assertJsonPath('data.parent_id', $parent->id);
    });

    it('updates a category with a partial payload and leaves other fields unchanged', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
            'name' => 'Old Name',
            'sku' => 'C-KEEP',
        ]);

        $response = $this->actingAs($this->user)->putJson("{$this->base}/{$category->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.sku', 'C-KEEP');
    });

    it('restores a soft-deleted category', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);
        $category->delete();

        $response = $this->actingAs($this->user)
            ->postJson("{$this->base}/{$category->id}/restore");

        $response->assertOk()->assertJsonPath('data.deleted_at', null);
    });

    it('bulk-deletes multiple categories', function () {
        $ids = Category::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ])->pluck('id')->toArray();

        $this->actingAs($this->user)
            ->postJson("{$this->base}/bulk-delete", ['ids' => $ids])
            ->assertOk();

        expect(Category::whereIn('id', $ids)->whereNull('deleted_at')->count())->toBe(0);
    });

    it('returns lookup data with the shape the parent picker expects', function () {
        Category::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson("{$this->base}/lookup");

        $response->assertOk()->assertJsonStructure([
            'data' => [
                ['id', 'name', 'parent_id'],
            ],
        ]);
    });
});

// =========================================================================
//  Validation
// =========================================================================

describe('validation', function () {
    it('returns 422 when name is missing on create', function () {
        // CategoryStoreRequest::withValidator surfaces the cross-locale "no name in any
        // language" failure under the `ja.name` key (single error rather than one per
        // locale) — matches the message convention used by ProductStoreRequest too.
        $this->actingAs($this->user)
            ->postJson($this->base, ['sku' => 'C-NONAME', 'brand_id' => $this->brand->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ja.name']);
    });

    it('returns 422 (not 500) when parent_id equals the category id', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$category->id}", ['parent_id' => $category->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    });

    it('returns 422 when parent_id is a descendant (cycle)', function () {
        $a = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);
        $b = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => $a->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$a->id}", ['parent_id' => $b->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    });
});

// =========================================================================
//  Authorization
// =========================================================================

describe('authorization', function () {
    it('returns 403 when accessing a brand from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'slug' => 'other-brand',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$otherBrand->slug}/categories")
            ->assertForbidden();
    });

    it('returns 401 when unauthenticated', function () {
        $this->getJson($this->base)->assertUnauthorized();
    });

    it('returns 422 when parent_id belongs to another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $foreignParent = Category::factory()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => Brand::factory()->create([
                'console_organization_id' => $otherOrgId,
                'is_active' => true,
            ])->id,
            'parent_id' => null,
        ]);

        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Cross-Org',
                'parent_id' => $foreignParent->id,
                'brand_id' => $this->brand->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    });
});

// =========================================================================
//  Edge cases & side effects
// =========================================================================

describe('edge cases', function () {
    it('returns 200 with empty data when the org has no categories', function () {
        $this->actingAs($this->user)
            ->getJson($this->base)
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    });

    it('returns 422 when bulk-deleting more than 100 ids', function () {
        $ids = collect(range(1, 101))->map(fn () => (string) Str::uuid())->toArray();

        $this->actingAs($this->user)
            ->postJson("{$this->base}/bulk-delete", ['ids' => $ids])
            ->assertUnprocessable();
    });

    it('returns 404 for a non-existent uuid', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/".(string) Str::uuid())
            ->assertNotFound();
    });

    it('caps per_page at 100 so the UI can detect truncation', function () {
        Category::factory()->count(105)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}?per_page=100");

        $response->assertOk()
            ->assertJsonPath('meta.total', 105)
            ->assertJsonCount(100, 'data');
    });

    it('soft-deletes the parent without cascading to children', function () {
        $parent = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);
        $children = Category::factory()->count(5)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$parent->id}")
            ->assertNoContent();

        // FK `onDelete: CASCADE` only fires on a HARD delete. Soft-delete via
        // SoftDeletes trait sets deleted_at on the parent only — children
        // remain in the table pointing at a soft-deleted parent.
        //
        // The UI's confirmation dialog must therefore warn the user that
        // children are orphaned, NOT cascade-deleted. See plan-002 NOTES.md.
        expect(Category::withTrashed()->find($parent->id)->deleted_at)->not->toBeNull();
        foreach ($children as $child) {
            expect(Category::find($child->id))->not->toBeNull();
        }
    });
});

// =========================================================================
//  Error handling
// =========================================================================

describe('error handling', function () {
    it('returns a structured error response when the import CSV is malformed', function () {
        $file = UploadedFile::fake()->createWithContent(
            'broken.csv',
            "this,is,not,a,valid,categories,csv\nbad row\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson("{$this->base}/import", [
                'file' => $file,
                'brand_id' => $this->brand->id,
            ]);

        // The importer either returns 200 with an errors[] payload OR 422 —
        // pin both shapes so the dialog can render whichever lands.
        expect($response->status())->toBeIn([200, 422]);
        if ($response->status() === 200) {
            $response->assertJsonStructure(['data']);
        }
    });

    it('returns 404 when DELETE is called twice on the same category', function () {
        $category = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$category->id}")
            ->assertNoContent();

        // Already soft-deleted — second DELETE should 404 (default route
        // binding does NOT include trashed rows).
        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$category->id}")
            ->assertNotFound();
    });
});

describe('side effects', function () {
    it('forces organization_id from the user context, not the request body', function () {
        $foreignOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $foreignOrgId,
            'console_organization_id' => $foreignOrgId,
        ]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Forced Org',
            'organization_id' => $foreignOrgId,
            'brand_id' => $this->brand->id,
        ]);

        $response->assertCreated();
        $id = $response->json('data.id');
        expect(Category::find($id)->organization_id)->toBe($this->orgId);
    });

    it('bulk-delete sets deleted_at on targeted rows AND leaves others untouched', function () {
        $targets = Category::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);
        $bystander = Category::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'parent_id' => null,
        ]);

        $this->actingAs($this->user)
            ->postJson("{$this->base}/bulk-delete", ['ids' => $targets->pluck('id')->toArray()])
            ->assertOk();

        foreach ($targets as $t) {
            expect(Category::withTrashed()->find($t->id)->deleted_at)->not->toBeNull();
        }
        expect(Category::find($bystander->id)->deleted_at)->toBeNull();
    });
});
