<?php

use App\Models\Branch;
/**
 * plan-054 M4b — the service that mints the PayPay QR a customer scans.
 *
 * Two halves, and the split is deliberate:
 *
 * 1. Behaviour. Every refusal path is exercised for real. Each one matters
 *    because it runs BEFORE anything is minted at PayPay: once a code is live
 *    it can be scanned, and a code nobody can match to an attempt is money
 *    that arrives as `paypay_no_matching_attempt` — an event marked succeeded
 *    while the ledger never moves.
 *
 * 2. Ordering, pinned structurally. `PayPayQrCodeClient` — the only class here
 *    that touches the network — is `final`, has no interface, and its two
 *    constructor collaborators (PayPaySdkClientFactory, PayPayCredentialsResolver)
 *    are `final` too. Nothing can be substituted for it: Mockery refuses a final
 *    class, and `overload:` needs process isolation, which this suite's runner
 *    (Pest) cannot provide. So any call that reaches `create`/`retrieve`/`delete`
 *    performs real HTTP to PayPay, and the four orderings the design turns on
 *    cannot be observed behaviourally yet. They are pinned against the source
 *    instead — crude, but it fails loudly the moment someone reorders the calls.
 *    Replace those with real assertions the day the client gains an interface.
 */

use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Customer\PayPayPaymentService;
use App\Services\Customer\PayPayUnavailable;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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
 * An order PayPay would accept: JPY branch, open, nothing paid yet.
 *
 * Console ids are explicit and agree across org/brand/branch because the
 * bootstrap publishes a real policy revision, and the publisher validates that
 * scope — the shared `00000000-…-0001` fixture org would not clear it.
 */
function plan054QrOrder(array $orderOverrides = [], array $branchOverrides = []): CustomerOrder
{
    $consoleOrganizationId = (string) Str::uuid();

    $organization = Organization::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $branch = Branch::factory()->create(array_merge([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'JPY',
    ], $branchOverrides));

    $order = CustomerOrder::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 1000,
        'paid_amount' => 0,
    ], $orderOverrides));

    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $order->organization_id,
        'currency_code' => 'JPY',
    ]);

    return $order;
}

function plan054QrService(): PayPayPaymentService
{
    return app(PayPayPaymentService::class);
}

/** Body of a PayPayPaymentService method, for the ordering pins below. */
function plan054QrSourceOf(string $method): string
{
    $reflection = new ReflectionMethod(PayPayPaymentService::class, $method);
    $lines = file((string) $reflection->getFileName());

    return implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
}

/*
|--------------------------------------------------------------------------
| Refusals — every one of these must happen before a code exists at PayPay
|--------------------------------------------------------------------------
*/

it('refuses a branch with no PayPay credentials before provisioning anything', function () {
    // Ordered first on purpose: the availability gate runs ahead of the
    // bootstrap, so a half-configured deployment never ends up with connection
    // and policy rows advertising a gateway it can never call.
    config(['services.paypay.api_key' => '']);

    $order = plan054QrOrder();

    expect(fn () => plan054QrService()->createQrCode(orderSnapshot($order)))
        ->toThrow(PayPayUnavailable::class, 'PayPay is not available for this branch.');

    expect(PaymentGatewayConnection::query()->count())->toBe(0)
        ->and(PaymentAttempt::query()->count())->toBe(0);
});

it('refuses a branch priced in a currency PayPay cannot settle', function () {
    // The policy request hardcodes JPY regardless of the branch, so without this
    // gate a VND branch is told PayPay is available and then has a VND figure
    // submitted to PayPay as yen.
    $order = plan054QrOrder(branchOverrides: ['currency' => 'VND']);

    expect(fn () => plan054QrService()->createQrCode(orderSnapshot($order)))
        ->toThrow(PayPayUnavailable::class, 'PayPay is not available for this branch.');

    expect(PaymentAttempt::query()->count())->toBe(0);
});

it('refuses an order that can no longer be paid', function (string $status) {
    $order = plan054QrOrder(['status' => $status]);

    expect(fn () => plan054QrService()->createQrCode(orderSnapshot($order)))
        ->toThrow(PayPayUnavailable::class, 'This order can no longer be paid.');

    expect(PaymentAttempt::query()->count())->toBe(0);
})->with([
    CustomerOrderStatusEnum::Closed->value,
    CustomerOrderStatusEnum::Voided->value,
    CustomerOrderStatusEnum::Expired->value,
]);

