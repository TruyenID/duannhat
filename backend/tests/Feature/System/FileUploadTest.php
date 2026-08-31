<?php

use App\Models\Brand;
use App\Models\File;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use App\Omnify\Enums\FileStatusEnum;
use App\Omnify\Traits\HasFiles;
use App\Services\FileUploadService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // `local` cho các bài kiểm tự đặt file lên disk đó (makePermanent, cleanup);
    // disk upload thì phải fake theo cấu hình, vì #2101 đã tách đích upload ra
    // khỏi `filesystems.default` — hard-code 'local' ở đây là tái lập đúng cái
    // ghép sai vừa gỡ.
    Storage::fake('local');
    Storage::fake(config('filesystems.uploads'));
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
});

// =============================================================================
// FileStatusEnum
// =============================================================================

describe('FileStatusEnum', function () {
    it('has exactly 2 cases: Temporary and Permanent', function () {
        $cases = FileStatusEnum::cases();
        expect($cases)->toHaveCount(2)
            ->and($cases[0])->toBe(FileStatusEnum::Temporary)
            ->and($cases[1])->toBe(FileStatusEnum::Permanent);
    });

    it('has correct string values', function () {
        expect(FileStatusEnum::Temporary->value)->toBe('temporary')
            ->and(FileStatusEnum::Permanent->value)->toBe('permanent');
    });

    it('has label() method', function () {
        expect(FileStatusEnum::Temporary->label())->toBeString()
            ->and(FileStatusEnum::Permanent->label())->toBeString();
    });

    it('has values() method returning string array', function () {
        $values = FileStatusEnum::values();
        expect($values)->toBe(['temporary', 'permanent']);
    });
});

// =============================================================================
// File Model — Creation & Casting
// =============================================================================

