<?php

/**
 * plan-054 M3 — customer-web learns whether the PayPay QR flow is available.
 *
 * The flag is deliberately a boolean rather than an effective-option array:
 *   - `payment-context` is public and unauthenticated, and the presenter shape
 *     carries connection ids, org-unit ids and the full policy trace.
 *   - The PayPay radio renders either way. `false` means today's behaviour
 *     (`qr_pay` is a label staff settle by hand), `true` upgrades it to the API
 *     flow — so turning PayPay off can never make a working button vanish.
 */

use App\Models\PaymentGatewayProvider;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

uses()->group('payment');

function plan054ConfigurePayPay(bool $configured = true): void
{
    config([
        'services.paypay.api_key' => $configured ? 'a_Jf0KxxXIi6_LM8r' : '',
        'services.paypay.api_secret' => $configured ? 'a-secret' : '',
        'services.paypay.merchant_id' => $configured ? '991602796635988897' : '',
    ]);
}

function plan054Context(PaymentPolicyApiFixtures $fixtures)
{
    return test()->getJson('/api/v1/customer/branches/'.$fixtures->shop->slug.'/payment-context');
}

it('reports PayPay available when credentials and branch currency line up', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    plan054ConfigurePayPay();

    DB::table('branches')->where('id', $fixtures->shop->id)->update(['currency' => 'JPY']);

    plan054Context($fixtures)
        ->assertOk()
        ->assertJsonPath('data.paypay_enabled', true);
});

it('reports PayPay unavailable when the deployment has no PayPay credentials', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    plan054ConfigurePayPay(configured: false);

    DB::table('branches')->where('id', $fixtures->shop->id)->update(['currency' => 'JPY']);

    plan054Context($fixtures)
        ->assertOk()
        ->assertJsonPath('data.paypay_enabled', false);
});

it('reports PayPay unavailable on a branch that does not settle in yen', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    plan054ConfigurePayPay();

    // The policy request hardcodes 'JPY' regardless of the branch, so without
    // this guard a VND branch would be offered PayPay and then have a VND
    // figure submitted to PayPay as yen.
    DB::table('branches')->where('id', $fixtures->shop->id)->update(['currency' => 'VND']);

    plan054Context($fixtures)
        ->assertOk()
        ->assertJsonPath('data.paypay_enabled', false);
});

it('keeps the existing public contract intact', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    plan054ConfigurePayPay();

    $response = plan054Context($fixtures)->assertOk();

    // Fields plan-048 T2.5 established; adding paypay_enabled must not disturb them.
    $response->assertJsonStructure([
        'data' => ['policy_revision', 'gateway_option_id', 'async_payment_methods_enabled', 'paypay_enabled'],
    ]);

    // "Ids only, never provider/connection detail" — this endpoint is public.
    expect(array_keys($response->json('data')))
        ->not->toContain('connection_id')
        ->not->toContain('options')
        ->not->toContain('trace');
});

it('lets a shop opt out of PayPay even though the brand enabled it', function () {
    // D9: the brand enables, a shop may opt out. Until now that row was inert —
    // shop_payment_options is read in exactly one place, the policy candidate
    // loader, and the PayPay path never reaches the policy engine. An operator
    // could set the switch and nothing would change, which is worse than having
    // no switch at all.
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    plan054ConfigurePayPay();

    DB::table('branches')->where('id', $fixtures->shop->id)->update(['currency' => 'JPY']);

    expect(plan054Context($fixtures)->json('data.paypay_enabled'))->toBeTrue();

    // The catalog row is normally created by the runtime bootstrap on the first
    // payment; payment-context alone never triggers it.
    $provider = PaymentGatewayProvider::query()->firstOrCreate(
        ['code' => 'paypay'],
        ['is_active' => true, 'name' => 'PayPay', 'sort_order' => 20],
    );
    (new PaymentGatewayCatalogSeeder)->seedPayPayQrOptionFor($provider);

    $option = DB::table('payment_gateway_options')
        ->where('code', PaymentGatewayCatalogSeeder::PAYPAY_QR_OPTION_CODE)
        ->sole();

    DB::table('shop_payment_options')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $fixtures->organization->id,
        'brand_id' => $fixtures->brand->id,
        'branch_id' => $fixtures->shop->id,
        'option_id' => $option->id,
        'preference' => 'disabled',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    plan054Context($fixtures)
        ->assertOk()
        ->assertJsonPath('data.paypay_enabled', false);
});
