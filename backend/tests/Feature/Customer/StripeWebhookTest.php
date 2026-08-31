<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * #2851 — "không khớp đơn nào" có HAI nghĩa, và gộp chúng làm cả hai cùng chìm.
 *
 * Tài khoản Stripe này dùng CHUNG với trang đặt món online riêng của quán
 * (WooCommerce + PaymentPlugins), nên Stripe gửi cả sự kiện của họ tới endpoint
 * của Tempo. Đo 2026-08-14: 47 sự kiện không khớp đơn / 14 PaymentIntent do
 * Tempo tạo. Một dòng INFO cho cả hai ca làm người đọc log tin rằng có 47 đơn
 * hỏng — và làm một ca tiền THẬT thất lạc trông giống hệt 46 ca nhiễu.
 *
 * Bắt log bằng `MessageLogged` chứ không `Log::spy()`: đường này có gọi
 * `Log::channel('payment_orchestration')`, spy trả null và biến 200 thành 500.
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function stripeCaptureLogs(): ArrayObject
{
    $seen = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($seen) {
        $seen[] = ['level' => $e->level, 'message' => $e->message] + $e->context;
    });

    return $seen;
}

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
    ]);

    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->selfRegistered()->create();

    // Closures bound to test instance — avoids global function namespace pollution.
    $this->makeStripeEvent = function (string $type, array $dataObject, string $secret = 'whsec_test_secret_xyz'): array {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $dataObject],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return [
            'payload' => $payload,
            'header' => "t={$timestamp},v1={$signature}",
        ];
    };

    $this->postWebhook = fn (string $payload, string $signature) => $this->call(
        'POST',
        '/api/v1/customer/stripe/webhook',
        [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );
});

// =============================================================================
// Signature verification
// =============================================================================

it('returns 400 when Stripe-Signature header is missing', function () {
    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid signature.']);
});

it('returns 400 when signature is a random string', function () {
    $this->call(
        'POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => 'bad-sig', 'CONTENT_TYPE' => 'application/json'],
        '{}',
    )->assertStatus(400);
});

it('returns 400 when signature uses wrong secret', function () {
    $event = ($this->makeStripeEvent)('payment_intent.succeeded', ['id' => 'pi_test'], 'wrong_secret');

    ($this->postWebhook)($event['payload'], $event['header'])->assertStatus(400);
});

it('returns 200 when signature is valid', function () {
    $event = ($this->makeStripeEvent)('payment_intent.created', ['id' => 'pi_noop']);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
});

it('logs a warning on signature verification failure', function () {
    // #2893 — KHÔNG dùng `Log::spy()` ở đây nữa.
    //
    // Đường intake phân giải connection TRƯỚC khi xác minh chữ ký (phải biết
    // connection mới biết dùng webhook secret nào). #2893 thêm một dòng
    // `Log::channel('payment_orchestration')` vào lưới cuối của bộ phân giải,
    // và với `Log::spy()` thì `channel()` trả null ⇒ gọi `warning()` trên null
    // ⇒ ném ⇒ rơi vào `catch (\Throwable)` của controller ⇒ 500, và nhánh
    // 400-kèm-warning KHÔNG BAO GIỜ chạy tới. Test đỏ với thông điệp
    // "warning should be called 1 times but called 0 times" — nghe như log
    // biến mất, thật ra là cả đường đi đã đổi.
    //
    // Chính file này đã ghi cạm bẫy đó ở khối `stripeCaptureLogs()` bên dưới;
    // bài này chỉ là chỗ cuối cùng còn dùng cách cũ. Nghe `MessageLogged` giữ
    // logger THẬT tại chỗ nên đường đi không đổi.
    $seen = stripeCaptureLogs();

    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
        ->assertStatus(400);

    $warned = array_filter(
        iterator_to_array($seen),
        static fn (array $entry): bool => ($entry['level'] ?? null) === 'warning'
            && str_starts_with((string) ($entry['message'] ?? ''), 'Stripe webhook'),
    );

    expect($warned)->not->toBeEmpty();
});

// =============================================================================
// Event routing
// =============================================================================

it('returns 200 noop for payment_intent.created event', function () {
    $event = ($this->makeStripeEvent)('payment_intent.created', ['id' => 'pi_abc']);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk()->assertJson(['received' => true]);
});

it('returns 200 noop for payment_intent.payment_failed event', function () {
    $event = ($this->makeStripeEvent)('payment_intent.payment_failed', ['id' => 'pi_abc']);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk()->assertJson(['received' => true]);
});

