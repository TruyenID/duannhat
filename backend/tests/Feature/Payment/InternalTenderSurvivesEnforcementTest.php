<?php

/**
 * plan-055 #1831 — enforcement applies to GATEWAY-routed money, not to internal
 * tenders.
 *
 * Internal tenders (cash, standalone card terminal, on-account) are outside
 * gateway policy by construction: `PaymentPolicyEvaluationService::effectiveOptions()`
 * — the source of both the published snapshot AND what the validator reads —
 * never calls the internal-tender enricher, so an internal option is never IN
 * the snapshot. A client that names one is refused with "Submitted gateway
 * option is absent from the referenced policy revision", which is exactly why
 * pos-web deliberately sends no identity for cash.
 *
 * So requiring a policy option for cash is a category error: it demands an
 * identity that cannot exist in the thing being checked. Before this, flipping
 * `payments.policy_enforcement.required` refused EVERY CASH PAYMENT — and the
 * suite already encoded that, green, without anyone reading it as a warning.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Customer\OrderPaymentService;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use App\Services\Payment\Policy\Admin\PosEffectivePaymentOptionEnricher;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->organizationId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    $this->operator = User::factory()->create(['console_organization_id' => $this->organizationId]);

    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'code' => 'cash',
        'type' => 'cash',
        'is_active' => true,
    ]);
    $this->card = PaymentMethod::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'code' => 'card',
        'type' => 'card',
        'is_active' => true,
    ]);
});

function tenderOrder(object $ctx): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $ctx->organizationId,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $ctx->branch->id,
        'status' => 'checkout',
        'total_amount' => 800,
        'paid_amount' => 0,
    ]);
}

function tenderPayload(CustomerOrder $order, object $ctx, string $methodId, string $key): array
{
    return [
        'customer_order_id' => $order->id,
        'payment_method_id' => $methodId,
        'amount' => 800,
        'tendered_amount' => 800,
        'received_by_id' => $ctx->operator->id,
        'organization_id' => $ctx->organizationId,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $ctx->branch->id,
        'orchestrator_transport' => 'pos',
        'device_id' => 'device-under-test',
        'idempotency_key' => $key,
    ];
}

beforeEach(function () {
    // #2318 — catalog internal trước đây do một data migration seed, nên
    // RefreshDatabase (chỉ migrate) là đủ. Migration đó đã bị xoá; nguồn duy
    // nhất bây giờ là seeder, nên test phải gọi nó.
    app(PaymentGatewayCatalogSeeder::class)->seedInternal();
});

it('#1831 takes a CASH payment with the enforcement flag ON', function () {
    config(['payments.policy_enforcement.required' => true]);

    $order = tenderOrder($this);

    $payment = app(OrderPaymentService::class)->create(
        tenderPayload($order, $this, (string) $this->cash->id, 'cash-enforced-1')
    );

    expect($payment->id)->not->toBeNull()
        ->and((float) $order->fresh()->paid_amount)->toBe(800.0);
});

it('#1831 still REFUSES a gateway-routed payment with no option id, same flag, same run', function () {
    // Both halves in one flip, deliberately. A test that only proves the refusal
    // cannot distinguish "enforcement works" from "enforcement blocks
    // everything" — which is the state this issue found the code in.
    config(['payments.policy_enforcement.required' => true]);

    $order = tenderOrder($this);

    expect(fn () => app(OrderPaymentService::class)->create(
        tenderPayload($order, $this, (string) $this->card->id, 'card-enforced-1')
    ))->toThrow(PaymentConfigurationException::class);
});

it('#1831 exempts ON-ACCOUNT, which has no catalog option at all', function () {
    // Caught in review: the first version's docblock CLAIMED to cover
    // on-account and did not. No catalog option carries `method_type =
    // on_account`, so the catalog-derived set never contained `debt` — while
    // pos-web's debt CTA posts it with no gateway identity. The Gate 6 flip
    // would still have refused every 掛売 collection, with a comment saying it
    // was fixed.
    config(['payments.policy_enforcement.required' => true]);

    $debt = PaymentMethod::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'code' => 'debt',
        // Production shape (PaymentMethodSeeder): code `debt`, type `on_account`.
        'type' => 'on_account',
        'is_active' => true,
    ]);

    $order = tenderOrder($this);

    $payment = app(OrderPaymentService::class)->create(
        tenderPayload($order, $this, (string) $debt->id, 'debt-enforced-1')
    );

    expect($payment->id)->not->toBeNull();
});

it('#1831 exempts a cash payment that DID name an option — the kiosk shape', function () {
    // The other blocking gap. The kiosk sends an identity for every option it
    // displays, including the internal ones the same enricher appended, so a
    // kiosk cash sale lands on the WITH-identity fork with an option id that can
    // never be in the snapshot. The first version exempted only the no-identity
    // fork (pos-web's shape), so kiosk cash stayed broken while the plan file
    // said the obstacle was removed.
    config(['payments.policy_enforcement.required' => true]);

    $internalOption = PaymentGatewayOption::query()
        ->whereHas('provider', fn ($q) => $q->where('code', 'internal'))
        ->firstOrFail();

    $order = tenderOrder($this);

    $payment = app(OrderPaymentService::class)->create(
        tenderPayload($order, $this, (string) $this->cash->id, 'cash-with-identity-1') + [
            'gateway_option_id' => (string) $internalOption->id,
            'policy_revision' => 1,
        ]
    );

    expect($payment->id)->not->toBeNull()
        ->and((float) $order->fresh()->paid_amount)->toBe(800.0);
});

it('#1831 STOPS exempting a method once a real gateway can also route it', function () {
    // The mirror hazard, and it is already half-built: `legacyPaymentMethodCode()`
    // maps `card_present` to the same `card_terminal` code the internal option
    // maps to, and Stripe Terminal card_present is live. The day a gateway
    // option with `card_present` is seeded — the Gate 2 cutover — that method
    // becomes genuinely gateway-routable, and waiving it would waive real
    // gateway money.
    $terminal = PaymentMethod::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'code' => 'card_terminal',
        'type' => 'card',
        'is_active' => true,
    ]);

    $enricher = app(PosEffectivePaymentOptionEnricher::class);

    expect($enricher->internalTenderMethodIds($this->branch))->toContain((string) $terminal->id);

    // Now a GATEWAY option lands on the same legacy code.
    $gateway = PaymentGatewayProvider::query()->where('code', '!=', 'internal')->first()
        ?? PaymentGatewayProvider::factory()->create(['code' => 'stripe', 'is_active' => true]);
    PaymentGatewayOption::factory()->create([
        'provider_id' => $gateway->id,
        'rail' => 'card',
        'method_type' => 'card_present',
        'is_active' => true,
        // #1868 — kept explicit even though the factory default is now in
        // force. The original reason given here was wrong: `effective_from`
        // never lands in the future (Faker draws 1970→now, measured 200/200
        // past). The column that dropped the row was `effective_to`.
        'effective_from' => now()->subDay(),
        'effective_to' => null,
    ]);

    // #1856 — the enricher is now `scoped()`, so its per-shop memo lives for one
    // REQUEST. That is the granularity the freshness claim above holds at: a
    // catalog edit is seen by the next request, not mid-request. Nothing in a
    // payment request edits the catalog, so production semantics are unchanged
    // — but this test does edit it, so it has to cross the boundary explicitly
    // instead of relying on `app()` happening to hand back a fresh instance.
    app()->forgetInstance(PosEffectivePaymentOptionEnricher::class);

    expect(app(PosEffectivePaymentOptionEnricher::class)->internalTenderMethodIds($this->branch))
        ->not->toContain((string) $terminal->id);
});

it('#1831 pins the internal catalog to tenders that cannot be gateway-routed', function () {
    // The exemption is derived from the live catalog on purpose, so it adapts.
    // The hazard is the same property: adding an internal option on a generic
    // rail (say `card`/`card`) would silently exempt the shop's ordinary card
    // method, which CAN be gateway-routed. This pins today's shape so that
    // addition is a red test rather than a quiet widening of a money guard.
    $internal = PaymentGatewayProvider::query()->where('code', 'internal')->first();

    expect($internal)->not->toBeNull();

    $shapes = PaymentGatewayOption::query()
        ->where('provider_id', $internal->id)
        ->where('is_active', true)
        ->get()
        ->map(fn ($o): string => ($o->rail instanceof BackedEnum ? $o->rail->value : (string) $o->rail)
            .'/'.(string) $o->method_type)
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($shapes)->toBe(['card/card_terminal', 'cash/cash']);
});

it('#1831 resolves exactly the internal tender methods for the branch', function () {
    $ids = app(PosEffectivePaymentOptionEnricher::class)->internalTenderMethodIds($this->branch);

    expect($ids)->toContain((string) $this->cash->id)
        // The ordinary card method is NOT internal — exempting it would waive a
        // payment that really can be gateway-routed.
        ->and($ids)->not->toContain((string) $this->card->id);
});

it('#1855 never lets a gateway option on the CASH rail un-exempt cash', function () {
    // The subtraction's dangerous edge, measured in review of #1846.
    // `PaymentOptionRailEnum::Cash` is available to ANY provider, and
    // `payment_gateway_options` has no org scoping at all — so ONE konbini row
    // on that rail would subtract `cash` for EVERY shop in EVERY org, and the
    // Gate 6 flip would refuse cash at the till.
    //
    // Not hypothetical: Stripe already builds konbini intents, `sbps` is in the
    // provider enum, and those catalog rows land at the Gate 2 cutover — i.e.
    // exactly when the flip is being prepared.
    //
    // The failure modes are asymmetric, which is why the guard is: under-
    // subtract waives a gateway payment (recoverable); over-subtract refuses
    // cash, and cash cannot be un-refused.
    $gateway = PaymentGatewayProvider::query()->where('code', '!=', 'internal')->first()
        ?? PaymentGatewayProvider::factory()->create(['code' => 'sbps', 'is_active' => true]);

    PaymentGatewayOption::factory()->create([
        'provider_id' => $gateway->id,
        'rail' => 'cash',
        'method_type' => 'cash',
        'is_active' => true,
        // #1868 — kept explicit even though the factory default is now in
        // force. The original reason given here was wrong: `effective_from`
        // never lands in the future (Faker draws 1970→now, measured 200/200
        // past). The column that dropped the row was `effective_to`.
        'effective_from' => now()->subDay(),
        'effective_to' => null,
    ]);

    expect(app(PosEffectivePaymentOptionEnricher::class)->internalTenderMethodIds($this->branch))
        ->toContain((string) $this->cash->id);
});

it('#1855 never lets a gateway option on the ON_ACCOUNT method type un-exempt 掛売', function () {
    // The debt half of NEVER_GATEWAY_ROUTABLE was UNFALSIFIABLE without this:
    // no test ever put `debt` into $gatewayCodes, so deleting
    // `self::ON_ACCOUNT_CODE` from the constant left the suite green. My own
    // "1 red" measurement only exercised the cash half, and I reported it as if
    // it covered both — caught in review.
    $debt = PaymentMethod::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'code' => 'debt',
        'type' => 'on_account',
        'is_active' => true,
    ]);

    $gateway = PaymentGatewayProvider::query()->where('code', '!=', 'internal')->first()
        ?? PaymentGatewayProvider::factory()->create(['code' => 'sbps', 'is_active' => true]);

    PaymentGatewayOption::factory()->create([
        'provider_id' => $gateway->id,
        'rail' => 'bank_transfer',
        'method_type' => 'on_account',
        'is_active' => true,
        // #1868 — kept explicit even though the factory default is now in
        // force. The original reason given here was wrong: `effective_from`
        // never lands in the future (Faker draws 1970→now, measured 200/200
        // past). The column that dropped the row was `effective_to`.
        'effective_from' => now()->subDay(),
        'effective_to' => null,
    ]);

    expect(app(PosEffectivePaymentOptionEnricher::class)->internalTenderMethodIds($this->branch))
        ->toContain((string) $debt->id);
});

it('#1859 ignores a gateway option that is not effective YET', function () {
    // A row seeded AHEAD of the Gate 2 cutover — `is_active = true` but
    // `effective_from` in the future. `PaymentPolicyResolver:304` already
    // refuses such a capability (`appliesAt()` → CapabilityExpired); this
    // method used to count it anyway, so the two disagreed and `card_terminal`
    // was un-exempted for every shop in every org BEFORE the option went live.
    $terminal = PaymentMethod::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'code' => 'card_terminal',
        'type' => 'card',
        'is_active' => true,
    ]);

    $gateway = PaymentGatewayProvider::query()->where('code', '!=', 'internal')->first()
        ?? PaymentGatewayProvider::factory()->create(['code' => 'sbps', 'is_active' => true]);

    PaymentGatewayOption::factory()->create([
        'provider_id' => $gateway->id,
        'rail' => 'card',
        'method_type' => 'card_present',
        'is_active' => true,
        'effective_from' => now()->addMonth(),
        'effective_to' => null,
    ]);

    expect(app(PosEffectivePaymentOptionEnricher::class)->internalTenderMethodIds($this->branch))
        ->toContain((string) $terminal->id);
});

it('#1859 ignores a gateway option whose window has EXPIRED', function () {
    // The other end of the window. A retired option must not keep un-exempting
    // a method forever.
    $terminal = PaymentMethod::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'code' => 'card_terminal',
        'type' => 'card',
        'is_active' => true,
    ]);

    $gateway = PaymentGatewayProvider::query()->where('code', '!=', 'internal')->first()
        ?? PaymentGatewayProvider::factory()->create(['code' => 'sbps', 'is_active' => true]);

    PaymentGatewayOption::factory()->create([
        'provider_id' => $gateway->id,
        'rail' => 'card',
        'method_type' => 'card_present',
        'is_active' => true,
        'effective_from' => now()->subYear(),
        'effective_to' => now()->subDay(),
    ]);

    expect(app(PosEffectivePaymentOptionEnricher::class)->internalTenderMethodIds($this->branch))
        ->toContain((string) $terminal->id);
});
