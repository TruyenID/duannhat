<?php

/**
 * Shared fixtures for the Verify/Stripe money-bug suite.
 *
 * Prefix: vst*. Required (require_once) at the top of each Verify/Stripe test
 * file so the functions are declared exactly once per run.
 *
 * Everything here builds REAL rows and REAL signed Stripe webhook payloads.
 * The ONLY thing ever mocked is \Stripe\StripeClient (the SDK network layer);
 * StripePaymentService / OrderPaymentService / the controllers under test are
 * always the production classes.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Str;
use Stripe\StripeClient;

const VST_WEBHOOK_SECRET = 'whsec_vst_secret';

/**
 * Baseline Stripe config for every Verify test.
 */
function vstConfigureStripe(string $currency = 'jpy', bool $liveRefunds = false): void
{
    config([
        'services.stripe.key' => 'pk_test_vst',
        'services.stripe.secret' => 'sk_test_vst',
        'services.stripe.webhook_secret' => VST_WEBHOOK_SECRET,
        'services.stripe.currency' => $currency,
        'payments.stripe_live_refunds_enabled' => $liveRefunds,
    ]);
}

/**
 * Build an org + brand + branch, optionally with a shop_order_settings row
 * carrying a branch currency (the setting #815 says the code never reads).
 *
 * @return array{org_id: string, brand: Brand, branch: Branch}
 */
function vstTenant(?string $branchCurrency = null): array
{
    $orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);

    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    if ($branchCurrency !== null) {
        ShopOrderSetting::factory()->create([
            'branch_id' => $branch->id,
            'organization_id' => $orgId,
            'currency_code' => $branchCurrency,
        ]);
    }

    return ['org_id' => $orgId, 'brand' => $brand, 'branch' => $branch];
}

/**
 * An OPEN customer order for the given tenant.
 *
 * @param  array{org_id: string, brand: Brand, branch: Branch}  $tenant
 */
function vstOrder(array $tenant, float $total, float $paid = 0.0): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $tenant['org_id'],
        'brand_id' => $tenant['brand']->id,
        'branch_id' => $tenant['branch']->id,
        'total_amount' => $total,
        'paid_amount' => $paid,
        'status' => CustomerOrderStatusEnum::Open->value,
        'stripe_payment_intent_id' => null,
    ]);
}

/**
 * A correctly-signed Stripe webhook payload — the real production
 * Webhook::constructEvent() verification runs against this.
 *
 * @return array{payload: string, header: string}
 */
function vstSignedEvent(string $type, array $dataObject): array
{
    $payload = json_encode([
        'id' => 'evt_'.Str::random(24),
        'object' => 'event',
        'api_version' => '2024-06-20',
        'type' => $type,
        'data' => ['object' => $dataObject],
    ]);

    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", VST_WEBHOOK_SECRET);

    return ['payload' => $payload, 'header' => "t={$timestamp},v1={$signature}"];
}

/**
 * A PaymentIntent object shaped exactly like Stripe delivers it.
 */
function vstIntentObject(string $id, int $amount, string $currency, string $status, array $metadata): array
{
    return [
        'id' => $id,
        'object' => 'payment_intent',
        'amount' => $amount,
        'currency' => $currency,
        'status' => $status,
        'client_secret' => $id.'_secret_vst',
        'metadata' => $metadata,
    ];
}

/**
 * Bind a StripePaymentService backed by a mocked StripeClient into the
 * container so the HTTP controllers + the webhook resolve THIS instance.
 */
function vstBindStripe(StripeClient $client): StripePaymentService
{
    $service = new StripePaymentService($client);
    app()->instance(StripePaymentService::class, $service);

    return $service;
}
