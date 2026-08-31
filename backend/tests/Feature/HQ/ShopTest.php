<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'test-brand',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/shops";
});

// =========================================================================
//  Happy path — tempo-native (no dxs Console round-trip)
// =========================================================================

describe('happy path', function () {
    it('creates a shop and returns 201 with branch resource', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => '渋谷店',
            'slug' => 'shibuya',
            'timezone' => 'Asia/Tokyo',
            'currency' => 'JPY',
            'locale' => 'ja',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', '渋谷店')
            ->assertJsonPath('data.slug', 'shibuya')
            ->assertJsonPath('data.timezone', 'Asia/Tokyo')
            ->assertJsonPath('data.currency', 'JPY')
            ->assertJsonPath('data.locale', 'ja')
            ->assertJsonPath('data.is_headquarters', false)
            ->assertJsonPath('data.is_active', true);

        $branch = Branch::find($response->json('data.id'));

        expect($branch)->not->toBeNull()
            ->and($branch->console_organization_id)->toBe($this->orgId)
            ->and($branch->console_brand_id)->toBe($this->brand->console_brand_id)
            ->and($branch->console_branch_id)->not->toBeNull();
    });

    it('self-mints a console_branch_id (no Console call)', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Self Minted',
            'slug' => 'self-minted',
        ])->assertCreated();

        $branch = Branch::find($response->json('data.id'));

        // console_branch_id is a locally-minted uuid, present + unique.
        expect($branch->console_branch_id)->not->toBeNull()
            ->and(Str::isUuid($branch->console_branch_id))->toBeTrue();
    });

    it('applies defaults when optional fields are omitted', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Default Shop',
            'slug' => 'default-shop',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.timezone', 'Asia/Tokyo')
            ->assertJsonPath('data.currency', 'JPY')
            ->assertJsonPath('data.locale', 'ja');
    });
});

// =========================================================================
//  Validation errors
// =========================================================================

describe('validation', function () {
    it('returns 422 when name is missing', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'slug' => 'no-name',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('returns 422 when slug is missing', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'No Slug Shop',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    });

    it('returns 422 when slug format is invalid', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Bad Slug',
            'slug' => 'Bad Slug With Spaces!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    });

    it('returns 422 when slug is duplicate within the same organization', function () {
        Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'slug' => 'existing-shop',
        ]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Duplicate Slug',
            'slug' => 'existing-shop',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    });

    it('allows duplicate slug across different organizations', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);

        Branch::factory()->create([
            'console_organization_id' => $otherOrgId,
            'slug' => 'same-slug',
        ]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Same Slug Different Org',
            'slug' => 'same-slug',
        ]);

        $response->assertCreated();
    });

    it('returns 422 when locale is invalid', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Bad Locale',
            'slug' => 'bad-locale',
            'locale' => 'fr',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['locale']);
    });
});

// =========================================================================
//  Authorization
// =========================================================================

describe('authorization', function () {
    it('returns 403 when user belongs to a different organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);

        $otherUser = User::factory()->create([
            'console_organization_id' => $otherOrgId,
        ]);

        $response = $this->actingAs($otherUser)->postJson($this->base, [
            'name' => 'Unauthorized Shop',
            'slug' => 'unauthorized',
        ]);

        $response->assertForbidden();
    });

    it('returns 401 when unauthenticated', function () {
        $response = $this->postJson($this->base, [
            'name' => 'No Auth',
            'slug' => 'no-auth',
        ]);

        $response->assertUnauthorized();
    });
});

// =========================================================================
//  UPDATE — tempo-native local edit
// =========================================================================

