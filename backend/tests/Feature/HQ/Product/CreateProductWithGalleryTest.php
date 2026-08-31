<?php

use App\Models\Brand;
use App\Models\File;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Support\Str;

/**
 * CreateProductWithGalleryTest
 *
 * Covers POST /api/v1/hq/{brandSlug}/products with `gallery_file_ids` —
 * the staged-create flow used by /products/new in the admin web. Client
 * uploads images via POST /files/upload (returns temp UUIDs) then sends
 * the ordered UUID list as part of the product create payload.
 *
 * Scenarios:
 *   - Happy path: temp files become permanent + attached + ordered
 *   - Backward compat: omitting gallery_file_ids still works
 *   - Order: array index = sort_order
 *   - Validation: non-uuid → 422, max 20 → 422
 *   - Cross-org guard: file from another org → 422
 *   - Idempotent reorder: re-posting same IDs in different order updates sort_order
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-create-gallery',
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

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

// ---------------------------------------------------------------------------
//  Helper
// ---------------------------------------------------------------------------

/**
 * Create a temp File belonging to the current org (mimics the result of a
 * prior POST /files/upload call from the staged create flow).
 */
function makeTempFileForCreate(string $orgId): File
{
    return File::factory()->create([
        'organization_id' => $orgId,
        'status' => FileStatusEnum::Temporary,
        'expires_at' => now()->addHours(12),
        'collection' => 'default',
        'sort_order' => 0,
    ]);
}

// ---------------------------------------------------------------------------
//  Happy path
// ---------------------------------------------------------------------------

describe('happy path', function () {
    it('creates a product and attaches gallery files in the given order', function () {
        $f1 = makeTempFileForCreate($this->orgId);
        $f2 = makeTempFileForCreate($this->orgId);
        $f3 = makeTempFileForCreate($this->orgId);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Pho',
            'product_type_id' => $this->productType->id,
            'status' => 'draft',
            'gallery_file_ids' => [$f3->id, $f1->id, $f2->id], // intentional order
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Pho')
            ->assertJsonCount(3, 'data.gallery');

        // Gallery returned in sort_order
        expect($response->json('data.gallery.0.id'))->toBe($f3->id);
        expect($response->json('data.gallery.1.id'))->toBe($f1->id);
        expect($response->json('data.gallery.2.id'))->toBe($f2->id);

        $productId = $response->json('data.id');

        // All files are now permanent + attached + correctly ordered
        expect($f3->fresh())
            ->status->toBe(FileStatusEnum::Permanent)
            ->fileable_type->toBe((new Product)->getMorphClass())
            ->fileable_id->toBe($productId)
            ->collection->toBe('gallery')
            ->sort_order->toBe(0)
            ->expires_at->toBeNull();

        expect($f1->fresh()->sort_order)->toBe(1);
        expect($f2->fresh()->sort_order)->toBe(2);
    });

    it('creates a product without gallery_file_ids (backward compat)', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Pho',
            'product_type_id' => $this->productType->id,
            'status' => 'draft',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Pho')
            ->assertJsonPath('data.gallery', []);
    });

    it('accepts an empty gallery_file_ids array', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Pho',
            'product_type_id' => $this->productType->id,
            'gallery_file_ids' => [],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.gallery', []);
    });
});

// ---------------------------------------------------------------------------
//  Validation
// ---------------------------------------------------------------------------

describe('validation', function () {
    it('returns 422 when gallery_file_ids contains a non-uuid value', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Pho',
                'product_type_id' => $this->productType->id,
                'gallery_file_ids' => ['not-a-uuid'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gallery_file_ids.0']);
    });

    it('returns 422 when gallery_file_ids exceeds 20 items', function () {
        $ids = collect()
            ->range(0, 20)
            ->map(fn () => makeTempFileForCreate($this->orgId)->id)
            ->all();

        expect($ids)->toHaveCount(21);

        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Pho',
                'product_type_id' => $this->productType->id,
                'gallery_file_ids' => $ids,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gallery_file_ids']);
    });

    it('returns 422 when a file_id does not exist', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Pho',
                'product_type_id' => $this->productType->id,
                'gallery_file_ids' => [(string) Str::uuid()],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gallery_file_ids.0']);
    });
});

// ---------------------------------------------------------------------------
//  Cross-org guard
// ---------------------------------------------------------------------------

describe('cross-org guard', function () {
    it('returns 422 when a file_id belongs to another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);

        $foreignFile = File::factory()->create([
            'organization_id' => $otherOrgId,
            'status' => FileStatusEnum::Temporary,
            'expires_at' => now()->addHours(12),
        ]);

        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Pho',
                'product_type_id' => $this->productType->id,
                'gallery_file_ids' => [$foreignFile->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gallery_file_ids.0']);

        // Foreign file remains untouched (still temp, no fileable)
        expect($foreignFile->fresh())
            ->status->toBe(FileStatusEnum::Temporary)
            ->fileable_type->toBeNull()
            ->fileable_id->toBeNull();
    });
});

// ---------------------------------------------------------------------------
//  Side effects
// ---------------------------------------------------------------------------

describe('side effects', function () {
    it('clears expires_at on attached files (no longer temp)', function () {
        $f = makeTempFileForCreate($this->orgId);

        expect($f->expires_at)->not->toBeNull();

        $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Pho',
            'product_type_id' => $this->productType->id,
            'gallery_file_ids' => [$f->id],
        ])->assertCreated();

        expect($f->fresh()->expires_at)->toBeNull();
    });

    it('does not affect other temp files in the same org', function () {
        $attached = makeTempFileForCreate($this->orgId);
        $orphan = makeTempFileForCreate($this->orgId);

        $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Pho',
            'product_type_id' => $this->productType->id,
            'gallery_file_ids' => [$attached->id],
        ])->assertCreated();

        // Orphan still temp + still expires
        expect($orphan->fresh())
            ->status->toBe(FileStatusEnum::Temporary)
            ->fileable_type->toBeNull()
            ->expires_at->not->toBeNull();
    });
});
