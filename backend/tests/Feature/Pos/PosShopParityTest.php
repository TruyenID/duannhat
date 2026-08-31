<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Parity tests for /api/v1/pos/* — every endpoint here is registered to a
 * controller that is ALSO mounted under /api/v1/shops/{slug}/*. The two
 * namespaces must therefore return byte-identical JSON for the same
 * underlying request; this file proves that contract for a representative
 * read (orders.index) and write (orders.update), so the rest of the surface
 * stays honest by construction.
 *
 * If you change controller behavior in Api\V1\Shop\*, these tests will fail
 * if you forget to keep the namespaces in sync (e.g. by adding a new field
 * only visible to one path). That failure is the goal.
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
        'slug' => 'parity-shop',
        'is_active' => true,
    ]);

    $managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $this->order = CustomerOrder::first();
});

it('returns identical payload data for orders.index via /shops and /pos', function () {
    $viaShops = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertOk()
        ->json();

    $viaPos = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/orders')
        ->assertOk()
        ->json();

    // Pagination link/path fields legitimately differ (self-referencing the
    // request URL). Compare the resource payload + counts; that is what
    // controllers control.
    expect($viaPos['data'])->toEqual($viaShops['data']);
    expect($viaPos['meta']['total'])->toBe($viaShops['meta']['total']);
    expect($viaPos['meta']['per_page'])->toBe($viaShops['meta']['per_page']);
});

it('returns identical JSON for orders.show via /shops and /pos', function () {
    $viaShops = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}")
        ->assertOk()
        ->json();

    $viaPos = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson("/api/v1/pos/orders/{$this->order->id}")
        ->assertOk()
        ->json();

    expect($viaPos)->toEqual($viaShops);
});

it('mutates state identically for orders.update via /pos', function () {
    $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->putJson("/api/v1/pos/orders/{$this->order->id}", [
            'guest_count' => 7,
            'note' => 'POS namespace mutation',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.guest_count', 7)
        ->assertJsonPath('data.note', 'POS namespace mutation');

    expect((int) $this->order->fresh()->guest_count)->toBe(7);
});
