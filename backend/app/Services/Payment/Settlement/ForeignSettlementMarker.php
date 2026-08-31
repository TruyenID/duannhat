<?php

namespace App\Services\Payment\Settlement;

use App\Models\PaymentSettlement;
use App\Services\Customer\StripePaymentService;
use App\Services\Payment\ProviderEvent\StripeIntentOrigin;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use Illuminate\Support\Facades\Log;

/**
 * #2864 phần 2 — đánh dấu hàng settlement là tiền của merchant KHÁC.
 *
 * ## Vì sao tồn tại
 *
 * Tài khoản Stripe production dùng CHUNG với trang đặt món WooCommerce riêng
 * của quán, nên tầng ingest đã nuốt tiền của họ vào sổ dưới trạng thái
 * `orphan`. Đo 2026-08-14: **202 hàng, ¥366.643 gross, ¥13.194 phí**, tăng
 * ~35–50 hàng/ngày kể từ 2026-08-10. Bản vá chặn tại nguồn (#2867) chỉ chặn
 * hàng MỚI.
 *
 * ## Vì sao ở tầng service chứ không nằm trong Console command
 *
 * `architecture:domain-writers` chỉ công nhận writer của aggregate `payment` ở
 * tầng service; một `update()` viết trong `app/Console/Commands/` bị đếm là
 * đường ghi thô. Rào đó đúng — và bản đầu của vá này viết ở command rồi bị
 * bắt. Command giờ chỉ còn là vỏ CLI.
 *
 * ## Vì sao phải hỏi Stripe
 *
 * Hàng settlement chỉ mang `provider_object_id` (pi_) + `charge_id`, KHÔNG
 * mang `metadata.order_id`. "Của ta nhưng chưa khớp" và "của merchant khác"
 * nhìn từ dữ liệu local là y hệt nhau — đó chính là lý do cả hai cùng rơi vào
 * `orphan`. Phép phân biệt duy nhất nằm ở metadata bên Stripe.
 *
 * ## Hướng an toàn
 *
 * CHỈ `ForeignAccount` bị đánh dấu. `Unknown` (intent không mang
 * `metadata.order_id`) và mọi lượt gọi Stripe hỏng đều **giữ nguyên `orphan`**:
 * bỏ sót một hàng của hệ khác chỉ là rác trong sổ, còn đánh dấu nhầm một hàng
 * của TA là giấu mất một khoản tiền thật khỏi đối soát.
 *
 * ## #2981 — nhánh thứ hai: hàng KHÔNG CÓ intent
 *
 * Nhánh trên giả định mọi hàng đều tra được về một PaymentIntent. Có một loại
 * không: **điều chỉnh phí của chính Stripe**, ingest từ payout listing với
 * metadata `{"raw_type":"adjustment"}` và **không** `provider_object_id`. Bản
 * đầu đếm chúng vào `unknown` rồi bỏ qua — đúng theo hướng an toàn, nhưng hệ
 * quả là chúng nằm lại `orphan` VĨNH VIỄN, vì không có payment nào sẽ tới nhận
 * một dòng thuế trên hoá đơn phí.
 *
 * Với loại đó, câu hỏi phân biệt không phải "đơn này của ai" mà "đây có phải
 * tiền hàng không", và Stripe trả lời được bằng `reporting_category` trên
 * chính balance transaction. Vẫn giữ nguyên hướng an toàn: chỉ đúng
 * `reporting_category === 'fee'` mới đổi trạng thái; mọi giá trị khác, và mọi
 * lượt gọi hỏng, giữ nguyên `orphan`.
 */
