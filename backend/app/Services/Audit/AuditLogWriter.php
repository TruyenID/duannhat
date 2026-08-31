<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ghi một dòng vết kiểm toán cho thứ KHÔNG phải một model đang cầm trên tay
 * (#1666).
 *
 * `AuditsActivity::logAudit()` đã lo phần lớn: nó là trait trên model, tự lấy
 * morph class và khoá chính của chính nó. Nhưng có hai chỗ không dùng được nó,
 * và cả hai đã tự chép lại `AuditLog::create` vào controller:
 *
 *   · `IamMemberController` — `User` KHÔNG dùng trait đó;
 *   · `KioskController`     — ghi cho `auditable_type`/`auditable_id` do client
 *                             gửi, và `user_id` là NULL vì tác nhân là một
 *                             THIẾT BỊ, không phải người.
 *
 * ## Fail-open là hợp đồng, không phải sơ suất
 *
 * Ghi vết hỏng **không được** làm hỏng thao tác nghiệp vụ. Một lần vô hiệu hoá
 * tài khoản đã thành công thì phải trả về thành công, kể cả khi dòng audit
 * không ghi được. Cùng lựa chọn với `AuditsActivity`, và lý do đó là lý do
 * DUY NHẤT `catch (Throwable)` ở đây không phải là nuốt lỗi: nó vẫn ghi log
 * cảnh báo, nên thất bại đi vào chỗ người vận hành nhìn thấy được.
 *
 * Trả về `null` khi ghi hỏng — chỗ gọi nào cần dòng vừa tạo cho response thì
 * phải tự xử `null`, chứ không được giả định nó luôn có.
 */
final class AuditLogWriter
{
    /**
     * Ghi vết và ĐỂ LỖI NỔI LÊN — cho chỗ mà ghi vết CHÍNH LÀ việc.
     *
     * `POST /kiosk/audit-log` không có nghiệp vụ nào khác ngoài dòng nó vừa
     * ghi, và nó trả `id` + `recorded_at` của dòng đó. Nuốt lỗi ở đây là trả
     * 201 kèm một id không tồn tại — tức nói dối client. Fail-open chỉ đúng khi
     * có một thao tác nghiệp vụ THẬT đứng sau cần được bảo vệ.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordOrFail(
        string $auditableType,
        string $auditableId,
        string $action,
        ?string $userId = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::create([
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'action' => $action,
            'user_id' => $userId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $auditableType,
        string $auditableId,
        string $action,
        ?string $userId = null,
        array $metadata = [],
    ): ?AuditLog {
        try {
            return AuditLog::create([
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'action' => $action,
                'user_id' => $userId,
                'metadata' => $metadata,
            ]);
        } catch (Throwable $e) {
            Log::warning('audit.write_failed', [
                'action' => $action,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
