<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use App\Models\WorkstationLogRecord;
use App\Models\WorkstationLogRequest;
use App\Services\Iam\UserWorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * #2901 — đường ĐỌC của bề mặt log máy trạm.
 *
 * ## Vì sao tách khỏi controller
 *
 * `HqControllerArchTest` cấm truy vấn Eloquent thẳng trong controller HQ, và lý
 * lẽ của luật đó (#1920) đúng nguyên vẹn ở đây: **điều kiện phạm vi sống cùng
 * chỗ với truy vấn**. Khi hai thứ ở hai file, một endpoint mới sẽ sao chép câu
 * truy vấn mà quên vế phạm vi, và không có gì đỏ.
 *
 * ## Phạm vi CHI NHÁNH là rào, không phải bộ lọc — và hôm nay nó là lớp THỨ HAI
 *
 * `branch_id` do client gửi là **bộ lọc tuỳ chọn**, mà bỏ trống bộ lọc thì thấy
 * HẾT. Nên phạm vi được áp ở ĐÂY, luôn luôn, bằng
 * `UserWorkspaceAccess::branches()` — cùng nguồn với `canAccessBranch()`, nên
 * hai đường không thể lệch nhau. Tham số của client chỉ **thu hẹp thêm** bên
 * trong phạm vi đã được phép.
 *
 * Nói thẳng giới hạn của phép đo, vì một bình luận nói quá là thứ người sau sẽ
 * tin: **hôm nay lớp này chưa chặn ai cả.** Route HQ không đặt `branch_id` vào
 * request attributes, nên `ResolvesOrganization::resolveLocalBranchId()` trả
 * `null`, và `User::getRolesForContext($org, null)` chỉ nhận pivot
 * `branch_id IS NULL`. Hệ quả: một `shop-manager` gắn cứng vào quán A **không
 * qua nổi policy** — 403 trước khi chạm tới đây — còn mọi người vào được thì
 * đều là org-wide và `branches()` trả về đúng toàn bộ chi nhánh của tổ chức.
 *
 * Giữ nó vì cái giá của chiều ngược lại: ngày nào đó có người gắn ngữ cảnh quán
 * vào route này (hoặc mở policy cho vai gắn-quán), truy vấn ở đây vẫn đúng mà
 * không cần ai nhớ ra. Đó là lý do nó sống CÙNG FILE với truy vấn thay vì ở
 * controller — luật #1920.
 *
 * `branches()` giữ đúng ruling #2460: pivot `branch_id IS NULL` nghĩa là MỌI
 * chi nhánh của tổ chức, không phải "không chi nhánh nào" — nên org-admin không
 * mất gì. Đó là vế mà `WorkstationLogAccessTest` phải chứng minh: một rào chỉ
 * biết KÊU mà không biết IM thì sẽ bị tắt.
 */
final class WorkstationLogReads
{
    public function __construct(private readonly UserWorkspaceAccess $access) {}

    /**
     * Thiết bị, chỉ khi nó thuộc đúng tổ chức đang xét.
     *
     * Trả `null` cho cả hai ca "không tồn tại" và "của tổ chức khác" là cố ý:
     * controller biến cả hai thành 404, để một id đoán mò không xác nhận được
     * rằng thiết bị đó có thật ở nơi khác.
     */
    public function findDeviceInOrganization(string $deviceId, string $organizationId): ?Device
    {
        $device = Device::query()->whereKey($deviceId)->first();

        if ($device === null || (string) $device->organization_id !== $organizationId) {
            return null;
        }

        return $device;
    }

    /**
     * Tên chi nhánh theo lô.
     *
     * Theo LÔ chứ không từng hàng: bản trước tra `Branch::…->value('name')`
     * ngay trong hàm dựng payload, tức một truy vấn cho MỖI dòng của trang —
     * 25 yêu cầu là 25 lượt đi DB cho một cột hiển thị.
     *
     * @param  list<string>  $branchIds
     * @return array<string, string>
     */
    public function branchNames(array $branchIds): array
    {
        $ids = array_values(array_unique(array_filter($branchIds)));

        if ($ids === []) {
            return [];
        }

        return Branch::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * @param  array{device_id?: string|null, branch_id?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, WorkstationLogRequest>
     */
    public function requestsFor(User $user, string $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return WorkstationLogRequest::query()
            ->where('organization_id', $organizationId)
            ->whereIn('branch_id', $this->visibleBranchIds($user))
            ->when($filters['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{request_id?: string|null, device_id?: string|null, branch_id?: string|null, level?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, WorkstationLogRecord>
     */
    public function recordsFor(User $user, string $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return WorkstationLogRecord::query()
            ->where('organization_id', $organizationId)
            ->whereIn('branch_id', $this->visibleBranchIds($user))
            ->when($filters['request_id'] ?? null, fn ($q, $v) => $q->where('request_id', $v))
            ->when($filters['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['level'] ?? null, fn ($q, $v) => $q->where('level', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('logged_at', '>=', CarbonImmutable::parse($v)->utc()))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('logged_at', '<=', CarbonImmutable::parse($v)->utc()))
            // Thứ tự thời gian TĂNG dần: người ta đọc log để dựng lại một chuỗi
            // sự kiện, và đọc ngược một chuỗi nhân quả là cách nhanh nhất để
            // kết luận nhầm về nguyên nhân.
            ->orderBy('logged_at')
            ->orderBy('local_id')
            ->paginate($perPage);
    }

    /**
     * Tập chi nhánh user được phép nhìn, dưới dạng **truy vấn con**.
     *
     * Truy vấn con chứ không `pluck()` rồi nhét mảng vào: một org lớn có hàng
     * trăm chi nhánh, và một `whereIn` với hàng trăm uuid gõ thẳng vào SQL là
     * thứ sẽ đụng trần placeholder của driver ở đúng khách hàng lớn nhất.
     */
    private function visibleBranchIds(User $user): Builder
    {
        return $this->access->branches($user)->select('id');
    }
}
