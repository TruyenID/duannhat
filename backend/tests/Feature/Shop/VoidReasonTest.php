<?php

/**
 * plan-051 T1.3 — shop read-only void-reason list
 * (/shops/{slug}/void-reasons: active rows only, sorted by sort_order) and
 * the workstation settings mirror (GET /workstation/branch → settings):
 * `item_voidable_statuses` is the RESOLVED effective list (matrix ∪ pending,
 * legacy-flag fallback — VoidableStatusResolver), `void_reasons` carries the
 * brand's active reasons with labels in the shop's print locale.
 */

use App\Models\Device;
use App\Models\ShopOrderSetting;
use App\Models\VoidReason;
use Illuminate\Support\Str;

require_once __DIR__.'/../Verify/Invoice/VinHelpers.php';

beforeEach(function () {
    vinBootstrap($this, 'void-reason-shop');
});

/** Create a void reason for the bootstrap brand with explicit translations. */
function voidReasonForShop(object $ctx, array $overrides = [], array $labels = []): VoidReason
{
    return VoidReason::create(array_merge([
        'organization_id' => $ctx->orgId,
        'brand_id' => $ctx->brand->id,
        'stock_effect' => 'restock',
        'requires_note' => false,
        'is_active' => true,
        'sort_order' => 0,
    ], $overrides, [
        'ja' => ['label' => $labels['ja'] ?? '打ち間違い'],
        'en' => ['label' => $labels['en'] ?? 'Entered by mistake'],
        'vi' => ['label' => $labels['vi'] ?? 'Bấm nhầm'],
    ]));
}

/** Pair a workstation device and pull /workstation/branch settings. */
function voidReasonWorkstationSettings(object $ctx): array
{
    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation', 'status' => 'active', 'device_token' => $token,
        'organization_id' => $ctx->orgId, 'branch_id' => $ctx->shop->id,
    ]);

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/workstation/branch')
        ->assertOk()
        ->json('data.settings');
}

// =========================================================================
//  Shop read-only list
// =========================================================================

it('lists only ACTIVE reasons of the shop\'s brand, sorted by sort_order', function () {
    voidReasonForShop($this, ['sort_order' => 2], ['en' => 'Second']);
    voidReasonForShop($this, ['sort_order' => 1], ['en' => 'First']);
    voidReasonForShop($this, ['is_active' => false, 'sort_order' => 0], ['en' => 'Hidden']);

    $data = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/void-reasons")
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(2)
        ->and($data[0]['sort_order'])->toBe(1)
        ->and($data[1]['sort_order'])->toBe(2)
        ->and(collect($data)->pluck('is_active')->unique()->all())->toBe([true]);
});

it('returns an empty list when the brand has no reasons (no suggestions on the shop tier)', function () {
    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/void-reasons")
        ->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('suggestions'))->toBeNull();
});

// =========================================================================
//  Workstation settings payload
// =========================================================================

it('ships pending-only voidable statuses when the shop has no settings row, with empty reasons', function () {
    $settings = voidReasonWorkstationSettings($this);

    expect($settings['item_voidable_statuses'])->toBe(['pending'])
        ->and($settings['void_reasons'])->toBe([]);
});

it('resolves the legacy allow_item_edit_any_status=true fallback to all four statuses', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['organization_id' => $this->orgId, 'allow_item_edit_any_status' => true],
    );

    $settings = voidReasonWorkstationSettings($this);

    expect($settings['item_voidable_statuses'])->toBe(['pending', 'preparing', 'ready', 'served'])
        // Old workstation builds keep reading the untouched legacy key.
        ->and($settings['allow_item_edit_any_status'])->toBeTruthy();
});

it('ships the RESOLVED matrix (pending unioned in) — not the raw column', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        [
            'organization_id' => $this->orgId,
            'allow_item_edit_any_status' => false,
            'item_voidable_statuses' => ['preparing'],
        ],
    );

    $settings = voidReasonWorkstationSettings($this);

    expect($settings['item_voidable_statuses'])->toBe(['pending', 'preparing']);
});

it('mirrors the brand\'s active void reasons with print-locale labels, sorted', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['organization_id' => $this->orgId, 'print_label_locale' => 'vi'],
    );

    $first = voidReasonForShop($this, ['sort_order' => 1, 'stock_effect' => 'waste', 'requires_note' => true],
        ['vi' => 'Nấu hỏng / đổ bỏ']);
    voidReasonForShop($this, ['sort_order' => 2], ['vi' => 'Bấm nhầm']);
    voidReasonForShop($this, ['is_active' => false], ['vi' => 'Ẩn']);

    $settings = voidReasonWorkstationSettings($this);

    expect($settings['void_reasons'])->toHaveCount(2)
        ->and($settings['void_reasons'][0])->toMatchArray([
            'id' => $first->id,
            'label' => 'Nấu hỏng / đổ bỏ',
            'stock_effect' => 'waste',
            'requires_note' => true,
            'sort_order' => 1,
        ])
        ->and($settings['void_reasons'][1]['label'])->toBe('Bấm nhầm');
});