it('refuses to mint a code on an order whose payment window has closed', function () {
    // R24. The `Expired` status check is not enough on its own: `payment_due_at`
    // passes the moment it passes, while the status is only stamped when the
    // reaper next runs. In that gap a mint would hand out a five-minute code on
    // an order `OrderPaymentService` already refuses payment for — the guest
    // scans it, pays, and the money lands with nothing willing to book it.
    $order = plan054QrOrder(['payment_due_at' => now()->subMinute()]);

    expect(fn () => plan054QrService()->createQrCode(orderSnapshot($order)))
        ->toThrow(PayPayUnavailable::class);

    expect(PaymentAttempt::query()->count())->toBe(0);
});

it('never shows a code as outliving the order it collects for', function () {
    // PayPay picks ~5 minutes and offers no way to shorten it, so an order due
    // in 60 seconds would otherwise display a five-minute countdown. Only the
    // DISPLAYED deadline moves: the code stays live at PayPay until PayPay
    // retires it, because shortening what the guest sees is safe while
    // shortening what the provider honours would strand a payment in flight.
    // Đồng hồ ĐÓNG BĂNG: `now()` được gọi ở fixture rồi gọi lại ở phần khẳng
    // định, nên một ranh giới giây trôi qua giữa hai lần là lệch đúng 1 và test
    // đỏ ngẫu nhiên. Đã bắt được thật trong một lượt chạy rộng.
    Carbon::setTestNow(Carbon::parse('2026-08-03T12:00:00Z'));

    $order = plan054QrOrder(['payment_due_at' => now()->addSeconds(60)]);
    $codeLapsesAt = now()->addSeconds(300)->getTimestamp();

    $clamped = (new ReflectionMethod(PayPayPaymentService::class, 'clampToOrderDeadline'))
        ->invoke(plan054QrService(), $codeLapsesAt, orderSnapshot($order));

    expect($clamped)->toBe(now()->addSeconds(60)->getTimestamp())
        ->and($clamped)->toBeLessThan($codeLapsesAt);
});

it('leaves the code expiry alone on an order with no deadline', function () {
    // Dine-in has no `payment_due_at`; the clamp must not invent one.
    $order = plan054QrOrder(['payment_due_at' => null]);
    $codeLapsesAt = now()->addSeconds(300)->getTimestamp();

    expect((new ReflectionMethod(PayPayPaymentService::class, 'clampToOrderDeadline'))
        ->invoke(plan054QrService(), $codeLapsesAt, orderSnapshot($order)))
        ->toBe($codeLapsesAt);
});

it('refuses an order with nothing left to pay', function () {
    // A second QR for a settled order is an overpayment waiting to happen: the
    // ledger funnel would refuse the money after the customer had already sent
    // it, and refunding a PayPay payment is not wired.
    $order = plan054QrOrder(['paid_amount' => 1000]);

    expect(fn () => plan054QrService()->createQrCode(orderSnapshot($order)))
        ->toThrow(PayPayUnavailable::class, 'This order has nothing left to pay.');

    expect(PaymentAttempt::query()->count())->toBe(0);
});

it('refuses a requested amount larger than what is outstanding', function () {
    // The split-bill case the amount parameter exists for. Two of four payers
    // have settled their share; the third asks for more than the ¥400 left.
    // Deriving the figure from total - paid is exactly what must NOT happen, and
    // trusting the caller's number is what must not happen either.
    $order = plan054QrOrder(['total_amount' => 1000, 'paid_amount' => 600]);

    expect(fn () => plan054QrService()->createQrCode(orderSnapshot($order), 500.0))
        ->toThrow(PayPayUnavailable::class, 'The requested amount does not match what is outstanding.');

    expect(PaymentAttempt::query()->count())->toBe(0);
});

it('refuses an amount that collects nothing', function (float $amount) {
    $order = plan054QrOrder();

    expect(fn () => plan054QrService()->createQrCode(orderSnapshot($order), $amount))
        ->toThrow(PayPayUnavailable::class, 'The requested amount does not match what is outstanding.');

    expect(PaymentAttempt::query()->count())->toBe(0);
})->with([0.0, -100.0, 0.004]);

