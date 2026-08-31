<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TillTenderType;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * #1156 — per-branch tender activation endpoints.
 *
 *   GET   /shops/{slug}/till/tender-types              — effective list
 *   PATCH /shops/{slug}/till/tender-types/{tenderKey}  — activation switch
 *
 * The PATCH materializes a branch-scoped override row on first flip (copying
 * the org row's fields) and updates it in place afterwards. Org-wide seeded
 * rows are never mutated, so sibling branches are untouched.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'activation-shop',
        'is_active' => true,
    ]);
    $this->otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'activation-other-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/till/tender-types";

    // Org vocabulary: credit / paypay / merpay (merpay globally off).
    foreach ([
        ['credit', 1, true],
        ['paypay', 2, true],
        ['merpay', 3, false],
    ] as [$key, $sort, $active]) {
        TillTenderType::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => null,
            'tender_key' => $key,
            'name' => ucfirst($key),
            'category' => 'qr',
            'sort_order' => $sort,
            'is_active' => $active,
            'payment_method_code' => $key === 'credit' ? 'card' : null,
            'is_expected_anchor' => $key === 'credit',
            'requires_terminal_total' => true,
        ]);
    }
});

// =========================================================================
//  GET — effective list
// =========================================================================

it('lists the effective tenders for the branch (org vocabulary, active only)', function () {
    $response = $this->actingAs($this->manager)->getJson($this->base)->assertOk();

    expect(collect($response->json('data'))->pluck('tender_key')->all())
        ->toBe(['credit', 'paypay']);
});

it('applies this branch overrides and ignores sibling-branch overrides', function () {
    // This branch hides paypay; sibling branch hides credit.
    TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'tender_key' => 'paypay',
        'category' => 'qr',
        'sort_order' => 2,
        'is_active' => false,
    ]);
    TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
        'tender_key' => 'credit',
        'category' => 'qr',
        'sort_order' => 1,
        'is_active' => false,
    ]);

    $response = $this->actingAs($this->manager)->getJson($this->base)->assertOk();

    expect(collect($response->json('data'))->pluck('tender_key')->all())
        ->toBe(['credit']);
});

// =========================================================================
//  PATCH — activation switch
// =========================================================================

it('deactivating creates a branch override row copying the org row fields', function () {
    $this->actingAs($this->manager)
        ->patchJson("{$this->base}/credit", ['is_active' => false])
        ->assertCreated()
        ->assertJsonPath('data.tender_key', 'credit')
        ->assertJsonPath('data.is_active', false);

    $override = TillTenderType::query()
        ->where('organization_id', $this->orgId)
        ->where('branch_id', $this->shop->id)
        ->where('tender_key', 'credit')
        ->first();

    expect($override)->not->toBeNull()
        ->and($override->is_active)->toBeFalse()
        ->and($override->category instanceof BackedEnum ? $override->category->value : $override->category)->toBe('qr')
        ->and($override->payment_method_code)->toBe('card')
        ->and((bool) $override->is_expected_anchor)->toBeTrue()
        ->and((bool) $override->requires_terminal_total)->toBeTrue()
        ->and((int) $override->sort_order)->toBe(1);

    // Org row untouched; effective list no longer shows credit.
    expect(TillTenderType::query()->whereNull('branch_id')->where('tender_key', 'credit')->first()->is_active)->toBeTrue();

    $keys = collect($this->actingAs($this->manager)->getJson($this->base)->json('data'))
        ->pluck('tender_key')->all();
    expect($keys)->toBe(['paypay']);
});

it('flipping twice updates the SAME override row — no duplicates', function () {
    $this->actingAs($this->manager)
        ->patchJson("{$this->base}/paypay", ['is_active' => false])
        ->assertCreated();
    $this->actingAs($this->manager)
        ->patchJson("{$this->base}/paypay", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    $overrides = TillTenderType::query()
        ->where('branch_id', $this->shop->id)
        ->where('tender_key', 'paypay')
        ->get();

    expect($overrides)->toHaveCount(1)
        ->and($overrides->first()->is_active)->toBeTrue();

    $keys = collect($this->actingAs($this->manager)->getJson($this->base)->json('data'))
        ->pluck('tender_key')->all();
    expect($keys)->toBe(['credit', 'paypay']);
});

it('can activate a tender the org keeps globally off (branch-level re-activation)', function () {
    $this->actingAs($this->manager)
        ->patchJson("{$this->base}/merpay", ['is_active' => true])
        ->assertCreated();

    $keys = collect($this->actingAs($this->manager)->getJson($this->base)->json('data'))
        ->pluck('tender_key')->all();
    expect($keys)->toBe(['credit', 'paypay', 'merpay']);

    // Sibling branch remains unaffected by this branch's re-activation.
    $siblingKeys = collect(
        $this->actingAs($this->manager)
            ->getJson("/api/v1/shops/{$this->otherShop->slug}/till/tender-types")
            ->json('data'),
    )->pluck('tender_key')->all();
    expect($siblingKeys)->toBe(['credit', 'paypay']);
});

it('toggles a branch-only custom tender own row directly (no org counterpart needed)', function () {
    $custom = TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'tender_key' => 'shop_voucher',
        'category' => 'qr',
        'sort_order' => 50,
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->patchJson("{$this->base}/shop_voucher", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($custom->fresh()->is_active)->toBeFalse()
        ->and(TillTenderType::query()->where('tender_key', 'shop_voucher')->count())->toBe(1);
});

it('404s for a tender_key unknown to the org vocabulary', function () {
    $this->actingAs($this->manager)
        ->patchJson("{$this->base}/nonexistent", ['is_active' => false])
        ->assertNotFound()
        ->assertJsonPath('code', 'TENDER_KEY_UNKNOWN');
});

it('validates the body — is_active is required and boolean', function () {
    $this->actingAs($this->manager)
        ->patchJson("{$this->base}/credit", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_active']);

    $this->actingAs($this->manager)
        ->patchJson("{$this->base}/credit", ['is_active' => 'maybe'])
        ->assertUnprocessable();
});

// =========================================================================
//  Auth
// =========================================================================

it('rejects unauthenticated requests', function () {
    $this->getJson($this->base)->assertUnauthorized();
    $this->patchJson("{$this->base}/credit", ['is_active' => false])->assertUnauthorized();
});

it('rejects cross-org users', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);

    $this->actingAs($outsider)->getJson($this->base)->assertForbidden();
    $this->actingAs($outsider)
        ->patchJson("{$this->base}/credit", ['is_active' => false])
        ->assertForbidden();
});

// =========================================================================
//  POS surface consumes the same resolution
// =========================================================================

it('the POS close-screen tender list respects a branch deactivation', function () {
    $cashierRole = Role::firstOrCreate(['slug' => 'org-staff'], ['name' => 'Org Staff', 'level' => 10]);
    $cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $cashier->assignRole($cashierRole, $this->orgId);
    grantOrgAccess($cashier, $this->orgId);

    $this->actingAs($this->manager)
        ->patchJson("{$this->base}/paypay", ['is_active' => false])
        ->assertCreated();

    $keys = collect(
        $this->actingAs($cashier)
            ->withHeader('X-Shop-Slug', $this->shop->slug)
            ->getJson('/api/v1/pos/till/tender-types')
            ->assertOk()
            ->json('data'),
    )->pluck('tender_key')->all();

    expect($keys)->toBe(['credit']);
});
