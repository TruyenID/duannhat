<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Till;
use App\Models\TillSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Issue #903 — Gate::before used to bypass EVERY policy check for a device
 * token, latent only because forceAbandon/manualSettle authorize() against
 * a `User`-typed param that a Device request TypeErrors on. These pin the
 * actual fix: a device token is granted only the explicit operational
 * allowlist. Manager-only and unknown future abilities fail closed regardless
 * of what the policy method's signature would otherwise allow.
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
        'slug' => 'gate-903-shop',
        'is_active' => true,
    ]);

    $this->deviceToken = Str::random(64);
    $this->device = Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $till = Till::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $this->session = TillSession::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'till_id' => $till->id,
        'status' => 'open',
    ]);

    $till->update(['current_session_id' => $this->session->id]);
});

function deviceRequest()
{
    return test()
        ->withHeader('X-Shop-Slug', test()->shop->slug)
        ->withHeader('Authorization', 'Bearer '.test()->deviceToken);
}

it('denies a device token on force-abandon', function () {
    deviceRequest()
        ->postJson("/api/v1/pos/till/sessions/{$this->session->id}/force-abandon", [
            'reason_code' => 'other',
            'reason_detail' => 'device-token probe reason for force-abandon',
        ])
        ->assertForbidden();
});

it('denies a device token on manual-settle', function () {
    $this->session->update(['status' => 'expired']);
    $denomination = Denomination::factory()->jpy10000()->create();

    deviceRequest()
        ->postJson("/api/v1/pos/till/sessions/{$this->session->id}/manual-settle", [
            'closing_counts' => [
                ['denomination_id' => $denomination->id, 'quantity' => 1],
            ],
            'tender_details' => [],
            'manual_settle_reason' => 'device-token probe reason for manual-settle',
        ])
        ->assertForbidden();
});

it('still allows the cashier-level abandon operation', function () {
    deviceRequest()
        ->postJson("/api/v1/pos/till/sessions/{$this->session->id}/abandon", [
            'abandon_reason' => 'probe',
        ])
        ->assertOk();

    expect($this->session->fresh()->status->value)->toBe('abandoned');
});

it('denies a device token on the manager-only stale-session list', function () {
    deviceRequest()
        ->getJson('/api/v1/pos/till/sessions/stale')
        ->assertForbidden();
});

it('denies a device token on order void', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-903-TEST',
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'customer_id' => $customer->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    deviceRequest()
        ->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'void_reason' => 'probe',
        ])
        ->assertForbidden();

    expect($order->fresh()->status->value)->toBe('open');
});

it('still allows a device token on an explicitly allowed ability (viewCurrent)', function () {
    deviceRequest()
        ->getJson('/api/v1/pos/till/current')
        ->assertOk();
});

it('denies and audits an unknown future ability by default', function () {
    Route::middleware('auth.sso_or_device')
        ->get('/api/v1/test/device-gate-future-ability', function () {
            Gate::authorize('futureSensitiveAbility');

            return response()->noContent();
        });

    Log::shouldReceive('channel')->once()->with('pos_auth')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with(
        'pos_device_gate_denied',
        Mockery::on(fn (array $context): bool => $context['ability'] === 'futureSensitiveAbility'
            && $context['device_id'] !== null
            && ! array_key_exists('token', $context)
            && ! array_key_exists('token_head', $context)),
    );

    $this->withHeader('Authorization', 'Bearer '.$this->deviceToken)
        ->getJson('/api/v1/test/device-gate-future-ability')
        ->assertForbidden();
});

it('does not grant a generic allowed ability to an unlisted policy target', function () {
    Route::middleware('auth.sso_or_device')
        ->delete('/api/v1/test/device-gate-wrong-target', function () {
            Gate::authorize('delete', $this->device);

            return response()->noContent();
        });

    Log::shouldReceive('channel')->once()->with('pos_auth')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with(
        'pos_device_gate_denied',
        Mockery::on(fn (array $context): bool => $context['ability'] === 'delete'
            && $context['target'] === Device::class
            && $context['device_id'] === $this->device->id),
    );

    $this->withHeader('Authorization', 'Bearer '.$this->deviceToken)
        ->deleteJson('/api/v1/test/device-gate-wrong-target')
        ->assertForbidden();
});
