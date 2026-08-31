<?php

namespace App\Services\Payment\ProviderEvent;

use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Enums\ConnectionLookupKind;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentGatewayProvider;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Plan-048 Gate 3 — pick the PaymentGatewayConnection a provider webhook
 * belongs to, per WEBHOOKS.md connection-resolution strategies.
 *
 * ## #2938 — file này KHÔNG còn biết tên nhà cung cấp nào
 *
 * Trước #2938 nó phân giải bằng `match ($provider)` rồi gọi `resolveStripe()` /
 * `resolvePayPay()` ngay trong chính nó — tức kiến thức riêng của từng nhà cung
 * cấp rò ra ngoài adapter, và nhà cung cấp thứ tư phải sửa file dùng chung này.
 * Đúng thứ `PaymentGatewayRegistry` sinh ra để tránh.
 *
 * Nay đường đi chỉ còn ba nhịp: **hỏi adapter → tra DB → không có thì `null`**.
 *
 * Lý do lịch sử của cái `match` thì vẫn đúng và được giữ nguyên trong thiết kế:
 * phải biết connection TRƯỚC mới biết dùng webhook secret nào để xác minh, nên
 * không gọi được một adapter instance đã gắn với connection. Vì thế
 * {@see PaymentGatewayContract::identifyConnection()}
 * là phép KHÔNG TRẠNG THÁI: adapter chỉ đọc payload và mô tả **cách tra**, còn
 * mọi lượt chạm DB nằm ở đây.
 *
 * Trả `null` khi không khớp gì — chỗ gọi phải coi đó là thất bại xác minh
 * (fail-closed), không bao giờ đoán.
 */
final class WebhookConnectionResolver
{
    public function __construct(
        private readonly LegacyGlobalStripeConnection $legacyStripe,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
    ) {}

