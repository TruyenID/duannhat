<?php

namespace App\Services\Payment\Settlement;

use App\Models\GatewayPayout;
use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentRefund;
use App\Models\PaymentSettlement;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\ProviderEvent\StripePlatformAccount;
use App\Services\Payment\Settlement\Exceptions\SettlementAttributionRefused;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * #2893 — chuyển quy thuộc bản ghi tiền Stripe từ hàng connection TỔNG HỢP
 * sang hàng connection THẬT, và đóng dấu định danh PSP thật lên hàng thật.
 *
 * ## Vì sao phải có bước này
 *
 * `WebhookConnectionResolver` nay phân giải sự kiện tài khoản nền về hàng
 * connection mang đúng `acct_…` (xem {@see StripePlatformAccount}). Bản vá đó
 * chỉ chặn hàng MỚI. Bản ghi đã sinh — đo trên production 2026-08-15: **747**
 * `payment_settlements`, **220** `payment_provider_events`, **1**
 * `gateway_payouts` — vẫn trỏ vào hàng tổng hợp, thuộc một tổ chức không có
 * thành viên nào, nên `SettlementController` (lọc theo org+brand của người
 * đăng nhập) trả về RỖNG cho chính chủ sở hữu ¥939.235.
 *
 * ## Ba việc, một lượt, đúng thứ tự
 *
 *  1. **đóng dấu `merchant_account_id`** = tài khoản Stripe THẬT. Đây là vế
 *     "chuẩn ngành": tài khoản PSP này dùng CHUNG với một hệ khác (#2864), nên
 *     nhãn nội bộ `orchestrator:customer-web:{org}` không đối soát payout được.
 *     Đóng dấu trước, vì chính nó là khoá mà đường webhook dùng để phân giải;
 *  2. **chuyển `connection_id`** của ba bảng bản ghi tiền;
 *  3. **ngưng dùng** hàng tổng hợp (`is_active=false`) — KHÔNG xoá: nó là chủ
 *     sở hữu lịch sử, và `payment_settlements.connection_id` còn FK vào nó.
 *
 * ## Cái KHÔNG đụng, và vì sao
 *
 * `order_payments` là ĐƯỜNG TIỀN — sổ cái, không phải sổ đối soát. Đo được 0
 * hàng trỏ vào hàng tổng hợp, nên không có gì phải sửa; và kể cả có, sửa nó là
 * việc khác với việc này. Ở đây nó chỉ được ĐẾM và in ra (cùng
 * `payment_attempts`/`payment_refunds`) để người chạy thấy còn sót gì.
 *
 * Lệnh chạy lại được: mọi bước đều điều kiện hoá theo trạng thái hiện tại, nên
 * lượt hai không đổi gì thêm.
 */
final class SettlementAttributionMigrator
{
    /**
     * Hàng connection TỔNG HỢP đã ngưng dùng — id cố định, do
     * `App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection`
     * sinh ra.
     *
     * Ghi lại giá trị ở đây thay vì tham chiếu hằng của lớp đó, vì
     * `LegacyIdentifierBanTest` (#2188) cấm định danh /legacy/i trong MÃ mới
     * dưới `app/` và danh sách miễn trừ của nó chỉ được TEO. Rủi ro trôi giữa
     * hai chỗ được ghim bằng test: `StripeAttributionMigrationTest` gieo dữ
     * liệu lên đúng hàng mà lớp kia dựng, rồi đòi lệnh này dọn sạch nó.
     *
     * #2969 — nay **public**: rào deploy `deploy:verify-stripe-attribution` cần
     * đúng danh tính này để hỏi "tiền còn đang rơi vào đây không", và vì cùng
     * lý do trên nó cũng không được tham chiếu lớp kia. Một hằng, một chủ — hai
     * chỗ tự gõ lại cùng một UUID là chỗ chúng trôi khỏi nhau.
     */
    public const RETIRED_CONNECTION_ID = '00000000-0000-4000-8000-000000000001';

    /**
     * @return array{
     *     applied: bool,
     *     source_present: bool,
     *     retired_connection_id: string,
     *     target_connection_id: ?string,
     *     platform_account_id: ?string,
     *     merchant_account: array{before: ?string, after: ?string, stamped: bool},
     *     before: array<string, int>,
     *     after: array<string, int>,
     *     planned: array<string, int>,
     *     moved: array<string, int>,
     *     skipped_provider_events: int,
     *     residual: array<string, int>,
     *     retired: bool,
     * }
     */
    public function migrate(?string $targetConnectionId, bool $apply): array
    {
        $source = PaymentGatewayConnection::query()->find(self::RETIRED_CONNECTION_ID);

        if ($source === null) {
            return $this->emptyResult($apply);
        }

        $target = $this->resolveTarget($source, $targetConnectionId);
        $platformAccountId = StripePlatformAccount::accountId();

        if ($apply && $platformAccountId === null) {
            // Fail-closed: chuyển hàng mà không đóng dấu định danh thật thì
            // đường webhook vẫn không phân giải được về đây, và lượt sau lại
            // đẻ ra đúng đống hàng vừa dọn.
            throw new SettlementAttributionRefused(
                'STRIPE_ACCOUNT_ID chưa khai (hoặc sai dạng acct_…). '
                .'Không có nó thì đường webhook không phân giải được về connection thật, '
                .'nên chuyển hàng bây giờ chỉ dọn được một lần rồi hàng mới lại rơi vào chỗ cũ.',
            );
        }

        $merchantBefore = (string) $target->merchant_account_id;
        $stamped = false;

        if ($platformAccountId !== null && $merchantBefore !== $platformAccountId) {
            if (preg_match('/^acct_[A-Za-z0-9_]+$/', $merchantBefore) === 1) {
                throw new SettlementAttributionRefused(sprintf(
                    'Connection đích %s đang mang tài khoản Stripe KHÁC (%s ≠ %s). '
                    .'Đó là một merchant thật, không phải nhãn nội bộ — đè lên là đổi danh tính một cổng đang chạy.',
                    (string) $target->id,
                    $merchantBefore,
                    $platformAccountId,
                ));
            }

            $stamped = true;
        }

        $before = $this->countsOn((string) $source->id);
        $skippedEventIds = $this->collidingProviderEventIds($source, $target);

        if ($apply) {
            DB::transaction(function () use ($source, $target, $platformAccountId, $stamped, $skippedEventIds): void {
                if ($stamped && $platformAccountId !== null) {
                    $target->update(['merchant_account_id' => $platformAccountId]);
                }

                PaymentSettlement::query()
                    ->where('connection_id', $source->id)
                    ->update(['connection_id' => $target->id]);

                GatewayPayout::query()
                    ->where('connection_id', $source->id)
                    ->update(['connection_id' => $target->id]);

                // Hàng inbox mang CẢ `organization_id` (đóng dấu lúc nhận từ
                // `MutationContext`), nên chuyển connection mà bỏ quên nó là để
                // lại một bản ghi tự mâu thuẫn.
                PaymentProviderEvent::query()
                    ->where('connection_id', $source->id)
                    ->when($skippedEventIds !== [], fn ($query) => $query->whereNotIn('id', $skippedEventIds))
                    ->update([
                        'connection_id' => $target->id,
                        'organization_id' => $target->organization_id,
                    ]);

                if ((bool) $source->is_active) {
                    $source->update(['is_active' => false]);
                }
            });
        }

        $after = $this->countsOn((string) $source->id);

        // Dry-run KHÔNG được báo `moved: 0` — đó là con số đúng về mặt số học
        // và vô dụng về mặt vận hành: người chạy dry-run hỏi "sẽ chuyển bao
        // nhiêu", không hỏi "vừa chuyển bao nhiêu".
        $planned = [
            'payment_settlements' => $before['payment_settlements'],
            'payment_provider_events' => $before['payment_provider_events'] - count($skippedEventIds),
            'gateway_payouts' => $before['gateway_payouts'],
        ];

        return [
            'applied' => $apply,
            'source_present' => true,
            'retired_connection_id' => (string) $source->id,
            'target_connection_id' => (string) $target->id,
            'platform_account_id' => $platformAccountId,
            'merchant_account' => [
                'before' => $merchantBefore,
                'after' => $apply && $stamped ? $platformAccountId : $merchantBefore,
                'stamped' => $stamped,
            ],
            'before' => $before,
            'after' => $after,
            'planned' => $planned,
            'moved' => [
                'payment_settlements' => $before['payment_settlements'] - $after['payment_settlements'],
                'payment_provider_events' => $before['payment_provider_events'] - $after['payment_provider_events'],
                'gateway_payouts' => $before['gateway_payouts'] - $after['gateway_payouts'],
            ],
            'skipped_provider_events' => count($skippedEventIds),
            'residual' => $this->residualOn((string) $source->id),
            'retired' => ! (bool) $source->fresh()?->is_active,
        ];
    }

    /**
     * Đích đến: chỉ định tay, hoặc — khi chỉ có MỘT ứng viên — tự suy ra.
     *
     * Tự suy ra chứ không ghim UUID vào mã: uuid trong mã là một sự thật của
     * MỘT lần cài đặt, và nó sẽ sai ở mọi lần cài đặt khác mà không ai biết.
     * Nhiều hơn một ứng viên thì DỪNG: chọn hộ là chọn tiền của ai về tay ai.
     */
    private function resolveTarget(PaymentGatewayConnection $source, ?string $targetConnectionId): PaymentGatewayConnection
    {
        if ($targetConnectionId !== null && $targetConnectionId !== '') {
            $target = PaymentGatewayConnection::query()->find($targetConnectionId);

            if ($target === null) {
                throw new SettlementAttributionRefused('Không tìm thấy connection đích '.$targetConnectionId.'.');
            }

            if ((string) $target->id === (string) $source->id) {
                throw new SettlementAttributionRefused('Connection đích không thể chính là hàng tổng hợp đang ngưng dùng.');
            }

            return $target;
        }

        $candidates = PaymentGatewayConnection::query()
            ->whereHas('provider', fn ($query) => $query->where('code', PaymentGatewayProviderCodeEnum::Stripe->value))
            ->where('is_active', true)
            ->where('environment', $this->enumValue($source->environment))
            ->whereKeyNot($source->id)
            ->limit(3)
            ->get();

        if ($candidates->count() !== 1) {
            throw new SettlementAttributionRefused(sprintf(
                'Không suy ra được connection đích: có %d connection Stripe đang hoạt động ở môi trường %s. '
                .'Truyền --to=<uuid> để chỉ đích danh.',
                $candidates->count(),
                (string) $this->enumValue($source->environment),
            ));
        }

        return $candidates->first();
    }

    /**
     * `payment_provider_events` có UNIQUE (connection_id, environment,
     * provider_event_id). Cùng một sự kiện đã tồn tại ở đích (giao hàng lặp
     * rơi vào hai connection khác nhau) thì chuyển sang sẽ vi phạm khoá — và
     * một lượt UPDATE vỡ giữa chừng còn tệ hơn bỏ sót vài hàng. Những hàng đó
     * ở lại chỗ cũ và được ĐẾM ra, để người chạy thấy chứ không phải đoán.
     *
     * @return list<string>
     */
    private function collidingProviderEventIds(PaymentGatewayConnection $source, PaymentGatewayConnection $target): array
    {
        $sourceEvents = PaymentProviderEvent::query()
            ->where('connection_id', $source->id)
            ->get(['id', 'environment', 'provider_event_id']);

        if ($sourceEvents->isEmpty()) {
            return [];
        }

        $existing = PaymentProviderEvent::query()
            ->where('connection_id', $target->id)
            ->whereIn('provider_event_id', $sourceEvents->pluck('provider_event_id')->all())
            ->get(['environment', 'provider_event_id'])
            ->map(fn (PaymentProviderEvent $row): string => $this->identityKey($row))
            ->all();

        if ($existing === []) {
            return [];
        }

        return $sourceEvents
            ->filter(fn (PaymentProviderEvent $row): bool => in_array($this->identityKey($row), $existing, true))
            ->map(fn (PaymentProviderEvent $row): string => (string) $row->id)
            ->values()
            ->all();
    }

    private function identityKey(PaymentProviderEvent $row): string
    {
        return $this->enumValue($row->environment).'|'.(string) $row->provider_event_id;
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    /** @return array<string, int> */
    private function countsOn(string $connectionId): array
    {
        return [
            'payment_settlements' => PaymentSettlement::query()->where('connection_id', $connectionId)->count(),
            'payment_provider_events' => PaymentProviderEvent::query()->where('connection_id', $connectionId)->count(),
            'gateway_payouts' => GatewayPayout::query()->where('connection_id', $connectionId)->count(),
        ];
    }

    /**
     * Chỉ ĐỌC. Ba bảng này không nằm trong phạm vi di trú — `order_payments` là
     * đường tiền và cố ý đứng ngoài — nhưng một con số khác 0 ở đây là thứ
     * người chạy phải nhìn thấy, không phải thứ để phát hiện sau ba tháng.
     *
     * @return array<string, int>
     */
    private function residualOn(string $connectionId): array
    {
        return [
            'order_payments' => OrderPayment::query()->where('gateway_connection_id', $connectionId)->count(),
            'payment_attempts' => PaymentAttempt::query()->where('connection_id', $connectionId)->count(),
            'payment_refunds' => PaymentRefund::query()->where('connection_id', $connectionId)->count(),
        ];
    }

    /**
     * @return array{
     *     applied: bool, source_present: bool, retired_connection_id: string,
     *     target_connection_id: ?string, platform_account_id: ?string,
     *     merchant_account: array{before: ?string, after: ?string, stamped: bool},
     *     before: array<string, int>, after: array<string, int>,
     *     planned: array<string, int>, moved: array<string, int>, skipped_provider_events: int,
     *     residual: array<string, int>, retired: bool,
     * }
     */
    private function emptyResult(bool $apply): array
    {
        $zero = ['payment_settlements' => 0, 'payment_provider_events' => 0, 'gateway_payouts' => 0];

        return [
            'applied' => $apply,
            'source_present' => false,
            'retired_connection_id' => self::RETIRED_CONNECTION_ID,
            'target_connection_id' => null,
            'platform_account_id' => StripePlatformAccount::accountId(),
            'merchant_account' => ['before' => null, 'after' => null, 'stamped' => false],
            'before' => $zero,
            'after' => $zero,
            'planned' => $zero,
            'moved' => $zero,
            'skipped_provider_events' => 0,
            'residual' => ['order_payments' => 0, 'payment_attempts' => 0, 'payment_refunds' => 0],
            'retired' => true,
        ];
    }
}
