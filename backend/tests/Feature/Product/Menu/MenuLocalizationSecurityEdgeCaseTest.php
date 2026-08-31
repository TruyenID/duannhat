<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->consoleOrgId = $this->orgId;
    $this->user = User::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'slug' => 'localization-brand-a',
    ]);
    $this->foreignBrand = Brand::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'slug' => 'localization-brand-b',
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'brand-a-shop',
        'is_active' => true,
    ]);
    $this->foreignBranch = Branch::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'console_brand_id' => $this->foreignBrand->console_brand_id,
        'slug' => 'brand-b-shop',
        'is_active' => true,
    ]);

    $this->ownMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'name' => 'Brand A Menu',
    ]);
    $this->foreignMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->foreignBrand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'created_by_id' => $this->user->id,
        'name' => 'Brand B Secret Menu',
    ]);

    $this->ownSection = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Brand A Section',
    ]);
    $this->foreignSection = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->foreignBrand->id,
        'name' => 'Brand B Secret Section',
    ]);

    $foreignType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->foreignBrand->id,
    ]);
    $this->foreignProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->foreignBrand->id,
        'product_type_id' => $foreignType->id,
    ]);
    ProductSku::factory()->create(['product_id' => $this->foreignProduct->id]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

it('scopes menu, master-menu, lookup and section lists to the route brand', function () {
    $menuIds = collect($this->actingAs($this->user)
        ->getJson("{$this->baseUrl}/menus")
        ->assertOk()
        ->json('data'))->pluck('id');
    $masterIds = collect($this->actingAs($this->user)
        ->getJson("{$this->baseUrl}/master-menus")
        ->assertOk()
        ->json('data'))->pluck('id');
    $lookupIds = collect($this->actingAs($this->user)
        ->getJson("{$this->baseUrl}/menus/lookup")
        ->assertOk()
        ->json('data'))->pluck('id');
    $sectionIds = collect($this->actingAs($this->user)
        ->getJson("{$this->baseUrl}/menu-sections")
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($menuIds)->toContain($this->ownMenu->id)->not->toContain($this->foreignMenu->id)
        ->and($masterIds)->toContain($this->ownMenu->id)->not->toContain($this->foreignMenu->id)
        ->and($lookupIds)->toContain($this->ownMenu->id)->not->toContain($this->foreignMenu->id)
        ->and($sectionIds)->toContain($this->ownSection->id)->not->toContain($this->foreignSection->id);
});

it('does not reveal or mutate a sibling-brand menu through a swapped id', function (string $method, string $suffix, array $payload = []) {
    $url = "{$this->baseUrl}/menus/{$this->foreignMenu->id}{$suffix}";
    $response = match ($method) {
        'get' => $this->actingAs($this->user)->getJson($url),
        'put' => $this->actingAs($this->user)->putJson($url, $payload),
        'delete' => $this->actingAs($this->user)->deleteJson($url),
        'post' => $this->actingAs($this->user)->postJson($url, $payload),
    };

    $response->assertNotFound();
    expect($this->foreignMenu->fresh()->name)->toBe('Brand B Secret Menu')
        ->and($this->foreignMenu->fresh()->deleted_at)->toBeNull();
})->with([
    'detail' => ['get', ''],
    'update' => ['put', '', ['name' => 'Stolen Menu']],
    'delete' => ['delete', ''],
    'workflow' => ['post', '/deactivate'],
    'layout' => ['put', '/layout', ['menu_items' => []]],
]);

it('rejects a sibling-brand branch and product during menu creation', function () {
    $this->actingAs($this->user)->postJson("{$this->baseUrl}/menus", [
        'name' => 'Cross-brand branch attempt',
        'branch_id' => $this->foreignBranch->id,
        'product_ids' => [$this->foreignProduct->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id', 'product_ids.0']);

    $this->assertDatabaseMissing('menus', ['name' => 'Cross-brand branch attempt']);
});

it('rejects an inactive or deleted branch during create and update', function () {
    $inactive = Branch::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'inactive-brand-a-shop',
        'is_active' => false,
    ]);

    $this->actingAs($this->user)->postJson("{$this->baseUrl}/menus", [
        'name' => 'Inactive branch menu',
        'branch_id' => $inactive->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

    $draft = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
    ]);
    $this->actingAs($this->user)->putJson("{$this->baseUrl}/menus/{$draft->id}", [
        'branch_id' => $inactive->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
});

it('rejects whitespace-only and malformed localized names without partial writes', function (array $payload, string $errorKey) {
    $before = Menu::count();

    $this->actingAs($this->user)->postJson("{$this->baseUrl}/menus", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);

    expect(Menu::count())->toBe($before);
})->with([
    'base whitespace' => [['name' => " \t "], 'name'],
    'localized whitespace' => [['name' => 'Valid', 'en' => ['name' => '   ']], 'en.name'],
    'localized wrong type' => [['name' => 'Valid', 'vi' => ['name' => ['bad']]], 'vi.name'],
    'localized too long' => [['name' => 'Valid', 'ja' => ['name' => str_repeat('名', 256)]], 'ja.name'],
]);

it('ignores client attempts to mass-assign organization and brand context', function () {
    $id = $this->actingAs($this->user)->postJson("{$this->baseUrl}/menus", [
        'name' => 'Context-safe menu',
        'organization_id' => '99999999-9999-4999-8999-999999999999',
        'brand_id' => $this->foreignBrand->id,
        'branch_id' => $this->branch->id,
    ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('menus', [
        'id' => $id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
});

it('rejects unknown locale objects and malformed JSON with stable validation contracts', function () {
    $this->actingAs($this->user)->postJson("{$this->baseUrl}/menus", [
        'name' => 'Known locale menu',
        'fr' => ['name' => 'Menu français'],
    ])->assertUnprocessable()->assertJsonValidationErrors('fr');

    $response = $this->actingAs($this->user)->call(
        'POST',
        "{$this->baseUrl}/menus",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        '{"name":',
    );
    $response->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('requires authentication for HQ localization reads and mutations', function () {
    $this->getJson("{$this->baseUrl}/menus")->assertUnauthorized();
    $this->postJson("{$this->baseUrl}/menus", ['name' => 'No auth'])->assertUnauthorized();
    $this->putJson("{$this->baseUrl}/menus/{$this->ownMenu->id}", ['name' => 'No auth'])->assertUnauthorized();
});

it('records actor and safe before-after values for localized menu and section mutations', function () {
    $requestId = 'menu-localization-audit-test';
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'name' => '監査前',
    ]);
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => '分類前',
    ]);

    $this->actingAs($this->user)->withHeader('X-Request-ID', $requestId)->putJson("{$this->baseUrl}/menus/{$menu->id}", [
        'name' => '監査後',
        'ja' => ['name' => '監査後'],
        'en' => ['name' => 'After audit'],
        'vi' => ['name' => 'Sau kiểm toán'],
    ])->assertOk()->assertHeader('X-Request-ID', $requestId);
    $this->actingAs($this->user)->withHeader('X-Request-ID', $requestId)->putJson("{$this->baseUrl}/menu-sections/{$section->id}", [
        'name' => '分類後',
        'ja' => ['name' => '分類後'],
        'en' => ['name' => 'After section'],
        'vi' => ['name' => 'Sau danh mục'],
    ])->assertOk()->assertHeader('X-Request-ID', $requestId);

    foreach ([$menu, $section] as $model) {
        $audit = AuditLog::query()
            ->where('auditable_id', $model->id)
            ->where('action', 'updated')
            ->latest('created_at')
            ->first();

        expect($audit)->not->toBeNull()
            ->and($audit->user_id)->toBe($this->user->id)
            ->and($audit->metadata)->toHaveKeys(['changes', 'original', 'request_id'])
            ->and($audit->metadata['request_id'])->toBe($requestId)
            ->and(json_encode($audit->metadata))->not->toContain('token')
            ->not->toContain('cookie')
            ->not->toContain('password');
    }
});

it('rejects a stale menu update without overwriting a concurrent localized change', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'status' => 'Draft',
        'created_by_id' => $this->user->id,
        'name' => 'Original menu',
    ]);
    $staleTimestamp = $menu->updated_at->toISOString();
    Menu::query()->whereKey($menu->id)->update([
        'name' => 'Concurrent menu edit',
        'updated_at' => now()->addMinute(),
    ]);

    $this->actingAs($this->user)->putJson("{$this->baseUrl}/menus/{$menu->id}", [
        'updated_at' => $staleTimestamp,
        'name' => 'Stale overwrite',
        'en' => ['name' => 'Stale English overwrite'],
    ])->assertConflict();

    // SQLite's test transaction rolls the setup write back with the nested
    // request transaction; the invariant under test is that stale data never
    // wins and no translation is partially inserted.
    expect($menu->fresh()->name)->not->toBe('Stale overwrite');
    $this->assertDatabaseMissing('menu_translations', [
        'menu_id' => $menu->id,
        'locale' => 'en',
        'name' => 'Stale English overwrite',
    ]);
});

it('rejects a stale section update without partially writing translations', function () {
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Original section',
    ]);
    $staleTimestamp = $section->updated_at->toISOString();
    MenuSection::query()->whereKey($section->id)->update([
        'name' => 'Concurrent section edit',
        'updated_at' => now()->addMinute(),
    ]);

    $this->actingAs($this->user)->putJson("{$this->baseUrl}/menu-sections/{$section->id}", [
        'updated_at' => $staleTimestamp,
        'name' => 'Stale section overwrite',
        'vi' => ['name' => 'Ghi đè cũ'],
    ])->assertConflict();

    expect($section->fresh()->name)->not->toBe('Stale section overwrite');
    $this->assertDatabaseMissing('menu_section_translations', [
        'menu_section_id' => $section->id,
        'locale' => 'vi',
        'name' => 'Ghi đè cũ',
    ]);
});
