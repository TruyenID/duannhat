<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\TaxType;
use App\Models\Till;
use App\Models\TillSession;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Str;

/**
 * #1129 — an HQ tax-rate edit lands on EVERY branch of the brand at once, and
 * it is deliberately NOT blocked: plan-043 Q6 rules that per-line snapshots
 * protect orders already created, so the edit stays allowed (unlike currency,
 * tax mode and rounding, which 409).
 *
 * What was missing is the operator half. A shift could end up spanning two
 * rates with nothing said beyond a static hint in the UI, and the mismatch only
 * surfaced later in a Z-report that would not reconcile. The response now names
 * the branches that are mid-shift, using the SAME predicate the 409 guards use
 * so a warning here can never disagree with a block there.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'name' => 'Ningyocho',
        'is_active' => true,
    ]);

    $this->taxType = TaxType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'STANDARD',
        'rate' => 10,
        'is_default' => true,
    ]);
});

/** Put one till of the branch into an OPEN shift. */
function openShiftAt(Branch $branch): TillSession
{
    $till = Till::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => $branch->id,
    ]);
    $session = TillSession::factory()->create([
        'organization_id' => test()->orgId,
        'till_id' => $till->id,
        'status' => TillSessionStatusEnum::Open->value,
    ]);
    $till->update(['current_session_id' => $session->id]);

    return $session;
}

it('names the mid-shift branches when the rate actually moves', function () {
    openShiftAt($this->branch);

    $meta = app(TillSessionService::class)
        ->openShiftBranchesForBrand((string) $this->brand->id);

    expect($meta)->toHaveCount(1)
        ->and($meta[0]['id'])->toBe((string) $this->branch->id)
        ->and($meta[0]['name'])->toBe('Ningyocho');
});

it('reports nothing when no till is open', function () {
    expect(app(TillSessionService::class)
        ->openShiftBranchesForBrand((string) $this->brand->id))->toBe([]);
});

it('does not treat a closed shift as open', function () {
    $session = openShiftAt($this->branch);
    $session->till->update(['current_session_id' => null]);
    $session->update(['status' => TillSessionStatusEnum::Settled->value]);

    expect(app(TillSessionService::class)
        ->openShiftBranchesForBrand((string) $this->brand->id))->toBe([]);
});

it('counts a CLOSING shift too — the same predicate the 409 guards use', function () {
    $session = openShiftAt($this->branch);
    $session->update(['status' => TillSessionStatusEnum::Closing->value]);

    expect(app(TillSessionService::class)
        ->openShiftBranchesForBrand((string) $this->brand->id))->toHaveCount(1);
});

it('ignores branches of a DIFFERENT brand', function () {
    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'is_active' => true,
    ]);
    openShiftAt($otherBranch);

    expect(app(TillSessionService::class)
        ->openShiftBranchesForBrand((string) $this->brand->id))->toBe([]);
});
