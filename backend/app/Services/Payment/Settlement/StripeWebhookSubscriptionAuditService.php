<?php

namespace App\Services\Payment\Settlement;

use App\Models\PaymentGatewayConnection;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Stripe\StripeConnectScope;
use App\Services\Payment\ProviderEvent\GatewayConnectionDataFactory;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan-050 T2.4 (#1978) — compare the webhook endpoints REGISTERED at Stripe
 * against the events the settlement layer needs, per connection.
 *
 * This is the pre-emptive twin of `SettlementReconciliationService` direction
 * A: a missing subscription shows up there only as money that never arrived,
 * days late and indistinguishable from a genuine gateway delay. Here it shows
 * up as a named event nobody is listening for.
 *
 * Rules that are deliberate:
 *
 * - Coverage is the UNION across the connection's endpoints. Splitting
 *   payout events onto a second endpoint is a legitimate setup.
 * - Only `status = enabled` endpoints count. A disabled endpoint delivers
 *   nothing, so its subscriptions are worth exactly zero — it is reported
 *   separately so the operator sees WHY the events read as missing.
 * - `*` in `enabled_events` covers everything (Stripe's own "all events").
 * - A required entry ending in `.*` is a FAMILY: Stripe accepts only concrete
 *   names or `*`, so `charge.dispute.*` is satisfied by any subscription
 *   starting with `charge.dispute.`.
 * - A connection whose listing call fails is reported as an ERROR, never as
 *   "everything missing" — we did not look, so we do not accuse. The sweep
 *   continues to the next connection (fail-open per connection, same shape
 *   as the settlement alert step).
 *
 * SCOPE CAVEAT — see StripeSettlementClient::listWebhookEndpoints(): each
 * connection is read in its own Stripe account scope, and a platform-level
 * Connect endpoint is not visible from a connected account.
 */
final class StripeWebhookSubscriptionAuditService
{
    public function __construct(
        private readonly StripeSettlementClient $client,
    ) {}

    /**
     * @return list<array{
     *     connection_id: string,
     *     provider: string,
     *     environment: string,
     *     account_scope: string,
     *     status: string,
     *     endpoints: list<array{id: string, url: string, status: string, enabled_events: list<string>}>,
     *     enabled_endpoint_count: int,
     *     disabled_endpoint_ids: list<string>,
     *     required_events: list<string>,
     *     missing_events: list<string>,
     *     error: string|null,
     * }>
     */
    public function audit(?string $connectionId = null): array
    {
        /** @var list<string> $required */
        $required = array_values(array_map(
            'strval',
            (array) config('payments.settlement.required_webhook_events.stripe', []),
        ));

        $report = [];

        foreach ($this->stripeConnections($connectionId) as $connection) {
            $report[] = $this->auditConnection($connection, $required);
        }

        Log::channel('payment_orchestration')->info('stripe_webhook_subscription_audit', [
            'connection_id' => $connectionId,
            'connection_count' => count($report),
            'gap_count' => count(array_filter($report, static fn (array $row): bool => $row['status'] === 'gap')),
            'error_count' => count(array_filter($report, static fn (array $row): bool => $row['status'] === 'error')),
        ]);

        return $report;
    }

    /**
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function auditConnection(PaymentGatewayConnection $connection, array $required): array
    {
        $base = [
            'connection_id' => (string) $connection->id,
            'provider' => PaymentGatewayProviderCodeEnum::Stripe->value,
            'environment' => $connection->environment instanceof \BackedEnum
                ? (string) $connection->environment->value
                : (string) $connection->environment,
            'account_scope' => StripeConnectScope::summaryFields(
                GatewayConnectionDataFactory::fromModel($connection),
            )['connect_account_scope'],
            'required_events' => $required,
        ];

        try {
            $endpoints = $this->client->listWebhookEndpoints($connection);
        } catch (Throwable $exception) {
            Log::channel('payment_orchestration')->warning('stripe_webhook_subscription_audit_failed', [
                'connection_id' => (string) $connection->id,
                'exception' => $exception::class,
            ]);

            return $base + [
                'status' => 'error',
                'endpoints' => [],
                'enabled_endpoint_count' => 0,
                'disabled_endpoint_ids' => [],
                'missing_events' => [],
                'error' => $exception::class.': '.$exception->getMessage(),
            ];
        }

        $rows = [];
        $disabled = [];
        $subscribed = [];

        foreach ($endpoints as $endpoint) {
            $status = strtolower((string) ($endpoint->status ?? ''));
            /** @var list<string> $events */
            $events = array_values(array_map('strval', (array) ($endpoint->enabled_events ?? [])));

            $rows[] = [
                'id' => (string) ($endpoint->id ?? ''),
                'url' => (string) ($endpoint->url ?? ''),
                'status' => $status,
                'enabled_events' => $events,
            ];

            if ($status !== 'enabled') {
                $disabled[] = (string) ($endpoint->id ?? '');

                continue;
            }

            foreach ($events as $event) {
                $subscribed[] = $event;
            }
        }

        $missing = [];
        $partialFamilies = [];

        foreach ($required as $pattern) {
            if (! $this->isCovered($pattern, $subscribed)) {
                $missing[] = $pattern;

                continue;
            }

            // Một yêu cầu dạng HỌ (`charge.dispute.*`) khớp bởi MỘT thành viên
            // vẫn là khớp một phần. Trước đây chỗ này trả thẳng `ok`, tức đăng ký
            // mỗi `charge.dispute.created` cũng báo xanh trong khi thiếu
            // `.closed` / `.funds_withdrawn` — một chữ `ok` nói quá.
            //
            // Không siết bằng cách liệt kê đủ họ: danh sách event của Stripe đổi
            // theo thời gian, và bịa ra nó chính là đoán hợp đồng bên thứ ba —
            // thứ đã cấm ở chính issue này. Chỉ nói ĐÃ THẤY GÌ, rồi để người đọc
            // xét, và không gọi đó là `ok`.
            $members = $this->familyMembers($pattern, $subscribed);

            if ($members !== null) {
                $partialFamilies[$pattern] = $members;
            }
        }

        $status = 'ok';
        if ($missing !== []) {
            $status = 'gap';
        } elseif ($partialFamilies !== []) {
            $status = 'partial';
        }

        return $base + [
            'status' => $status,
            'endpoints' => $rows,
            'enabled_endpoint_count' => count($rows) - count($disabled),
            'disabled_endpoint_ids' => $disabled,
            'missing_events' => $missing,
            'partial_families' => $partialFamilies,
            'error' => null,
        ];
    }

    /**
     * Với một yêu cầu dạng HỌ, trả về những thành viên CỤ THỂ đang được đăng ký.
     *
     * Trả `null` khi yêu cầu không phải họ, hoặc khi họ được phủ bằng `*` — hai
     * trường hợp đó không có gì "một phần" để báo.
     *
     * @param  list<string>  $subscribed
     * @return list<string>|null
     */
    private function familyMembers(string $pattern, array $subscribed): ?array
    {
        if (! str_ends_with($pattern, '.*') || in_array('*', $subscribed, true)) {
            return null;
        }

        $prefix = substr($pattern, 0, -1);

        return array_values(array_filter(
            $subscribed,
            static fn (string $event): bool => str_starts_with($event, $prefix),
        ));
    }

    /**
     * @param  list<string>  $subscribed
     */
    private function isCovered(string $pattern, array $subscribed): bool
    {
        if (in_array('*', $subscribed, true)) {
            return true;
        }

        if (in_array($pattern, $subscribed, true)) {
            return true;
        }

        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -1);

            foreach ($subscribed as $event) {
                if (str_starts_with($event, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return Collection<int, PaymentGatewayConnection>
     */
    private function stripeConnections(?string $connectionId)
    {
        return PaymentGatewayConnection::query()
            ->with('provider')
            ->whereHas('provider', fn ($query) => $query->where('code', PaymentGatewayProviderCodeEnum::Stripe->value))
            ->where('is_active', true)
            ->when($connectionId !== null, fn ($query) => $query->where('id', $connectionId))
            ->orderBy('created_at')
            ->get();
    }
}