describe('PUT /shops/{shop}', function () {
    it('renames a shop locally', function () {
        $shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'slug' => 'rename-me',
            'name' => 'Original Name',
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$shop->id}", ['name' => 'Renamed'])
            ->assertOk();

        expect($shop->fresh()->name)->toBe('Renamed');
    });

    it('updates local-only fields', function () {
        $shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'slug' => 'detail-only',
            'address' => '東京都新宿区1-1',
            'phone' => '03-0000-0000',
            'seat_capacity' => 20,
        ]);

        $this->actingAs($this->user)->putJson("{$this->base}/{$shop->id}", [
            'address' => '東京都渋谷区2-2',
            'phone' => '03-9999-9999',
            'seat_capacity' => 50,
        ])->assertOk();

        $fresh = $shop->fresh();
        expect($fresh->address)->toBe('東京都渋谷区2-2')
            ->and($fresh->phone)->toBe('03-9999-9999')
            ->and($fresh->seat_capacity)->toBe(50);
    });
});

// =========================================================================
//  Logo + banner (img_branches) — customer-web storefront images
// =========================================================================

describe('logo + banner', function () {
    it('persists logo + img_branches on create', function () {
        $logo = 'http://localhost:5490/tempo/branches/logo-abc.png';
        $banner = 'http://localhost:5490/tempo/branches/banner-abc.png';

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Storefront Shop',
            'slug' => 'storefront-shop',
            'logo' => $logo,
            'img_branches' => $banner,
        ])->assertCreated();

        $branch = Branch::find($response->json('data.id'));
        expect($branch->getRawOriginal('logo'))->toBe($logo)
            ->and($branch->getRawOriginal('img_branches'))->toBe($banner);
    });

    it('updates logo + img_branches', function () {
        $shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'slug' => 'restyle-me',
        ]);

        $this->actingAs($this->user)->putJson("{$this->base}/{$shop->id}", [
            'logo' => 'http://localhost:5490/tempo/branches/logo-new.png',
            'img_branches' => 'http://localhost:5490/tempo/branches/banner-new.png',
        ])->assertOk();

        $fresh = $shop->fresh();
        expect($fresh->getRawOriginal('logo'))->toBe('http://localhost:5490/tempo/branches/logo-new.png')
            ->and($fresh->getRawOriginal('img_branches'))->toBe('http://localhost:5490/tempo/branches/banner-new.png');
    });

    it('clears logo + img_branches when set to null', function () {
        $shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'slug' => 'clear-me',
            'logo' => 'http://localhost:5490/tempo/branches/logo-old.png',
            'img_branches' => 'http://localhost:5490/tempo/branches/banner-old.png',
        ]);

        $this->actingAs($this->user)->putJson("{$this->base}/{$shop->id}", [
            'logo' => null,
            'img_branches' => null,
        ])->assertOk();

        $fresh = $shop->fresh();
        expect($fresh->getRawOriginal('logo'))->toBeNull()
            ->and($fresh->getRawOriginal('img_branches'))->toBeNull();
    });
});

// =========================================================================
//  #936 — per-breakpoint banners (desktop / tablet / mobile)
// =========================================================================

