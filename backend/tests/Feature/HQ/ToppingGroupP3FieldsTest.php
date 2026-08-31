<?php

/**
 * Issue #151 P3 — Topping group advanced configuration fields
 *
 * Covers:
 *   - modifier_type enum {add, remove}
 *   - price_strategy enum {flat, free_up_to_n} + free_quantity validation
 *   - cross-field rules surfaced in withValidator
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\ToppingGroup;
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
        'slug' => 'acme-p3-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/topping-groups";
});

function p3Payload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test',
        'ja' => ['name' => 'テスト'],
        'min_select' => 0,
    ], $overrides);
}

// =============================================================================
// modifier_type
// =============================================================================

describe('modifier_type', function () {
    it('defaults to add', function () {
        $r = $this->actingAs($this->user)->postJson($this->base, p3Payload())->assertCreated();
        expect(ToppingGroup::findOrFail($r->json('data.id'))->modifier_type->value)->toBe('add');
    });

    it('accepts modifier_type=remove (pho không hành use case)', function () {
        $r = $this->actingAs($this->user)->postJson($this->base, p3Payload(['modifier_type' => 'remove']))
            ->assertCreated();
        expect(ToppingGroup::findOrFail($r->json('data.id'))->modifier_type->value)->toBe('remove');
    });

    it('rejects unknown modifier_type values', function () {
        $this->actingAs($this->user)->postJson($this->base, p3Payload(['modifier_type' => 'discount']))
            ->assertUnprocessable()->assertJsonValidationErrors(['modifier_type']);
    });
});

// =============================================================================
// price_strategy + free_quantity cross-field
// =============================================================================

describe('price_strategy + free_quantity', function () {
    it('defaults to flat with NULL free_quantity', function () {
        $r = $this->actingAs($this->user)->postJson($this->base, p3Payload())->assertCreated();
        $g = ToppingGroup::findOrFail($r->json('data.id'));
        expect($g->price_strategy->value)->toBe('flat')
            ->and($g->free_quantity)->toBeNull();
    });

    it('accepts free_up_to_n with a positive free_quantity', function () {
        $r = $this->actingAs($this->user)->postJson($this->base, p3Payload([
            'price_strategy' => 'free_up_to_n',
            'free_quantity' => 3,
        ]))->assertCreated();
        $g = ToppingGroup::findOrFail($r->json('data.id'));
        expect($g->price_strategy->value)->toBe('free_up_to_n')
            ->and($g->free_quantity)->toBe(3);
    });

    it('rejects free_up_to_n WITHOUT free_quantity (cross-field rule)', function () {
        $this->actingAs($this->user)->postJson($this->base, p3Payload([
            'price_strategy' => 'free_up_to_n',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['free_quantity']);
    });

    it('rejects free_up_to_n with free_quantity=0', function () {
        $this->actingAs($this->user)->postJson($this->base, p3Payload([
            'price_strategy' => 'free_up_to_n',
            'free_quantity' => 0,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['free_quantity']);
    });

    it('rejects unknown price_strategy values', function () {
        $this->actingAs($this->user)->postJson($this->base, p3Payload([
            'price_strategy' => 'pyramid_scheme',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['price_strategy']);
    });

    it('cross-field also checks on update when only price_strategy is changed', function () {
        $g = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'price_strategy' => 'flat',
            'free_quantity' => null,
        ]);
        $this->actingAs($this->user)
            ->putJson("{$this->base}/{$g->id}", ['price_strategy' => 'free_up_to_n'])
            ->assertUnprocessable()->assertJsonValidationErrors(['free_quantity']);
    });
});
