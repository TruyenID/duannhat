<?php

declare(strict_types=1);

use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\Contracts\OrderPricingResolutionPort;
use App\Services\Order\Internal\CustomerOrderPricingResolution;
use App\Services\Order\ValueObjects\OrderDraftPayload;
use App\Services\Order\ValueObjects\OrderLineEvidence;
use App\Services\Order\ValueObjects\OrderLinePayload;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderPricingEvidence;
use App\Services\Order\ValueObjects\OrderSelectionPayload;
use App\Services\Order\ValueObjects\OrderServiceChargePayload;
use App\Services\Order\ValueObjects\TrustedOrderSnapshot;
use Illuminate\Support\Str;

/**
 * 席料・サービス料 billing integrity — issue #1090.
 *
 * WHAT THIS PROTECTS, IN SHOP TERMS
 *
 * A shop sets a service charge (e.g. 10%) and, in Japan, that charge is itself
 * taxable. Three parties then have to agree on one number:
 *
 *   - the CUSTOMER, who pays what the receipt says
 *   - the SHOP, whose Z-report and 精算 must balance at close
 *   - the TAX FILING, which must show tax per rate (インボイス)
 *
 * If the tax on the service charge goes missing anywhere in that chain, the
 * order silently under-bills: the customer pays less than the receipt's own
 * arithmetic implies, the drawer comes up short at close, and the filed
 * consumption tax is understated. None of that surfaces as an error — it just
 * quietly loses money, every order, at every shop that charges service.
 *
 * These tests are written to FAIL LOUDLY AND SPECIFICALLY. Each assertion says
 * what a shop would actually experience, and the failure message reports the
 * exact yen discrepancy, so whoever breaks it does not have to reverse-engineer
 * the arithmetic from a bare "expected 1210, got 1200".
 */
function scOrgId(): string
{
    return '00000000-0000-0000-0000-000000000001';
}

function scContext(): MutationContext
{
    return new MutationContext(scOrgId(), null, (string) Str::uuid(), (string) Str::uuid(), 1);
}

function scCreateCommand(): CreateOrderCommand
{
    $selection = new OrderSelectionPayload([
        new OrderLineSelectionPayload((string) Str::uuid(), (string) Str::uuid(), 1),
    ]);

    return new CreateOrderCommand(
        scContext(),
        (string) Str::uuid(),
        (string) Str::uuid(),
        $selection,
        $selection->fingerprint(),
    );
}

/** One ¥1,000 dish taxed at 10% → ¥100 of line tax. */
function scDishLine(int $subtotalMinor = 1000, int $taxMinor = 100): OrderLinePayload
{
    return new OrderLinePayload(
        itemId: (string) Str::uuid(),
        productId: (string) Str::uuid(),
        skuId: (string) Str::uuid(),
        quantity: 1,
        unitPriceMinor: $subtotalMinor,
        toppings: [],
        evidence: new OrderLineEvidence(
            menuId: null,
            menuProductId: null,
            menuProductSkuId: (string) Str::uuid(),
            taxTypeId: null,
            originalUnitPriceMinor: $subtotalMinor,
            taxRateBasisPoints: 1000,
            taxAmountMinor: $taxMinor,
            lineSubtotalMinor: $subtotalMinor,
        ),
    );
}

/**
 * Build the snapshot the way the pricing resolver will, so these tests exercise
 * the real sealing path rather than a bypass.
 */
function scSnapshot(OrderDraftPayload $draft): TrustedOrderSnapshot
{
    $resolver = new CustomerOrderPricingResolution;

    return TrustedOrderSnapshot::fromPricingResolver(
        $resolver,
        VerificationAuthority::forConfiguredAdapter($resolver, OrderPricingResolutionPort::class, ['order.trusted_snapshot']),
        scCreateCommand(),
        $draft,
        CustomerOrderStatusEnum::Open,
        'JPY',
        hash('sha256', 'resolver'),
        '2026-07-26T12:00:00+09:00',
    );
}

function scDraft(?OrderServiceChargePayload $serviceCharge, OrderPricingEvidence $pricing, ?array $lines = null): OrderDraftPayload
{
    return new OrderDraftPayload(
        lines: $lines ?? [scDishLine()],
        status: CustomerOrderStatusEnum::Open,
        pricingEvidence: $pricing,
        serviceCharge: $serviceCharge,
    );
}

// ---------------------------------------------------------------------------
// The shop scenario this whole file exists for
// ---------------------------------------------------------------------------

