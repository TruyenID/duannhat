<?php

declare(strict_types=1);

namespace App\Services\Device\Internal;

use App\Models\WorkstationLogRecord;
use App\Models\WorkstationLogRequest;
use App\Omnify\Enums\WorkstationLogRequestStatusEnum;
use App\Support\Workstation\WorkstationLogAllowlist;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * #2901 — người ghi DUY NHẤT của `workstation_log_records` và
 * `workstation_log_requests`.
 *
 * ## Vì sao MỘT class, và vì sao nó ở đây
 *
 * `config/domain-mutation-guard.php` khai aggregate `workstation_log` và lấy
 * file này làm biên giới. Để lệnh ghi nằm rải trong controller + command thì
 * biên giới của một bảng chở PII sẽ là **tầng HTTP cộng tầng CLI**, tức không
 * còn là biên giới — đúng chuyện #1666 đã phải gỡ ra khỏi
 * `ShopFaqController::visibility`, và đúng lý lẽ của
 * `OrderMoneyEvidenceRecorder` (#2885).
 *
 * Chỗ ở là `App\Services\Device\Internal`, KHÔNG phải `App\Services\Workstation`:
 * cái sau được khai là **Composition** trong `deptrac.yaml`, và Composition
 * không sở hữu aggregate nào nên nó không được GHI. Có ghi nghĩa là có bất biến
 * để giữ, và bất biến thuộc về một module — ở đây là PlatformIntegration, module
 * đã sở hữu `Device`. Log này là quan sát về một THIẾT BỊ, nên nó về đúng nhà.
 *
 * ## Idempotency: bắt vi phạm UNIQUE, không `exists()` rồi `create()`
 *
 * `exists()` rồi mới ghi là một cửa sổ race — hai lô song song cùng thấy "chưa
 * có" rồi cùng INSERT. Chỉ ràng buộc `(device_id, local_id)` ở tầng DB mới
 * quyết được, nên đường đúng là cứ ghi và bắt ngoại lệ.
 *
 * Và đây là **no-op tuyệt đối**, không phải `updateOrCreate`: gửi lại cùng khoá
 * thì hàng cũ KHÔNG bị đụng tới, kể cả khi nội dung khác. Nội dung khác trên
 * cùng `(device_id, local_id)` nghĩa là có bug ở đầu kia — ghi đè sẽ xoá mất
 * dấu vết của chính bug đó.
 *
 * ## Bốn con số trả về là bốn chuyện khác nhau
 *
 * - `accepted`   — hàng mới thật sự được lưu.
 * - `duplicates` — đã có hàng mang đúng cặp khoá. Máy trạm gửi lại sau khi mất
 *   mạng là chuyện thường, KHÔNG phải lỗi.
 * - `rejected`   — `message` không có trong allowlist. Con số duy nhất nói
 *   "bảng khai đang hẹp hơn thực tế"; không đếm riêng thì lỗ hổng vô hình và
 *   người điều tra chỉ thấy một câu trả lời ngắn.
 * - `over_limit` — vượt `max_records` của yêu cầu. Tách khỏi `rejected` vì hai
 *   thứ này đòi hai hành động khác nhau: một cái là mở rộng allowlist, cái kia
 *   là thu hẹp khoảng thời gian hỏi.
 */
final class WorkstationLogArchive
{
    public function __construct(private readonly WorkstationLogAllowlist $allowlist) {}

    /**
     * Mở một yêu cầu kéo log.
     *
     * Phạm vi CỐ ĐỊNH: thiết bị đích, khoảng thời gian, trần, hạn. Chữ ký này
     * là chỗ ràng buộc "chỉ đọc log, không phải cửa hậu thực thi lệnh" được
     * cưỡng chế bằng kiểu — không có tham số tự do nào để nhét lệnh vào.
     */
    public function openRequest(
        string $deviceId,
        string $branchId,
        string $organizationId,
        ?string $requestedByUserId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $maxRecords,
        CarbonImmutable $expiresAt,
    ): WorkstationLogRequest {
        return WorkstationLogRequest::create([
            'device_id' => $deviceId,
            'branch_id' => $branchId,
            'organization_id' => $organizationId,
            'requested_by_user_id' => $requestedByUserId,
            'window_from' => $from->utc(),
            'window_to' => $to->utc(),
            'max_records' => $maxRecords,
            'status' => WorkstationLogRequestStatusEnum::Pending->value,
            'expires_at' => $expiresAt->utc(),
            'fulfilled_at' => null,
            'received_count' => 0,
            'rejected_count' => 0,
        ]);
    }

    /**
     * Nhận MỘT lô cho MỘT yêu cầu.
     *
     * `$deviceId` / `$branchId` / `$organizationId` đến từ device token, KHÔNG
     * từ payload — một thiết bị không ghi được log sang chi nhánh khác.
     *
     * @param  list<array<string, mixed>>  $rows  `records[]` ĐÃ qua validate
     * @return array{accepted: int, duplicates: int, rejected: int, over_limit: int}
     */
    public function ingest(
        WorkstationLogRequest $logRequest,
        string $deviceId,
        string $branchId,
        string $organizationId,
        array $rows,
        bool $final,
    ): array {
        $accepted = 0;
        $duplicates = 0;
        $rejected = 0;
        $overLimit = 0;

        // Trần đếm trên TỔNG của cả yêu cầu, không phải trên từng lô: máy trạm
        // chia lô theo ý nó, nên một trần theo lô không chặn được gì.
        $remaining = max(0, $logRequest->max_records - $logRequest->received_count);

        foreach ($rows as $row) {
            $message = (string) $row['message'];

            [$keep, $attrs] = $this->allowlist->filter($message, $this->attrsOf($row));

            if (! $keep) {
                $rejected++;

                continue;
            }

            if ($remaining <= 0) {
                $overLimit++;

                continue;
            }

            $written = $this->write($logRequest, $deviceId, $branchId, $organizationId, $row, $message, $attrs);

            if ($written) {
                $accepted++;
                $remaining--;
            } else {
                // Dòng trùng KHÔNG ăn vào hạn ngạch: nó đã được tính ở lượt
                // trước. Trừ hai lần sẽ làm một lượt gửi lại (chuyện thường sau
                // khi mất mạng) âm thầm cụt mất phần đuôi.
                $duplicates++;
            }
        }

        $this->closeOrAdvance($logRequest, $accepted, $rejected, $final || $remaining <= 0);

        return [
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'over_limit' => $overLimit,
        ];
    }

    /**
     * Đánh dấu các yêu cầu quá hạn mà chưa ai trả lời.
     *
     * KHÔNG đụng `fulfilled`: hàng đó mang một khẳng định ("đã trả lời, được N
     * dòng") và đè nó thành `expired` sẽ xoá mất chính khẳng định ấy.
     *
     * @return int số hàng đã đổi
     */
    public function expireStaleRequests(CarbonImmutable $now): int
    {
        return WorkstationLogRequest::query()
            ->where('status', WorkstationLogRequestStatusEnum::Pending->value)
            ->where('expires_at', '<=', $now)
            ->update(['status' => WorkstationLogRequestStatusEnum::Expired->value]);
    }

    /**
     * Xoá MỘT lô bản ghi quá hạn, theo KHOÁ CHÍNH.
     *
     * Nhận danh sách id chứ không nhận điều kiện: lượt quét (bên gọi) đã quyết
     * định tập, nên lệnh ghi không chạm gì mà lượt quét chưa đồng ý — và một
     * `DELETE ... WHERE logged_at < ?` không chặn sẽ giữ khoá trên một khoảng
     * không giới hạn.
     *
     * @param  list<string>  $ids
     */
    public function deleteRecords(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return WorkstationLogRecord::query()->whereIn('id', $ids)->delete();
    }

    /**
     * Ghi MỘT dòng. `false` = đã có dòng mang đúng cặp khoá.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $attrs
     */
    private function write(
        WorkstationLogRequest $logRequest,
        string $deviceId,
        string $branchId,
        string $organizationId,
        array $row,
        string $message,
        ?array $attrs,
    ): bool {
        try {
            WorkstationLogRecord::create([
                'device_id' => $deviceId,
                'branch_id' => $branchId,
                'organization_id' => $organizationId,
                'request_id' => (string) $logRequest->id,
                'local_id' => (int) $row['local_id'],
                // Parse tường minh rồi ép UTC: chuỗi đã kết thúc bằng `Z` nên
                // instant là xác định, và ép UTC ở đây khiến cột không bao giờ
                // phụ thuộc `app.timezone` của tiến trình đang chạy (#1091).
                'logged_at' => CarbonImmutable::parse((string) $row['logged_at'])->utc(),
                'level' => (string) $row['level'],
                'message' => $message,
                'attrs' => $attrs,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Cộng dồn số đếm lên yêu cầu, và đóng nó khi lô cuối tới nơi.
     *
     * Cộng ở tầng SQL chứ không đọc-sửa-ghi: hai lô song song của cùng một
     * thiết bị (máy trạm gửi lại trong lúc lô trước còn đang chạy) sẽ đè mất số
     * đếm của nhau nếu đi qua PHP.
     *
     * `fulfilled` chỉ đặt MỘT LẦN, và điều kiện `status = pending` nằm ngay
     * trong câu UPDATE: một lô tới muộn sau khi yêu cầu đã đóng không được đẩy
     * `fulfilled_at` trôi đi.
     */
    private function closeOrAdvance(WorkstationLogRequest $logRequest, int $accepted, int $rejected, bool $close): void
    {
        $now = CarbonImmutable::now('UTC');

        WorkstationLogRequest::query()
            ->whereKey($logRequest->id)
            ->update([
                'received_count' => DB::raw('received_count + '.$accepted),
                'rejected_count' => DB::raw('rejected_count + '.$rejected),
                'updated_at' => $now,
            ]);

        if ($close) {
            WorkstationLogRequest::query()
                ->whereKey($logRequest->id)
                ->where('status', WorkstationLogRequestStatusEnum::Pending->value)
                ->update([
                    'status' => WorkstationLogRequestStatusEnum::Fulfilled->value,
                    'fulfilled_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $logRequest->refresh();
    }

    /**
     * `attrs` là tuỳ chọn trong hợp đồng wire; thiếu nó không phải lỗi.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function attrsOf(array $row): array
    {
        $attrs = $row['attrs'] ?? null;

        return is_array($attrs) ? $attrs : [];
    }
}
