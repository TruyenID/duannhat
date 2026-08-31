<?php

use App\Models\Brand;
use App\Models\File;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Support\Str;

/**
 * ProductImageSyncTest
 *
 * Covers POST /api/v1/hq/{brandSlug}/products/{product}/images/sync
 *
 * Scenarios:
 *   - Happy path: sync attaches temp files, sets sort_order, returns gallery
 *   - Reorder: calling sync again with a different order updates sort_order
 *   - Remove: files not in the new list are soft-deleted
 *   - Empty list: passing [] removes all gallery images
 *   - Auth: 401 without token
 *   - Cross-org guard: file from another org → 403
 *   - Product not found → 404
 *   - Validation: file_ids must be UUIDs, max 20
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-img',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->product = Product::factory()->forBrand($this->brand)->create();

    $this->base = "/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/images/sync";
});

// ---------------------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------------------

/**
 * Create a temp File belonging to the test org.
 */
function makeTempFile(string $orgId, int $sortOrder = 0): File
{
    return File::factory()->create([
        'organization_id' => $orgId,
        'status' => FileStatusEnum::Temporary,
        'expires_at' => now()->addHours(12),
        'sort_order' => $sortOrder,
        'collection' => 'default',
        'disk' => 'local',
    ]);
}

// ---------------------------------------------------------------------------
//  Happy path
// ---------------------------------------------------------------------------

it('attaches temp files to the product gallery in order', function () {
    $file1 = makeTempFile($this->orgId);
    $file2 = makeTempFile($this->orgId);

    $response = $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file1->id, $file2->id]]);

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $file1->id)
        ->assertJsonPath('data.1.id', $file2->id);

    // Files are now permanent and attached to the product
    expect($file1->fresh())
        ->status->toBe(FileStatusEnum::Permanent)
        ->fileable_type->toBe((new Product)->getMorphClass())
        ->fileable_id->toBe($this->product->id)
        ->collection->toBe('gallery')
        ->sort_order->toBe(0);

    expect($file2->fresh()->sort_order)->toBe(1);
});

it('returns gallery ordered by sort_order', function () {
    $files = collect(range(0, 2))->map(fn ($i) => makeTempFile($this->orgId, $i));

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => $files->pluck('id')->all()])
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect($this->product->gallery()->pluck('id')->all())
        ->toEqual($files->pluck('id')->all());
});

// ---------------------------------------------------------------------------
//  Reorder
// ---------------------------------------------------------------------------

it('updates sort_order when the same files are sent in a different order', function () {
    $file1 = makeTempFile($this->orgId);
    $file2 = makeTempFile($this->orgId);

    // First sync: [file1, file2]
    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file1->id, $file2->id]]);

    // Second sync: reversed order
    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file2->id, $file1->id]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $file2->id)
        ->assertJsonPath('data.1.id', $file1->id);

    expect($file2->fresh()->sort_order)->toBe(0);
    expect($file1->fresh()->sort_order)->toBe(1);
});

// ---------------------------------------------------------------------------
//  Remove
// ---------------------------------------------------------------------------

it('reverts removed gallery files back to temporary with 24h expiry', function () {
    $keep = makeTempFile($this->orgId);
    $remove = makeTempFile($this->orgId);

    // Attach both
    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$keep->id, $remove->id]]);

    // Sync with only $keep
    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$keep->id]])
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // $remove is reverted to TEMP, detached, with a fresh 24h expiry —
    // physical file stays in MinIO until cleanupExpired() sweeps it.
    expect($remove->fresh())
        ->status->toBe(FileStatusEnum::Temporary)
        ->fileable_type->toBeNull()
        ->fileable_id->toBeNull()
        ->expires_at->not->toBeNull();

    // expires_at is roughly now + 24h (allow ±5min for test execution drift)
    $expectedExpiry = now()->addHours(24);
    expect($remove->fresh()->expires_at->diffInMinutes($expectedExpiry))
        ->toBeLessThan(5);

    // $keep still permanent + attached
    expect($keep->fresh())
        ->status->toBe(FileStatusEnum::Permanent)
        ->fileable_id->toBe($this->product->id);
});

it('reverts all gallery images to temp when file_ids is empty', function () {
    $file = makeTempFile($this->orgId);

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file->id]]);

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect($this->product->gallery()->count())->toBe(0);

    // File is reverted to TEMP, not soft-deleted
    expect($file->fresh())
        ->status->toBe(FileStatusEnum::Temporary)
        ->fileable_id->toBeNull()
        ->expires_at->not->toBeNull();
});

it('allows a reverted file to be re-attached within the recovery window', function () {
    $file = makeTempFile($this->orgId);

    // Attach
    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file->id]]);

    // Remove (revert to temp)
    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => []]);

    expect($file->fresh()->status)->toBe(FileStatusEnum::Temporary);

    // Re-attach within the 24h window
    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file->id]])
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($file->fresh())
        ->status->toBe(FileStatusEnum::Permanent)
        ->fileable_id->toBe($this->product->id)
        ->expires_at->toBeNull();
});

// ---------------------------------------------------------------------------
//  Auth
// ---------------------------------------------------------------------------

it('returns 401 when unauthenticated', function () {
    $this->postJson($this->base, ['file_ids' => []])
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
//  Cross-org guard
// ---------------------------------------------------------------------------

it('returns 403 when a file belongs to another organization', function () {
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
        ->postJson($this->base, ['file_ids' => [$foreignFile->id]])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
//  Product not found
// ---------------------------------------------------------------------------

it('returns 404 for a non-existent product', function () {
    $url = "/api/v1/hq/{$this->brand->slug}/products/".(string) Str::uuid().'/images/sync';

    $this->actingAs($this->user)
        ->postJson($url, ['file_ids' => []])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
//  Validation
// ---------------------------------------------------------------------------

it('returns 422 when file_ids contains a non-uuid value', function () {
    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => ['not-a-uuid']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_ids.0']);
});

it('returns 422 when file_ids exceeds 20 items', function () {
    $ids = array_fill(0, 21, (string) Str::uuid());

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => $ids])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_ids']);
});
