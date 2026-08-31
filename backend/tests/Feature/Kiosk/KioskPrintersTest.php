<?php

/**
 * GET /api/v1/kiosk/printers — read-only replica of the branch's Cloud printer
 * config for kiosk / self_regi devices (issue #1159). Mirrors the workstation
 * replica: scope by the device's org + branch, active + non-trashed only, one
 * PrinterResource shape. No CRUD — the device token can only read.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Printer;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    $this->organization = Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->deviceToken = Str::random(32);

    $this->device = Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

/** Create a printer bound to this branch's org + branch. */
function branchPrinter(array $attributes = []): Printer
{
    return Printer::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'is_active' => true,
    ], $attributes));
}

/** Call the endpoint as the default active kiosk device. */
function getKioskPrinters()
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->deviceToken])
        ->getJson('/api/v1/kiosk/printers');
}

it('returns the active printers of the device branch (unwrapped data + generated_at)', function () {
    $a = branchPrinter(['name' => 'AAA Kitchen']);
    $b = branchPrinter(['name' => 'BBB Receipt']);

    $response = getKioskPrinters()->assertOk();

    // `data` is a flat, unwrapped array (->resolve()), plus a generated_at stamp.
    $response->assertJsonCount(2, 'data');
    expect($response->json('generated_at'))->not->toBeNull();

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toEqual(['AAA Kitchen', 'BBB Receipt']); // orderBy('name')

    // Assert a subset of fields — NOT assertExactJson: the base resource also
    // emits last_seen_at / notes / deleted_at (null here) and org/branch ids.
    $first = collect($response->json('data'))->firstWhere('name', 'AAA Kitchen');
    expect($first)
        ->toHaveKeys(['id', 'name', 'roles', 'connection_type', 'address'])
        ->and($first['id'])->toBe($a->id)
        ->and($first['roles'])->toBeArray();
    expect($b->id)->not->toBeNull();
});

it('does not return printers of another branch in the same org', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    branchPrinter(['name' => 'Mine']);
    Printer::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
        'name' => 'Other branch',
        'is_active' => true,
    ]);

    $names = collect(getKioskPrinters()->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toEqual(['Mine']);
});

it('does not return printers of another organization', function () {
    // Guards the organization_id filter specifically — a regression dropping it
    // would still pass the same-org/other-branch test above.
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'is_active' => true,
    ]);

    branchPrinter(['name' => 'Mine']);
    Printer::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
        'name' => 'Other org',
        'is_active' => true,
    ]);

    $names = collect(getKioskPrinters()->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toEqual(['Mine']);
});

it('does not return inactive printers', function () {
    branchPrinter(['name' => 'Active one']);
    branchPrinter(['name' => 'Disabled one', 'is_active' => false]);

    $names = collect(getKioskPrinters()->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toEqual(['Active one']);
});

it('does not return soft-deleted printers', function () {
    branchPrinter(['name' => 'Kept']);
    $trashed = branchPrinter(['name' => 'Trashed']);
    $trashed->delete();

    $names = collect(getKioskPrinters()->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toEqual(['Kept']);
});

it('serves a self_regi device on the same surface', function () {
    $token = Str::random(32);
    Device::factory()->create([
        'type' => 'self_regi',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    branchPrinter(['name' => 'Shared']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/kiosk/printers')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Shared');
});

it('rejects a non-kiosk-surface device type with 403 DEVICE_TYPE_NOT_ALLOWED', function () {
    $token = Str::random(32);
    Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/kiosk/printers')
        ->assertForbidden()
        ->assertJsonPath('code', 'DEVICE_TYPE_NOT_ALLOWED');
});

it('rejects a revoked device with 401', function () {
    $token = Str::random(32);
    Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'revoked',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/kiosk/printers')
        ->assertUnauthorized();
});

it('rejects a request with no token', function () {
    $this->getJson('/api/v1/kiosk/printers')->assertUnauthorized();
});