final readonly class ForeignSettlementMarker
{
    public function __construct(
        private StripePaymentService $stripe,
        private StripeSettlementClient $settlementClient,
    ) {}

    /**
     * @return array{scanned: int, foreign: int, fee_adjustment: int, tempo: int, unknown: int,
     *     errors: int, gross_minor: int, fee_minor: int, marked: list<array<string, mixed>>}
     */
    public function sweep(?string $connectionId, int $limit, bool $apply): array
    {
        $rows = PaymentSettlement::query()
            // #2981 — rows without an intent need their owning connection for
            // the balance-transaction lookup. Eager-load it once so a manual
            // sweep of several adjustments does not add a DB query per row.
            ->with('connection')
            ->where('status', SettlementStatus::Orphan->value)
            ->when($connectionId !== null, fn ($q) => $q->where('connection_id', $connectionId))
            ->orderBy('provider_settled_at')
            ->limit(max(1, $limit))
            ->get();

        $result = [
            'scanned' => $rows->count(),
            'foreign' => 0, 'fee_adjustment' => 0, 'tempo' => 0, 'unknown' => 0, 'errors' => 0,
            'gross_minor' => 0, 'fee_minor' => 0, 'marked' => [],
        ];

        foreach ($rows as $row) {
            $metadata = is_array($row->metadata) ? $row->metadata : [];
            $intentId = $metadata['provider_object_id'] ?? null;

            if (! is_string($intentId) || $intentId === '') {
                // #2981 — không có intent thì chưa kết luận được gì; hỏi Stripe
                // xem dòng này có phải TIỀN HÀNG không trước khi bỏ qua.
                $this->classifyWithoutIntent($row, $apply, $result);

                continue;
            }

            try {
                $intent = $this->stripe->retrieveIntent($intentId);
            } catch (\Throwable $e) {
                // Một lượt gọi hỏng KHÔNG được biến thành một kết luận.
                $result['errors']++;
                Log::channel('payment_orchestration')->warning('mark_foreign_retrieve_failed', [
                    'settlement_id' => (string) $row->id,
                    'payment_intent' => $intentId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $origin = StripeIntentOrigin::fromOrderIdMetadata(
                is_object($intent->metadata ?? null) ? ($intent->metadata->order_id ?? null) : null,
            );

            if ($origin !== StripeIntentOrigin::ForeignAccount) {
                $result[$origin === StripeIntentOrigin::Tempo ? 'tempo' : 'unknown']++;

                continue;
            }

            $result['foreign']++;
            $result['gross_minor'] += (int) $row->gross_minor;
            $result['fee_minor'] += (int) $row->fee_minor;
            $result['marked'][] = [
                'settlement_id' => (string) $row->id,
                'payment_intent' => $intentId,
                'gross_minor' => (int) $row->gross_minor,
                'fee_minor' => (int) $row->fee_minor,
            ];

            if ($apply) {
                // `update()` — cùng lối mà `SettlementReconciliationService`
                // dùng khi re-match orphan. Không mở đường ghi thứ hai.
                $row->update(['status' => SettlementStatus::Foreign->value]);
            }
        }

        return $result;
    }

    /**
     * Hàng không có PaymentIntent — phân loại bằng `reporting_category` của
     * chính balance transaction.
     *
     * Đi qua `StripeSettlementClient` chứ không `StripePaymentService`: port
     * này là bề mặt Stripe DUY NHẤT của tầng settlement và mọi lời gọi của nó
     * đã được scope theo đúng connection sở hữu, nên không có đường nào đọc
     * nhầm số dư của tài khoản khác.
     *
     * @param  array{foreign: int, fee_adjustment: int, tempo: int, unknown: int, errors: int,
     *     gross_minor: int, fee_minor: int, marked: list<array<string, mixed>>, scanned: int}  $result
     */
    private function classifyWithoutIntent(PaymentSettlement $row, bool $apply, array &$result): void
    {
        $ref = (string) $row->external_ref;

        // Chỉ balance transaction mới hỏi được kiểu này. Ref dạng khác thì
        // KHÔNG đoán — giữ nguyên `orphan`, đúng hướng an toàn của lớp này.
        if (! str_starts_with($ref, 'txn_')) {
            $result['unknown']++;

            return;
        }

        $connection = $row->connection;

        if ($connection === null) {
            $result['unknown']++;

            return;
        }

        try {
            $txn = $this->settlementClient->retrieveBalanceTransaction($connection, $ref);
        } catch (\Throwable $e) {
            // Cùng luật với nhánh intent: một lượt gọi hỏng KHÔNG được biến
            // thành một kết luận.
            $result['errors']++;
            Log::channel('payment_orchestration')->warning('mark_fee_adjustment_retrieve_failed', [
                'settlement_id' => (string) $row->id,
                'balance_transaction' => $ref,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // `fee` là từ vựng của Stripe cho "đây là phí/điều chỉnh phí của
        // Stripe", tách bạch với `charge`/`refund`/`payout`. Chỉ đúng giá trị
        // này mới đổi trạng thái — `reporting_category` lạ thì giữ `orphan` để
        // có người nhìn, thay vì im lặng nuốt một khoản chưa hiểu.
        if (($txn->reporting_category ?? null) !== 'fee') {
            $result['unknown']++;

            return;
        }

        $result['fee_adjustment']++;
        $result['marked'][] = [
            'settlement_id' => (string) $row->id,
            'balance_transaction' => $ref,
            'reporting_category' => 'fee',
            'gross_minor' => (int) $row->gross_minor,
            'fee_minor' => (int) $row->fee_minor,
        ];

        if ($apply) {
            $row->update(['status' => SettlementStatus::FeeAdjustment->value]);
        }
    }
}
