<?php

/**
 * Issue #151 P2 — Topping international-standard fields
 *
 * Covers:
 *   - ToppingGroupItem.is_default — Square `on_by_default` pattern
 *   - ToppingGroup.selection_type enum — single / multiple
 *   - Cross-field rule: selection_type=single ⇒ max_select must be 1
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
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
        'slug' => 'acme-p2-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->toppingType = ProductType::factory()->create([
        'code' => 'topping',
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/topping-groups";
});

// =============================================================================
// selection_type enum
// =============================================================================

describe('selection_type enum', function () {
    it('defaults to multiple when not provided', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Default selection type',
            'ja' => ['name' => 'デフォルト'],
            'min_select' => 0,
            'max_select' => 3,
        ])->assertCreated();

        $group = ToppingGroup::findOrFail($response->json('data.id'));
        expect($group->selection_type->value)->toBe('multiple');
    });

    it('accepts selection_type=single with max_select=1', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Size pick',
            'ja' => ['name' => 'サイズ'],
            'selection_type' => 'single',
            'min_select' => 1,
            'max_select' => 1,
        ])->assertCreated();

        expect(ToppingGroup::findOrFail($response->json('data.id'))->selection_type->value)
            ->toBe('single');
    });

    it('accepts selection_type=multiple with max_select > 1', function () {
        $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Sauce add-ons',
            'ja' => ['name' => 'ソース'],
            'selection_type' => 'multiple',
            'min_select' => 0,
            'max_select' => 5,
        ])->assertCreated();
    });

    it('rejects an invalid selection_type value', function () {
        $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Bad enum',
            'ja' => ['name' => 'NG'],
            'selection_type' => 'one_or_two_lol',
            'min_select' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['selection_type']);
    });

    it('rejects selection_type=single with max_select=2 (cross-field rule)', function () {
        $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Inconsistent',
            'ja' => ['name' => 'おかしい'],
            'selection_type' => 'single',
            'min_select' => 1,
            'max_select' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors(['max_select']);
    });

    it('cross-field rule also fires on update when only max_select changes', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'selection_type' => 'single',
            'max_select' => 1,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$group->id}", ['max_select' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['max_select']);
    });
});

// =============================================================================
// max_qty_per_item — restored Phase 2 field, cap số lượng từng loại topping
// =============================================================================

describe('max_qty_per_item field', function () {
    it('persists a value > 1 on create', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Stackable extras',
            'ja' => ['name' => '追加可能'],
            'min_select' => 0,
            'max_select' => 5,
            'max_qty_per_item' => 5,
        ])->assertCreated();

        expect((int) ToppingGroup::findOrFail($response->json('data.id'))->max_qty_per_item)
            ->toBe(5);
    });

    it('defaults to 1 when omitted on create', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Default qty',
            'ja' => ['name' => 'デフォルト数量'],
            'min_select' => 0,
            'max_select' => 3,
        ])->assertCreated();

        expect((int) ToppingGroup::findOrFail($response->json('data.id'))->max_qty_per_item)
            ->toBe(1);
    });

    it('rejects max_qty_per_item < 1', function () {
        $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Bad qty',
            'ja' => ['name' => 'NG'],
            'min_select' => 0,
            'max_qty_per_item' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['max_qty_per_item']);
    });

    it('updates max_qty_per_item via PUT', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'max_qty_per_item' => 1,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$group->id}", ['max_qty_per_item' => 4])
            ->assertOk();

        expect((int) $group->fresh()->max_qty_per_item)->toBe(4);
    });
});

// =============================================================================
// ToppingGroupItem.is_default
// =============================================================================

describe('is_default field', function () {
    beforeEach(function () {
        $this->group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $this->itemsBase = "{$this->base}/{$this->group->id}/items";
    });

    it('defaults to false when item is created without explicit value', function () {
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'product_type_id' => $this->toppingType->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson($this->itemsBase, ['product_id' => $product->id])
            ->assertCreated();

        $item = ToppingGroupItem::findOrFail($response->json('data.id'));
        expect($item->is_default)->toBeFalse();
    });

    it('persists is_default=true when explicitly set on the model', function () {
        // The current addItem() service signature doesn't accept `is_default`
        // (Phase 2 will wire that). For now exercise the model layer directly
        // — covers the column + cast + fillable wiring.
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'product_type_id' => $this->toppingType->id,
        ]);

        $item = ToppingGroupItem::create([
            'topping_group_id' => $this->group->id,
            'product_id' => $product->id,
            'is_default' => true,
        ]);

        expect($item->fresh()->is_default)->toBeTrue();
    });
});
