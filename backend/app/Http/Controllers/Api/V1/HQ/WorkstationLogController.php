<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\WorkstationLogRecord;
use App\Models\WorkstationLogRequest;
use App\Omnify\Enums\WorkstationLogLevelEnum;
use App\Omnify\Enums\WorkstationLogRequestStatusEnum;
use App\Policies\WorkstationLogRequestPolicy;
use App\Services\Device\Internal\WorkstationLogArchive;
use App\Services\Device\WorkstationLogReads;
use App\Services\Iam\UserWorkspaceAccess;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * #2901 — bề mặt HQ: tạo yêu cầu kéo log, và đọc những gì đã về.
 *
 * ## Chỉ ĐỌC log, phạm vi cố định
 *
 * `store()` nhận đúng bốn thứ: thiết bị đích, khoảng thời gian, trần số bản
 * ghi, hạn. Không có trường tự do nào đi tới máy trạm. Ràng buộc này là điểm
 * chốt của thiết kế — đường nhận yêu cầu ở máy trạm **không được** thành cửa
 * hậu thực thi lệnh — nên nó được cưỡng chế ngay ở `validate()` chứ không chỉ
 * ghi trong tài liệu.
 *
 * ## Quyền: cổng vai đã có, không dựng trục thứ hai
 *
 * `shop.manage` qua {@see WorkstationLogRequestPolicy}, cộng một
 * kiểm tra cách ly chi nhánh cho ĐÚNG thiết bị đích. Hai lớp cần cả hai:
 * permission trả lời "vai này có được điều tra không", `canAccessBranch()` trả
 * lời "có được điều tra QUÁN NÀY không". Một org-admin có
 * `all_branches_access` (pivot `branch_id IS NULL`, nghĩa là MỌI chi nhánh —
 * #2460) qua được cả hai; một shop-manager chỉ có pivot ở quán A thì không
 * chạm được máy của quán B.
 *
 * ## Cột `received_count` là câu trả lời cho bẫy mẫu số bằng không
 *
 * Danh sách yêu cầu trả về cả trạng thái lẫn số đếm, vì `fulfilled` với 0 dòng
 * ("khoảng đó không có gì qua allowlist") KHÁC `expired` ("máy không trả lời")
 * và cả hai đều KHÁC "quán không có sự cố". Máy trạm bản cũ chưa biết endpoint
 * này thì mọi yêu cầu của nó sẽ `expired` — Cloud không được đọc im lặng đó
 * thành lời khẳng định.
 */
final class WorkstationLogController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/workstation-log-requests',
        summary: 'Tạo một yêu cầu kéo log từ một máy trạm (#2901)',
        tags: ['HQ Workstation'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Đã tạo yêu cầu treo'),
            new OA\Response(response: 403, description: 'Không đủ vai, hoặc không có quyền ở chi nhánh của thiết bị'),
            new OA\Response(response: 404, description: 'Thiết bị không thuộc tổ chức này'),
            new OA\Response(response: 422, description: 'Payload sai hình dạng · thiết bị không phải máy trạm · thiết bị chưa gắn chi nhánh'),
        ],
    )]
    public function store(
        Request $request,
        UserWorkspaceAccess $access,
        WorkstationLogArchive $archive,
        WorkstationLogReads $reads,
    ): JsonResponse {
        $this->authorize('create', WorkstationLogRequest::class);

        $maxRecords = (int) config('workstation_logs.request_max_records');
        $ttlHours = (int) config('workstation_logs.request_ttl_hours');

        $data = $request->validate([
            'device_id' => ['required', 'uuid'],
            // RFC3339 UTC, cùng hình dạng với đường máy trạm gửi lên. Một
            // khoảng thời gian gõ theo giờ tường sẽ hỏi nhầm 7–9 tiếng ở quán
            // VN/JP (#1091), mà kết quả rỗng KHÔNG tự tố cáo điều đó.
            'from' => ['required', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'to' => ['required', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'max_records' => ['nullable', 'integer', 'min:1', 'max:'.$maxRecords],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:'.$ttlHours],
        ]);

        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();

        if ($to <= $from) {
            return response()->json(['message' => 'Khoảng thời gian rỗng: "to" phải sau "from".'], 422);
        }

        $organizationId = $this->getOrganizationId();

        $device = $reads->findDeviceInOrganization($data['device_id'], $organizationId);

        if ($device === null) {
            // 404 chứ không 403: đừng để một id đoán mò xác nhận được rằng
            // thiết bị đó tồn tại ở tổ chức khác.
            return response()->json(['message' => 'Thiết bị không tồn tại trong tổ chức này.'], 404);
        }

        // `devices.type` được cast thành enum ở BaseModel, nên so thẳng với
        // chuỗi luôn SAI — và sai theo chiều fail-closed, tức mọi yêu cầu đều
        // 422 và tính năng chết im lặng. So theo `->value`.
        $deviceType = $device->type instanceof BackedEnum ? $device->type->value : (string) $device->type;

        if ($deviceType !== 'workstation') {
            return response()->json(['message' => 'Chỉ máy trạm mới có đường trả log.'], 422);
        }

        if ($device->branch_id === null) {
            return response()->json(['message' => 'Thiết bị chưa gắn chi nhánh.'], 422);
        }

        if (! $access->canAccessBranch($request->user(), (string) $device->branch_id, $organizationId)) {
            return response()->json(['message' => 'Không có quyền ở chi nhánh của thiết bị này.'], 403);
        }

        // Ghi đi qua `WorkstationLogArchive`, KHÔNG `WorkstationLogRequest::create()`
        // ngay đây: `config/domain-mutation-guard.php` lấy class đó làm biên
        // giới ghi của aggregate `workstation_log`, và một lệnh ghi trong
        // controller sẽ biến biên giới ấy thành tầng HTTP (#1666).
        $logRequest = $archive->openRequest(
            deviceId: (string) $device->id,
            branchId: (string) $device->branch_id,
            organizationId: $organizationId,
            requestedByUserId: (string) $request->user()->id,
            from: $from,
            to: $to,
            maxRecords: (int) ($data['max_records'] ?? $maxRecords),
            expiresAt: CarbonImmutable::now('UTC')->addHours((int) ($data['expires_in_hours'] ?? $ttlHours)),
        );

        return response()->json(['data' => $this->requestPayload($logRequest)], 201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/workstation-log-requests',
        summary: 'Danh sách yêu cầu kéo log của tổ chức (#2901)',
        tags: ['HQ Workstation'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách + phân trang'),
            new OA\Response(response: 403, description: 'Không đủ vai'),
        ],
    )]
    public function indexRequests(Request $request, WorkstationLogReads $reads): JsonResponse
    {
        $this->authorize('viewAny', WorkstationLogRequest::class);

        $data = $request->validate([
            'device_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(WorkstationLogRequestStatusEnum::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $reads->requestsFor(
            $request->user(),
            $this->getOrganizationId(),
            $data,
            (int) ($data['per_page'] ?? 25),
        );

        $branchNames = $reads->branchNames(
            collect($page->items())->pluck('branch_id')->map(fn ($id): string => (string) $id)->all(),
        );

        return response()->json([
            'data' => collect($page->items())->map(fn (WorkstationLogRequest $r) => $this->requestPayload($r, $branchNames))->all(),
            'meta' => $this->meta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total()),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/workstation-log-records',
        summary: 'Đọc các dòng log đã kéo về (#2901)',
        tags: ['HQ Workstation'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách + phân trang'),
            new OA\Response(response: 403, description: 'Không đủ vai'),
        ],
    )]
    public function indexRecords(Request $request, WorkstationLogReads $reads): JsonResponse
    {
        $this->authorize('viewAny', WorkstationLogRecord::class);

        $data = $request->validate([
            'request_id' => ['nullable', 'uuid'],
            'device_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'level' => ['nullable', 'string', 'in:'.implode(',', array_column(WorkstationLogLevelEnum::cases(), 'value'))],
            'from' => ['nullable', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'to' => ['nullable', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = $reads->recordsFor(
            $request->user(),
            $this->getOrganizationId(),
            $data,
            (int) ($data['per_page'] ?? 100),
        );

        return response()->json([
            'data' => collect($page->items())->map(fn (WorkstationLogRecord $r): array => [
                'id' => (string) $r->id,
                'request_id' => (string) $r->request_id,
                'device_id' => (string) $r->device_id,
                'branch_id' => (string) $r->branch_id,
                'local_id' => (int) $r->local_id,
                'logged_at' => CarbonImmutable::parse($r->logged_at)->utc()->format('Y-m-d\TH:i:s\Z'),
                'level' => $r->level->value,
                'message' => $r->message,
                'attrs' => $r->attrs,
            ])->all(),
            'meta' => $this->meta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total()),
        ]);
    }

    /**
     * @param  array<string, string>  $branchNames
     * @return array<string, mixed>
     */
    private function requestPayload(WorkstationLogRequest $r, array $branchNames = []): array
    {
        return [
            'id' => (string) $r->id,
            'device_id' => (string) $r->device_id,
            'branch_id' => (string) $r->branch_id,
            'branch_name' => $branchNames[(string) $r->branch_id] ?? null,
            'requested_by_user_id' => $r->requested_by_user_id,
            'from' => CarbonImmutable::parse($r->window_from)->utc()->format('Y-m-d\TH:i:s\Z'),
            'to' => CarbonImmutable::parse($r->window_to)->utc()->format('Y-m-d\TH:i:s\Z'),
            'max_records' => (int) $r->max_records,
            'status' => $r->status->value,
            'expires_at' => CarbonImmutable::parse($r->expires_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            'fulfilled_at' => $r->fulfilled_at === null
                ? null
                : CarbonImmutable::parse($r->fulfilled_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            // Hai con số này là thứ ngăn "không có log" bị đọc thành "không có
            // sự cố": `received_count = 0` trên một yêu cầu `fulfilled` là một
            // khẳng định, trên một yêu cầu `expired` thì không là gì cả.
            'received_count' => (int) $r->received_count,
            // Khác 0 nghĩa là allowlist đang hẹp hơn thực tế — tín hiệu phải
            // mở rộng `docs/reference/workstation-log-allowlist.md`.
            'rejected_count' => (int) $r->rejected_count,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function meta(int $currentPage, int $lastPage, int $perPage, int $total): array
    {
        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }
}
