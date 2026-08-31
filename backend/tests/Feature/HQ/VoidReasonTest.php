<?php

/**
 * plan-051 T1.3 — HQ VoidReason CRUD (/hq/{brand}/void-reasons).
 *
 * index/store/update only — deactivation is update {is_active:false}; there
 * is NO hard delete (historical order lines reference reasons by id). When
 * the brand has zero rows, index carries the five built-in suggestions
 * (creation hints — never persisted).
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use App\Models\VoidReason;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-void',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/void-reasons";

    $this->actingAs($this->user);
});

/** Helper: create a void reason in the test brand/org. */
function voidReasonInBrand(array $overrides = []): VoidReason
{
    return VoidReason::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'is_active' => true,
    ], $overrides));
}

// =========================================================================
//  Index + built-in suggestions
// =========================================================================

describe('index', function () {
    it('returns the five built-in suggestions when the brand has zero rows — and persists none of them', function () {
        $response = $this->getJson($this->baseUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(5, 'suggestions');

        foreach ($response->json('suggestions') as $suggestion) {
            expect($suggestion)->toHaveKeys(['label', 'stock_effect', 'requires_note', 'is_builtin_suggestion'])
                ->and($suggestion['is_builtin_suggestion'])->toBeTrue()
                ->and($suggestion['label'])->toHaveKeys(['ja', 'en', 'vi'])
                ->and($suggestion['stock_effect'])->toBeIn(['waste', 'restock', 'none']);
        }

        // Suggestions are hints, not rows.
        expect(VoidReason::count())->toBe(0);
    });

    it('lists the brand rows sorted by sort_order and drops the suggestions once any row exists', function () {
        voidReasonInBrand(['sort_order' => 2]);
        voidReasonInBrand(['sort_order' => 1]);

        $response = $this->getJson($this->baseUrl)->assertOk()->assertJsonCount(2, 'data');

        expect($response->json('suggestions'))->toBeNull()
            ->and($response->json('data.0.sort_order'))->toBe(1)
            ->and($response->json('data.1.sort_order'))->toBe(2);
    });

    it('does not show void reasons from other brands or orgs', function () {
        voidReasonInBrand();
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        VoidReason::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $otherBrand->id]);
        VoidReason::factory()->create(['organization_id' => (string) Str::uuid()]);

        $this->getJson($this->baseUrl)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a void reason with translatable labels and server-side org/brand + defaults', function () {
        $response = $this->postJson($this->baseUrl, [
            'ja' => ['label' => '打ち間違い'],
            'en' => ['label' => 'Entered by mistake'],
            'vi' => ['label' => 'Bấm nhầm'],
            'stock_effect' => 'restock',
        ])->assertCreated();

        $id = $response->json('data.id');
        $reason = VoidReason::with('translations')->findOrFail($id);

        expect($reason->organization_id)->toBe($this->orgId)
            ->and($reason->brand_id)->toBe($this->brand->id)
            ->and($reason->stock_effect->value)->toBe('restock')
            ->and($reason->requires_note)->toBeFalse()
            ->and($reason->is_active)->toBeTrue()
            ->and($reason->sort_order)->toBe(0)
            ->and($reason->translate('ja')?->label)->toBe('打ち間違い')
            ->and($reason->translate('en')?->label)->toBe('Entered by mistake')
            ->and($reason->translate('vi')?->label)->toBe('Bấm nhầm');
    });

    it('rejects an unknown stock_effect', function () {
        $this->postJson($this->baseUrl, [
            'ja' => ['label' => 'テスト'],
            'stock_effect' => 'explode',
        ])->assertStatus(422)->assertJsonValidationErrors('stock_effect');
    });

    it('requires a label in at least one language', function () {
        $this->postJson($this->baseUrl, [
            'stock_effect' => 'waste',
        ])->assertStatus(422)->assertJsonValidationErrors('ja.label');
    });
});

// =========================================================================
//  Update + soft deactivate
// =========================================================================

describe('update', function () {
    it('updates label, flags and sort order', function () {
        $reason = voidReasonInBrand(['requires_note' => false, 'sort_order' => 0, 'stock_effect' => 'none']);

        $this->patchJson("{$this->baseUrl}/{$reason->id}", [
            'ja' => ['label' => '調理ミス'],
            'stock_effect' => 'waste',
            'requires_note' => true,
            'sort_order' => 7,
        ])->assertOk();

        $reason->refresh();
        expect($reason->stock_effect->value)->toBe('waste')
            ->and($reason->requires_note)->toBeTrue()
            ->and($reason->sort_order)->toBe(7)
            ->and($reason->translate('ja')?->label)->toBe('調理ミス');
    });

    it('soft-deactivates via is_active=false — the row remains for historical references', function () {
        $reason = voidReasonInBrand();

        $this->patchJson("{$this->baseUrl}/{$reason->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect(VoidReason::findOrFail($reason->id)->is_active)->toBeFalse();
    });

    it('has no hard-delete endpoint (405)', function () {
        $reason = voidReasonInBrand();

        $this->deleteJson("{$this->baseUrl}/{$reason->id}")->assertStatus(405);

        expect(VoidReason::query()->whereKey($reason->id)->exists())->toBeTrue();
    });
});

// =========================================================================
//  Scoping — brand + org boundaries
// =========================================================================

describe('scoping', function () {
    it('404s an update on a sibling brand\'s row addressed through this brand\'s slug', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $foreign = VoidReason::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'is_active' => true,
        ]);

        $this->patchJson("{$this->baseUrl}/{$foreign->id}", ['is_active' => false])
            ->assertStatus(404);

        expect(VoidReason::findOrFail($foreign->id)->is_active)->toBeTrue();
    });

    it('403s a manager of another organization on this brand\'s endpoints', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $outsider = User::factory()->create([
            'console_organization_id' => $otherOrgId,
        ]);
        grantOrgAccess($outsider, $otherOrgId);

        $this->actingAs($outsider)
            ->getJson($this->baseUrl)
            ->assertStatus(403);

        $this->actingAs($outsider)
            ->postJson($this->baseUrl, [
                'ja' => ['label' => '侵入'],
                'stock_effect' => 'none',
            ])->assertStatus(403);
    });
});
