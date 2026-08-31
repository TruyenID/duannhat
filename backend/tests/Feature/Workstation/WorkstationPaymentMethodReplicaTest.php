<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgA = (string) Str::uuid();
    $this->orgB = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgA,
        'console_organization_id' => $this->orgA,
    ]);
    Organization::factory()->create([
        'id' => $this->orgB,
        'console_organization_id' => $this->orgB,
    ]);

    $brandA = Brand::factory()->create(['console_organization_id' => $this->orgA]);
    $this->branchA = Branch::factory()->create([
        'console_organization_id' => $this->orgA,
        'console_brand_id' => $brandA->console_brand_id,
    ]);

    $this->wsToken = Str::random(64);
    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgA,
        'branch_id' => $this->branchA->id,
    ]);

    PaymentMethod::factory()->create([
        'organization_id' => $this->orgA,
        'branch_id' => null,
        'code' => 'cash_a_global',
        'name' => 'Org A Cash',
    ]);

    PaymentMethod::factory()->create([
        'organization_id' => $this->orgB,
        'branch_id' => null,
        'code' => 'cash_b_global',
        'name' => 'Org B Cash',
    ]);
});

it('scopes workstation payment method replica to the device organization', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/payment-methods')
        ->assertOk();

    $codes = collect($response->json('data'))->pluck('code')->all();

    expect($codes)->toContain('cash_a_global')
        ->and($codes)->not->toContain('cash_b_global');
});

/*
 * `type` is the BEHAVIOURAL classification (cash / card / on_account / …) and
 * the only way the workstation can tell a debt method from a cash one — `code`
 * cannot, because a shop may name its on-account method anything.
 *
 * Leaving it out of this feed did not fail loudly. The workstation column is
 * `NOT NULL DEFAULT 'cash'`, so every mirrored method read back as cash and two
 * LAN money paths went quietly wrong:
 *
 *   - handleLANPrintDebtSlip refuses anything that is not `on_account`, so
 *     every 掛売 slip printed over LAN answered payment_method_not_on_account.
 *   - ComputeTillDebtSummary sums `pm.type = 'on_account'`, so the shift report
 *     said 0 debt issued however much had been recorded.
 */
it('carries the behavioural type so the workstation can recognise a debt method', function () {
    $debt = PaymentMethod::factory()->create([
        'organization_id' => $this->orgA,
        'branch_id' => null,
        'code' => 'debt_a',
        'name' => 'Org A On Account',
        'type' => 'on_account',
    ]);

    $rows = collect(
        $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
            ->getJson('/api/v1/workstation/payment-methods')
            ->assertOk()
            ->json('data')
    )->keyBy('code');

    expect($rows)->toHaveKey('debt_a')
        ->and($rows['debt_a'])->toHaveKey('type')
        ->and($rows['debt_a']['type'])->toBe('on_account')
        ->and((string) $debt->type)->toBe('on_account');

    // Every row carries it, not just the debt one — a partially populated
    // feed would leave the mirror guessing for the rest.
    $missing = $rows->filter(fn (array $row): bool => ! array_key_exists('type', $row))->keys()->all();
    expect($missing)->toBe([]);
});
