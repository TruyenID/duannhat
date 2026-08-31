<?php

declare(strict_types=1);

namespace App\Services\Till;

use App\Models\Branch;
use App\Models\CashDeviceTransaction;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\TillSession;
use App\Omnify\Enums\CashDeviceOutcomeEnum;
use App\Services\Order\Contracts\BranchOrderMembership;
use App\Services\PeripheralDevice\Contracts\BranchCashDevices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * T1 của #2876 (#2878) — nhận sổ lượt thu tiền ở máy 釣銭機 từ máy trạm.
 *
 * ## Vì sao có bảng này khi đã có `order_payments`
 *
 * `order_payments` chỉ có hàng khi thu ĐƯỢC tiền. Bốn kết cục còn lại của máy
 * — `cancel`, `abort`, `timeout`, `failure` — không sinh dòng tiền nào, nên
 * trước T1 chúng không để lại dấu vết nào trên Cloud. `timeout` là cái đắt
 * nhất: máy ĐANG GIỮ tiền của khách mà không sổ nào biết.
 *
 * ## Idempotency: theo (máy, mã giao dịch), KHÔNG theo id
 *
 * Máy trạm chạy offline dài ngày rồi đẩy bù, và một lô có thể đi lại nhiều
 * lần. Khoá tự nhiên là cặp `(peripheral_device_id, glory_transaction_id)` —
 * một máy không bao giờ phát lại một mã giao dịch. Dùng `id` do máy trạm sinh
 * sẽ đẻ hàng trùng ngay lượt gửi lại đầu tiên.
 *
 * ## Trọng tài khi gửi lại: `machine_seq_no`, không phải đồng hồ
 *
 * `seqNo` do ADAPTER phát (UNIX-ms). Máy trạm chạy offline nhiều ngày thì đồng
 * hồ của nó trôi, và một hàng cũ sẽ ghi đè hàng mới nếu lấy `created_at` làm
 * trọng tài. Seq nhỏ hơn hoặc bằng ⇒ BỎ QUA, không ghi đè.
 *
 * ## Không tin FK thiết bị gửi lên
 *
 * `order_payment_id` được phân giải Ở ĐÂY từ `idempotency_key = "glory:<mã>"`
 * (khoá mà `cash_changer_recorder.go` đã đóng khi ghi khoản thu), chứ không
 * nhận từ payload. Thiết bị khai một id thanh toán của chi nhánh khác thì đó
 * là gắn tiền sai chỗ — và Cloud có sẵn dữ kiện để tự phân giải nên không có
 * lý do gì phải tin.
 *
 * `customer_order_id` / `till_session_id` thì buộc phải nhận từ thiết bị (Cloud
 * không suy ra được), nên chúng bị KIỂM PHẠM VI: không thuộc chi nhánh của
 * thiết bị ⇒ bỏ giá trị đó đi, giữ lại phần còn lại của hàng. Vứt cả hàng chỉ
 * vì một FK lạc là đánh mất bằng chứng tiền — sai chiều.
 */