it('returns 200 noop for charge.succeeded event', function () {
    $event = ($this->makeStripeEvent)('charge.succeeded', ['id' => 'ch_abc']);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk()->assertJson(['received' => true]);
});

// =============================================================================
// markOrderPaidFromIntent — happy path
// =============================================================================

it('marks order as closed on payment_intent.succeeded', function () {
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'stripe_payment_intent_id' => 'pi_test_happy',
        'total_amount' => 1500,
        'status' => 'open',
        'checkout_at' => null,
        'closed_at' => null,
    ]);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', ['object' => 'payment_intent', 'id' => 'pi_test_happy', 'amount' => 1500]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    $order->refresh();
    expect($order->status)->toBe(CustomerOrderStatusEnum::Closed);
});

it('sets paid_amount on payment_intent.succeeded', function () {
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'stripe_payment_intent_id' => 'pi_test_paid',
        'total_amount' => 2500,
        'paid_amount' => 0,
        'status' => 'open',
    ]);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', ['object' => 'payment_intent', 'id' => 'pi_test_paid', 'amount' => 2500]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect($order->fresh()->paid_amount)->toEqual(2500);
});

it('records the actual charged amount, not order total, when full payment closes a split order', function () {
    // Split-bill scenario: customer A already paid 2991; customer B clicks
    // "Thanh toán toàn bộ" which charges remaining (2991), not total (5982).
    // The recorded payment row must show what Stripe charged.
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'stripe_payment_intent_id' => 'pi_test_full_after_split',
        'total_amount' => 5982,
        'paid_amount' => 2991,
        'status' => 'open',
    ]);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_test_full_after_split',
        'amount' => 2991, // remaining, not total
        'currency' => 'jpy',
    ]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    $payment = OrderPayment::where('reference_no', 'pi_test_full_after_split')->first();
    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toBe(2991.0);
});

it('sets closed_at on payment_intent.succeeded', function () {
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'stripe_payment_intent_id' => 'pi_test_closed',
        'total_amount' => 1000,
        'status' => 'open',
        'closed_at' => null,
    ]);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', ['object' => 'payment_intent', 'id' => 'pi_test_closed', 'amount' => 1000]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect($order->fresh()->closed_at)->not->toBeNull();
});

it('does not overwrite existing checkout_at', function () {
    $existingCheckout = now()->subMinutes(5);
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'stripe_payment_intent_id' => 'pi_test_checkout',
        'total_amount' => 800,
        'status' => 'open',
        'checkout_at' => $existingCheckout,
    ]);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', ['object' => 'payment_intent', 'id' => 'pi_test_checkout', 'amount' => 800]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect($order->fresh()->checkout_at->toDateTimeString())->toBe($existingCheckout->toDateTimeString());
});

// =============================================================================
// No matching order
// =============================================================================

it('returns 200 and records ignored_no_order when no order matches payment intent', function () {
    $event = ($this->makeStripeEvent)('payment_intent.succeeded', ['object' => 'payment_intent', 'id' => 'pi_no_match_xyz']);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    $eventId = json_decode($event['payload'], true)['id'];
    $inbox = PaymentProviderEvent::query()->where('provider_event_id', $eventId)->first();

    expect($inbox)->not->toBeNull()
        ->and($inbox->state)->toBe(PaymentProviderEventStateEnum::Succeeded)
        ->and($inbox->outcome)->toBe('ignored_no_order');
});

/** @param ArrayObject<int, array<string, mixed>> $seen */
function stripeFindLog(ArrayObject $seen, string $message): ?array
{
    foreach ($seen as $entry) {
        if (($entry['message'] ?? null) === $message) {
            return $entry;
        }
    }

    return null;
}