describe('File model basics', function () {
    it('generates UUID primary key', function () {
        $file = File::factory()->create();
        expect($file->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
    });

    it('casts status to FileStatusEnum', function () {
        $file = File::factory()->create();
        expect($file->status)->toBeInstanceOf(FileStatusEnum::class);
    });

    it('casts expires_at to Carbon', function () {
        $file = File::factory()->create();
        expect($file->expires_at)->toBeInstanceOf(Carbon::class);
    });

    it('casts size to integer', function () {
        $file = File::factory()->create(['size' => '12345']);
        expect($file->size)->toBeInt()->toBe(12345);
    });

    it('casts sort_order to integer', function () {
        $file = File::factory()->create(['sort_order' => '5']);
        expect($file->sort_order)->toBeInt()->toBe(5);
    });

    it('has fileable_type and fileable_id in fillable', function () {
        $file = File::create([
            'organization_id' => $this->orgId,
            'fileable_type' => 'Product',
            'fileable_id' => (string) Str::uuid(),
            'collection' => 'default',
            'disk' => 'local',
            'path' => 'test.jpg',
            'original_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'status' => FileStatusEnum::Temporary,
        ]);

        expect($file->fileable_type)->toBe('Product')
            ->and($file->fileable_id)->not->toBeNull();
    });

    it('supports soft deletes', function () {
        $file = File::factory()->create();
        $id = $file->id;

        $file->delete();

        expect(File::find($id))->toBeNull()
            ->and(File::withTrashed()->find($id))->not->toBeNull()
            ->and(File::withTrashed()->find($id)->deleted_at)->not->toBeNull();
    });

    it('defaults collection to "default"', function () {
        $file = File::factory()->create();
        expect($file->collection)->toBe('default');
    });

    it('defaults disk to "local"', function () {
        $file = File::factory()->create();
        expect($file->disk)->toBe('local');
    });

    it('defaults sort_order to 0', function () {
        $file = File::factory()->create();
        expect($file->sort_order)->toBe(0);
    });
});

// =============================================================================
// File Model — Factory States
// =============================================================================

describe('File factory states', function () {
    it('default state is temporary with expires_at', function () {
        $file = File::factory()->create();
        expect($file->status)->toBe(FileStatusEnum::Temporary)
            ->and($file->expires_at)->not->toBeNull();
    });

    it('permanent() state has no expires_at', function () {
        $file = File::factory()->permanent()->create();
        expect($file->status)->toBe(FileStatusEnum::Permanent)
            ->and($file->expires_at)->toBeNull();
    });

    it('expired() state has past expires_at', function () {
        $file = File::factory()->expired()->create();
        expect($file->status)->toBe(FileStatusEnum::Temporary)
            ->and($file->expires_at->isPast())->toBeTrue();
    });
});

// =============================================================================
// File Model — Scopes
// =============================================================================

describe('File model scopes', function () {
    it('scopeTemporary returns only temporary files', function () {
        File::factory()->count(2)->create();
        File::factory()->permanent()->create();

        expect(File::temporary()->count())->toBe(2);
    });

    it('scopePermanent returns only permanent files', function () {
        File::factory()->create();
        File::factory()->count(3)->permanent()->create();

        expect(File::permanent()->count())->toBe(3);
    });

    it('scopeExpired returns only expired temp files', function () {
        File::factory()->create();              // temp, not expired
        File::factory()->expired()->create();   // temp, expired
        File::factory()->permanent()->create(); // permanent

        expect(File::expired()->count())->toBe(1);
    });

    it('scopeExpired excludes permanent files even with old dates', function () {
        File::factory()->permanent()->create([
            'expires_at' => now()->subDays(10), // permanent but with old expires_at (edge case)
        ]);

        expect(File::expired()->count())->toBe(0);
    });

    it('scopeExpired excludes temp files with null expires_at', function () {
        File::factory()->create(['expires_at' => null]); // temp but no expiry

        expect(File::expired()->count())->toBe(0);
    });

    it('scopeInCollection filters by collection name', function () {
        File::factory()->create(['collection' => 'images']);
        File::factory()->create(['collection' => 'documents']);
        File::factory()->create(['collection' => 'images']);

        expect(File::inCollection('images')->count())->toBe(2)
            ->and(File::inCollection('documents')->count())->toBe(1)
            ->and(File::inCollection('nonexistent')->count())->toBe(0);
    });

    it('scopes can be chained', function () {
        File::factory()->permanent()->create(['collection' => 'images']);
        File::factory()->create(['collection' => 'images']); // temporary
        File::factory()->permanent()->create(['collection' => 'documents']);

        expect(File::permanent()->inCollection('images')->count())->toBe(1);
    });
});

// =============================================================================
// File Model — Helpers
// =============================================================================

describe('File model helpers', function () {
    it('isPermanent() returns correct value', function () {
        expect(File::factory()->permanent()->create()->isPermanent())->toBeTrue()
            ->and(File::factory()->create()->isPermanent())->toBeFalse();
    });

    it('isExpired() handles all cases', function () {
        // Expired temp
        expect(File::factory()->expired()->create()->isExpired())->toBeTrue();
        // Non-expired temp
        expect(File::factory()->create()->isExpired())->toBeFalse();
        // Permanent (never expired)
        expect(File::factory()->permanent()->create()->isExpired())->toBeFalse();
        // Temp with null expires_at (never expires)
        expect(File::factory()->create(['expires_at' => null])->isExpired())->toBeFalse();
    });

    it('makePermanent() transitions status and clears expires_at', function () {
        $file = File::factory()->create();
        expect($file->status)->toBe(FileStatusEnum::Temporary);

        $result = $file->makePermanent();

        expect($result)->toBeInstanceOf(File::class)
            ->and($file->fresh()->status)->toBe(FileStatusEnum::Permanent)
            ->and($file->fresh()->expires_at)->toBeNull();
    });

    it('makePermanent() is idempotent on permanent files', function () {
        $file = File::factory()->permanent()->create();

        $file->makePermanent();

        expect($file->fresh()->status)->toBe(FileStatusEnum::Permanent);
    });

    it('fileable() morphTo resolves correct parent model', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        $file = File::factory()->permanent()->create([
            'organization_id' => $this->orgId,
            'fileable_type' => 'Product',
            'fileable_id' => $product->id,
        ]);

        expect($file->fileable)->toBeInstanceOf(Product::class)
            ->and($file->fileable->id)->toBe($product->id);
    });

    it('fileable() returns null when not attached', function () {
        $file = File::factory()->create([
            'fileable_type' => null,
            'fileable_id' => null,
        ]);

        expect($file->fileable)->toBeNull();
    });
});

