<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\WorkstationLogRequest;
use App\Omnify\Enums\WorkstationLogLevelEnum;
use App\Omnify\Enums\WorkstationLogRequestStatusEnum;
use App\Services\Device\Internal\WorkstationLogArchive;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * #2901 — máy trạm đẩy lô log đã lọc lên Cloud.
 *
 * ## Hai lớp lọc, cùng một luật
 *
 * Máy trạm lọc theo `docs/reference/workstation-log-allowlist.md` TRƯỚC khi
 * gửi. Cloud kiểm **lại** theo cùng bảng đó lúc nhận, vì hợp đồng nói thẳng:
 * không tin đầu kia đã lọc đúng. Một máy trạm bản cũ, một bản build lỗi, hay
 * một thiết bị bị chiếm quyền đều gửi được thứ mà bộ lọc ở nguồn lẽ ra đã
 * chặn — và PII đi qua ranh giới hệ thống thì không thu hồi được (#2220).
 *
 * ## Ba cách từ chối, và sự khác nhau là CỐ Ý
 *
 * | Sai gì | Cloud làm gì | Vì sao |
 * |---|---|---|
 * | `level: "debug"` | **422 cả lô** | Bộ lọc ở NGUỒN đã hỏng ⇒ mọi dòng khác trong lô cũng đáng ngờ |
 * | `message` ngoài allowlist | bỏ dòng, `rejected++`, lô vẫn **202** | Chỉ là một dòng chưa ai khai; làm rơi cả lô là mất bằng chứng của những dòng đã khai |
 * | attr ngoài allowlist | bỏ attr, **giữ dòng** | Một dòng thiếu một trường vẫn trả lời được câu hỏi vận hành |
 *
 * ## `request_id`: 422 và 404 nói hai chuyện khác nhau
 *
 * - Thuộc thiết bị KHÁC ⇒ **422**. Đó là lỗi của người gọi: thiết bị đang gửi
 *   một thứ không bao giờ được trao cho nó, nên nó phải sửa chứ không phải bỏ
 *   qua.
 * - Không tồn tại / đã đóng / đã hết hạn ⇒ **404**, và hợp đồng ghi rõ máy
 *   trạm coi đó là "thôi, bỏ qua", KHÔNG alert. Yêu cầu hết hạn trong lúc máy
 *   trạm đang lọc là chuyện bình thường, không phải sự cố.
 *
 * ## Fail-open nằm ở phía máy trạm, không nằm ở đây
 *
 * Kỷ luật #2695 (đường phụ hỏng không được chạm backpressure dùng chung) là
 * việc của máy trạm. Ở đây lỗi lạ vẫn đi lên 5xx **có chủ đích**: nuốt lỗi sẽ
 * làm máy trạm tưởng đã gửi xong một lô chưa bao giờ tới nơi.
 */
final class LogRecordController extends Controller
{
    #[OA\Post(
        path: '/api/v1/workstation/log-records',
        summary: 'Máy trạm đẩy lô log đã lọc lên Cloud (#2901)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['request_id', 'final', 'records'],
                properties: [
                    new OA\Property(property: 'request_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'final', type: 'boolean', description: 'false = còn lô nữa; true = lô cuối, Cloud đóng yêu cầu'),
                    new OA\Property(
                        property: 'records',
                        type: 'array',
                        items: new OA\Items(
                            required: ['local_id', 'logged_at', 'level', 'message'],
                            properties: [
                                new OA\Property(property: 'local_id', type: 'integer', minimum: 1, description: 'Id autoincrement cục bộ — khoá idempotency cùng với device.'),
                                new OA\Property(property: 'logged_at', type: 'string', format: 'date-time', description: 'RFC3339 UTC, bắt buộc kết thúc bằng Z.'),
                                new OA\Property(property: 'level', type: 'string', enum: ['info', 'warn', 'error'], description: '`debug` ⇒ 422 cả lô.'),
                                new OA\Property(property: 'message', type: 'string', description: 'Khoá allowlist, NGUYÊN VĂN.'),
                                new OA\Property(property: 'attrs', type: 'object', nullable: true),
                            ],
                            type: 'object',
                        ),
                    ),
                ],
            ),
        ),
        tags: ['Workstation'],
        responses: [
            new OA\Response(
                response: 202,
                description: 'Đã nhận (kể cả khi mọi dòng đều trùng hoặc bị allowlist loại)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'accepted', type: 'integer'),
                    new OA\Property(property: 'duplicates', type: 'integer'),
                    new OA\Property(property: 'rejected', type: 'integer', description: 'Dòng bị bỏ vì message ngoài allowlist'),
                    new OA\Property(property: 'over_limit', type: 'integer', description: 'Dòng bị bỏ vì đã chạm max_records của yêu cầu'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Thiếu/sai device token'),
            new OA\Response(response: 404, description: 'request_id không tồn tại / đã đóng / đã hết hạn'),
            new OA\Response(response: 422, description: 'Payload sai hình dạng · level debug · thiết bị chưa gắn chi nhánh · request_id thuộc thiết bị khác'),
        ],
    )]
    public function store(Request $request, WorkstationLogArchive $archive): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        if ($branchId === null) {
            return response()->json(['message' => 'Thiết bị chưa gắn chi nhánh.'], 422);
        }

        $data = $request->validate($this->rules());

        $branch = Branch::query()->whereKey($branchId)->first();

        if ($branch === null) {
            return response()->json(['message' => 'Chi nhánh không tồn tại.'], 422);
        }

        // #2847 — `branches` KHÔNG có cột `organization_id`, chỉ có
        // `console_organization_id`. Tin nhầm điều đó chính là bug đã làm chết
        // 7.523 alert máy trạm trong hai ngày, tỷ lệ hỏng 100% từ dòng đầu
        // tiên: `(string) $branch->organization_id` ra chuỗi RỖNG và không ai
        // thấy. Org local phải suy qua mirror console.
        $organizationId = Organization::query()
            ->where('console_organization_id', $branch->console_organization_id)
            ->value('id');

        if ($organizationId === null) {
            return response()->json(['message' => 'Tổ chức của chi nhánh chưa được nhân bản về Tempo.'], 422);
        }

        $logRequest = WorkstationLogRequest::query()->whereKey($data['request_id'])->first();

        if ($logRequest === null) {
            return response()->json(['message' => 'Yêu cầu không tồn tại.'], 404);
        }

        if ((string) $logRequest->device_id !== (string) $device->id) {
            // 422, không 404: thiết bị đang gửi một `request_id` chưa bao giờ
            // được trao cho nó. Trả 404 ở đây sẽ dạy máy trạm "im lặng bỏ qua"
            // đúng cái ca đáng phải sửa.
            return response()->json(['message' => 'Yêu cầu này không thuộc thiết bị đang gọi.'], 422);
        }

        if ($logRequest->status !== WorkstationLogRequestStatusEnum::Pending
            || CarbonImmutable::parse($logRequest->expires_at)->utc() <= CarbonImmutable::now('UTC')) {
            return response()->json(['message' => 'Yêu cầu đã đóng hoặc đã hết hạn.'], 404);
        }

        $counts = $archive->ingest(
            $logRequest,
            (string) $device->id,
            (string) $branchId,
            (string) $organizationId,
            $data['records'],
            (bool) $data['final'],
        );

        // 202 chứ không 200, cùng lý lẽ với `/alerts`: Cloud đã NHẬN, không
        // hứa gì thêm.
        return response()->json($counts, 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            // `boolean` chứ không `nullable`: thiếu `final` thì Cloud không
            // biết có nên đóng yêu cầu hay không, và đoán sai theo chiều nào
            // cũng hỏng — đóng sớm thì mất phần đuôi, không đóng thì yêu cầu
            // treo tới lúc hết hạn.
            'final' => ['required', 'boolean'],
            'records' => ['required', 'array', 'min:1', 'max:'.(int) config('workstation_logs.batch_max')],

            // `min:1` — id autoincrement của SQLite bắt đầu từ 1. Một `local_id`
            // ≤ 0 nghĩa là đầu kia gửi giá trị mặc định của biến chưa gán, và
            // nhận nó sẽ tạo một khoá idempotency giả mà mọi dòng lỗi cùng đâm
            // vào.
            'records.*.local_id' => ['required', $this->strictlyInteger(), 'integer', 'min:1'],

            // RFC3339 **UTC**: bắt buộc hậu tố `Z`. Chấp nhận thêm `+09:00`
            // nghe có vẻ rộng lượng, nhưng nó mở đúng cái cửa mà #1091 đóng —
            // và ở đây còn đắt hơn chỗ khác: hạn giữ 14 ngày ĐẾM THEO cột này,
            // nên một dòng lệch 9 tiếng là một dòng sống sai hạn.
            'records.*.logged_at' => [
                'required',
                'string',
                'date_format:Y-m-d\TH:i:s\Z,Y-m-d\TH:i:s.v\Z,Y-m-d\TH:i:s.u\Z',
            ],

            // Chốt "info trở lên" phải cưỡng chế ĐƯỢC ở Cloud. Danh sách sinh
            // từ enum chứ không gõ tay: hai `in:` gõ tay cho cùng một khái
            // niệm là đúng thứ #2860 đã trả giá (bảy cách viết cho ba khái
            // niệm, giao nhau đúng MỘT giá trị, sống nhiều tháng).
            'records.*.level' => ['required', 'string', 'in:'.implode(',', array_column(WorkstationLogLevelEnum::cases(), 'value'))],

            'records.*.message' => ['required', 'string', 'max:255'],
            'records.*.attrs' => ['nullable', 'array'],
        ];
    }

    /**
     * Bắt buộc kiểu JSON **integer** thật, không phải "thứ ép được về integer".
     *
     * Luật `integer` của Laravel chạy qua `filter_var(FILTER_VALIDATE_INT)` nên
     * nó nhận cả `"8123"` lẫn `true` — và `true` đi tiếp vào `(int)` thành
     * **1**. Trên khoá idempotency, đó là mọi dòng hỏng cùng đâm vào một khoá.
     *
     * Đầu kia là Go (`encoding/json` phát số nguyên đúng kiểu), nên chặt ở đây
     * không mất gì của đường thật.
     */
    private function strictlyInteger(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value)) {
                $fail("Trường {$attribute} phải là số nguyên JSON, không phải chuỗi hay boolean.");
            }
        };
    }
}