it('bills a taxed service charge in full: ¥1,000 dish + 10% service + tax on both = ¥1,210', function () {
    // What the customer sees on the receipt:
    //   料理          ¥1,000
    //   サービス料 10%   ¥100
    //   消費税        ¥110   (¥100 on the dish + ¥10 on the service charge)
    //   合計         ¥1,210
    $draft = scDraft(
        new OrderServiceChargePayload(amountMinor: 100, taxAmountMinor: 10, taxRateBasisPoints: 1000),
        new OrderPricingEvidence(
            subtotalMinor: 1000,
            discountMinor: 0,
            serviceChargeMinor: 100,
            taxMinor: 110,
            totalMinor: 1210,
            taxIncluded: false,
            taxRoundingMode: 'round',
            taxRoundingDecimals: 0,
        ),
    );

    $snapshot = scSnapshot($draft);

    expect($snapshot->draft->pricingEvidence->totalMinor)->toBe(
        1210,
        'The customer must be charged ¥1,210. Any other number means the receipt and the ledger disagree.',
    );
    expect($snapshot->draft->serviceCharge->taxAmountMinor)->toBe(
        10,
        'The ¥10 of tax on the service charge must stay attached to the charge, or it cannot be reported per rate for インボイス.',
    );
});

it('refuses an order that silently drops the tax on the service charge', function () {
    // The exact under-billing this issue is about: everything looks plausible,
    // but taxMinor only covers the dish. The shop would collect ¥1,200 while the
    // receipt line items add up to ¥1,210 — ¥10 short, every single order.
    $build = fn () => scSnapshot(scDraft(
        new OrderServiceChargePayload(amountMinor: 100, taxAmountMinor: 10, taxRateBasisPoints: 1000),
        new OrderPricingEvidence(
            subtotalMinor: 1000,
            discountMinor: 0,
            serviceChargeMinor: 100,
            taxMinor: 100,   // ← the ¥10 of service-charge tax went missing
            totalMinor: 1200,
            taxIncluded: false,
            taxRoundingMode: 'round',
            taxRoundingDecimals: 0,
        ),
    ));

    expect($build)->toThrow(
        InvalidArgumentException::class,
        'Tax evidence adds up to 110',
    );
});

it('refuses an order whose total quietly pockets tax no line can account for', function () {
    // The mirror image: the customer is charged for tax that no line and no
    // service charge justifies. On an audit this reads as over-collection.
    $build = fn () => scSnapshot(scDraft(
        null,
        new OrderPricingEvidence(
            subtotalMinor: 1000,
            discountMinor: 0,
            serviceChargeMinor: 0,
            taxMinor: 150,   // ← ¥50 more tax than the single ¥100 line produced
            totalMinor: 1150,
            taxIncluded: false,
            taxRoundingMode: 'round',
            taxRoundingDecimals: 0,
        ),
    ));

    expect($build)->toThrow(InvalidArgumentException::class, 'must be attributable to a line or to the service charge');
});

it('refuses an order where the charge line and the order total disagree', function () {
    // A resolver bug that updates one and not the other. Left unchecked the
    // receipt shows ¥100 service while the ledger books ¥200.
    $build = fn () => scSnapshot(scDraft(
        new OrderServiceChargePayload(amountMinor: 100, taxAmountMinor: 10, taxRateBasisPoints: 1000),
        new OrderPricingEvidence(
            subtotalMinor: 1000,
            discountMinor: 0,
            serviceChargeMinor: 200,   // ← ledger says ¥200, the line says ¥100
            taxMinor: 110,
            totalMinor: 1310,
            taxIncluded: false,
            taxRoundingMode: 'round',
            taxRoundingDecimals: 0,
        ),
    ));

    expect($build)->toThrow(InvalidArgumentException::class, 'Service charge evidence says 100 but the order totals say 200');
});

it('keeps the service charge OUT of the subtotal so day-end reports stay comparable', function () {
    // `customer_orders.subtotal` has always meant "what the food and drink came
    // to". Folding service into it would inflate every revenue report and break
    // comparability with historical orders — silently, since nothing errors.
    $snapshot = scSnapshot(scDraft(
        new OrderServiceChargePayload(amountMinor: 100, taxAmountMinor: 10, taxRateBasisPoints: 1000),
        new OrderPricingEvidence(
            subtotalMinor: 1000,
            discountMinor: 0,
            serviceChargeMinor: 100,
            taxMinor: 110,
            totalMinor: 1210,
            taxIncluded: false,
            taxRoundingMode: 'round',
            taxRoundingDecimals: 0,
        ),
    ));

    expect($snapshot->draft->pricingEvidence->subtotalMinor)->toBe(
        1000,
        'Subtotal must be food and drink only. If the ¥100 service charge leaked in, every revenue report shifts and nothing warns you.',
    );
});

