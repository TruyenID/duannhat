<?php

declare(strict_types=1);

namespace App\Services\Till;

use App\Models\Branch;
use App\Models\CashDeviceErrorEvent;
use App\Models\CashDeviceInventorySnapshot;
use App\Models\CashDeviceTransaction;
use App\Models\Organization;
use App\Models\TillSession;
use App\Services\PeripheralDevice\Contracts\BranchCashDevices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * T2 (#2879) + T5 (#2882) — nhận 在高 và sự cố của máy 釣銭機 từ máy trạm.
 *
 * Cùng nhà với {@see CashDeviceTransactionIntake} và cùng ba luật của nó:
 * idempotent theo khoá tự nhiên, không tin FK thiết bị gửi, và FK lạc thì rớt
 * GIÁ TRỊ chứ không rớt hàng.
 *
 * Hai loại quan sát ở chung một lớp vì chúng đi cùng một đường (lô, fail-open,
 * cùng vòng đẩy) và chia chung phép kiểm phạm vi. Tách đôi sẽ nhân đôi ba luật
 * trên — mà ba luật chép đôi là ba luật sẽ lệch nhau.
 */
final class CashDeviceObservationIntake
{
    public function __construct(private readonly BranchCashDevices $cashDevices) {}

    /**
     * 在高 — ảnh chụp tiền trong máy tại một ranh ca.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{accepted: int, updated: int, rejected: int}
     */
    public function ingestInventory(Branch $branch, array $rows): array
    {
        return $this->ingest($branch, $rows, function (array $row, string $orgId, string $deviceId) use ($branch): ?string {
            // `till_session_id` là NOT NULL ở bảng này (BR-INV01/BR-INV02): một
            // ảnh chụp không gắn ca thì không đối chiếu được với cái gì, và một
            // hàng không đối chiếu được là một hàng sẽ được tin mà không ai kiểm.
            $sessionId = $this->scopedTillSessionId($branch, $row['till_session_id'] ?? null);

            if ($sessionId === null) {
                return null;
            }

            // CHUẨN HOÁ sang chuỗi, KHÔNG lọc bỏ thứ không phải chuỗi.
            //
            // JSON `{"10000": …}` cho khoá chuỗi, nhưng `["10000"]` qua một
            // client khác có thể tới dạng số. `array_filter(..., 'is_string')`
            // sẽ vứt sạch cờ bất định của thiết bị đó — và vứt IM LẶNG, nghĩa
            // là mọi mệnh giá máy không chắc lại được đem cộng vào tổng. Đó
            // đúng là cái sai mà cột này sinh ra để chặn.
            $uncertain = is_array($row['uncertain_denominations'] ?? null)
                ? array_values(array_map(static fn ($d): string => (string) $d, $row['uncertain_denominations']))
                : [];

            $denominations = is_array($row['denominations'] ?? null) ? $row['denominations'] : [];

            $existing = CashDeviceInventorySnapshot::query()
                ->where('peripheral_device_id', $deviceId)
                ->where('till_session_id', $sessionId)
                ->where('count_phase', (string) $row['count_phase'])
                ->lockForUpdate()
                ->first();

            $attributes = [
                'organization_id' => $orgId,
                'branch_id' => $branch->id,
                'peripheral_device_id' => $deviceId,
                'till_session_id' => $sessionId,
                'count_phase' => (string) $row['count_phase'],
                'denominations' => $denominations,
                'uncertain_denominations' => $uncertain === [] ? null : $uncertain,
                'bill_reject_count' => (int) ($row['bill_reject_count'] ?? 0),
                // BR-INV02 — Cloud tự cộng, KHÔNG nhận `total_minor` từ thiết bị.
                // Tổng là hệ quả của hai trường trên; nhận nó rời ra là mở đường
                // cho một tổng không khớp chi tiết mà vẫn trông hợp lệ.
                'total_minor' => $this->certainTotalMinor($denominations, $uncertain),
                'machine_seq_no' => isset($row['machine_seq_no']) ? (int) $row['machine_seq_no'] : null,
                'captured_at' => $this->timestampOrNull($row['captured_at'] ?? null) ?? now(),
            ];

            if ($existing === null) {
                CashDeviceInventorySnapshot::query()->create($attributes);

                return 'accepted';
            }

            $existing->fill($attributes)->save();

            return 'updated';
        });
    }

    /**
     * Sự cố — một LẦN XẢY RA, không phải một lượt gặp lỗi.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{accepted: int, updated: int, rejected: int}
     */
    public function ingestErrors(Branch $branch, array $rows): array
    {
        return $this->ingest($branch, $rows, function (array $row, string $orgId, string $deviceId) use ($branch): ?string {
            $occurredAt = $this->timestampOrNull($row['occurred_at'] ?? null);

            if ($occurredAt === null) {
                // `occurred_at` là nửa khoá idempotent (BR-ERR01). Không có nó
                // thì mỗi lượt đẩy đẻ một hàng mới, và sổ sự cố thành sổ rác.
                return null;
            }

            $existing = CashDeviceErrorEvent::query()
                ->where('peripheral_device_id', $deviceId)
                ->where('error_title', (string) $row['error_title'])
                ->where('occurred_at', $occurredAt)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'organization_id' => $orgId,
                'branch_id' => $branch->id,
                'peripheral_device_id' => $deviceId,
                'error_title' => (string) $row['error_title'],
                'error_group' => (string) $row['error_group'],
                'occurred_at' => $occurredAt,
                'cleared_at' => $this->timestampOrNull($row['cleared_at'] ?? null),
                'cash_device_transaction_id' => $this->scopedTransactionId($branch, $row['glory_transaction_id'] ?? null, $deviceId),
                'till_session_id' => $this->scopedTillSessionId($branch, $row['till_session_id'] ?? null),
            ];

            if ($existing === null) {
                CashDeviceErrorEvent::query()->create($attributes);

                return 'accepted';
            }

            // Lượt đẩy sau mang `cleared_at` — đó là cách một sự cố ĐÓNG LẠI, và
            // là thứ cho phép tính thời lượng. Cập nhật, không đẻ hàng mới.
            $existing->fill($attributes)->save();

            return 'updated';
        });
    }

    /**
     * Khung chung: phân giải org, kiểm máy thuộc chi nhánh, đếm kết quả.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>, string, string): ?string  $apply
     * @return array{accepted: int, updated: int, rejected: int}
     */
    private function ingest(Branch $branch, array $rows, callable $apply): array
    {
        $organizationId = Organization::query()
            ->where('console_organization_id', $branch->console_organization_id)
            ->value('id');

        if ($organizationId === null) {
            // #2847 — `branches` chỉ có `console_organization_id`.
            return ['accepted' => 0, 'updated' => 0, 'rejected' => count($rows)];
        }

        $result = ['accepted' => 0, 'updated' => 0, 'rejected' => 0];
        $deviceIds = $this->cashDevices->activeCashDeviceIds((string) $branch->id);

        foreach ($rows as $row) {
            $deviceId = (string) $row['peripheral_device_id'];

            if (! in_array($deviceId, $deviceIds, true)) {
                // Ranh giới chi nhánh — từ chối RIÊNG hàng đó.
                $result['rejected']++;

                continue;
            }

            $applied = DB::transaction(fn (): ?string => $apply($row, (string) $organizationId, $deviceId));

            if ($applied === null) {
                $result['rejected']++;

                continue;
            }

            $result[$applied]++;
        }

        return $result;
    }

    /**
     * BR-INV02 — tổng CHỈ trên mệnh giá máy nói nó chắc chắn.
     *
     * Mệnh giá 在高不確定 mà đem cộng là bịa ra một con số rồi bắt quán đi tìm
     * tiền không mất. Đây là phép tính quan trọng nhất của T2 và nó nằm ở Cloud
     * chứ không nhận từ thiết bị — xem chỗ gọi.
     *
     * @param  array<string, mixed>  $denominations
     * @param  list<string>  $uncertain
     */
    private function certainTotalMinor(array $denominations, array $uncertain): int
    {
        $total = 0;

        foreach ($denominations as $denom => $count) {
            if (in_array((string) $denom, $uncertain, true)) {
                continue;
            }

            $total += (int) $denom * (int) $count;
        }

        return $total;
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

    /**
     * Trỏ sự cố về lượt thu đã sinh ra nó — phân giải Ở ĐÂY từ mã giao dịch,
     * không nhận id bảng từ thiết bị (máy trạm không biết id Cloud).
     */
    private function scopedTransactionId(Branch $branch, mixed $gloryTransactionId, string $deviceId): ?string
    {
        if (! is_string($gloryTransactionId) || $gloryTransactionId === '') {
            return null;
        }

        $id = CashDeviceTransaction::query()
            ->where('branch_id', $branch->id)
            ->where('peripheral_device_id', $deviceId)
            ->where('glory_transaction_id', $gloryTransactionId)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    private function timestampOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