// =============================================================================
// HasFiles Trait — Relationship Methods
// =============================================================================

describe('HasFiles trait relationships', function () {
    beforeEach(function () {
        $this->product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);
    });

    it('Product uses HasFiles trait', function () {
        expect(in_array(HasFiles::class, class_uses_recursive($this->product)))->toBeTrue();
    });

    it('files() returns MorphMany', function () {
        expect($this->product->files())->toBeInstanceOf(MorphMany::class);
    });

    it('files() returns only files for this model', function () {
        File::factory()->count(2)->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
        ]);
        File::factory()->permanent()->create(); // unattached

        expect($this->product->files()->count())->toBe(2);
    });

    it('filesInCollection() scopes by collection', function () {
        File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'images',
        ]);
        File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'documents',
        ]);

        expect($this->product->filesInCollection('images')->count())->toBe(1)
            ->and($this->product->filesInCollection('documents')->count())->toBe(1)
            ->and($this->product->filesInCollection('nonexistent')->count())->toBe(0);
    });

    it('permanentFiles() excludes temporary', function () {
        File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
        ]);
        File::factory()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
        ]); // temporary

        expect($this->product->permanentFiles()->count())->toBe(1)
            ->and($this->product->files()->count())->toBe(2);
    });
});

// =============================================================================
// HasFiles Trait — attachFiles()
// =============================================================================

describe('HasFiles::attachFiles', function () {
    beforeEach(function () {
        $this->product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);
    });

    it('attaches files and makes them permanent', function () {
        $files = File::factory()->count(3)->create(['organization_id' => $this->orgId]);

        $this->product->attachFiles($files->pluck('id')->all(), 'images');

        foreach ($files as $file) {
            $file->refresh();
            expect($file->status)->toBe(FileStatusEnum::Permanent)
                ->and($file->expires_at)->toBeNull()
                ->and($file->fileable_type)->toBe('Product')
                ->and($file->fileable_id)->toBe($this->product->id)
                ->and($file->collection)->toBe('images');
        }
    });

    it('does nothing with empty array', function () {
        $this->product->attachFiles([]);
        expect($this->product->files()->count())->toBe(0);
    });

    it('does nothing with non-existent IDs', function () {
        $this->product->attachFiles([(string) Str::uuid(), (string) Str::uuid()]);
        expect($this->product->files()->count())->toBe(0);
    });

    it('sets collection on attached files', function () {
        $file = File::factory()->create(['organization_id' => $this->orgId, 'collection' => 'old']);

        $this->product->attachFiles([$file->id], 'new-collection');

        expect($file->fresh()->collection)->toBe('new-collection');
    });

    it('uses morph map alias (not FQCN)', function () {
        $file = File::factory()->create(['organization_id' => $this->orgId]);

        $this->product->attachFiles([$file->id]);

        $file->refresh();
        expect($file->fileable_type)->toBe('Product')
            ->and($file->fileable_type)->not->toBe('App\\Models\\Product');
    });
});

// =============================================================================
// HasFiles Trait — syncFiles()
// =============================================================================

