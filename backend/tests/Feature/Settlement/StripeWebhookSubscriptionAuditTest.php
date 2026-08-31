<?php

use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\Payment\FakeStripeSettlementClient;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 T2.4 (#1978) — `settlements:audit-webhooks`.
 *
 * The 5 required events are chốt in docs/guide/gateway-settlement.md and live
 * in config `payments.settlement.required_webhook_events.stripe`.
 *
 * Covered: partial subscription → named missing events · family match
 * (`charge.dispute.*`) · Stripe's `*` · DISABLED endpoint contributes nothing ·
 * union across endpoints · unreachable connection is an error, not an
 * accusation, and does not stop the sweep · --connection scoping · --strict
 * exit codes · --json shape.
 *
 * Output is asserted through Artisan::output() rather than
 * `expectsOutputToContain`, which consumes one written line per expectation
 * and therefore cannot assert two substrings of the SAME line — and every
 * interesting assertion here ("missing X, Y, Z") lives on one line.
 */
function fakeWebhookClient(): FakeStripeSettlementClient
{
    $fake = new FakeStripeSettlementClient;
    app()->instance(StripeSettlementClient::class, $fake);

    return $fake;
}

/**
 * @param  list<string>  $events
 * @return array<string, mixed>
 */
function webhookEndpoint(string $id, array $events, string $status = 'enabled'): array
{
    return [
        'id' => $id,
        'url' => 'https://example.test/api/v1/webhooks/stripe',
        'status' => $status,
        'enabled_events' => $events,
    ];
}

/**
 * @param  array<string, mixed>  $options
 * @return array{code: int, output: string}
 */
function runWebhookAudit(array $options = []): array
{
    $code = Artisan::call('settlements:audit-webhooks', $options);

    return ['code' => $code, 'output' => Artisan::output()];
}

/** Every required event, so a test can subtract exactly one thing. */
function allRequiredWebhookEvents(): array
{
    return [
        'payment_intent.succeeded',
        'charge.refunded',
        'charge.dispute.created',
        'payout.paid',
        'payout.failed',
    ];
}

it('names every required event a partially subscribed endpoint is missing', function () {
    $connection = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()->withWebhookEndpoints($connection->id, [
        webhookEndpoint('we_partial', ['payment_intent.succeeded', 'charge.refunded']),
    ]);

    $result = runWebhookAudit(['--connection' => $connection->id]);

    expect($result['output'])
        ->toContain('Missing required events:')
        ->toContain('charge.dispute.*')
        ->toContain('payout.paid')
        ->toContain('payout.failed')
        // Already subscribed — must NOT be listed as missing.
        ->not->toContain('Missing required events: payment_intent.succeeded')
        ->and($result['code'])->toBe(0);
});

it('gọi họ khớp MỘT PHẦN là `partial`, không phải `ok` — và --strict đỏ vì nó', function () {
    // Luật cũ: một thành viên cụ thể của `charge.dispute.*` là đủ để báo `ok`.
    // Nghĩa là đăng ký mỗi `charge.dispute.created` cũng xanh, trong khi thiếu
    // `.closed` / `.funds_withdrawn`. Một chữ `ok` nói quá.
    //
    // Luật mới KHÔNG phán họ đã đủ chưa — danh sách event của Stripe đổi theo
    // thời gian, khẳng định "đủ" ở đây là đoán hợp đồng bên thứ ba. Nó chỉ nói
    // ĐÃ THẤY GÌ, và từ chối gọi đó là `ok`.
    $connection = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()->withWebhookEndpoints($connection->id, [
        webhookEndpoint('we_family', allRequiredWebhookEvents()),
    ]);

    $result = runWebhookAudit(['--connection' => $connection->id, '--strict' => true]);

    expect($result['output'])
        ->not->toContain('All required events are subscribed.')
        ->not->toContain('Missing required events')
        ->toContain('Family charge.dispute.* matched only by:')
        ->toContain('1 partially covered')
        // --strict tồn tại để CHỨNG MINH đăng ký là đủ; `partial` chưa chứng
        // minh được điều đó, nên nó phải đỏ.
        ->and($result['code'])->toBe(1);
});

it('không strict thì `partial` chỉ hiện ra để người đọc xét, không làm đỏ', function () {
    $connection = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()->withWebhookEndpoints($connection->id, [
        webhookEndpoint('we_family', allRequiredWebhookEvents()),
    ]);

    $result = runWebhookAudit(['--connection' => $connection->id]);

    expect($result['output'])->toContain('Family charge.dispute.* matched only by:')
        ->and($result['code'])->toBe(0);
});

it('phủ bằng `*` thì KHÔNG bị coi là partial — không có gì một phần để báo', function () {
    $connection = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()->withWebhookEndpoints($connection->id, [
        webhookEndpoint('we_all', ['*']),
    ]);

    $result = runWebhookAudit(['--connection' => $connection->id, '--strict' => true]);

    expect($result['output'])->toContain('All required events are subscribed.')
        ->not->toContain('Family charge.dispute.*')
        ->and($result['code'])->toBe(0);
});

it("accepts Stripe's catch-all * subscription", function () {
    $connection = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()->withWebhookEndpoints($connection->id, [
        webhookEndpoint('we_all', ['*']),
    ]);

    $result = runWebhookAudit(['--connection' => $connection->id, '--strict' => true]);

    expect($result['output'])->toContain('All required events are subscribed.')
        ->and($result['code'])->toBe(0);
});

it('counts a DISABLED endpoint as delivering nothing — its events stay missing', function () {
    $connection = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()->withWebhookEndpoints($connection->id, [
        webhookEndpoint('we_disabled', allRequiredWebhookEvents(), 'disabled'),
    ]);

    $result = runWebhookAudit(['--connection' => $connection->id, '--strict' => true]);

    expect($result['output'])
        ->toContain('Disabled endpoints delivering nothing: we_disabled')
        ->toContain('Missing required events:')
        ->toContain('payment_intent.succeeded')
        ->toContain('payout.failed')
        ->and($result['code'])->toBe(1);
});

it('unions coverage across several endpoints — splitting payout events is a legitimate setup', function () {
    $connection = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()->withWebhookEndpoints($connection->id, [
        webhookEndpoint('we_orders', ['payment_intent.succeeded', 'charge.refunded', 'charge.dispute.funds_withdrawn']),
        webhookEndpoint('we_payouts', ['payout.paid', 'payout.failed']),
    ]);

    $result = runWebhookAudit(['--connection' => $connection->id]);

    // Hợp nhất VẪN đúng: `payout.*` tách sang endpoint khác không bị coi là
    // thiếu. Nhưng họ dispute ở đây chỉ có MỘT thành viên, nên trạng thái là
    // `partial` — hợp nhất và "đủ họ" là hai câu hỏi khác nhau.
    expect($result['output'])
        ->not->toContain('Missing required events')
        ->toContain('Family charge.dispute.* matched only by: charge.dispute.funds_withdrawn')
        ->and($result['code'])->toBe(0);
});

it('reports a connection with no endpoint at all as a full gap', function () {
    $connection = SettlementTestFactory::stripeConnection();
    fakeWebhookClient();

    $result = runWebhookAudit(['--connection' => $connection->id, '--strict' => true]);

    expect($result['output'])
        ->toContain('No webhook endpoint registered at all.')
        ->toContain('Missing required events:')
        ->and($result['code'])->toBe(1);
});

it('reports an unreachable connection as an error, never as missing events, and keeps sweeping', function () {
    $down = SettlementTestFactory::stripeConnection();
    $healthy = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()
        ->failWebhookEndpoints($down->id, 'Stripe API unreachable')
        ->withWebhookEndpoints($healthy->id, [webhookEndpoint('we_ok', ['*'])]);

    $result = runWebhookAudit();

    expect($result['output'])
        ->toContain('Could not list webhook endpoints')
        ->toContain('Stripe API unreachable')
        ->toContain('Coverage UNKNOWN for this connection')
        // We did not look, so we do not accuse.
        ->not->toContain('Missing required events')
        ->toContain('All required events are subscribed.')
        ->toContain('Audited 2 Stripe connection(s): 0 with missing events, 0 partially covered, 1 unreachable.')
        ->and($result['code'])->toBe(0);

    expect(runWebhookAudit(['--strict' => true])['code'])->toBe(1);
});

it('scopes to a single connection with --connection', function () {
    $inScope = SettlementTestFactory::stripeConnection();
    $outOfScope = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()
        ->withWebhookEndpoints($inScope->id, [webhookEndpoint('we_in', ['*'])])
        ->withWebhookEndpoints($outOfScope->id, [webhookEndpoint('we_out', ['payment_intent.succeeded'])]);

    $result = runWebhookAudit(['--connection' => $inScope->id, '--strict' => true]);

    expect($result['output'])
        ->toContain($inScope->id)
        ->not->toContain($outOfScope->id)
        ->not->toContain('we_out')
        ->and($result['code'])->toBe(0);
});

it('emits the audit as JSON with --json', function () {
    $connection = SettlementTestFactory::stripeConnection();

    fakeWebhookClient()->withWebhookEndpoints($connection->id, [
        webhookEndpoint('we_json', ['payment_intent.succeeded']),
    ]);

    $result = runWebhookAudit(['--connection' => $connection->id, '--json' => true]);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($result['output'], true);

    expect($decoded['gap_count'])->toBe(1)
        ->and($decoded['error_count'])->toBe(0)
        ->and($decoded['connections'][0]['status'])->toBe('gap')
        ->and($decoded['connections'][0]['missing_events'])->toBe([
            'charge.refunded',
            'charge.dispute.*',
            'payout.paid',
            'payout.failed',
        ])
        ->and($result['code'])->toBe(0);
});
