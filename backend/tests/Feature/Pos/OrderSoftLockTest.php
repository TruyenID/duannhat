<?php

/**
 * plan-034 audit HIGH (test-gap) — Phase 6 POS soft-lock had zero tests.
 *
 * Phase 6 lets POS staff freeze a live dine-in order while they fix it:
 *   - PATCH /api/v1/pos/orders/{order}/start-edit stamps `editing_by_staff_at`
 *     and broadcasts OrderEditingStarted.
 *   - Customer devices that try to append (POST /tables/{qr}/orders) while the
 *     stamp is fresh get a 409 (OrderEditingLockedException).
 *   - PATCH .../end-edit clears the stamp and broadcasts OrderEditingEnded,
 *     re-opening customer writes.
 *   - The lock auto-expires after 60s so a crashed POS can't strand the table.
 */

use App\Events\OrderEditingEnded;
use App\Events\OrderEditingStarted;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Build an org whose local PK == console id (so getOrganizationId() and the
 * order's organization_id line up under ResolvePosShop), plus a shop + table.
 *
 * @return array{orgId:string, brand:Brand, shop:Branch, table:Table}
 */
function softLockShop(string $slug): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $shop = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => $slug,
        'is_active' => true,
    ]);
    $table = Table::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $shop->id,
        'status' => 'free',
        'is_active' => true,
        'qr_token' => (string) Str::uuid(),
    ]);

    return ['orgId' => $orgId, 'brand' => $brand, 'shop' => $shop, 'table' => $table];
}

function softLockManager(string $orgId): User
{
    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 80]);
    $user = User::factory()->create(['console_organization_id' => $orgId]);
    $user->assignRole($role, $orgId);

    return $user;
}

/** Open a shared session + order via the public customer flow, return the id. */
function softLockSeedOrder(Table $table, ProductSku $sku): string
{
    test()->postJson("/api/v1/customer/tables/{$table->qr_token}/join")->assertOk();

    return test()->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", [
        'items' => [['product_sku_id' => $sku->id, 'quantity' => 1]],
    ])->assertCreated()->json('data.id');
}

it('start-edit stamps editing_by_staff_at and broadcasts OrderEditingStarted', function () {
    Event::fake([OrderEditingStarted::class, OrderEditingEnded::class]);

    $a = softLockShop('softlock-start');
    $orderId = softLockSeedOrder($a['table'], ProductSku::factory()->create());
    $manager = softLockManager($a['orgId']);

    $this->actingAs($manager)
        ->withHeader('X-Shop-Slug', $a['shop']->slug)
        ->patchJson("/api/v1/pos/orders/{$orderId}/start-edit")
        ->assertOk()
        ->assertJsonPath('data.id', $orderId);

    expect(CustomerOrder::find($orderId)->editing_by_staff_at)->not->toBeNull();
    Event::assertDispatched(OrderEditingStarted::class,
        fn (OrderEditingStarted $e) => $e->order->id === $orderId);
});

it('end-edit clears the stamp and broadcasts OrderEditingEnded', function () {
    Event::fake([OrderEditingStarted::class, OrderEditingEnded::class]);

    $a = softLockShop('softlock-end');
    $orderId = softLockSeedOrder($a['table'], ProductSku::factory()->create());
    CustomerOrder::find($orderId)->forceFill(['editing_by_staff_at' => now()])->save();
    $manager = softLockManager($a['orgId']);

    $this->actingAs($manager)
        ->withHeader('X-Shop-Slug', $a['shop']->slug)
        ->patchJson("/api/v1/pos/orders/{$orderId}/end-edit")
        ->assertOk();

    expect(CustomerOrder::find($orderId)->editing_by_staff_at)->toBeNull();
    Event::assertDispatched(OrderEditingEnded::class,
        fn (OrderEditingEnded $e) => $e->order->id === $orderId);
});

it('blocks a customer append with 409 while the soft-lock is fresh', function () {
    $a = softLockShop('softlock-block');
    $sku = ProductSku::factory()->create();
    $orderId = softLockSeedOrder($a['table'], $sku);
    $manager = softLockManager($a['orgId']);

    $this->actingAs($manager)
        ->withHeader('X-Shop-Slug', $a['shop']->slug)
        ->patchJson("/api/v1/pos/orders/{$orderId}/start-edit")
        ->assertOk();

    // Device B tries to add a line while staff holds the lock.
    $extra = ProductSku::factory()->create();
    $this->postJson("/api/v1/customer/tables/{$a['table']->qr_token}/orders", [
        'items' => [['product_sku_id' => $extra->id, 'quantity' => 1]],
    ])->assertStatus(409);

    // Nothing was appended — still just the seed line.
    expect(CustomerOrder::find($orderId)->items()->count())->toBe(1);
});

it('re-opens customer writes after end-edit', function () {
    $a = softLockShop('softlock-reopen');
    $sku = ProductSku::factory()->create();
    $orderId = softLockSeedOrder($a['table'], $sku);
    $manager = softLockManager($a['orgId']);

    $this->actingAs($manager)->withHeader('X-Shop-Slug', $a['shop']->slug)
        ->patchJson("/api/v1/pos/orders/{$orderId}/start-edit")->assertOk();
    $this->actingAs($manager)->withHeader('X-Shop-Slug', $a['shop']->slug)
        ->patchJson("/api/v1/pos/orders/{$orderId}/end-edit")->assertOk();

    $extra = ProductSku::factory()->create();
    $this->postJson("/api/v1/customer/tables/{$a['table']->qr_token}/orders", [
        'items' => [['product_sku_id' => $extra->id, 'quantity' => 1]],
    ])->assertOk();

    expect(CustomerOrder::find($orderId)->items()->count())->toBe(2);
});

it('lets the soft-lock auto-expire after 60s so a crashed POS cannot strand the table', function () {
    $a = softLockShop('softlock-expire');
    $sku = ProductSku::factory()->create();
    $orderId = softLockSeedOrder($a['table'], $sku);

    // Staff stamped the lock 61s ago and never called /end-edit.
    CustomerOrder::find($orderId)->forceFill(['editing_by_staff_at' => now()->subSeconds(61)])->save();

    $extra = ProductSku::factory()->create();
    $this->postJson("/api/v1/customer/tables/{$a['table']->qr_token}/orders", [
        'items' => [['product_sku_id' => $extra->id, 'quantity' => 1]],
    ])->assertOk();

    expect(CustomerOrder::find($orderId)->items()->count())->toBe(2);
});

it('forbids a caller from another org from acquiring the soft-lock', function () {
    $a = softLockShop('softlock-owner');
    $orderId = softLockSeedOrder($a['table'], ProductSku::factory()->create());

    $b = softLockShop('softlock-intruder');
    $intruder = softLockManager($b['orgId']);

    // Intruder addresses their OWN shop (passes ResolvePosShop) but the order
    // belongs to org A → authorizeOrganization must 403.
    $this->actingAs($intruder)
        ->withHeader('X-Shop-Slug', $b['shop']->slug)
        ->patchJson("/api/v1/pos/orders/{$orderId}/start-edit")
        ->assertForbidden();

    expect(CustomerOrder::find($orderId)->editing_by_staff_at)->toBeNull();
});
