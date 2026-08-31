<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
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
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'till-status-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);
});

it('returns has_open_shift=false when no till has been created yet', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/current")
        ->assertOk()
        ->assertJsonPath('data.has_open_shift', false)
        ->assertJsonPath('data.open_session', null);
});

it('returns has_open_shift=false when till exists but no open session', function () {
    Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/current")
        ->assertOk()
        ->assertJsonPath('data.has_open_shift', false)
        ->assertJsonPath('data.open_session', null);
});

it('returns has_open_shift=true with session details when a shift is open', function () {
    $till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);

    $session = TillSession::create([
        'session_code' => 'SESS-CURRENT-001',
        'status' => 'open',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 50000,
        'opened_at' => now(),
        'opened_by_id' => $this->manager->id,
        'opener_name' => 'Nam (Test Cashier)',
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/current")
        ->assertOk()
        ->assertJsonPath('data.has_open_shift', true)
        ->assertJsonPath('data.open_session.id', $session->id)
        ->assertJsonPath('data.open_session.session_code', 'SESS-CURRENT-001')
        ->assertJsonPath('data.open_session.opener_name', 'Nam (Test Cashier)')
        ->assertJsonPath('data.open_session.default_currency_code', 'JPY');
});

it('exposes the open-chain window after a handover so the pre-flight matches the 409 guards (#1130)', function () {
    $till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);

    // A handover SETTLES the shift (till.current_session_id cleared) but the
    // chain stays open until the next open's final close — plan-046 R8.
    TillSession::create([
        'session_code' => 'SESS-HANDOVER-001',
        'status' => 'settled',
        'settlement_kind' => 'handover',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 50000,
        'opened_at' => now()->subHours(8),
        'closed_at' => now()->subMinutes(5),
        'opened_by_id' => $this->manager->id,
        'opener_name' => 'Nam (Test Cashier)',
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/current")
        ->assertOk()
        ->assertJsonPath('data.has_open_shift', false)
        ->assertJsonPath('data.has_open_chain', true)
        ->assertJsonPath('data.settings_locked', true)
        ->assertJsonPath('data.open_session', null);

    // A FINAL close ends the chain — everything unlocks.
    TillSession::query()->where('session_code', 'SESS-HANDOVER-001')
        ->update(['settlement_kind' => 'final']);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/current")
        ->assertOk()
        ->assertJsonPath('data.has_open_chain', false)
        ->assertJsonPath('data.settings_locked', false);
});

it('rejects unauthenticated requests', function () {
    $this->getJson("/api/v1/shops/{$this->shop->slug}/till/current")
        ->assertUnauthorized();
});

it('rejects users without access to this shop org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);

    $this->actingAs($outsider)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/current")
        ->assertForbidden();
});