describe('HasFiles::syncFiles', function () {
    beforeEach(function () {
        $this->product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);
    });

    it('soft-deletes removed files and attaches new ones', function () {
        $oldFile = File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'images',
        ]);
        $newFile = File::factory()->create(['organization_id' => $this->orgId]);

        $this->product->syncFiles([$newFile->id], 'images');

        expect(File::find($oldFile->id))->toBeNull(); // soft-deleted
        expect(File::withTrashed()->find($oldFile->id))->not->toBeNull();

        $newFile->refresh();
        expect($newFile->status)->toBe(FileStatusEnum::Permanent)
            ->and($newFile->fileable_type)->toBe('Product');
    });

    it('soft-deletes all when empty array', function () {
        File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'docs',
        ]);

        $this->product->syncFiles([], 'docs');

        expect($this->product->filesInCollection('docs')->count())->toBe(0);
    });

    it('only affects specified collection', function () {
        $imageFile = File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'images',
        ]);
        $docFile = File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'documents',
        ]);

        $this->product->syncFiles([], 'images');

        expect(File::find($imageFile->id))->toBeNull(); // soft-deleted
        expect(File::find($docFile->id))->not->toBeNull(); // untouched
    });

    it('updates sort_order based on array position', function () {
        $files = File::factory()->count(3)->create(['organization_id' => $this->orgId]);
        $ids = $files->pluck('id')->reverse()->values()->all(); // reversed order

        $this->product->syncFiles($ids, 'gallery');

        foreach ($ids as $index => $id) {
            expect(File::find($id)->sort_order)->toBe($index);
        }
    });

    it('keeps existing files that are still in the list', function () {
        $keepFile = File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'images',
        ]);

        $this->product->syncFiles([$keepFile->id], 'images');

        $keepFile->refresh();
        expect($keepFile->fileable_id)->toBe($this->product->id)
            ->and($keepFile->status)->toBe(FileStatusEnum::Permanent)
            ->and(File::withTrashed()->find($keepFile->id)->deleted_at)->toBeNull();
    });
});

// =============================================================================
// Product — thumbnail() MorphOne accessor
// =============================================================================

describe('Product thumbnail() accessor', function () {
    beforeEach(function () {
        $this->product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);
    });

    it('returns MorphOne relationship', function () {
        expect($this->product->thumbnail())->toBeInstanceOf(MorphOne::class);
    });

    it('returns null when no thumbnail attached', function () {
        expect($this->product->thumbnail)->toBeNull();
    });

    it('returns single File when thumbnail exists', function () {
        File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'thumbnail',
        ]);

        expect($this->product->thumbnail)->toBeInstanceOf(File::class);
    });

    it('only returns files in thumbnail collection', function () {
        File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'gallery', // NOT thumbnail
        ]);

        expect($this->product->thumbnail)->toBeNull();
    });

    it('returns latest when multiple thumbnails exist (edge case)', function () {
        File::factory()->count(2)->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'thumbnail',
        ]);

        // MorphOne returns first match — should not crash
        expect($this->product->thumbnail)->toBeInstanceOf(File::class);
    });
});

// =============================================================================
// Product — gallery() MorphMany accessor
// =============================================================================

describe('Product gallery() accessor', function () {
    beforeEach(function () {
        $this->product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);
    });

    it('returns MorphMany relationship', function () {
        expect($this->product->gallery())->toBeInstanceOf(MorphMany::class);
    });

    it('returns empty collection when no gallery files', function () {
        expect($this->product->gallery)->toHaveCount(0);
    });

    it('returns multiple files', function () {
        File::factory()->count(5)->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'gallery',
        ]);

        expect($this->product->gallery)->toHaveCount(5);
    });

    it('excludes files from other collections', function () {
        File::factory()->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'thumbnail',
        ]);
        File::factory()->count(3)->permanent()->create([
            'fileable_type' => 'Product',
            'fileable_id' => $this->product->id,
            'collection' => 'gallery',
        ]);

        expect($this->product->gallery)->toHaveCount(3);
    });
});

// =============================================================================
// Upload API — POST /api/v1/files/upload
// =============================================================================

