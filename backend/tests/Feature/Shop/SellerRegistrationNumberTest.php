<?php

/**
 * #1152 — インボイス T+13 登録番号 (適格請求書発行事業者登録番号).
 *
 * Entry at BOTH levels (HQ brand default + branch override), resolved
 * branch ?? brand, snapshotted onto 適格請求書 + 赤伝 at issuance regardless
 * of the display toggle, and serialized into the workstation settings mirror
 * (`seller_registration_number` — the key its print paths already read)
 * ONLY while show_seller_registration_on_receipt (default ON) allows it.
 */

use App\Models\Brand;
use App\Models\Device;
use App\Models\ShopOrderSetting;
use Illuminate\Support\Str;

require_once __DIR__.'/../Verify/Invoice/VinHelpers.php';

beforeEach(function () {
    vinBootstrap($this, 'reg-no-shop');
    $this->brandRegNo = 'T1234567890123';
    $this->branchRegNo = 'T9876543210987';
});

function regNoWorkstationSettings(object $ctx): array
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
//  Entry + resolution chain
// =========================================================================

it('accepts the brand-level number via HQ settings and rejects a malformed one', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'invoice_registration_number' => 'T123', // not 13 digits
        ])->assertStatus(422);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'invoice_registration_number' => $this->brandRegNo,
        ])->assertOk();

    expect(Brand::find($this->brand->id)->invoice_registration_number)->toBe($this->brandRegNo);
});

it('accepts a branch override via shop settings, exposes the chain, and null clears back to brand', function () {
    $this->brand->update(['invoice_registration_number' => $this->brandRegNo]);

    // Malformed override rejected.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'invoice_registration_number' => '1234567890123T',
        ])->assertStatus(422);

    // Valid override wins over the brand default.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'invoice_registration_number' => $this->branchRegNo,
        ])->assertOk();

    $chain = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/branch")
        ->assertOk()->json('data');

    expect($chain['invoice_registration_number'])->toBe($this->branchRegNo)
        ->and($chain['hq_brand_invoice_registration_number'])->toBe($this->brandRegNo)
        ->and($chain['effective_invoice_registration_number'])->toBe($this->branchRegNo);

    // Clearing the override falls back to the brand default.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'invoice_registration_number' => null,
        ])->assertOk();

    $chain = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/branch")
        ->assertOk()->json('data');

    expect($chain['invoice_registration_number'])->toBeNull()
        ->and($chain['effective_invoice_registration_number'])->toBe($this->brandRegNo);
});

// =========================================================================
//  Snapshot at issuance — 適格請求書 + 赤伝
// =========================================================================

it('serializes the resolved number into workstation settings (default toggle ON)', function () {
    $this->brand->update(['invoice_registration_number' => $this->brandRegNo]);
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['organization_id' => $this->orgId],
    );

    $settings = regNoWorkstationSettings($this);

    expect($settings['seller_registration_number'])->toBe($this->brandRegNo);
});

it('serializes an EMPTY string when the display toggle is OFF — old workstation builds print nothing', function () {
    $this->brand->update(['invoice_registration_number' => $this->brandRegNo]);
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['organization_id' => $this->orgId, 'show_seller_registration_on_receipt' => false],
    );

    $settings = regNoWorkstationSettings($this);

    expect($settings['seller_registration_number'])->toBe('');
});

it('round-trips the toggle via PATCH settings/order (default true, shadowed-fillable guard)', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.show_seller_registration_on_receipt', true);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'show_seller_registration_on_receipt' => false,
        ])->assertOk();

    expect((bool) ShopOrderSetting::where('branch_id', $this->shop->id)->value('show_seller_registration_on_receipt'))
        ->toBeFalse();

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.show_seller_registration_on_receipt', false);
});