final class CashDeviceTransactionIntake
{
    /**
     * Hai cổng công bố thay cho hai lượt đọc thẳng model của module khác.
     * `TillSession` KHÔNG cần cổng — nó thuộc chính module Payments.
     */
    public function __construct(
        private readonly BranchCashDevices $cashDevices,
        private readonly BranchOrderMembership $orders,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{accepted: int, updated: int, skipped_stale: int, rejected: int}
     */
    public function ingest(Branch $branch, array $rows): array
    {
        $organizationId = Organization::query()
            ->where('console_organization_id', $branch->console_organization_id)
            ->value('id');

        if ($organizationId === null) {
            // #2847 — `branches` KHÔNG có `organization_id`, chỉ có
            // `console_organization_id`. Org chưa nhân bản về Tempo là trạng
            // thái ghép cặp, không phải lỗi máy chủ. Trả về đếm rỗng và để
            // controller quyết; hàng vẫn nằm trong SQLite của quán.
            return ['accepted' => 0, 'updated' => 0, 'skipped_stale' => 0, 'rejected' => count($rows)];
        }

        $result = ['accepted' => 0, 'updated' => 0, 'skipped_stale' => 0, 'rejected' => 0];

        // Nạp trước tập máy hợp lệ của chi nhánh: một truy vấn cho cả lô thay
        // vì một truy vấn mỗi hàng. Lô tối đa 50 nên khác biệt nhỏ, nhưng đây
        // là đường chạy mỗi phút trên mọi quán.
        $deviceIds = $this->cashDevices->activeCashDeviceIds((string) $branch->id);

        foreach ($rows as $row) {
            $deviceId = (string) $row['peripheral_device_id'];

            if (! in_array($deviceId, $deviceIds, true)) {
                // Máy không thuộc chi nhánh của thiết bị đang gọi. Đây là ranh
                // giới chi nhánh (docs/explanation/branch-isolation.md), không
                // phải lỗi hình dạng — từ chối RIÊNG hàng đó.
                $result['rejected']++;

                continue;
            }

            $outcome = $this->outcomeOrNull($row['outcome']);

            if ($outcome === null) {
                $result['rejected']++;

                continue;
            }

            $applied = $this->upsert($branch, (string) $organizationId, $deviceId, $outcome, $row);
            $result[$applied]++;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return 'accepted'|'updated'|'skipped_stale'
     */
    private function upsert(
        Branch $branch,
        string $organizationId,
        string $deviceId,
        CashDeviceOutcomeEnum $outcome,
        array $row,
    ): string {
        $gloryId = (string) $row['glory_transaction_id'];
        $seqNo = isset($row['machine_seq_no']) ? (int) $row['machine_seq_no'] : null;

        return DB::transaction(function () use ($branch, $organizationId, $deviceId, $outcome, $row, $gloryId, $seqNo): string {
            $existing = CashDeviceTransaction::query()
                ->where('peripheral_device_id', $deviceId)
                ->where('glory_transaction_id', $gloryId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $this->isNewer($seqNo, $existing->machine_seq_no)) {
                // Lượt gửi lại mang seq cũ hơn hoặc bằng. Đây là đường CHẠY
                // BÌNH THƯỜNG, không phải lỗi: máy trạm đẩy lại cả lô sau khi
                // mất mạng. Im lặng bỏ qua.
                return 'skipped_stale';
            }

            $attributes = [
                'organization_id' => $organizationId,
                'branch_id' => $branch->id,
                'peripheral_device_id' => $deviceId,
                'glory_transaction_id' => $gloryId,
                'outcome' => $outcome->value,
                'requested_minor' => (int) ($row['requested_minor'] ?? 0),
                'deposited_minor' => (int) ($row['deposited_minor'] ?? 0),
                'change_minor' => (int) ($row['change_minor'] ?? 0),
                'dispensed_minor' => (int) ($row['dispensed_minor'] ?? 0),
                'error_title' => $row['error_title'] ?? null,
                'machine_seq_no' => $seqNo,
                'started_at' => $this->timestampOrNull($row['started_at'] ?? null) ?? now(),
                'finished_at' => $this->timestampOrNull($row['finished_at'] ?? null),
                'order_payment_id' => $this->resolveOrderPaymentId($branch, $gloryId),
                'customer_order_id' => $this->scopedOrderId($branch, $row['customer_order_id'] ?? null),
                'till_session_id' => $this->scopedTillSessionId($branch, $row['till_session_id'] ?? null),
            ];

            if ($existing === null) {
                CashDeviceTransaction::query()->create($attributes);

                return 'accepted';
            }

            $existing->fill($attributes)->save();

            return 'updated';
        });
    }

    /**
     * Seq mới hơn thì thắng. Cả hai cùng NULL ⇒ coi là mới hơn để lượt đẩy
     * đầu tiên của một máy chưa báo seq vẫn ghi được; hàng đã có seq thì một
     * hàng KHÔNG seq không bao giờ đè lên nó.
     */
    private function isNewer(?int $incoming, ?int $existing): bool
    {
        if ($existing === null) {
            return true;
        }

        if ($incoming === null) {
            return false;
        }

        return $incoming > $existing;
    }

    /**
     * Phân giải khoản thu từ khoá mà máy trạm đã đóng lúc ghi tiền
     * (`cash_changer_recorder.go`: `idemKey := "glory:" + transactionID`).
     *
     * NULL là câu trả lời ĐÚNG với mọi kết cục ≠ finish (BR-CDT02), và cũng
     * đúng khi lượt thu lên trước khoản thu — lô sau đẩy lại sẽ điền nốt, vì
     * upsert này chạy lại toàn bộ thuộc tính.
     */
    private function resolveOrderPaymentId(Branch $branch, string $gloryTransactionId): ?string
    {
        $id = OrderPayment::query()
            ->where('branch_id', $branch->id)
            ->where('idempotency_key', 'glory:'.$gloryTransactionId)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    private function scopedOrderId(Branch $branch, mixed $orderId): ?string
    {
        if (! is_string($orderId) || $orderId === '') {
            return null;
        }

        return $this->orders->orderBelongsToBranch($orderId, (string) $branch->id)
            ? $orderId
            : null;
    }

    private function scopedTillSessionId(Branch $branch, mixed $sessionId): ?string
    {
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        $exists = TillSession::query()
            ->whereKey($sessionId)
            ->where('branch_id', $branch->id)
            ->exists();

        return $exists ? $sessionId : null;
    }

    private function outcomeOrNull(mixed $value): ?CashDeviceOutcomeEnum
    {
        return is_string($value) ? CashDeviceOutcomeEnum::tryFrom($value) : null;
    }

    /**
     * Giờ của MÁY (ISO-8601 từ adapter), lưu UTC.
     *
     * Không đi qua `BusinessClock`: đây là dấu thời gian thiết bị, cùng họ với
     * `payment_settlements.provider_settled_at` vốn theo lịch của cổng chứ
     * không theo giờ nghiệp vụ chi nhánh (#1091). Đổi múi giờ là việc của tầng
     * trình bày.
     */
    private function timestampOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            // Giờ hỏng không được làm mất cả hàng — cùng lý lẽ với
            // `cash_changer_session_store.go`: thời điểm chỉ để hiển thị, thứ
            // quyết định là mã giao dịch.
            return null;
        }
    }
}