describe('upload API', function () {
    it('uploads a file and returns 201 with correct structure', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->image('photo.jpg', 640, 480),
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'collection', 'original_name', 'mime_type', 'size', 'status', 'url', 'is_permanent', 'is_expired', 'expires_at', 'sort_order', 'created_at'],
            ])
            ->assertJsonPath('data.original_name', 'photo.jpg')
            ->assertJsonPath('data.status', 'temporary')
            ->assertJsonPath('data.is_permanent', false)
            ->assertJsonPath('data.is_expired', false);
    });

    it('stores file on disk at correct path', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->image('test.png'),
            ]);

        $file = File::first();
        expect($file->disk)->toBe(config('filesystems.uploads'));
        Storage::disk($file->disk)->assertExists($file->path);
        expect($file->path)->toStartWith("uploads/temp/{$this->orgId}/");
    });

    it('sets organization_id from authenticated user', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->image('test.jpg'),
            ]);

        expect(File::first()->organization_id)->toBe($this->orgId);
    });

    it('sets expires_at approximately 12 hours from now', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->image('test.png'),
            ]);

        $hoursUntilExpiry = now()->diffInHours(File::first()->expires_at, false);
        expect($hoursUntilExpiry)->toBeLessThanOrEqual(12)
            ->and($hoursUntilExpiry)->toBeGreaterThanOrEqual(11);
    });

    it('accepts custom collection', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->image('test.jpg'),
                'collection' => 'avatars',
            ]);

        expect(File::first()->collection)->toBe('avatars');
    });

    it('handles various file types', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf'),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.mime_type', 'application/pdf');
    });

    it('rejects missing file', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    });

    it('rejects oversized file (>10MB)', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->create('big.zip', 11000),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    });

    it('rejects non-file value', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', ['file' => 'not-a-file'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    });

    it('rejects collection longer than 50 chars', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->image('test.jpg'),
                'collection' => str_repeat('x', 51),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collection');
    });

    it('returns 401 without auth', function () {
        $this->postJson('/api/v1/files/upload', [
            'file' => UploadedFile::fake()->image('test.jpg'),
        ])->assertUnauthorized();
    });
});

// =============================================================================
// Show API — GET /api/v1/files/{file}
// =============================================================================

