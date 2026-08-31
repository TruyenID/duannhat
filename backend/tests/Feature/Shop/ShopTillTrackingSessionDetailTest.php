<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\TillSettlementTenderDetail;
use App\Models\TillTenderType;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Plan 036 — Session detail endpoint (GET /shops/{slug}/till/sessions/{id}).
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
        'slug' => 'till-detail-shop',
    ]);

    Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'level' => 30]);

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->manager, $this->orgId);

    $this->staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->staff->assignRole('staff', $this->orgId);

    $this->till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 100,
        'is_active' => true,
    ]);
});

it('returns 404 when the session does not belong to the shop', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);
    $otherTill = Till::create([
        'till_code' => 'OTHER',
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
        'is_active' => true,
    ]);
    $otherSession = TillSession::factory()->settled()->create([
        'till_id' => $otherTill->id,
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions/{$otherSession->id}")
        ->assertNotFound();
});

it('returns full detail with reconciliation + audit_trail keys', function () {
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}")
        ->assertOk();

    $response->assertJsonPath('data.id', $session->id);
    $response->assertJsonPath('data.status', 'settled');
    $response->assertJsonStructure([
        'data' => [
            'session_code', 'status', 'till', 'currency_code',
            'opening_counts', 'closing_counts', 'cash_events',
            'reconciliation' => ['by_tender_category'],
            'audit_trail',
            'links' => ['z_report_pdf', 'z_report_available'],
        ],
    ]);
});

it('exposes z_report_available=false on an open session', function () {
    $session = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}")
        ->assertOk()
        ->assertJsonPath('data.links.z_report_available', false);
});

it('rejects unauthenticated', function () {
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}")
        ->assertUnauthorized();
});

it('rejects staff with 403', function () {
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->staff)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}")
        ->assertForbidden();
});

it('mirrors the drawer figures onto the cash tender row so both blocks agree (issue #1006)', function () {
    // settled() state stamps expected/counted = 100000, variance = 0.
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    // Mirror production persistSettlementDetails(): the cash tender detail is
    // written with expected/variance NULL (cash reconciles via the drawer count,
    // not as a terminal tender), which is exactly what produced the 0/0/0 bug.
    $cashType = TillTenderType::factory()->cash()->create([
        'organization_id' => $this->orgId,
    ]);
    TillSettlementTenderDetail::factory()->create([
        'session_id' => $session->id,
        'tender_type_id' => $cashType->id,
        'tender_key' => 'cash',
        'category' => 'cash',
        'currency_code' => 'JPY',
        'expected_amount' => null,
        'declared_amount' => 0,
        'variance_amount' => null,
        'variance_reason' => null,
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}")
        ->assertOk();

    // Flat "cash reconciliation" block — the authoritative drawer figures.
    $response->assertJsonPath('data.expected_cash_amount', 100000);
    $response->assertJsonPath('data.counted_cash_amount', 100000);
    $response->assertJsonPath('data.cash_variance_amount', 0);

    // "By tender" cash row now mirrors the drawer figures instead of 0/0/0.
    $cashCategory = collect($response->json('data.reconciliation.by_tender_category'))
        ->firstWhere('category', 'cash');
    expect($cashCategory)->not->toBeNull();
    $cashRow = collect($cashCategory['tender_rows'])->firstWhere('tender_key', 'cash');
    expect((float) $cashRow['expected_amount'])->toBe(100000.0);
    expect((float) $cashRow['declared_amount'])->toBe(100000.0);
    expect((float) $cashRow['variance_amount'])->toBe(0.0);

    // Regression guard: the persisted DB row stays null so close() never
    // double-reconciles cash (the fix is presentation-layer only).
    $this->assertDatabaseHas('till_settlement_tender_details', [
        'session_id' => $session->id,
        'tender_key' => 'cash',
        'expected_amount' => null,
        'variance_amount' => null,
    ]);
});