it('refuses rather than minting a code it could never credit when the transport is off', function () {
    // Stripe tolerates a null prepare because its ledger write keys on the
    // intent id. PayPay's webhook matches on the attempt, so a QR minted without
    // one could never be credited — fail closed instead.
    config(['payments.orchestrator_runtime.transport_switches.customer_web' => false]);

    $order = plan054QrOrder();

    expect(fn () => plan054QrService()->createQrCode(orderSnapshot($order)))
        ->toThrow(PayPayUnavailable::class, 'PayPay payments are disabled for this transport.');

    expect(PaymentAttempt::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| syncStatus — which attempt, if any, is worth asking PayPay about
|--------------------------------------------------------------------------
*/

it('reports NOT_FOUND without asking PayPay when no code is outstanding', function () {
    $order = plan054QrOrder();

    expect(plan054QrService()->syncStatus(orderSnapshot($order)))->toBe([
        'status' => 'NOT_FOUND',
        'is_fully_paid' => false,
        'order_status' => CustomerOrderStatusEnum::Open->value,
        'expires_in_seconds' => null,
        // Nothing outstanding means no code to name.
        'merchant_payment_id' => null,
    ]);

    expect(OrderPayment::query()->count())->toBe(0);
});

it('does not resurrect a QR attempt that already reached a terminal state', function () {
    // Terminal means the code is gone — PayPay would answer for an id nobody can
    // be credited against, and the poll would keep the customer's screen alive
    // on a code they can no longer scan.
    $order = plan054QrOrder(['paid_amount' => 1000]);

    PaymentAttempt::factory()->create([
        'customer_order_id' => $order->id,
        'provider_object_id' => 'tempoqr-'.str_replace('-', '', (string) Str::uuid()),
        'state' => PaymentAttemptStateEnum::Canceled->value,
    ]);

    expect(plan054QrService()->syncStatus(orderSnapshot($order)))->toMatchArray([
        'status' => 'NOT_FOUND',
        // The order is settled by other means; the payload still tells the truth.
        'is_fully_paid' => true,
    ]);
});

it('never polls PayPay for a provider reference that is not a QR', function () {
    // Same order, a live Stripe attempt. Without the merchant-payment-id prefix
    // filter the service would call the PayPay code endpoint with `pi_…`, which
    // 404s and dead-letters after five retries.
    $order = plan054QrOrder();

    PaymentAttempt::factory()->create([
        'customer_order_id' => $order->id,
        'provider_object_id' => 'pi_3PlanZeroFiveFour',
        'state' => PaymentAttemptStateEnum::ProviderPending->value,
    ]);

    expect(plan054QrService()->syncStatus(orderSnapshot($order))['status'])->toBe('NOT_FOUND');
});

/*
|--------------------------------------------------------------------------
| Orderings — pinned against the source until the client can be faked
|--------------------------------------------------------------------------
*/

it('reserves the attempt before the code is minted', function () {
    // The attempt mints the merchant payment id, so a code can never be live at
    // PayPay without a row to match its webhook against.
    $source = plan054QrSourceOf('createQrCode');

    expect(strpos($source, 'preparePayPayQrAttempt('))
        ->toBeLessThan(strpos($source, '$this->qrCodes->create('));
});

it('kills whatever code was outstanding before minting the next one', function () {
    // Two scannable codes for one order is how an overpayment happens without
    // anybody doing anything wrong.
    $create = plan054QrSourceOf('createQrCode');
    $invalidate = plan054QrSourceOf('invalidateOutstandingQr');

    expect(strpos($create, '$this->invalidateOutstandingQr('))
        ->toBeLessThan(strpos($create, 'preparePayPayQrAttempt('))
        // Delete first there too: the attempt is the match key for a payment
        // that lands anyway, so it must outlive the code, not precede it.
        ->and(strpos($invalidate, '$this->qrCodes->delete('))
        ->toBeLessThan(strpos($invalidate, 'abandonPayPayQrAttempt('));
});

it('deletes the code at PayPay before the attempt goes terminal', function () {
    // Once the attempt is terminal a webhook for that code resolves to
    // `paypay_ignored_terminal`, which is marked succeeded and drops the money
    // silently. Closing the window at PayPay first is what keeps that from
    // being reachable.
    $source = plan054QrSourceOf('createQrCode');

    expect(strpos($source, '$this->qrCodes->delete('))
        ->toBeLessThan(strpos($source, 'abandonPayPayQrAttempt('));
});

it('anchors the expiry countdown to the server clock', function () {
    // A client with a skewed clock would otherwise show a live code as expired
    // hours ago — the plan-031 seconds_until_due lesson.
    $source = plan054QrSourceOf('createQrCode');

    expect($source)->toContain("'expires_in_seconds' =>")
        ->and($source)->toContain('max(0, $expiresAt - now()->getTimestamp())');
});

it('records the amount PayPay reports, never the order total', function () {
    // A source pin, not a behaviour test: this file drives the real service and
    // there is no fake QR client, so the COMPLETED branch cannot be reached
    // without a network call. Pinned loosely on purpose — an earlier version
    // asserted the exact expression `(float) ($details['amount'] ?? 0)` and broke
    // when the minor-unit conversion was aligned with the webhook path, even
    // though the number reaching the ledger never changed.
    //
    // What must stay true: the amount comes from PayPay's answer, and the order
    // total never enters this method. Booking the total would invent money on a
    // split share, or on a bill that grew after the code was minted.
    $source = plan054QrSourceOf('syncStatus');

    expect($source)->toContain("\$details['amount']")
        ->and($source)->not->toContain('total_amount');
});

/*
|--------------------------------------------------------------------------
| A reload is not a request for a new code
|--------------------------------------------------------------------------
*/

/** An order with a live QR attempt and its session cached, as a mint leaves it. */
function plan054ResumableOrder(float $amount = 1000, int $secondsLeft = 240): array
{
    $order = plan054QrOrder(['total_amount' => 1000, 'paid_amount' => 0]);
    $connection = PaymentGatewayConnection::factory()->create();
    $merchantPaymentId = 'tempoqr-'.str_replace('-', '', (string) Str::uuid());

    PaymentAttempt::factory()->create([
        'customer_order_id' => $order->id,
        'connection_id' => $connection->id,
        'provider_object_id' => $merchantPaymentId,
        'state' => PaymentAttemptStateEnum::Prepared->value,
    ]);

    Cache::put('paypay:qr-session:'.$merchantPaymentId, [
        'qr_url' => 'https://qr-stg.sandbox.paypay.ne.jp/resumable',
        'deeplink' => 'paypay://payment?link_key=resumable',
        'expires_at' => now()->addSeconds($secondsLeft)->getTimestamp(),
        'amount' => $amount,
    ], now()->addMinutes(30));

    return [$order, $merchantPaymentId];
}

it('hands a reload the code the order already has', function () {
    // The pay page re-POSTs the mint on every load. Treating that as a new
    // request deleted the QR the guest may have had open in the PayPay app,
    // issued another, and restarted their countdown at five minutes — and ten
    // reloads spent the whole per-order mint allowance.
    [$order, $merchantPaymentId] = plan054ResumableOrder();

    expect(plan054QrService()->createQrCode(orderSnapshot($order)))->toMatchArray([
        'qr_url' => 'https://qr-stg.sandbox.paypay.ne.jp/resumable',
        'merchant_payment_id' => $merchantPaymentId,
        'amount' => 1000.0,
    ]);

    // Resumed, not re-minted: no second attempt, and PayPay was never called
    // (this suite has no fake client, so a mint here would attempt real HTTP).
    expect(PaymentAttempt::query()->count())->toBe(1);
});

/**
 * Whether the service would resume, asked directly.
 *
 * The refusals below are asserted here rather than by driving `createQrCode`
 * and reading whichever exception the minting path happens to raise first. An
 * earlier version did that and was worthless: the mint cannot complete in this
 * suite (no fake QR client), so it failed somewhere on the way to PayPay, and
 * WHICH failure arrived first varied run to run. Declining to resume is the
 * claim; assert the claim.
 */
function plan054WouldResume(CustomerOrder $order, ?float $requestedAmount = null): ?array
{
    return (new ReflectionMethod(PayPayPaymentService::class, 'resumeOutstandingQr'))
        ->invoke(plan054QrService(), orderSnapshot($order), $requestedAmount);
}

it('refuses to resume when the bill has moved', function () {
    // A coupon, a split payment or an item added since the mint all change what
    // is outstanding. Handing back the cached code would collect the old sum.
    [$order] = plan054ResumableOrder(amount: 1000);

    expect(plan054WouldResume($order))->not->toBeNull();

    $order->forceFill(['paid_amount' => 400])->save();

    expect(plan054WouldResume($order))->toBeNull();
});

it('refuses to resume a share that is not the one the code collects', function () {
    // Split bill: the cached code promises the whole outstanding amount, and the
    // second payer asks for their own slice.
    [$order] = plan054ResumableOrder(amount: 1000);

    expect(plan054WouldResume($order, requestedAmount: 400))->toBeNull();
});

it('refuses to resume a code about to lapse', function () {
    // A code seconds from expiry is worse than no code: the guest scans it,
    // PayPay refuses, and the failure looks like ours.
    [$order] = plan054ResumableOrder(secondsLeft: 5);

    expect(plan054WouldResume($order))->toBeNull();
});

it('refuses to resume when the cached session is gone', function () {
    // A cache miss must never be a failure — it simply mints, which is always
    // safe. Nothing about correctness depends on the cache surviving.
    [$order, $merchantPaymentId] = plan054ResumableOrder();

    Cache::forget('paypay:qr-session:'.$merchantPaymentId);

    expect(plan054WouldResume($order))->toBeNull();
});

it('refuses to resume an attempt that is no longer live', function () {
    // A retired or cancelled attempt cannot be credited, so its code must not be
    // handed to anyone — even while the session is still cached.
    [$order] = plan054ResumableOrder();

    PaymentAttempt::query()->update(['state' => PaymentAttemptStateEnum::Canceled->value]);

    expect(plan054WouldResume($order))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The countdown survives a reload
|--------------------------------------------------------------------------
*/

it('answers the expiry the provider will not', function (callable $asStored) {
    // Verified against the sandbox: `/v2/codes` returns `expiryDate` on create
    // and `/v2/codes/payments/{mpid}` does not return it at all. So a guest who
    // reloads the page polls and gets `expires_in_seconds: null` — the countdown
    // has nothing to anchor on and the browser falls back to its own clock,
    // which is the exact failure this field exists to prevent.
    $order = plan054QrOrder();
    $connection = PaymentGatewayConnection::factory()->create();
    $merchantPaymentId = 'tempoqr-'.str_replace('-', '', (string) Str::uuid());

    PaymentAttempt::factory()->create([
        'customer_order_id' => $order->id,
        'connection_id' => $connection->id,
        'provider_object_id' => $merchantPaymentId,
        'state' => PaymentAttemptStateEnum::Prepared->value,
    ]);

    $lapsesAt = now()->addSeconds(180)->getTimestamp();
    Cache::put('paypay:qr-session:'.$merchantPaymentId, [
        'qr_url' => 'https://qr-stg.sandbox.paypay.ne.jp/anchored',
        'deeplink' => null,
        'expires_at' => $asStored($lapsesAt),
        'amount' => 1000.0,
    ], now()->addMinutes(30));

    app()->instance(PayPayQrCodeClient::class, new class extends PayPayQrCodeClient
    {
        public function retrieve($connection, string $merchantPaymentId, string $correlationId): array
        {
            return [
                'status' => 'CREATED',
                'merchant_payment_id' => $merchantPaymentId,
                'paypay_payment_id' => null,
                'amount' => null,
                'currency' => null,
                // What PayPay actually answers on this endpoint.
                'expires_at' => null,
            ];
        }
    });

    expect(app(PayPayPaymentService::class)->syncStatus(orderSnapshot($order)))->toMatchArray([
        'status' => 'CREATED',
        'expires_in_seconds' => 180,
        // Names WHICH code this answer is about, so a screen holding an older
        // one can tell it is looking at somebody else's state.
        'merchant_payment_id' => $merchantPaymentId,
    ]);
})->with([
    // Both shapes, because they are not interchangeable in practice. The array
    // store this suite runs on returns the int it was handed; Redis — what every
    // deployment uses — returns the string '1785321763'. An `is_int` check
    // therefore passed here and returned null in production, which is how the
    // first cut of this shipped and was caught only by a real sandbox mint.
    'stored as an int (array store)' => [fn (int $timestamp): int => $timestamp],
    'stored as a string (redis store)' => [fn (int $timestamp): string => (string) $timestamp],
]);

it('reports a lapsed code as zero rather than as unknown', function () {
    // Held past the code's own life on purpose: "expired" lets the panel offer a
    // fresh code, while "unknown" leaves the guest staring at a QR that will
    // never be paid. The order itself is untouched either way — D7, the order
    // dies on `payment_due_at`, not on the QR.
    $order = plan054QrOrder();
    $connection = PaymentGatewayConnection::factory()->create();
    $merchantPaymentId = 'tempoqr-'.str_replace('-', '', (string) Str::uuid());

    PaymentAttempt::factory()->create([
        'customer_order_id' => $order->id,
        'connection_id' => $connection->id,
        'provider_object_id' => $merchantPaymentId,
        'state' => PaymentAttemptStateEnum::Prepared->value,
    ]);

    Cache::put('paypay:qr-session:'.$merchantPaymentId, [
        'qr_url' => 'https://qr-stg.sandbox.paypay.ne.jp/lapsed',
        'deeplink' => null,
        'expires_at' => now()->subMinutes(2)->getTimestamp(),
        'amount' => 1000.0,
    ], now()->addMinutes(30));

    app()->instance(PayPayQrCodeClient::class, new class extends PayPayQrCodeClient
    {
        public function retrieve($connection, string $merchantPaymentId, string $correlationId): array
        {
            return [
                'status' => 'EXPIRED',
                'merchant_payment_id' => $merchantPaymentId,
                'paypay_payment_id' => null,
                'amount' => null,
                'currency' => null,
                'expires_at' => null,
            ];
        }
    });

    expect(app(PayPayPaymentService::class)->syncStatus(orderSnapshot($order))['expires_in_seconds'])->toBe(0);
});
