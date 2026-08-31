<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Omnify\Enums\TillSessionStatusEnum;
use Illuminate\Support\Str;

/**
 * Cross-mode shift continuity regression: cashier opens a shift on
 * Cloud, switches pos-web to LAN mode, and expects /pos/till/current to
 * still reflect the open shift. Workstation's PullTillSessions polls
 * this endpoint on every slow tick — if it returns the wrong shape or
 * wrong scope, the LAN handler can't render the open shift and prompts
 * the cashier to open a second one on top of it.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->till = Till::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
});

it('returns open and closing sessions for the device branch', function () {
    $open = TillSession::factory()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => TillSessionStatusEnum::Open->value,
        'opened_by_id' => $this->user->id,
        'opener_name' => 'Alice',
        'opening_float_amount' => 5000,
    ]);
    TillSession::factory()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => TillSessionStatusEnum::Closing->value,
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/till-sessions/active')
        ->assertOk();

    expect($resp->json('data'))->toHaveCount(2);

    $found = collect($resp->json('data'))->firstWhere('id', $open->id);
    expect($found)->not->toBeNull()
        ->and($found['status'])->toBe('open')
        ->and($found['opener_name'])->toBe('Alice')
        ->and((float) $found['opening_float_amount'])->toBe(5000.0)
        ->and($found['till_id'])->toBe($this->till->id)
        ->and($found['branch_id'])->toBe($this->branch->id);
});

it('excludes settled and abandoned sessions', function () {
    TillSession::factory()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => TillSessionStatusEnum::Settled->value,
    ]);
    TillSession::factory()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => TillSessionStatusEnum::Abandoned->value,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/till-sessions/active')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('scopes to the device branch and never leaks other branches', function () {
    TillSession::factory()->create([
        'till_id' => Till::factory()->create([
            'branch_id' => $this->otherBranch->id,
            'organization_id' => $this->orgId,
        ])->id,
        'branch_id' => $this->otherBranch->id,
        'organization_id' => $this->orgId,
        'status' => TillSessionStatusEnum::Open->value,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/till-sessions/active')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