describe('responsive banners', function () {
    it('persists the three banners on create and returns them', function () {
        $urls = [
            'banner_desktop' => 'http://localhost:5490/tempo/branches/banner_desktop-a.png',
            'banner_tablet' => 'http://localhost:5490/tempo/branches/banner_tablet-a.png',
            'banner_mobile' => 'http://localhost:5490/tempo/branches/banner_mobile-a.png',
        ];

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Responsive Shop',
            'slug' => 'responsive-shop',
            ...$urls,
        ])->assertCreated();

        foreach ($urls as $field => $url) {
            $response->assertJsonPath("data.{$field}", $url);
        }

        $branch = Branch::find($response->json('data.id'));
        foreach ($urls as $field => $url) {
            expect($branch->getRawOriginal($field))->toBe($url);
        }
    });

    it('updates and clears each banner independently', function () {
        $shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'slug' => 'rebanner-me',
            'banner_desktop' => 'http://localhost:5490/tempo/branches/banner_desktop-old.png',
            'banner_tablet' => 'http://localhost:5490/tempo/branches/banner_tablet-old.png',
            'banner_mobile' => 'http://localhost:5490/tempo/branches/banner_mobile-old.png',
        ]);

        // Only mobile is sent — the other two must survive untouched.
        $this->actingAs($this->user)->putJson("{$this->base}/{$shop->id}", [
            'banner_mobile' => 'http://localhost:5490/tempo/branches/banner_mobile-new.png',
        ])->assertOk();

        $fresh = $shop->fresh();
        expect($fresh->getRawOriginal('banner_mobile'))->toBe('http://localhost:5490/tempo/branches/banner_mobile-new.png')
            ->and($fresh->getRawOriginal('banner_desktop'))->toBe('http://localhost:5490/tempo/branches/banner_desktop-old.png')
            ->and($fresh->getRawOriginal('banner_tablet'))->toBe('http://localhost:5490/tempo/branches/banner_tablet-old.png');

        $this->actingAs($this->user)->putJson("{$this->base}/{$shop->id}", [
            'banner_tablet' => null,
        ])->assertOk();

        expect($shop->fresh()->getRawOriginal('banner_tablet'))->toBeNull();
    });

    /**
     * Host công khai đổi ⇒ URL đã lưu vẫn phải đọc được. Base lấy từ ĐÍCH
     * UPLOAD (`filesystems.uploads`), không phải từ `s3` cố định (#2175) — nên
     * bài chạy trên cả hai hình dạng disk mà môi trường thật có thể dùng.
     */
    it('rebases stored banner URLs onto the live storage host', function (string $uploads, string $url, string $stored, string $expected) {
        config([
            'filesystems.uploads' => $uploads,
            "filesystems.disks.{$uploads}.url" => $url,
        ]);

        $shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'slug' => 'rebased-banner',
            'banner_desktop' => $stored,
        ]);

        expect($shop->fresh()->banner_desktop)->toBe($expected);
    })->with([
        'uploads=s3' => [
            's3',
            'https://cdn.example.test/tempo',
            'https://old-tunnel.test/tempo/branches/banner_desktop-x.png',
            'https://cdn.example.test/tempo/branches/banner_desktop-x.png',
        ],
        'uploads=public' => [
            'public',
            'https://api-new.example.test/storage',
            'https://old-tunnel.test/storage/branches/banner_desktop-x.png',
            'https://api-new.example.test/storage/branches/banner_desktop-x.png',
        ],
    ]);

    it('exposes the banners on the customer branch payload', function () {
        Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'slug' => 'customer-banner-shop',
            'is_active' => true,
            'banner_desktop' => 'http://localhost:5490/tempo/branches/banner_desktop-c.png',
            'banner_tablet' => null,
            'banner_mobile' => 'http://localhost:5490/tempo/branches/banner_mobile-c.png',
        ]);

        $branch = collect($this->getJson('/api/v1/customer/branches')->assertOk()->json('data'))
            ->firstWhere('slug', 'customer-banner-shop');

        expect($branch['banner_desktop'])->toBe('http://localhost:5490/tempo/branches/banner_desktop-c.png')
            ->and($branch['banner_tablet'])->toBeNull()
            ->and($branch['banner_mobile'])->toBe('http://localhost:5490/tempo/branches/banner_mobile-c.png');
    });
});