    public function resolve(
        PaymentGatewayProviderCodeEnum $provider,
        string $payload,
        ?string $connectionHint,
    ): ?PaymentGatewayConnection {
        $decoded = json_decode($payload, true);
        /** @var array<string, mixed> $decoded */
        $decoded = is_array($decoded) ? $decoded : [];

        $locator = $this->identify($provider, $decoded);

        if ($connectionHint !== null) {
            return $this->resolveFromHint($provider, $connectionHint, $locator);
        }

        if ($locator === null) {
            return null;
        }

        foreach ($locator->lookups as $lookup) {
            $match = $this->runLookup($provider, $lookup);

            if ($match !== null) {
                return $this->announce($match, $lookup);
            }

            // #1109 — merchant BIẾT nhưng đã tắt (`is_active=false`) phải TỪ
            // CHỐI, không rerouting ngầm sang connection khác: tắt kích hoạt là
            // công tắc chặn thu, không phải gợi ý để hệ thống đi tìm đường khác.
            if ($this->halts($provider, $lookup)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Hỏi adapter của nhà cung cấp: sự kiện này thuộc connection nào?
     *
     * Provider chưa đăng ký driver (SBPS, #1796 — CỐ Ý vắng khỏi
     * `config/payments.php`) thì không ai biết cách đọc payload của nó, nên câu
     * trả lời đúng là TỪ CHỐI. Bắt ngoại lệ tại đây chứ không để nó bay lên:
     * `PaymentProviderWebhookController` trả 5xx cho mọi `\Throwable`, mà một
     * webhook gửi tới nhà cung cấp chưa bật KHÔNG phải lỗi của ta.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function identify(PaymentGatewayProviderCodeEnum $provider, array $decoded): ?ConnectionLocator
    {
        try {
            $gateway = $this->gatewayRegistry->forProvider(
                $provider,
                'webhook-connection-resolve:'.$provider->value,
            );
        } catch (UnsupportedPaymentGatewayProvider) {
            return null;
        }

        return $gateway->identifyConnection($decoded);
    }

    private function resolveFromHint(
        PaymentGatewayProviderCodeEnum $provider,
        string $connectionHint,
        ?ConnectionLocator $locator,
    ): ?PaymentGatewayConnection {
        if (! Str::isUuid($connectionHint)) {
            return null;
        }

        $hinted = $this->activeConnections($provider)
            ->where('id', $connectionHint)
            ->first();

        if ($hinted === null) {
            return null;
        }

        // The hint may narrow resolution but never override the event's own
        // merchant identity: a Connect event whose `account` differs from the
        // hinted connection is rejected (prevents re-POSTing a genuinely signed
        // event under another connection's identity).
        //
        // #2938 — vế này từng viết cứng cho Stripe (`$decoded['account']`).
        // Nay chính adapter khai định danh mà **sự kiện tự khai** qua
        // `bindingMerchantAccountIds`; danh sách rỗng ⇒ không có gì để đối
        // chiếu ⇒ gợi ý đi tiếp như cũ. Nhà cung cấp nào mà merchant id không
        // phân biệt được tenant (PayPay) thì để rỗng có chủ đích — khai bừa
        // vào đó sẽ dựng một rào an ninh giả, chặn nhầm mà không chứng minh gì.
        $binding = $locator?->bindingMerchantAccountIds ?? [];
        if ($binding !== [] && ! in_array((string) $hinted->merchant_account_id, $binding, true)) {
            return null;
        }

        return $hinted;
    }

    private function runLookup(
        PaymentGatewayProviderCodeEnum $provider,
        ConnectionLookup $lookup,
    ): ?PaymentGatewayConnection {
        return match ($lookup->kind) {
            ConnectionLookupKind::MerchantAccount => $this->byMerchantAccount($provider, $lookup),
            ConnectionLookupKind::ProviderObjectReference => $this->byProviderObjectReference($provider, $lookup),
            ConnectionLookupKind::SoleActiveConnection => $this->bySoleActiveConnection($provider),
            ConnectionLookupKind::Designated => $this->byDesignatedId($lookup),
        };
    }

    private function byMerchantAccount(
        PaymentGatewayProviderCodeEnum $provider,
        ConnectionLookup $lookup,
    ): ?PaymentGatewayConnection {
        foreach ($lookup->values as $accountId) {
            $active = $this->merchantMatches($provider, $lookup, $accountId)
                ->first(fn (PaymentGatewayConnection $c): bool => (bool) $c->is_active);

            if ($active !== null) {
                return $active;
            }
        }

        return null;
    }

    /**
     * `payment_attempts.provider_object_id` mang mã tham chiếu do CHÍNH TA
     * sinh, nên nó chỉ đích danh connection.
     *
     * Chỉ attempt ĐẦU TIÊN tìm được mới được dùng: nếu connection của nó không
     * còn bật thì dừng phép tra này (`break`) chứ không thử tiếp khoá sau —
     * giữ nguyên hành vi trước #2938. Thử tiếp nghĩa là lấy đơn của một lượt
     * khác làm bằng chứng cho lượt này.
     */
    private function byProviderObjectReference(
        PaymentGatewayProviderCodeEnum $provider,
        ConnectionLookup $lookup,
    ): ?PaymentGatewayConnection {
        foreach ($lookup->values as $reference) {
            $attempt = PaymentAttempt::query()
                ->where('provider_object_id', $reference)
                ->first();

            if ($attempt === null) {
                continue;
            }

            return $this->activeConnections($provider)
                ->whereKey($attempt->connection_id)
                ->first();
        }

        return null;
    }

    private function bySoleActiveConnection(PaymentGatewayProviderCodeEnum $provider): ?PaymentGatewayConnection
    {
        $candidates = $this->activeConnections($provider)->limit(2)->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Lưới cuối trỏ đích danh một hàng.
     *
     * Hàng tổng hợp {@see LegacyGlobalStripeConnection} là hàng DUY NHẤT hệ
     * thống tự dựng khi vắng, nên nó phải được dựng ở đây — nó là chủ sở hữu
     * lịch sử của 968 bản ghi tiền và `payment_settlements.connection_id` còn
     * FK vào nó. Điều kiện là một **id hàng**, KHÔNG phải mã nhà cung cấp: thêm
     * nhà cung cấp thứ tư không phải sửa dòng này.
     */
    private function byDesignatedId(ConnectionLookup $lookup): ?PaymentGatewayConnection
    {
        $connectionId = $lookup->values[0] ?? null;

        if ($connectionId === null) {
            return null;
        }

        $existing = PaymentGatewayConnection::query()->find($connectionId);

        if ($existing !== null) {
            return $existing;
        }

        return $connectionId === LegacyGlobalStripeConnection::CONNECTION_ID
            ? $this->legacyStripe->resolveModel()
            : null;
    }

    /**
     * Phép tra này có quyền chặn đứng cả locator không?
     *
     * Chỉ đúng khi nó khai `haltWhenOnlyInactiveMatches` VÀ thật sự có hàng
     * khớp nhưng tất cả đã tắt (#1109).
     */
    private function halts(PaymentGatewayProviderCodeEnum $provider, ConnectionLookup $lookup): bool
    {
        if (! $lookup->haltWhenOnlyInactiveMatches) {
            return false;
        }

        foreach ($lookup->values as $accountId) {
            if ($this->merchantMatches($provider, $lookup, $accountId)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hàng khớp định danh merchant — CẢ đang bật lẫn đã tắt.
     *
     * Cố ý không lọc `is_active` ở tầng query: phân biệt "không biết merchant
     * này" với "biết nhưng đã tắt" chính là vế #1109, và một câu query lọc sẵn
     * sẽ xoá mất phân biệt đó.
     *
     * @return Collection<int, PaymentGatewayConnection>
     */
    private function merchantMatches(
        PaymentGatewayProviderCodeEnum $provider,
        ConnectionLookup $lookup,
        string $accountId,
    ) {
        return $this->connections($provider)
            ->where('merchant_account_id', $accountId)
            ->when(
                $lookup->environment !== null,
                fn (Builder $query) => $query->where('environment', $lookup->environment->value),
            )
            ->get();
    }

    private function announce(PaymentGatewayConnection $connection, ConnectionLookup $lookup): PaymentGatewayConnection
    {
        if ($lookup->warningEvent !== null) {
            Log::channel('payment_orchestration')->warning($lookup->warningEvent, $lookup->warningContext);
        }

        return $connection;
    }

    /** @return Builder<PaymentGatewayConnection> */
    private function connections(PaymentGatewayProviderCodeEnum $provider)
    {
        return PaymentGatewayConnection::query()
            ->whereHas('provider', fn ($query) => $query->where('code', $provider->value));
    }

    /** @return Builder<PaymentGatewayConnection> */
    private function activeConnections(PaymentGatewayProviderCodeEnum $provider)
    {
        return $this->connections($provider)->where('is_active', true);
    }
}