// ---------------------------------------------------------------------------
// Shops that do NOT tax the service charge, and shops with none at all
// ---------------------------------------------------------------------------

it('bills an untaxed service charge without inventing tax', function () {
    // service_charge_rate = 10, service_charge_tax_rate = 0.
    $snapshot = scSnapshot(scDraft(
        new OrderServiceChargePayload(amountMinor: 100, taxAmountMinor: 0),
        new OrderPricingEvidence(
            subtotalMinor: 1000,
            discountMinor: 0,
            serviceChargeMinor: 100,
            taxMinor: 100,
            totalMinor: 1200,
            taxIncluded: false,
            taxRoundingMode: 'round',
            taxRoundingDecimals: 0,
        ),
    ));

    expect($snapshot->draft->pricingEvidence->totalMinor)->toBe(1200, 'A shop that does not tax service must charge exactly ¥1,200.');
});

it('bills an order with no service charge at all', function () {
    $snapshot = scSnapshot(scDraft(
        null,
        new OrderPricingEvidence(
            subtotalMinor: 1000,
            discountMinor: 0,
            serviceChargeMinor: 0,
            taxMinor: 100,
            totalMinor: 1100,
            taxIncluded: false,
            taxRoundingMode: 'round',
            taxRoundingDecimals: 0,
        ),
    ));

    expect($snapshot->draft->serviceCharge)->toBeNull()
        ->and($snapshot->draft->pricingEvidence->totalMinor)->toBe(1100);
});

// ---------------------------------------------------------------------------
// Guards on the charge payload itself — catch nonsense at the door
// ---------------------------------------------------------------------------

it('refuses tax on a service charge with no rate to justify it', function () {
    // Tax with no rate cannot be reported per rate, so the filing would be
    // unsupportable even though the money adds up.
    expect(fn () => new OrderServiceChargePayload(amountMinor: 100, taxAmountMinor: 10))
        ->toThrow(InvalidArgumentException::class, 'requires the rate that produced it');
});

it('refuses tax on a service charge that was never levied', function () {
    expect(fn () => new OrderServiceChargePayload(amountMinor: 0, taxAmountMinor: 10, taxRateBasisPoints: 1000))
        ->toThrow(InvalidArgumentException::class, 'zero service charge cannot carry tax');
});

it('refuses a negative service charge or negative tax', function (int $amount, int $tax) {
    expect(fn () => new OrderServiceChargePayload(amountMinor: $amount, taxAmountMinor: $tax, taxRateBasisPoints: 1000))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
})->with([
    'negative charge' => [-100, 0],
    'negative tax' => [100, -10],
]);

// ---------------------------------------------------------------------------
// Diagnostics — a failure must name the money, not just say "invalid"
// ---------------------------------------------------------------------------

it('reports the exact yen discrepancy when tax evidence does not add up', function () {
    try {
        scSnapshot(scDraft(
            new OrderServiceChargePayload(amountMinor: 100, taxAmountMinor: 10, taxRateBasisPoints: 1000),
            new OrderPricingEvidence(
                subtotalMinor: 1000,
                discountMinor: 0,
                serviceChargeMinor: 100,
                taxMinor: 100,
                totalMinor: 1200,
                taxIncluded: false,
                taxRoundingMode: 'round',
                taxRoundingDecimals: 0,
            ),
        ));
        $this->fail('Expected the snapshot to reject tax evidence that does not add up.');
    } catch (InvalidArgumentException $e) {
        // Whoever hits this at 2am needs the numbers, not a category. The
        // message must state the total the evidence adds up to, break it down
        // by source, and name the exact gap so it can be traced to a receipt.
        expect($e->getMessage())->toContain('adds up to 110');
        expect($e->getMessage())->toContain('100 from lines');
        expect($e->getMessage())->toContain('10 from the service charge');
        expect($e->getMessage())->toContain('off by 10');
    }
});

it('reports the exact yen discrepancy when the order total does not reconcile', function () {
    try {
        scSnapshot(scDraft(
            null,
            new OrderPricingEvidence(
                subtotalMinor: 1000,
                discountMinor: 0,
                serviceChargeMinor: 0,
                taxMinor: 100,
                totalMinor: 1105,   // ¥5 more than the parts justify
                taxIncluded: false,
                taxRoundingMode: 'round',
                taxRoundingDecimals: 0,
            ),
        ));
        $this->fail('Expected the snapshot to reject a total that does not reconcile.');
    } catch (InvalidArgumentException $e) {
        // Reconciled figure, what the order claimed, and the gap in yen.
        expect($e->getMessage())->toContain('= 1100');
        expect($e->getMessage())->toContain('says 1105');
        expect($e->getMessage())->toContain('off by 5');
    }
});
