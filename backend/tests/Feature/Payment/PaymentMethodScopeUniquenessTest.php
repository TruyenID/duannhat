<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'id' => (string) Str::uuid(),
        'console_organization_id' => (string) Str::uuid(),
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->id,
    ]);
    $this->branchA = Branch::factory()->create([
        'console_organization_id' => $this->organization->id,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->branchB = Branch::factory()->create([
        'console_organization_id' => $this->organization->id,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
});

it('rejects duplicate organization-global payment method codes', function () {
    PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => null,
        'code' => 'cash',
    ]);

    expect(fn () => PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => null,
        'code' => 'cash',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('allows one global and one row per branch for the same code', function () {
    $global = PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => null,
        'code' => 'cash',
    ]);
    $branchA = PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branchA->id,
        'code' => 'cash',
    ]);
    $branchB = PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branchB->id,
        'code' => 'cash',
    ]);

    expect($global->fresh()->scope_key)->toBe(PaymentMethod::GLOBAL_SCOPE_KEY)
        ->and($branchA->fresh()->scope_key)->toBe($this->branchA->id)
        ->and($branchB->fresh()->scope_key)->toBe($this->branchB->id);
});

it('rejects duplicate codes inside the same branch', function () {
    PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branchA->id,
        'code' => 'card',
    ]);

    expect(fn () => PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branchA->id,
        'code' => 'card',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('derives scope key in the database and ignores mass assignment', function () {
    $method = PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branchA->id,
    ]);

    $method->fill(['scope_key' => 'forged']);

    expect($method->isDirty('scope_key'))->toBeFalse()
        ->and($method->fresh()->scope_key)->toBe($this->branchA->id)
        ->and($method->toArray())->not->toHaveKey('scope_key');

    $method->branch_id = null;
    $method->save();

    expect($method->fresh()->scope_key)->toBe(PaymentMethod::GLOBAL_SCOPE_KEY);
});
