<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * POST /api/v1/workstation/customers/find-or-create
 *
 * Device-authed twin of the POS find-or-create. Resolves org/branch/brand
 * from the paired device (no user session, no shop slug), dedupes by phone,
 * and honours the optional name/email the workstation forwards.
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
    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('creates a customer from phone + name/email, scoped to the device org/branch/brand', function () {
    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/customers/find-or-create', [
            'phone' => '0901234567',
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
            'email' => 'taro@example.com',
        ])
        ->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('data.phone', '0901234567')
        ->assertJsonPath('data.first_name', 'Taro')
        ->assertJsonPath('data.last_name', 'Yamada')
        ->assertJsonPath('data.email', 'taro@example.com');

    $customer = Customer::find($resp->json('data.id'));
    expect($customer->organization_id)->toBe($this->orgId);
    expect($customer->branch_id)->toBe($this->branch->id);
    expect($customer->brand_id)->toBe($this->brand->id);
});

it('returns the existing customer (200, created=false) without duplicating', function () {
    $existing = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'phone' => '0909999999',
        'first_name' => 'Existing',
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/customers/find-or-create', [
            'phone' => '0909999999',
            'first_name' => 'Should Be Ignored',
        ])
        ->assertOk()
        ->assertJsonPath('created', false)
        ->assertJsonPath('data.id', $existing->id)
        // find-or-create never overwrites a known customer.
        ->assertJsonPath('data.first_name', 'Existing');

    expect(Customer::where('phone', '0909999999')->count())->toBe(1);
});

it('falls back to first_name "Khách" when no name is supplied', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/customers/find-or-create', [
            'phone' => '0900000001',
        ])
        ->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('data.first_name', 'Khách');
});

it('dedupes within the device branch only — same phone in another branch creates a new row', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $otherBranchCustomer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
        'brand_id' => $this->brand->id,
        'phone' => '0905555555',
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/customers/find-or-create', [
            'phone' => '0905555555',
        ])
        ->assertCreated()
        ->assertJsonPath('created', true);

    // A distinct row scoped to THIS device's branch, not the other branch's.
    expect($resp->json('data.id'))->not->toBe($otherBranchCustomer->id);
    $created = Customer::find($resp->json('data.id'));
    expect($created->branch_id)->toBe($this->branch->id);
});

it('rejects a request without a device token (401)', function () {
    $this->postJson('/api/v1/workstation/customers/find-or-create', [
        'phone' => '0901234567',
    ])->assertUnauthorized();
});

it('validates that phone is required (422)', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/customers/find-or-create', [
            'first_name' => 'No Phone',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});
