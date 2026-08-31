<?php

use App\Models\Brand;
use App\Models\File;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\User;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Support\Str;

/**
 * ImageSyncTest
 *
 * Covers POST /api/v1/hq/{brandSlug}/skus/{sku}/images/sync
 *
 * Mirror of ProductImageSyncTest — same semantics, SKU scope.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-sku-img',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->product = Product::factory()->forBrand($this->brand)->create();
    $this->sku = ProductSku::factory()->create(['product_id' => $this->product->id]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/skus/{$this->sku->id}/images/sync";
});

function makeSkuTempFile(string $orgId, int $sortOrder = 0): File
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

it('attaches temp files to the SKU gallery in order', function () {
    $file1 = makeSkuTempFile($this->orgId);
    $file2 = makeSkuTempFile($this->orgId);

    $response = $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file1->id, $file2->id]]);

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $file1->id)
        ->assertJsonPath('data.1.id', $file2->id);

    expect($file1->fresh())
        ->status->toBe(FileStatusEnum::Permanent)
        ->fileable_type->toBe((new ProductSku)->getMorphClass())
        ->fileable_id->toBe($this->sku->id)
        ->collection->toBe('gallery')
        ->sort_order->toBe(0);

    expect($file2->fresh()->sort_order)->toBe(1);
});

it('updates sort_order when the same files are sent in a different order', function () {
    $file1 = makeSkuTempFile($this->orgId);
    $file2 = makeSkuTempFile($this->orgId);

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file1->id, $file2->id]]);

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file2->id, $file1->id]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $file2->id)
        ->assertJsonPath('data.1.id', $file1->id);

    expect($file2->fresh()->sort_order)->toBe(0);
    expect($file1->fresh()->sort_order)->toBe(1);
});

it('reverts removed gallery files back to temporary with 24h expiry', function () {
    $keep = makeSkuTempFile($this->orgId);
    $remove = makeSkuTempFile($this->orgId);

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$keep->id, $remove->id]]);

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$keep->id]])
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($remove->fresh())
        ->status->toBe(FileStatusEnum::Temporary)
        ->fileable_type->toBeNull()
        ->fileable_id->toBeNull()
        ->expires_at->not->toBeNull();

    $expectedExpiry = now()->addHours(24);
    expect($remove->fresh()->expires_at->diffInMinutes($expectedExpiry))
        ->toBeLessThan(5);

    expect($keep->fresh())
        ->status->toBe(FileStatusEnum::Permanent)
        ->fileable_id->toBe($this->sku->id);
});

it('reverts all gallery images to temp when file_ids is empty', function () {
    $file = makeSkuTempFile($this->orgId);

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => [$file->id]]);

    $this->actingAs($this->user)
        ->postJson($this->base, ['file_ids' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect($this->sku->gallery()->count())->toBe(0);

    expect($file->fresh())
        ->status->toBe(FileStatusEnum::Temporary)
        ->fileable_id->toBeNull()
        ->expires_at->not->toBeNull();
});

it('returns 401 when unauthenticated', function () {
    $this->postJson($this->base, ['file_ids' => []])
        ->assertUnauthorized();
});

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

it('returns 404 for a non-existent SKU', function () {
    $url = "/api/v1/hq/{$this->brand->slug}/skus/".(string) Str::uuid().'/images/sync';

    $this->actingAs($this->user)
        ->postJson($url, ['file_ids' => []])
        ->assertNotFound();
});

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
