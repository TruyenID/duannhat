<?php

/**
 * plan-054 M5 — the rows a PayPay QR payment needs, provisioned at runtime.
 *
 * Every case here pins one of the four things deliberately NOT copied from the
 * Stripe bootstrap. Each was a real defect found by reading that code, and each
 * would have been invisible until production.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentPolicyRevision;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Services\Payment\Orchestration\Internal\PayPayCustomerWebBootstrap;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use App\Services\Payment\Policy\Enums\PaymentPolicyPublicationSource;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Support\Str;

uses()->group('payment');

beforeEach(function () {
    config([
        'services.paypay.api_key' => 'a_key',
        'services.paypay.api_secret' => 'a_secret',
        'services.paypay.merchant_id' => '991602796635988897',
        'services.paypay.environment' => 'sandbox',
    ]);
});

/**
 * Explicit org/brand/branch rather than whatever the factory picks: the policy
 * snapshot validates its scope ids as real UUIDs, and a shared fixture org
 * (`00000000-…-0001`) is not one.
 */
function plan054BootstrapOrder(): CustomerOrder
{
    // The publisher validates that org, brand and branch agree on their console
    // ids — a stricter bar than the Stripe bootstrap has to clear, because that
    // one hand-writes its revision row and never goes through publishAtomically.
    $consoleOrganizationId = (string) Str::uuid();

    $organization = Organization::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    return CustomerOrder::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => 'open',
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);
}

/**
 * #1603 — bootstrap giờ nhận `OrderRef` (bốn neo) chứ không nhận model.
 *
 * Helper này dựng ref TỪ model để test vẫn đọc như trước. Cố ý viết ra từng
 * trường thay vì một hàm tiện lợi trong production: bốn chuỗi UUID cùng hình
 * dạng, và chỗ DUY NHẤT phát hiện hoán nhầm là chỗ viết tên trường ra.
 */
function plan054Ref(CustomerOrder $order): OrderRef
{
    return new OrderRef(
        orderId: (string) $order->id,
        organizationId: (string) $order->organization_id,
        brandId: $order->brand_id === null ? null : (string) $order->brand_id,
        branchId: (string) $order->branch_id,
    );
}

it('provisions every row a QR payment needs', function () {
    $refs = app(PayPayCustomerWebBootstrap::class)->resolveForOrder(plan054Ref(plan054BootstrapOrder()));

    expect($refs['connectionId'])->not->toBeEmpty()
        ->and($refs['connectionOptionId'])->not->toBeEmpty()
        ->and($refs['optionId'])->not->toBeEmpty()
        ->and($refs['policyRevision'])->toBeGreaterThanOrEqual(1);
});

it('stores a synthetic per-org merchant reference, never the real PayPay merchant id', function () {
    // payment_gateway_connections is unique on
    // (provider_id, environment, merchant_account_id) with NO organization_id.
    // Storing the real merchant id — global to the deployment — means the second
    // tenant to check out either reuses the first tenant's row or violates the
    // index. Either way: 500 at checkout for everyone but the first.
    $orderA = plan054BootstrapOrder();
    $orderB = plan054BootstrapOrder();

    $bootstrap = app(PayPayCustomerWebBootstrap::class);
    $refsA = $bootstrap->resolveForOrder(plan054Ref($orderA));
    $refsB = $bootstrap->resolveForOrder(plan054Ref($orderB));

    $merchantA = PaymentGatewayConnection::query()->find($refsA['connectionId'])->merchant_account_id;

    expect($merchantA)->toStartWith('orchestrator:customer-web:')
        ->and($merchantA)->not->toContain('991602796635988897');

    // Different orgs get their own connection rather than colliding.
    expect($refsA['connectionId'])->not->toBe($refsB['connectionId']);
});

it('is idempotent — a second checkout reuses the first one is rows', function () {
    $bootstrap = app(PayPayCustomerWebBootstrap::class);
    $order = plan054BootstrapOrder();

    expect($bootstrap->resolveForOrder(plan054Ref($order)))->toBe($bootstrap->resolveForOrder(plan054Ref($order->fresh())));
});