describe('POST /shops/upload-image', function () {
    it('stores an uploaded logo under branches/ and returns its URL', function () {
        // #2163 — fake ĐÚNG disk mà controller đọc từ config, không phải 's3' ghi
        // cứng. Ghim vào tên disk là chính cái lỗi issue này sửa: các bài dưới
        // đây xanh trên máy dev mà production vẫn 500 vì đích ghi thật khác.
        Storage::fake(config('filesystems.uploads'));

        $response = $this->actingAs($this->user)->postJson("{$this->base}/upload-image", [
            'type' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertCreated();

        $url = $response->json('data.url');
        expect($url)->toContain('branches/logo-');

        $stored = Storage::disk(config('filesystems.uploads'))->allFiles('branches');
        expect($stored)->toHaveCount(1)
            ->and($stored[0])->toStartWith('branches/logo-');
    });

    it('stores an uploaded banner under branches/', function () {
        // #2163 — fake ĐÚNG disk mà controller đọc từ config, không phải 's3' ghi
        // cứng. Ghim vào tên disk là chính cái lỗi issue này sửa: các bài dưới
        // đây xanh trên máy dev mà production vẫn 500 vì đích ghi thật khác.
        Storage::fake(config('filesystems.uploads'));

        $this->actingAs($this->user)->postJson("{$this->base}/upload-image", [
            'type' => 'banner',
            'file' => UploadedFile::fake()->image('banner.jpg', 1200, 400),
        ])->assertCreated();

        $stored = Storage::disk(config('filesystems.uploads'))->allFiles('branches');
        expect($stored[0])->toStartWith('branches/banner-');
    });

    it('stores each per-breakpoint banner under its own key prefix', function () {
        // #2163 — fake ĐÚNG disk mà controller đọc từ config, không phải 's3' ghi
        // cứng. Ghim vào tên disk là chính cái lỗi issue này sửa: các bài dưới
        // đây xanh trên máy dev mà production vẫn 500 vì đích ghi thật khác.
        Storage::fake(config('filesystems.uploads'));

        foreach (['banner_desktop', 'banner_tablet', 'banner_mobile'] as $type) {
            $this->actingAs($this->user)->postJson("{$this->base}/upload-image", [
                'type' => $type,
                'file' => UploadedFile::fake()->image("{$type}.jpg", 800, 400),
            ])->assertCreated()
                ->assertJsonPath('data.url', fn (string $url) => str_contains($url, "branches/{$type}-"));
        }

        expect(Storage::disk(config('filesystems.uploads'))->allFiles('branches'))->toHaveCount(3);
    });

    it('rejects a non-image upload', function () {
        // #2163 — fake ĐÚNG disk mà controller đọc từ config, không phải 's3' ghi
        // cứng. Ghim vào tên disk là chính cái lỗi issue này sửa: các bài dưới
        // đây xanh trên máy dev mà production vẫn 500 vì đích ghi thật khác.
        Storage::fake(config('filesystems.uploads'));

        $this->actingAs($this->user)->postJson("{$this->base}/upload-image", [
            'type' => 'logo',
            'file' => UploadedFile::fake()->create('malware.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
    });

    it('rejects an invalid image type value', function () {
        // #2163 — fake ĐÚNG disk mà controller đọc từ config, không phải 's3' ghi
        // cứng. Ghim vào tên disk là chính cái lỗi issue này sửa: các bài dưới
        // đây xanh trên máy dev mà production vẫn 500 vì đích ghi thật khác.
        Storage::fake(config('filesystems.uploads'));

        $this->actingAs($this->user)->postJson("{$this->base}/upload-image", [
            'type' => 'avatar',
            'file' => UploadedFile::fake()->image('logo.png'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['type']);
    });

    it('returns 401 when unauthenticated', function () {
        $this->postJson("{$this->base}/upload-image", [
            'type' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png'),
        ])->assertUnauthorized();
    });
});

// =========================================================================
//  DELETE — tempo-native soft-delete
// =========================================================================

describe('DELETE /shops/{shop}', function () {
    it('soft-deletes the shop locally', function () {
        $shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'slug' => 'delete-me',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$shop->id}")
            ->assertNoContent();

        expect(Branch::find($shop->id))->toBeNull()
            ->and(Branch::withTrashed()->where('id', $shop->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
    });

    it('returns 404 when the shop belongs to another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $shop = Branch::factory()->create([
            'console_organization_id' => $otherOrgId,
            'slug' => 'other-org-shop',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$shop->id}")
            ->assertNotFound();
    });
});