describe('show API', function () {
    it('returns file details', function () {
        $file = File::factory()->create(['organization_id' => $this->orgId]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/files/{$file->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $file->id);
    });

    it('shows permanent file correctly', function () {
        $file = File::factory()->permanent()->create(['organization_id' => $this->orgId]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/files/{$file->id}")
            ->assertJsonPath('data.is_permanent', true)
            ->assertJsonPath('data.expires_at', null);
    });

    it('shows expired file correctly', function () {
        $file = File::factory()->expired()->create(['organization_id' => $this->orgId]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/files/{$file->id}")
            ->assertJsonPath('data.is_expired', true);
    });

    it('returns 403 for other org', function () {
        $file = File::factory()->create(['organization_id' => (string) Str::uuid()]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/files/{$file->id}")
            ->assertForbidden();
    });

    it('returns 404 for non-existent', function () {
        $this->actingAs($this->user)
            ->getJson('/api/v1/files/'.fake()->uuid())
            ->assertNotFound();
    });

    it('returns 404 for soft-deleted', function () {
        $file = File::factory()->create(['organization_id' => $this->orgId]);
        $file->delete();

        $this->actingAs($this->user)
            ->getJson("/api/v1/files/{$file->id}")
            ->assertNotFound();
    });

    it('returns 401 without auth', function () {
        $file = File::factory()->create();
        $this->getJson("/api/v1/files/{$file->id}")->assertUnauthorized();
    });
});

// =============================================================================
// Make Permanent API — POST /api/v1/files/{file}/make-permanent
// =============================================================================

describe('make-permanent API', function () {
    it('transitions temp to permanent', function () {
        $file = File::factory()->create(['organization_id' => $this->orgId]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/files/{$file->id}/make-permanent")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'permanent')
            ->assertJsonPath('data.is_permanent', true)
            ->assertJsonPath('data.expires_at', null);

        expect($file->fresh()->status)->toBe(FileStatusEnum::Permanent);
    });

    it('is idempotent on permanent files', function () {
        $file = File::factory()->permanent()->create(['organization_id' => $this->orgId]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/files/{$file->id}/make-permanent")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'permanent');
    });

    it('works on expired temp files', function () {
        $file = File::factory()->expired()->create(['organization_id' => $this->orgId]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/files/{$file->id}/make-permanent")
            ->assertSuccessful()
            ->assertJsonPath('data.is_expired', false);
    });

    it('returns 403 for other org', function () {
        $file = File::factory()->create(['organization_id' => (string) Str::uuid()]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/files/{$file->id}/make-permanent")
            ->assertForbidden();
    });

    it('returns 404 for non-existent', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/'.fake()->uuid().'/make-permanent')
            ->assertNotFound();
    });

    it('returns 401 without auth', function () {
        $file = File::factory()->create();
        $this->postJson("/api/v1/files/{$file->id}/make-permanent")->assertUnauthorized();
    });
});

// =============================================================================
// Delete API — DELETE /api/v1/files/{file}
// =============================================================================

describe('delete API', function () {
    it('deletes file record and physical file', function () {
        Storage::disk('local')->put('uploads/temp/del.jpg', 'content');
        $file = File::factory()->create([
            'organization_id' => $this->orgId,
            'path' => 'uploads/temp/del.jpg',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/files/{$file->id}")
            ->assertNoContent();

        expect(File::withTrashed()->find($file->id))->toBeNull();
        Storage::disk('local')->assertMissing('uploads/temp/del.jpg');
    });

    it('can delete permanent files', function () {
        Storage::disk('local')->put('uploads/perm.pdf', 'content');
        $file = File::factory()->permanent()->create([
            'organization_id' => $this->orgId,
            'path' => 'uploads/perm.pdf',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/files/{$file->id}")
            ->assertNoContent();

        expect(File::withTrashed()->find($file->id))->toBeNull();
    });

    it('returns 403 for other org', function () {
        $file = File::factory()->create(['organization_id' => (string) Str::uuid()]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/files/{$file->id}")
            ->assertForbidden();
    });

    it('returns 404 for non-existent', function () {
        $this->actingAs($this->user)
            ->deleteJson('/api/v1/files/'.fake()->uuid())
            ->assertNotFound();
    });

    it('returns 401 without auth', function () {
        $file = File::factory()->create();
        $this->deleteJson("/api/v1/files/{$file->id}")->assertUnauthorized();
    });
});

// =============================================================================
// FileUploadService — direct tests
// =============================================================================

describe('FileUploadService', function () {
    it('uploadTemp creates correct record', function () {
        $uploaded = UploadedFile::fake()->image('svc.jpg', 200, 200);
        $service = app(FileUploadService::class);

        $file = $service->uploadTemp($uploaded, $this->orgId, 'photos', 'local');

        expect($file)->toBeInstanceOf(File::class)
            ->and($file->exists)->toBeTrue()
            ->and($file->organization_id)->toBe($this->orgId)
            ->and($file->collection)->toBe('photos')
            ->and($file->original_name)->toBe('svc.jpg')
            ->and($file->status)->toBe(FileStatusEnum::Temporary)
            ->and($file->expires_at)->not->toBeNull();

        Storage::disk('local')->assertExists($file->path);
    });

    it('makePermanent moves file when directory specified', function () {
        Storage::disk('local')->put('uploads/temp/move.jpg', 'content');
        $file = File::factory()->create([
            'organization_id' => $this->orgId,
            'path' => 'uploads/temp/move.jpg',
        ]);

        $service = app(FileUploadService::class);
        $service->makePermanent($file, 'uploads/permanent');

        expect($file->status)->toBe(FileStatusEnum::Permanent)
            ->and($file->path)->toBe('uploads/permanent/move.jpg');

        Storage::disk('local')->assertExists('uploads/permanent/move.jpg');
        Storage::disk('local')->assertMissing('uploads/temp/move.jpg');
    });

    it('makePermanent keeps path when no directory', function () {
        Storage::disk('local')->put('uploads/temp/keep.jpg', 'content');
        $file = File::factory()->create(['path' => 'uploads/temp/keep.jpg']);

        $service = app(FileUploadService::class);
        $service->makePermanent($file);

        expect($file->path)->toBe('uploads/temp/keep.jpg');
    });

    it('delete removes record and physical file', function () {
        Storage::disk('local')->put('uploads/del.jpg', 'content');
        $file = File::factory()->create(['path' => 'uploads/del.jpg']);

        $service = app(FileUploadService::class);
        $service->delete($file);

        expect(File::withTrashed()->find($file->id))->toBeNull();
        Storage::disk('local')->assertMissing('uploads/del.jpg');
    });

    it('cleanupExpired deletes only expired files', function () {
        Storage::disk('local')->put('uploads/expired.jpg', 'content');
        File::factory()->expired()->create(['path' => 'uploads/expired.jpg']);
        File::factory()->create(); // non-expired
        File::factory()->permanent()->create();

        $service = app(FileUploadService::class);
        $count = $service->cleanupExpired();

        expect($count)->toBe(1)
            ->and(File::count())->toBe(2);
    });
});

// =============================================================================
// Cleanup Command — omnify:cleanup-files
// =============================================================================

describe('omnify:cleanup-files phase 1', function () {
    it('soft-deletes expired temp files, keeps physical files', function () {
        Storage::disk('local')->put('uploads/temp/p1.jpg', 'content');
        File::factory()->expired()->create(['path' => 'uploads/temp/p1.jpg']);

        $this->artisan('omnify:cleanup-files --phase=1')
            ->expectsOutputToContain('Soft-deleted')
            ->assertSuccessful();

        expect(File::count())->toBe(0)
            ->and(File::withTrashed()->count())->toBe(1);
        Storage::disk('local')->assertExists('uploads/temp/p1.jpg');
    });

    it('does not touch non-expired temp files', function () {
        File::factory()->create(); // expires in 12h

        $this->artisan('omnify:cleanup-files --phase=1')->assertSuccessful();

        expect(File::count())->toBe(1);
    });

    it('does not touch permanent files', function () {
        File::factory()->permanent()->create();

        $this->artisan('omnify:cleanup-files --phase=1')->assertSuccessful();

        expect(File::count())->toBe(1);
    });

    it('reports zero when nothing expired', function () {
        $this->artisan('omnify:cleanup-files --phase=1')
            ->expectsOutputToContain('No expired')
            ->assertSuccessful();
    });
});

describe('omnify:cleanup-files phase 2', function () {
    it('purges soft-deleted files older than grace period', function () {
        Storage::disk('local')->put('uploads/temp/p2.jpg', 'content');
        $file = File::factory()->expired()->create(['path' => 'uploads/temp/p2.jpg']);
        $file->delete();
        File::withTrashed()->where('id', $file->id)->update([
            'deleted_at' => now()->subHours(73),
        ]);

        $this->artisan('omnify:cleanup-files --phase=2 --purge-hours=72')
            ->expectsOutputToContain('Purged')
            ->assertSuccessful();

        expect(File::withTrashed()->count())->toBe(0);
        Storage::disk('local')->assertMissing('uploads/temp/p2.jpg');
    });

    it('does not purge within grace period', function () {
        $file = File::factory()->expired()->create();
        $file->delete(); // just now

        $this->artisan('omnify:cleanup-files --phase=2 --purge-hours=72')
            ->expectsOutputToContain('No soft-deleted')
            ->assertSuccessful();

        expect(File::withTrashed()->count())->toBe(1);
    });

    it('respects custom purge-hours', function () {
        $file = File::factory()->expired()->create();
        $file->delete();
        File::withTrashed()->where('id', $file->id)->update([
            'deleted_at' => now()->subHours(25),
        ]);

        $this->artisan('omnify:cleanup-files --phase=2 --purge-hours=24')
            ->expectsOutputToContain('Purged')
            ->assertSuccessful();

        expect(File::withTrashed()->count())->toBe(0);
    });
});

describe('omnify:cleanup-files combined + dry-run', function () {
    it('runs both phases when no --phase specified', function () {
        File::factory()->expired()->create();

        $this->artisan('omnify:cleanup-files')
            ->assertSuccessful();
    });

    it('--dry-run previews without changes', function () {
        File::factory()->expired()->create();

        $this->artisan('omnify:cleanup-files --dry-run')
            ->expectsOutputToContain('Would')
            ->assertSuccessful();

        expect(File::count())->toBe(1); // unchanged
    });
});

// =============================================================================
// Edge Cases — Concurrent operations
// =============================================================================

describe('edge cases', function () {
    it('attaching already-attached file to different model re-attaches it', function () {
        $product1 = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);
        $product2 = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        $file = File::factory()->create(['organization_id' => $this->orgId]);

        $product1->attachFiles([$file->id], 'images');
        expect($file->fresh()->fileable_id)->toBe($product1->id);

        // Re-attach to product2 (already permanent, so attachFiles won't update)
        // This tests the behavior — permanent files don't get re-attached
        $product2->attachFiles([$file->id], 'images');
        // File stays with product1 because it's already permanent
        // and attachFiles only updates files matching whereIn('id', ...)
        expect($file->fresh()->fileable_id)->toBe($product2->id);
    });

    it('multiple collections on same model are independent', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        $thumb = File::factory()->create(['organization_id' => $this->orgId]);
        $gallery1 = File::factory()->create(['organization_id' => $this->orgId]);
        $gallery2 = File::factory()->create(['organization_id' => $this->orgId]);

        $product->attachFiles([$thumb->id], 'thumbnail');
        $product->attachFiles([$gallery1->id, $gallery2->id], 'gallery');

        // Refresh to drop the empty relation snapshot Eloquent took before
        // attachFiles() inserted the new rows.
        $product->refresh();

        expect($product->thumbnail)->toBeInstanceOf(File::class)
            ->and($product->gallery)->toHaveCount(2)
            ->and($product->files()->count())->toBe(3);

        // Sync gallery doesn't affect thumbnail
        $product->syncFiles([$gallery1->id], 'gallery');
        $product->refresh();
        expect($product->thumbnail)->not->toBeNull()
            ->and($product->filesInCollection('gallery')->count())->toBe(1);
    });

    it('file with empty path does not crash cleanup', function () {
        $file = File::factory()->expired()->create(['path' => '']);
        $file->delete();
        File::withTrashed()->where('id', $file->id)->update([
            'deleted_at' => now()->subHours(73),
        ]);

        $this->artisan('omnify:cleanup-files --phase=2 --purge-hours=72')
            ->assertSuccessful();

        expect(File::withTrashed()->count())->toBe(0);
    });

    it('uploading same filename twice creates separate records', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->image('same.jpg'),
            ])->assertCreated();

        $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->image('same.jpg'),
            ])->assertCreated();

        expect(File::count())->toBe(2);
        $files = File::all();
        expect($files[0]->id)->not->toBe($files[1]->id)
            ->and($files[0]->path)->not->toBe($files[1]->path);
    });

    it('zero-byte file upload is handled', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->create('empty.txt', 0),
            ]);

        $response->assertCreated();
        expect(File::first()->size)->toBe(0);
    });

    it('file with unicode filename is handled', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->create('日本語ファイル.pdf', 100, 'application/pdf'),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.original_name', '日本語ファイル.pdf');
    });
});
