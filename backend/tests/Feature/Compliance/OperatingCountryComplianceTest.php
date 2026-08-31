<?php

/**
 * #1153 — operating-country mirror + per-country compliance profiles.
 *
 * The Platform owns organizations.country (set at creation, immutable);
 * Tempo mirrors it as organizations.operating_country via UserProvisioner
 * (adopt-if-present) and resolves a ComplianceProfile from it. The core
 * stays international — one invoice model, one tax engine — profiles only
 * parametrize (registration format, return-document threshold). Default JP
 * everywhere preserves pre-#1153 behavior byte-for-byte.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Services\Compliance\ComplianceProfileResolver;
use App\Services\Compliance\JpComplianceProfile;
use App\Services\Compliance\VnComplianceProfile;
use Dxs\Auth\Contracts\ProvisionsUsers;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Verify/Invoice/VinHelpers.php';

// =========================================================================
//  Provisioner mirror (Platform → Tempo)
// =========================================================================

function countryContextFake(array $organization): void
{
    Http::fake([
        'https://platform.test/api/sso/organizations' => Http::response([$organization]),
        'https://platform.test/api/sso/brands*' => Http::response(['all_brands_access' => true, 'brands' => []]),
        'https://platform.test/api/sso/branches*' => Http::response(['all_branches_access' => true, 'branches' => []]),
    ]);
}

it('adopts the Platform operating country into the organization mirror', function () {
    countryContextFake([
        'organization_id' => 'cb77c7a3-62b0-54c2-b6dd-091429113b31',
        'organization_slug' => 'saigon-corp',
        'organization_name' => 'Saigon Corp',
        'country' => 'VN',
        'service_role' => 'admin',
        'service_role_level' => 100,
    ]);

    app(ProvisionsUsers::class)->provision([
        'sub' => 'country-subject-1',
        'name' => 'VN Admin',
        'email' => 'admin@saigon.vn',
        'organization_context_id' => 'cb77c7a3-62b0-54c2-b6dd-091429113b31',
    ], ['access_token' => 'tok', 'expires_in' => 900]);

    expect(Organization::query()
        ->where('console_organization_id', 'cb77c7a3-62b0-54c2-b6dd-091429113b31')
        ->value('operating_country'))->toBe('VN');
});

it('never resets a mirrored country when an older Platform omits the field', function () {
    Organization::factory()->create([
        'console_organization_id' => 'cb77c7a3-62b0-54c2-b6dd-091429113b31',
        'slug' => 'saigon-corp',
        'operating_country' => 'VN',
    ]);

    countryContextFake([
        'organization_id' => 'cb77c7a3-62b0-54c2-b6dd-091429113b31',
        'organization_slug' => 'saigon-corp',
        'organization_name' => 'Saigon Corp',
        // no 'country' key — pre-#1153 Platform payload
        'service_role' => 'admin',
        'service_role_level' => 100,
    ]);

    app(ProvisionsUsers::class)->provision([
        'sub' => 'country-subject-2',
        'name' => 'VN Admin',
        'email' => 'admin2@saigon.vn',
        'organization_context_id' => 'cb77c7a3-62b0-54c2-b6dd-091429113b31',
    ], ['access_token' => 'tok', 'expires_in' => 900]);

    expect(Organization::query()
        ->where('console_organization_id', 'cb77c7a3-62b0-54c2-b6dd-091429113b31')
        ->value('operating_country'))->toBe('VN');
});

// =========================================================================
//  Resolver
// =========================================================================

it('resolves JP by default and VN from the mirrored country', function () {
    $resolver = app(ComplianceProfileResolver::class);

    // No org row at all → JP (pre-#1153 behavior).
    expect($resolver->forOrganization(null))->toBeInstanceOf(JpComplianceProfile::class)
        ->and($resolver->forOrganization('00000000-0000-0000-0000-00000000dead'))
        ->toBeInstanceOf(JpComplianceProfile::class);

    // Unknown country without a profile → JP fail-safe.
    expect($resolver->forCountry('US'))->toBeInstanceOf(JpComplianceProfile::class)
        ->and($resolver->forCountry('VN'))->toBeInstanceOf(VnComplianceProfile::class)
        ->and($resolver->forCountry('vn'))->toBeInstanceOf(VnComplianceProfile::class);

    $org = Organization::factory()->create(['operating_country' => 'VN']);
    expect($resolver->forOrganization((string) $org->console_organization_id))
        ->toBeInstanceOf(VnComplianceProfile::class);
});

it('parametrizes the return-document exemption per country', function () {
    $jp = new JpComplianceProfile;
    $vn = new VnComplianceProfile;

    // 消令70条の9③二 — under ¥10,000 no 適格返還請求書 is owed.
    expect($jp->returnInvoiceExemptionThreshold('JPY'))->toBe(10000.0)
        ->and($jp->returnInvoiceExemptionThreshold('VND'))->toBeNull()
        // VN owes a credit-note document at any amount.
        ->and($vn->returnInvoiceExemptionThreshold('VND'))->toBeNull()
        ->and($vn->returnInvoiceExemptionThreshold('JPY'))->toBeNull();
});

// =========================================================================
//  Registration-number format follows the org's country
// =========================================================================

it('validates the seller registration number per country at both settings levels', function () {
    vinBootstrap($this, 'country-shop');

    // JP org (default): T+13 in, MST out — the #1152 behavior unchanged.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'invoice_registration_number' => '0312345678',
        ])->assertStatus(422);

    // Flip the mirrored country to VN: MST accepted, T+13 rejected.
    Organization::query()->where('console_organization_id', $this->orgId)
        ->update(['operating_country' => 'VN']);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'invoice_registration_number' => 'T1234567890123',
        ])->assertStatus(422);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'invoice_registration_number' => '0312345678',
        ])->assertOk();
    expect(Brand::find($this->brand->id)->invoice_registration_number)->toBe('0312345678');

    // Branch override takes the dependent-unit MST form; garbage still 422.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'invoice_registration_number' => '0312345678-001',
        ])->assertOk();

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'invoice_registration_number' => 'T9876543210987',
        ])->assertStatus(422);
});
