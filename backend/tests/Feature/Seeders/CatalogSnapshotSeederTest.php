<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use Database\Seeders\BetoyaSeeder;
use Illuminate\Support\Str;

it('restores the SQL catalog and menus onto Platform-owned roots', function () {
    $consoleOrganizationId = (string) Str::uuid();
    $consoleBrandId = (string) Str::uuid();
    $organization = Organization::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'slug' => 'betoya',
    ]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $consoleBrandId,
        'slug' => 'betoya',
        'is_active' => true,
    ]);

    foreach ([
        'head-office', 'sumiyoshi-kitchen', 'tsukiji', 'hongo', 'tameike-sanno',
        'shiroi-factory', 'jimbocho', 'ningyocho', 'laqua-dd',
        'ningyocho-delicatessen', 'event-store', 'tokyu-kichijoji-event',
        'monzen-nakacho', 'aeon-mall-tsudanuma', 'hikarie-norengai',
        'sogo-chiba', 'marui-kinshicho-event',
    ] as $slug) {
        Branch::factory()->create([
            'console_organization_id' => $consoleOrganizationId,
            'console_brand_id' => $consoleBrandId,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    $this->seed(BetoyaSeeder::class);
    $this->seed(BetoyaSeeder::class);

    $ningyocho = Branch::query()->where('slug', 'ningyocho')->firstOrFail();
    $ningyochoFloor = $this->getJson('/api/v1/customer/branches/ningyocho/zones')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->json('data');

    $branchIds = Branch::query()->pluck('id');
    $menuIds = DB::table('menus')->where('brand_id', $brand->id)->pluck('id');
    $sectionIds = DB::table('menu_sections')->where('brand_id', $brand->id)->pluck('id');
    $productIds = DB::table('products')->where('brand_id', $brand->id)->pluck('id');
    $toppingGroupIds = DB::table('topping_groups')->where('brand_id', $brand->id)->pluck('id');
    $eligibleSkuIds = DB::table('product_skus')
        ->whereIn('product_id', $productIds)
        ->where(function ($query) {
            $query->whereNotNull('name')
                ->orWhereNotNull('option_value1_id')
                ->orWhereNotNull('option_value2_id')
                ->orWhereNotNull('option_value3_id');
        })
        ->pluck('id');

    $localizedCopy = collect([
        ...DB::table('branch_translations')->whereIn('branch_id', $branchIds)->whereIn('locale', ['en', 'vi'])->pluck('name'),
        ...DB::table('menu_translations')->whereIn('menu_id', $menuIds)->whereIn('locale', ['en', 'vi'])->pluck('name'),
        ...DB::table('menu_section_translations')->whereIn('menu_section_id', $sectionIds)->whereIn('locale', ['en', 'vi'])->pluck('name'),
        ...DB::table('product_translations')->whereIn('product_id', $productIds)->whereIn('locale', ['en', 'vi'])->pluck('name'),
        ...DB::table('product_translations')->whereIn('product_id', $productIds)->whereIn('locale', ['en', 'vi'])->whereNotNull('description')->pluck('description'),
        ...DB::table('topping_group_translations')->whereIn('topping_group_id', $toppingGroupIds)->whereIn('locale', ['en', 'vi'])->pluck('name'),
        ...DB::table('product_sku_translations')->whereIn('product_sku_id', $eligibleSkuIds)->whereIn('locale', ['en', 'vi'])->pluck('name'),
    ]);
    expect(DB::table('products')->where('brand_id', $brand->id)->count())->toBe(419)
        ->and(DB::table('product_skus')->whereIn('product_id', DB::table('products')->where('brand_id', $brand->id)->select('id'))->count())->toBe(440)
        ->and(DB::table('categories')->where('brand_id', $brand->id)->count())->toBe(62)
        ->and(DB::table('recipes')->where('brand_id', $brand->id)->count())->toBe(3)
        ->and(DB::table('menus')->where('brand_id', $brand->id)->count())->toBe(32)
        ->and(DB::table('menu_products')->count())->toBe(2377)
        ->and(DB::table('menu_product_skus')->count())->toBe(1657)
        ->and(DB::table('branch_translations')->whereIn('branch_id', $branchIds)->count())->toBe(39)
        ->and(DB::table('menu_translations')->whereIn('menu_id', $menuIds)->count())->toBe(73)
        ->and(DB::table('menu_section_translations')->whereIn('menu_section_id', $sectionIds)->count())->toBe(375)
        ->and(DB::table('product_sku_translations')->whereIn('product_sku_id', $eligibleSkuIds)->count())->toBe(232)
        // Snapshot mirrors production: some en/vi rows still carry Japanese fallback copy.
        ->and($localizedCopy->filter(fn (?string $value): bool => is_string($value) && preg_match('/[ぁ-んァ-ン一-龯]/u', $value) === 1)->count())->toBeLessThanOrEqual(20)
        ->and(DB::table('branch_translations')->where('branch_id', Branch::query()->where('slug', 'jimbocho')->value('id'))->where('locale', 'en')->value('name'))->toBe('Jimbocho Store')
        ->and(DB::table('menu_translations')->where('menu_id', '019f6efa-2fa0-725a-946e-ddc7e0c891b9')->where('locale', 'en')->value('name'))->toBe('Jimbocho Store Menu')
        ->and(DB::table('menu_products')
            ->join('products', 'products.id', '=', 'menu_products.product_id')
            ->whereNull('menu_products.deleted_at')
            ->whereNotNull('products.deleted_at')
            ->count())->toBe(0)
        ->and(DB::table('topping_group_items')
            ->join('products', 'products.id', '=', 'topping_group_items.product_id')
            ->whereNull('topping_group_items.deleted_at')
            ->whereNotNull('products.deleted_at')
            ->count())->toBe(0)
        ->and(DB::table('menu_products')
            ->where('menu_id', '019f6efa-2f83-71a8-b061-2c8f9435718a')
            ->whereNull('deleted_at')
            ->count())->toBe(99)
        ->and(DB::table('files')->count())->toBe(296)
        ->and(DB::table('files')->where('disk', 'public')->count())->toBe(296)
        ->and(DB::table('files')->where('path', 'like', 'gallery-fixtures/%')->count())->toBe(0)
        ->and(DB::table('files')->where('fileable_id', '019f6ed5-4d4f-7020-b776-2ea6674e7580')->where('collection', 'gallery')->value('path'))
        ->toBe('products/019f6ed5-4d4f-7020-b776-2ea6674e7580/image.jpg')
        ->and(DB::table('zones')->where('branch_id', $ningyocho->id)->count())->toBe(4)
        ->and(DB::table('zones')->where('branch_id', $ningyocho->id)->whereNull('deleted_at')->count())->toBe(4)
        ->and(DB::table('tables')->where('branch_id', $ningyocho->id)->count())->toBe(43)
        ->and(DB::table('tables')->where('branch_id', $ningyocho->id)->whereNull('deleted_at')->count())->toBe(25)
        ->and(DB::table('tables')->where('branch_id', $ningyocho->id)->whereNull('deleted_at')->where('status', 'free')->count())->toBe(25)
        ->and(DB::table('tables')->where('branch_id', $ningyocho->id)->whereNotNull('current_order_id')->count())->toBe(0)
        ->and(collect($ningyochoFloor)->sum(fn (array $zone): int => count($zone['tables'])))->toBe(25)
        ->and(Organization::query()->where('console_organization_id', '00000000-aaaa-4aaa-aaaa-000000000001')->exists())->toBeFalse()
        ->and(Branch::query()->count())->toBe(17)
        ->and($organization->fresh()->console_organization_id)->toBe($consoleOrganizationId);
});

it('refuses to invent a tenant when Platform roots have not been synced', function () {
    $this->seed(BetoyaSeeder::class);
})->throws(RuntimeException::class, 'Platform brand [betoya] must be synced');

it('ships every real Betoya public media object with a verified checksum', function () {
    $fixtureDirectory = database_path('seeders/fixtures/catalog');
    $mediaManifest = json_decode(
        file_get_contents($fixtureDirectory.'/media_manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $files = json_decode(
        file_get_contents($fixtureDirectory.'/files.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $branchMedia = json_decode(
        file_get_contents($fixtureDirectory.'/branch_media.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $referencedPaths = collect($files)->pluck('path')
        ->merge(collect($branchMedia)->flatMap(
            fn (array $branch): array => collect($branch)
                ->filter(fn (mixed $value): bool => is_string($value) && str_starts_with($value, '/storage/'))
                ->map(fn (string $value): string => substr($value, strlen('/storage/')))
                ->values()
                ->all(),
        ))
        ->unique()
        ->sort()
        ->values();
    $manifestPaths = collect($mediaManifest)->pluck('path')->sort()->values();

    expect($mediaManifest)->toHaveCount(183)
        ->and($manifestPaths->all())->toBe($referencedPaths->all())
        ->and(collect($branchMedia)->flatten()->contains(
            fn (mixed $value): bool => is_string($value) && preg_match('#^https?://#', $value) === 1,
        ))->toBeFalse()
        ->and(collect($files)->contains(
            fn (array $file): bool => str_starts_with((string) $file['path'], 'gallery-fixtures/'),
        ))->toBeFalse();

    foreach ($mediaManifest as $media) {
        $source = $fixtureDirectory.'/media/'.$media['path'];
        expect(is_file($source))->toBeTrue()
            ->and(filesize($source))->toBe($media['size'])
            ->and(hash_file('sha256', $source))->toBe($media['sha256']);
    }
});
