<?php

/**
 * Plan 013 — ToppingGroupTest
 *
 * Covers:
 *   Happy H1  — POST /topping-groups → 201, brand_id scoped
 *   Happy H2  — GET  /topping-groups → only groups for this brand
 *   Happy H3  — PUT  /topping-groups/{id} → 200, name updated
 *   Happy H4  — DELETE /topping-groups/{id} → 204, soft-deleted
 *   Happy H5  — POST /topping-groups/{id}/restore → 200, deleted_at cleared
 *   Happy H10 — GET /topping-groups/lookup → [{id, name}] array
 *   Validation V1 — max_select < min_select → 422
 *   Validation V2 — missing name → 422
 *   Auth A1 — unauthenticated → 401
 *   Auth A2 — cross-brand access → 403/404
 *   Edge E1 — no groups → empty paginated list
 *   Edge E3 — max_select = null → stored as NULL
 *   Side effect SE4 — created_by_id = acting user
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductToppingGroup;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-tg-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/topping-groups";
});

describe('happy path', function () {
    it('creates a topping group scoped to the brand', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'ソーストッピング',
            'ja' => ['name' => 'ソーストッピング'],
            'en' => ['name' => 'Sauce Toppings'],
            'min_select' => 0,
        ]);

        $response->assertCreated();

        $group = ToppingGroup::findOrFail($response->json('data.id'));
        expect($group->brand_id)->toBe($this->brand->id)
            ->and($group->organization_id)->toBe($this->orgId);
    });

    it('auto-assigns sort_order as max+1 and ignores any sort_order sent by the client', function () {
        ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'sort_order' => 5,
        ]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'ja' => ['name' => '追加グループ'],
            'min_select' => 0,
            'sort_order' => 99,  // should be ignored
        ]);

        $response->assertCreated();

        $group = ToppingGroup::findOrFail($response->json('data.id'));
        expect($group->sort_order)->toBe(6);
    });

    it('lists only groups belonging to this brand', function () {
        ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        // Group in another brand
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        ToppingGroup::factory()->create([
            'brand_id' => $otherBrand->id,
            'organization_id' => $otherOrgId,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->base);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toHaveCount(1);
    });

    it('updates name and returns 200', function () {
        $group = ToppingGroup::factory()->withTranslations('旧名前', 'Old Name', 'Tên cũ')->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("{$this->base}/{$group->id}", [
                'name' => '新名前',
                'ja' => ['name' => '新名前'],
                'en' => ['name' => 'New Name'],
            ]);

        $response->assertOk();
    });

    it('soft-deletes a group returning 204 with deleted_at set', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$group->id}")
            ->assertNoContent();

        expect(ToppingGroup::withTrashed()->findOrFail($group->id)->deleted_at)->not->toBeNull();
    });

    it('bulk-delete skips in-use groups and surfaces used_by per id in errors', function () {
        $freeGroup = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $inUseGroup = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        $productType = ProductType::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $productType->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        ProductToppingGroup::create([
            'product_id' => $product->id,
            'topping_group_id' => $inUseGroup->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->base}/bulk-delete", [
                'ids' => [$freeGroup->id, $inUseGroup->id],
            ]);

        $response->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('errors.0.id', $inUseGroup->id)
            ->assertJsonPath('errors.0.error', 'TOPPING_GROUP_IN_USE')
            ->assertJsonPath('errors.0.used_by.0.id', (string) $product->id);

        expect(ToppingGroup::find($freeGroup->id)?->deleted_at)->toBeNull();
        expect(ToppingGroup::withTrashed()->find($freeGroup->id)->deleted_at)->not->toBeNull();
        expect(ToppingGroup::find($inUseGroup->id)->deleted_at)->toBeNull();
    });

    it('blocks delete with 409 TOPPING_GROUP_IN_USE when a product still references the group', function () {
        $group = ToppingGroup::factory()->withTranslations('使用中グループ', 'In-Use Group', 'Nhóm đang dùng')->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        $productType = ProductType::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $product = Product::factory()->create([
            'product_type_id' => $productType->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        ProductToppingGroup::create([
            'product_id' => $product->id,
            'topping_group_id' => $group->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$group->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error', 'TOPPING_GROUP_IN_USE')
            ->assertJsonPath('used_by.0.id', (string) $product->id);

        expect(ToppingGroup::find($group->id)->deleted_at)->toBeNull();
    });

    it('restores a soft-deleted group and clears deleted_at', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $group->delete();

        $response = $this->actingAs($this->user)
            ->postJson("{$this->base}/{$group->id}/restore");

        $response->assertOk();
        expect(ToppingGroup::findOrFail($group->id)->deleted_at)->toBeNull();
    });

    it('reorders groups and persists sort_order', function () {
        $g1 = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'sort_order' => 1,
        ]);
        $g2 = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'sort_order' => 2,
        ]);
        $g3 = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'sort_order' => 3,
        ]);

        // Reverse the order: g3, g1, g2
        $response = $this->actingAs($this->user)
            ->putJson("{$this->base}/reorder", [
                'topping_group_ids' => [$g3->id, $g1->id, $g2->id],
            ]);

        $response->assertOk();

        expect(ToppingGroup::find($g3->id)->sort_order)->toBe(1)
            ->and(ToppingGroup::find($g1->id)->sort_order)->toBe(2)
            ->and(ToppingGroup::find($g2->id)->sort_order)->toBe(3);
    });

    it('returns a lightweight lookup array with id and name keys', function () {
        ToppingGroup::factory()->withTranslations('ルックアップ')->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson("{$this->base}/lookup");

        $response->assertOk();
        $data = $response->json('data');
        expect($data)->toBeArray()->not->toBeEmpty();
        expect($data[0])->toHaveKey('id')->toHaveKey('name');
    });

    it('returns items_count reflecting active items per group', function () {
        // Empty group — admin created shell, hasn't added items yet.
        $emptyGroup = ToppingGroup::factory()->withTranslations('空グループ')->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'is_active' => true,
        ]);

        // Group with two items + one soft-deleted (must not count).
        $groupWithItems = ToppingGroup::factory()->withTranslations('具入りグループ')->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'is_active' => true,
        ]);
        $product1 = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $product2 = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $product3 = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        ToppingGroupItem::factory()->create([
            'topping_group_id' => $groupWithItems->id,
            'product_id' => $product1->id,
        ]);
        ToppingGroupItem::factory()->create([
            'topping_group_id' => $groupWithItems->id,
            'product_id' => $product2->id,
        ]);
        $deleted = ToppingGroupItem::factory()->create([
            'topping_group_id' => $groupWithItems->id,
            'product_id' => $product3->id,
        ]);
        $deleted->delete();

        $response = $this->actingAs($this->user)->getJson("{$this->base}/lookup");
        $response->assertOk();
        $data = collect($response->json('data'))->keyBy('id');

        expect($data[$emptyGroup->id]['items_count'])->toBe(0);
        expect($data[$groupWithItems->id]['items_count'])->toBe(2);
    });

    it('falls back ja → en → vi when current locale translation is missing', function () {
        // Factory's `name` field auto-creates a translation for the default
        // locale. Clear all translations first, then re-fetch a fresh instance
        // so Astrotomic's in-memory translation cache doesn't re-persist them.
        $makeBare = function () {
            $g = ToppingGroup::factory()->create([
                'brand_id' => $this->brand->id,
                'organization_id' => $this->orgId,
                'is_active' => true,
            ]);
            DB::table('topping_group_translations')->where('topping_group_id', $g->id)->delete();

            return ToppingGroup::find($g->id);
        };

        // Group with only EN — request locale is ja → should fall back to en.
        $enOnly = $makeBare();
        $enOnly->translateOrNew('en')->name = 'EN Only Group';
        $enOnly->save();

        // Group with only VI — no ja, no en → falls back to vi.
        $viOnly = $makeBare();
        $viOnly->translateOrNew('vi')->name = 'VI Only Group';
        $viOnly->save();

        // Group with ja — request locale is ja → uses ja directly.
        $jaGroup = $makeBare();
        $jaGroup->translateOrNew('ja')->name = 'JA Group';
        $jaGroup->translateOrNew('en')->name = 'EN Group';
        $jaGroup->save();

        $response = $this->actingAs($this->user)
            ->withHeader('Accept-Language', 'ja')
            ->getJson("{$this->base}/lookup");

        $response->assertOk();
        $data = collect($response->json('data'))->keyBy('id');

        expect($data[$enOnly->id]['name'])->toBe('EN Only Group');
        expect($data[$viOnly->id]['name'])->toBe('VI Only Group');
        expect($data[$jaGroup->id]['name'])->toBe('JA Group');
    });
});

describe('validation', function () {
    it('returns 422 when max_select is less than min_select', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'テスト',
                'ja' => ['name' => 'テスト'],
                'min_select' => 3,
                'max_select' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_select']);
    });

    it('returns 422 when name is missing', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'min_select' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ja.name']);
    });

    it('returns 422 (not 500) when PUT clears name to an empty string', function () {
        // Regression: Laravel's ConvertEmptyStringsToNull middleware turns
        // `"name": ""` into NULL before validation. The base column is
        // nullable, but `topping_group_translations.name` is NOT NULL — so a
        // PUT with empty name used to crash with a 500 (NOT NULL constraint
        // violation) on save. The update validator now catches this in the
        // cross-field check and returns 422.
        $group = ToppingGroup::factory()->withTranslations('旧', 'Old', 'Cũ')->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$group->id}", [
                'name' => '',
                'min_select' => 0,
                'max_select' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ja.name']);
    });
});

describe('authorization', function () {
    it('returns 401 when unauthenticated', function () {
        $this->postJson($this->base, [
            'name' => 'テスト',
            'ja' => ['name' => 'テスト'],
            'min_select' => 0,
        ])->assertUnauthorized();
    });

    it('returns 403 or 404 when accessing another brand\'s groups', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'slug' => 'other-brand-'.Str::random(4),
            'is_active' => true,
        ]);
        $otherGroup = ToppingGroup::factory()->create([
            'brand_id' => $otherBrand->id,
            'organization_id' => $otherOrgId,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/{$otherGroup->id}");

        expect($response->status())->toBeIn([403, 404]);
    });
});

describe('edge cases', function () {
    it('returns an empty paginated list when no groups exist', function () {
        $response = $this->actingAs($this->user)->getJson($this->base);

        $response->assertOk()
            ->assertJsonPath('data', []);
    });

    it('stores max_select as null when omitted', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'ヌルテスト',
            'ja' => ['name' => 'ヌルテスト'],
            'min_select' => 0,
        ]);

        $response->assertCreated();
        $group = ToppingGroup::findOrFail($response->json('data.id'));
        expect($group->max_select)->toBeNull();
    });
});

describe('side effects', function () {
    it('stamps created_by_id with the acting user', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'スタンプテスト',
            'ja' => ['name' => 'スタンプテスト'],
            'min_select' => 0,
        ]);

        $response->assertCreated();
        $group = ToppingGroup::findOrFail($response->json('data.id'));
        expect($group->created_by_id)->toBe($this->user->id);
    });
});