it('publishes the policy revision instead of hand-writing one', function () {
    // The Stripe bootstrap writes a revision with source 'orchestrator_bootstrap',
    // which is not a PaymentPolicyPublicationSource case. publishAtomically
    // validates the newest stored revision before writing a new one, so that row
    // makes every later real publish on the branch throw — permanently disabling
    // the admin payment-settings screens, including the shop-level PayPay switch.
    $order = plan054BootstrapOrder();
    app(PayPayCustomerWebBootstrap::class)->resolveForOrder(plan054Ref($order));

    $revision = PaymentPolicyRevision::query()->where('branch_id', $order->branch_id)->sole();

    expect(PaymentPolicyPublicationSource::tryFrom((string) $revision->source))->not->toBeNull()
        ->and((int) $revision->revision)->toBeGreaterThanOrEqual(1)
        ->and((array) $revision->snapshot)->toHaveKey('scope');
});

it('reuses an existing revision rather than adding a competing one', function () {
    $order = plan054BootstrapOrder();
    $bootstrap = app(PayPayCustomerWebBootstrap::class);

    $bootstrap->resolveForOrder(plan054Ref($order));
    $bootstrap->resolveForOrder(plan054Ref($order->fresh()));

    expect(PaymentPolicyRevision::query()->where('branch_id', $order->branch_id)->count())->toBe(1);
});

it('takes the environment from configuration, not from APP_ENV', function () {
    // PayPaySdkClientFactory picks the LIVE PayPay endpoint off this column.
    // Deriving it from app()->environment('production') would call the live API
    // while holding sandbox credentials — the normal posture during a pilot.
    config(['services.paypay.environment' => 'test']);

    $refs = app(PayPayCustomerWebBootstrap::class)->resolveForOrder(plan054Ref(plan054BootstrapOrder()));

    expect(PaymentGatewayConnection::query()->find($refs['connectionId'])->environment)
        ->toBe(PaymentGatewayEnvironmentEnum::Test);
});

it('refuses to provision a live connection outside production', function () {
    config(['services.paypay.environment' => 'live']);

    expect(fn () => app(PayPayCustomerWebBootstrap::class)->resolveForOrder(plan054Ref(plan054BootstrapOrder())))
        ->toThrow(RuntimeException::class, 'refused outside production');
});

it('refuses to provision anything when PayPay is not configured', function () {
    // Otherwise a half-configured deployment ends up with rows advertising a
    // gateway it can never call.
    config(['services.paypay.merchant_id' => '']);

    expect(fn () => app(PayPayCustomerWebBootstrap::class)->resolveForOrder(plan054Ref(plan054BootstrapOrder())))
        ->toThrow(RuntimeException::class, 'not configured');
});

it('stamps an evidence reference — a "verified" row without one can never resolve', function () {
    // plan-054 T5.5. `PaymentPolicyResolver` denies with CapabilityUnverified
    // when `evidenceReference === null`, and it does so REGARDLESS of
    // verification_state. So a row saying `verified` with a null reference is
    // internally contradictory: it advertises itself as usable and is denied
    // every time, silently, with a reason code that points at verification
    // rather than at the missing field.
    //
    // Harmless today only because PayPay is gated by PayPayAvailabilityService
    // and never reaches the policy engine — which is exactly what would make it
    // land as an unexplained deny on whoever routes it there (T5.8's debt).
    $refs = app(PayPayCustomerWebBootstrap::class)->resolveForOrder(plan054Ref(plan054BootstrapOrder()));

    $connectionOption = PaymentGatewayConnectionOption::query()->find($refs['connectionOptionId']);

    expect($connectionOption->verification_state)->toBe('verified');
    expect($connectionOption->evidence_ref)->not->toBeNull(
        'verification_state=verified + evidence_ref=null is the exact shape PaymentPolicyResolver '
        .'rejects as CapabilityUnverified. Either stamp a reference or stop claiming verified.'
    );
    expect($connectionOption->evidence_ref)->toBe('orchestrator:paypay-customer-web');
});

it('provisions the QR capability, not the preauth one', function () {
    // preauth needs a userAuthorizationId a guest checkout can never supply.
    $refs = app(PayPayCustomerWebBootstrap::class)->resolveForOrder(plan054Ref(plan054BootstrapOrder()));

    expect(PaymentGatewayOption::query()->find($refs['optionId'])->code)
        ->toBe(PaymentGatewayCatalogSeeder::PAYPAY_QR_OPTION_CODE);
});
