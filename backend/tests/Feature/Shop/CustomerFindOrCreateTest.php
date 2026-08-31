<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Plan 007 — POS convenience endpoint. Staff enters a phone number in
 * CreateOrderDialog; FE calls this endpoint to resolve it to a customer_id
 * (existing or freshly-created minimal row) before submitting the order.
 */

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
        'slug' => 'customer-foc-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
});

it('returns existing customer (status 200, created=false) when phone matches', function () {
    $existing = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'Nguyễn',
        'last_name' => 'Văn A',
        'phone' => '0912345678',
    ]);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/customers/find-or-create", [
            'phone' => '0912345678',
        ])
        ->assertOk();

    expect($response->json('created'))->toBeFalse();
    expect($response->json('data.id'))->toBe($existing->id);
    expect($response->json('data.first_name'))->toBe('Nguyễn');
    expect($response->json('data.phone'))->toBe('0912345678');

    expect(Customer::count())->toBe(1);
});

it('creates minimal customer (status 201, created=true) when phone is unknown', function () {
    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/customers/find-or-create", [
            'phone' => '0999888777',
        ])
        ->assertCreated();

    expect($response->json('created'))->toBeTrue();
    expect($response->json('data.phone'))->toBe('0999888777');
    expect($response->json('data.first_name'))->toBe('Khách');

    expect(Customer::count())->toBe(1);
    $created = Customer::first();
    expect($created->organization_id)->toBe($this->orgId);
    expect($created->branch_id)->toBe($this->shop->id);
});

it('is idempotent on repeated calls with the same phone', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/customers/find-or-create", [
            'phone' => '0987654321',
        ])
        ->assertCreated();

    expect(Customer::count())->toBe(1);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/customers/find-or-create", [
            'phone' => '0987654321',
        ])
        ->assertOk()
        ->assertJsonPath('created', false);

    expect(Customer::count())->toBe(1);
});

it('scopes lookup to the org — same phone in different org returns a new customer', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    Customer::factory()->create([
        'organization_id' => $otherOrgId,
        'phone' => '0911223344',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/customers/find-or-create", [
            'phone' => '0911223344',
        ])
        ->assertCreated()
        ->assertJsonPath('data.organization_id', $this->orgId);

    expect(Customer::count())->toBe(2);
});

it('requires phone', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/customers/find-or-create", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone']);
});
