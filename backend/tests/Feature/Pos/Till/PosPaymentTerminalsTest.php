<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * #1156 — GET /pos/till/payment-terminals: thin device-reachable projection
 * of the branch's payment terminals + metadata.accepts, feeding the POS
 * brand sub-choice and the per-terminal 精算 sections.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    $this->org = Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'terminal-shop',
        'is_active' => true,
    ]);
    $this->otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'other-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-staff'],
        ['name' => 'Org Staff', 'level' => 10],
    );
    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashier, $this->orgId);
});

function makeTerminal(string $branchId, string $orgId, string $name, array $metadata, bool $active = true, string $type = 'payment_terminal'): PeripheralDevice
{
    return PeripheralDevice::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'name' => $name,
        'type' => $type,
        'is_active' => $active,
        'metadata' => $metadata,
    ]);
}

it('lists active payment terminals of the branch with whitelisted metadata', function () {
    makeTerminal($this->shop->id, $this->org->id, 'Stera 01', [
        'host' => '192.168.1.240', 'port' => 443, 'model' => 'stera', 'accepts' => ['credit', 'paypay'],
    ]);

    $data = $this->actingAs($this->cashier)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/till/payment-terminals')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('Stera 01')
        ->and($data[0]['metadata']['accepts'])->toBe(['credit', 'paypay'])
        ->and($data[0]['metadata']['model'])->toBe('stera')
        // LAN clients must never see network internals or secrets.
        ->and($data[0]['metadata'])->not->toHaveKeys(['host', 'port'])
        ->and($data[0])->not->toHaveKey('secret');
});

it('excludes coin changers, inactive terminals, and other branches', function () {
    makeTerminal($this->shop->id, $this->org->id, 'Stera 01', ['accepts' => ['credit']]);
    makeTerminal($this->shop->id, $this->org->id, 'Glory 01', ['accepts' => ['cash']], type: 'coin_changer');
    makeTerminal($this->shop->id, $this->org->id, 'Dead terminal', ['accepts' => ['credit']], active: false);
    makeTerminal($this->otherShop->id, $this->org->id, 'Elsewhere', ['accepts' => ['credit']]);

    $data = $this->actingAs($this->cashier)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/till/payment-terminals')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('Stera 01');
});

it('returns an empty list for a branch without terminals and null accepts when unset', function () {
    $empty = $this->actingAs($this->cashier)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/till/payment-terminals')
        ->assertOk()
        ->json('data');
    expect($empty)->toBe([]);

    makeTerminal($this->shop->id, $this->org->id, 'Bare terminal', ['host' => '10.0.0.5']);

    $data = $this->actingAs($this->cashier)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/till/payment-terminals')
        ->json('data');
    expect($data[0]['metadata']['accepts'])->toBeNull();
});