it('#2851 intent của hệ khác trên cùng tài khoản Stripe đi xuống debug, không kêu', function () {
    $seen = stripeCaptureLogs();

    // Hình dạng thật đo được từ Stripe API: id đơn của WooCommerce là SỐ.
    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_foreign_woo',
        'metadata' => ['order_id' => '177210', 'partner' => 'PaymentPlugins'],
    ]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    // Nó KHÔNG được kêu. Nhiễu kêu to thì người ta tắt cảnh báo, rồi ca thật ở
    // bài dưới cũng im theo.
    expect(stripeFindLog($seen, 'stripe_intent_unattributed'))->toBeNull()
        // Và cũng không còn dòng INFO cũ ở kênh laravel chính — đó chính là 47
        // dòng/ngày mà #2851 đo được.
        ->and(stripeFindLog($seen, 'Stripe webhook received but no matching order'))->toBeNull();

    // Vết vẫn còn, chỉ ở mức `debug` trên kênh payment_orchestration — mà kênh
    // đó mặc định lọc từ `info`, nên bình thường nó KHÔNG được ghi chút nào.
    // Người điều tra bật lại bằng `PAYMENT_ORCHESTRATION_LOG_LEVEL=debug`.
    config(['logging.channels.payment_orchestration.level' => 'debug']);
    Log::forgetChannel('payment_orchestration');

    $seenWithDebug = stripeCaptureLogs();
    $second = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_foreign_woo_2',
        'metadata' => ['order_id' => '177213', 'partner' => 'PaymentPlugins'],
    ]);
    ($this->postWebhook)($second['payload'], $second['header'])->assertOk();

    $foreign = stripeFindLog($seenWithDebug, 'stripe_event_foreign_account');

    expect($foreign)->not->toBeNull()
        ->and($foreign['level'])->toBe('debug')
        ->and($foreign['payment_intent'])->toBe('pi_foreign_woo_2');
});

it('#2851 intent CỦA TA mà không quy được về đơn thì KÊU', function () {
    $seen = stripeCaptureLogs();

    // Mọi đường tạo intent của Tempo đóng dấu id `customer_orders` (UUID) vào
    // `metadata.order_id`. UUID này không trỏ tới đơn nào — đúng ca "tiền đã
    // trừ của khách mà không quy được về đâu".
    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_ours_unlinked',
        'metadata' => ['order_id' => (string) Str::uuid()],
    ]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    $ours = stripeFindLog($seen, 'stripe_intent_unattributed');

    expect($ours)->not->toBeNull()
        ->and($ours['level'])->toBe('warning')
        ->and(stripeFindLog($seen, 'stripe_event_foreign_account'))->toBeNull();
});

it('does not modify any DB record when no order matches', function () {
    $countBefore = CustomerOrder::count();

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', ['object' => 'payment_intent', 'id' => 'pi_no_order']);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(CustomerOrder::count())->toBe($countBefore);
});

// =============================================================================
// Idempotency
// =============================================================================

it('is idempotent — firing same event twice keeps order Closed and paid_amount stable', function () {
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'stripe_payment_intent_id' => 'pi_idempotent',
        'total_amount' => 500,
        'paid_amount' => 0,
        'status' => 'open',
    ]);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', ['object' => 'payment_intent', 'id' => 'pi_idempotent', 'amount' => 500]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
    $afterFirst = $order->fresh();
    expect($afterFirst->status)->toBe(CustomerOrderStatusEnum::Closed)
        ->and((int) $afterFirst->paid_amount)->toBe(500)
        ->and($afterFirst->closed_at)->not->toBeNull();
    $firstClosedAt = $afterFirst->closed_at;

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
    $afterSecond = $order->fresh();

    // Final state stays consistent — paid_amount must NOT accumulate.
    expect($afterSecond->status)->toBe(CustomerOrderStatusEnum::Closed)
        ->and((int) $afterSecond->paid_amount)->toBe(500);

    // closed_at IS rewritten on every call (current spec — `'closed_at' => now()`).
    // If this changes, update both the test and StripePaymentService::markOrderPaidFromIntent.
    expect($afterSecond->closed_at->greaterThanOrEqualTo($firstClosedAt))->toBeTrue();
});

it('is idempotent — only one CustomerOrder row exists after replay', function () {
    CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'stripe_payment_intent_id' => 'pi_replay',
        'total_amount' => 100,
        'status' => 'open',
    ]);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', ['object' => 'payment_intent', 'id' => 'pi_replay', 'amount' => 100]);

    $countBefore = CustomerOrder::count();
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(CustomerOrder::count())->toBe($countBefore);
});

// =============================================================================
// Cross-cutting
// =============================================================================

it('does not require any auth header', function () {
    $event = ($this->makeStripeEvent)('payment_intent.created', ['id' => 'pi_public']);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
});

// =============================================================================
// Malformed payload (signature would still match if computed against junk)
// =============================================================================

it('returns 400 when payload is malformed JSON even if signature header is set', function () {
    $payload = '{not valid json';
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_secret_xyz');

    ($this->postWebhook)($payload, "t={$timestamp},v1={$signature}")
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid signature.']);
});
