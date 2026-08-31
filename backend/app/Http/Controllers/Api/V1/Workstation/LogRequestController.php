<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\WorkstationLogRequest;
use App\Omnify\Enums\WorkstationLogRequestStatusEnum;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * #2901 — máy trạm hỏi "có ai yêu cầu log của tôi không".
 *
 * ## Vì sao đường này tồn tại
 *
 * Chủ dự án chốt cơ chế **kéo theo yêu cầu** (2026-08-15): log ở lại quán cho
 * tới khi có người bấm điều tra, để lượng PII nằm sẵn ở Cloud là ít nhất.
 *
 * Nhưng "kéo" KHÔNG thực hiện được theo nghĩa đen: máy trạm chạy `http.Server`
 * trên LAN của quán, sau NAT, không có địa chỉ công khai — Cloud không gọi
 * ngược vào được. Nên nó cài thành **yêu cầu treo, máy trạm tự nhận**: HQ ghi
 * một hàng `pending`, máy trạm thấy nó ở nhịp sync kế tiếp, lọc tại chỗ rồi
 * đẩy lên. Chiều vận chuyển vẫn luôn là máy trạm → Cloud.
 *
 * ## Ca THƯỜNG là danh sách RỖNG
 *
 * Endpoint này bị gọi mỗi nhịp sync và gần như luôn trả `{"requests":[]}`. Nó
 * phải rẻ và phải im lặng — nó KHÔNG được là chỗ để nhét thêm việc.
 *
 * ## Đây KHÔNG phải cửa hậu thực thi lệnh
 *
 * Một yêu cầu mang đúng bốn thứ: id, khoảng thời gian, trần số bản ghi. Không
 * trường tự do, không tên file, không đường dẫn, không lệnh. Ràng buộc này
 * được ghi thẳng vào hình dạng bảng `workstation_log_requests` — thêm một cột
 * "tham số" là mở lại đúng cửa đã cố ý đóng.
 */
final class LogRequestController extends Controller
{
    #[OA\Get(
        path: '/api/v1/workstation/log-requests',
        summary: 'Yêu cầu lấy log đang treo của CHÍNH thiết bị gọi (#2901)',
        tags: ['Workstation'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Danh sách yêu cầu treo — RỖNG là ca thường',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'requests',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'from', type: 'string', format: 'date-time', description: 'RFC3339 UTC, hậu tố Z'),
                            new OA\Property(property: 'to', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'max_records', type: 'integer'),
                        ], type: 'object'),
                    ),
                ]),
            ),
            new OA\Response(response: 401, description: 'Thiếu/sai device token'),
            new OA\Response(response: 422, description: 'Thiết bị chưa gắn chi nhánh'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');

        if ($device?->branch_id === null) {
            // Cùng lý lẽ với `/alerts` và `/money-overwrites`: chưa gắn chi
            // nhánh là trạng thái ghép cặp, không phải lỗi máy chủ.
            return response()->json(['message' => 'Thiết bị chưa gắn chi nhánh.'], 422);
        }

        $now = CarbonImmutable::now('UTC');

        $requests = WorkstationLogRequest::query()
            // Chỉ yêu cầu của CHÍNH thiết bị đang gọi. Đây là hàng rào cách ly
            // duy nhất trên đường này, nên nó nằm ở mệnh đề đầu tiên.
            ->where('device_id', (string) $device->id)
            ->where('status', WorkstationLogRequestStatusEnum::Pending->value)
            // Lọc theo `expires_at` NGAY Ở ĐÂY chứ không tin cột `status`:
            // lượt quét đánh dấu hết hạn chạy theo lịch, nên giữa hai lượt vẫn
            // có hàng `pending` đã quá hạn. Đúng đắn không được phụ thuộc vào
            // việc một cron có chạy đúng giờ hay không.
            ->where('expires_at', '>', $now)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'requests' => $requests->map(fn (WorkstationLogRequest $r): array => [
                'id' => (string) $r->id,
                // Hợp đồng wire phát `from`/`to`; cột tên `window_from`/
                // `window_to` vì `from`/`to` là từ khoá SQL.
                'from' => CarbonImmutable::parse($r->window_from)->utc()->format('Y-m-d\TH:i:s\Z'),
                'to' => CarbonImmutable::parse($r->window_to)->utc()->format('Y-m-d\TH:i:s\Z'),
                'max_records' => (int) $r->max_records,
            ])->all(),
        ]);
    }
}
