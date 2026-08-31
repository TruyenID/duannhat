<?php

declare(strict_types=1);

namespace App\Services\Payment\Observation;

use App\Models\OrderPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * T3 của #2876 (#2880) — tra cứu giao dịch toàn kênh.
 *
 * ## Đây là nghĩa vụ pháp lý, không phải tiện ích
 *
 * **電子帳簿保存法 検索要件**: chứng từ giao dịch điện tử phải tra được theo
 * **取引年月日 · 取引金額 · 取引先**, và phải KẾT HỢP được từ hai trục trở lên.
 * Trước bản này hệ thống không đáp ứng trục nào ở tầng giao diện: có màn hình
 * settlement (phía cổng) nhưng không có chỗ nào trả lời "giao dịch X là gì".
 *
 * ## Ô `reference` là mảnh quan trọng nhất
 *
 * Một giao dịch mang tới SÁU loại mã khác nhau tuỳ đường đi, và người vận hành
 * cầm trong tay đúng một cái — thường là cái nhà cung cấp đưa. Bắt họ biết mã
 * đang cầm thuộc cột nào là bắt họ hiểu kiến trúc nội bộ.
 *
 * Đường tra CỐ Ý tránh `metadata->…`: nó là JSON không index và một lượt tra
 * qua đó quét toàn bảng `order_payments`. Mọi khoá dưới đây đều là CỘT THẬT —
 * `reference_no` đã mang mã Glory và mã intent của cổng, nên không mất gì.
 *
 * ## Phạm vi là ORGANIZATION + BRAND — KHÔNG có phân quyền theo chi nhánh
 *
 * Và đó là một khẳng định, không phải một thiếu sót (#2911).
 *
 * Bản đầu mang một tham số `allowed_branch_ids` với vòng `whereIn` đi kèm,
 * nhưng controller **không bao giờ truyền nó** — đo được: `grep -c` trên
 * controller ra 0. Nên nó là một **rào câm**: đọc lên tưởng có phân quyền theo
 * chi nhánh, chạy thì không lọc gì. Một chỗ *trông như đã được canh* nguy hiểm
 * hơn một chỗ trống, vì người sau sẽ không đi kiểm nữa.
 *
 * Đã gỡ. Màn HQ vốn là phạm vi brand — `CustomerOrderController` (HQ orders)
 * cũng nhận `branch_id` như **bộ lọc**, không như rào — nên mã bây giờ nói
 * đúng thứ nó làm.
 *
 * Muốn phân quyền theo chi nhánh ở HQ thì đó là quyết định CẮT NGANG mọi màn
 * HQ đọc tiền, không riêng màn này, và phải làm cẩn thận: `branch_id IS NULL`
 * nghĩa là **MỌI** chi nhánh (`all_branches_access`), không phải "không chi
 * nhánh nào" — đọc sai chiều đó sẽ khoá nhầm người khỏi dữ liệu của chính họ
 * (`docs/explanation/branch-isolation.md`, và tầng thông báo từng vi phạm đúng
 * chỗ này ở #2460).
 *
 * ## Chỉ ĐỌC
 *
 * Không có đường sửa nào ở đây, kể cả sửa trạng thái. Sửa tiền đi qua đường đã
 * có, có lý do và có audit — thêm một cửa thứ hai vào tiền là thêm một cửa
 * không ai canh.
 */
final class TransactionLookupService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = OrderPayment::query()
            ->with(['paymentAttempt', 'paymentMethod', 'branch'])
            ->where('organization_id', $filters['organization_id']);

        $this->applyScope($query, $filters);
        $this->applyDateWindow($query, $filters);
        $this->applyAmountWindow($query, $filters);
        $this->applyReference($query, $filters);

        return $query
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /**
     * @param  Builder<OrderPayment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyScope(Builder $query, array $filters): void
    {
        $query
            ->when($filters['brand_id'] ?? null, fn (Builder $q, $v) => $q->where('brand_id', $v))
            // 取引先 — "đối tác giao dịch". Với một quán, đối tác của một khoản
            // thu là CỔNG (Stripe/PayPay) hoặc chính quán (tiền mặt), nên ba
            // tham số dưới đây cùng phục vụ một trục của 検索要件.
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['tender_key'] ?? null, fn (Builder $q, $v) => $q->where('tender_key', $v))
            ->when($filters['till_session_id'] ?? null, fn (Builder $q, $v) => $q->where('till_session_id', $v))
            ->when($filters['provider'] ?? null, fn (Builder $q, $v) => $q->where('gateway_provider_snapshot', $v));
    }

    /**
     * 取引年月日 — và nó phải là BUSINESS TIME của chi nhánh, không phải UTC.
     *
     * Quán VN (UTC+7) và JP (UTC+9) chạy chung một backend UTC, nên "ngày
     * 15/08" không phải một khoảng toàn cục (#1091). Chỗ gọi đã quy đổi cận
     * sang UTC bằng `BusinessClock`; ở đây chỉ so timestamp.
     *
     * @param  Builder<OrderPayment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateWindow(Builder $query, array $filters): void
    {
        $query
            ->when($filters['from_utc'] ?? null, fn (Builder $q, $v) => $q->where('paid_at', '>=', $v))
            // Cận trên EXCLUSIVE — `utcRangeForBusinessDates` trả `addDay()`,
            // nên `<=` sẽ nuốt trọn ngày kế tiếp.
            ->when($filters['to_utc'] ?? null, fn (Builder $q, $v) => $q->where('paid_at', '<', $v));
    }

    /**
     * 取引金額 — KHOẢNG tiền, không phải giá trị chính xác.
     *
     * 検索要件 nói rõ là khoảng: người đi tra thường nhớ "khoảng ba nghìn yên",
     * không nhớ 2.980.
     *
     * @param  Builder<OrderPayment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyAmountWindow(Builder $query, array $filters): void
    {
        $query
            ->when($filters['amount_min'] ?? null, fn (Builder $q, $v) => $q->where('amount', '>=', $v))
            ->when($filters['amount_max'] ?? null, fn (Builder $q, $v) => $q->where('amount', '<=', $v));
    }

    /**
     * Một ô, sáu loại mã — xem docblock của lớp.
     *
     * @param  Builder<OrderPayment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyReference(Builder $query, array $filters): void
    {
        $ref = $filters['reference'] ?? null;

        if (! is_string($ref) || trim($ref) === '') {
            return;
        }

        $ref = trim($ref);

        $query->where(function (Builder $q) use ($ref): void {
            $q->where('reference_no', $ref)
                ->orWhere('idempotency_key', $ref)
                // Máy trạm đóng khoá dạng `glory:<mã>`, nhưng người vận hành
                // cầm mã trần trên phiếu của máy. Dựng lại tiền tố ở đây rẻ
                // hơn nhiều so với bắt họ biết quy ước nội bộ.
                ->orWhere('idempotency_key', 'glory:'.$ref)
                ->orWhere('payment_code', $ref)
                ->orWhereHas('paymentAttempt', function (Builder $a) use ($ref): void {
                    $a->where('provider_object_id', $ref)
                        ->orWhere('provider_request_key', $ref);
                });
        });
    }

    /**
     * Dòng trả về là ALLOWLIST, không phải `toArray()`.
     *
     * Cùng lý lẽ với `SettlementController::settlementPayload`: `toArray()` trả
     * mọi cột hiện có VÀ mọi cột thêm về sau, nên một cột nội bộ thêm sau này
     * tự rò ra API mà không ai sửa file này. Với dữ liệu tiền, mặc định phải là
     * "không trả gì trừ khi khai".
     *
     * KHÔNG có dữ liệu thẻ ở đây — PCI DSS: không lưu, không hiện PAN. Thứ duy
     * nhất về thẻ là `redacted_summary` của attempt, và nó đã được che ở nguồn.
     *
     * @return array<string, mixed>
     */
    public function payload(OrderPayment $row): array
    {
        $attempt = $row->paymentAttempt;

        return [
            'id' => (string) $row->id,
            'payment_code' => $row->payment_code,
            'amount' => (float) $row->amount,
            'tip_amount' => (float) $row->tip_amount,
            'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            'paid_at' => $row->paid_at?->toIso8601String(),
            'channel' => $row->channel,
            'tender_key' => $row->tender_key,
            'reference_no' => $row->reference_no,
            'branch_id' => (string) $row->branch_id,
            'customer_order_id' => $row->customer_order_id === null ? null : (string) $row->customer_order_id,
            'till_session_id' => $row->till_session_id === null ? null : (string) $row->till_session_id,
            // Snapshot bất biến của cổng, đóng dấu lúc thu — KHÔNG join lại
            // connection để lấy giá trị hiện tại. Quán đổi cổng sau đó thì bản
            // ghi cũ vẫn phải kể đúng chuyện đã xảy ra.
            'gateway' => [
                'provider' => $row->gateway_provider_snapshot,
                'environment' => $row->gateway_environment_snapshot,
                'currency' => $row->gateway_currency_snapshot,
                'amount_minor' => $row->gateway_amount_minor_snapshot,
                'state' => $row->gateway_state_snapshot,
                'provider_status' => $row->gateway_provider_status_snapshot,
            ],
            'attempt' => $attempt === null ? null : [
                'id' => (string) $attempt->id,
                'provider_object_id' => $attempt->provider_object_id,
                'provider_status' => $attempt->provider_status,
            ],
        ];
    }
}
