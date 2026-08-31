<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Organization;
use App\Services\Order\Internal\OrderMoneyEvidenceRecorder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * #2885 — máy trạm đẩy BẰNG CHỨNG lệch tiền lên Cloud.
 *
 * ## Vì sao có endpoint này
 *
 * Máy trạm gửi đơn kèm con số của nó; Cloud tự tính lại từ ảnh chụp catalog
 * (đúng — "thiết bị không bao giờ tự khai giá", nền của #1092) rồi lưu số của
 * Cloud. Máy trạm so, thấy khác, ghi trước/sau vào SQLite tại quán rồi kêu
 * alert `cloud_money_overwrite`. Cloud **không** lưu thân request lúc nhận đơn
 * (đã kiểm: `OrderController` chỉ cache kết quả TRẢ VỀ để chống gửi trùng),
 * nên trước issue này khoảng lệch chỉ còn dấu vết ở nơi phát hiện ra nó.
 *
 * Ngày 2026-08-15, để trả lời "ba cảnh báo đang treo là những đơn nào" phải
 * lần ngược `audit_logs` bằng tay + tra Stripe API, và cuối cùng vẫn phải hỏi
 * trí nhớ người. Sổ Cloud quét 464 đơn kết thúc chỉ thấy 1 đơn lệch — nhưng
 * "sổ Cloud cân" KHÔNG chứng minh máy trạm đồng ý: cảnh báo đo khoảng lệch
 * giữa HAI hệ, mà một nửa dữ liệu nằm ngoài tầm với.
 *
 * **Endpoint này KHÔNG đụng đường tiền.** Nó chỉ thêm bản ghi bằng chứng.
 *
 * ## Idempotency: `(device_id, local_id)`, cưỡng chế ở tầng DB
 *
 * `local_id` là id autoincrement trong SQLite của chính máy trạm. Cặp
 * `(device_id, local_id)` là duy nhất và ổn định.
 *
 * KHÔNG dùng `(order_id, occurred_at)`: hai lần ghi đè cùng một đơn trong cùng
 * một giây sẽ gộp làm một — mất đúng bằng chứng đang cần.
 *
 * Cách cưỡng chế là bắt `UniqueConstraintViolationException` chứ **không**
 * `exists()` rồi mới `create()`. Hai request song song cùng lọt qua `exists()`
 * rồi cùng INSERT; chỉ ràng buộc DB mới quyết được. Và nó cũng là lý do dòng
 * trùng KHÔNG bao giờ được `updateOrCreate` — xem docblock của model.
 *
 * ## Không nuốt lỗi lạ
 *
 * Chỉ vi phạm unique mới đếm là `duplicates`. Lỗi khác (DB chết, cột lệch) đi
 * lên thành 5xx **có chủ đích**: máy trạm chỉ đánh dấu `synced_at` sau khi
 * Cloud nhận, nên 5xx làm nó thử lại — còn nuốt lỗi ở đây sẽ đánh dấu đã đồng
 * bộ một dòng chưa bao giờ tới nơi, tức mất bằng chứng trong im lặng. Kỷ luật
 * fail-open của #2695 nằm ở phía máy trạm (một lượt đẩy hỏng không được chạm
 * backpressure dùng chung), không phải ở đây.
 */
final class MoneyOverwriteController extends Controller
{
    /**
     * Trần lô, cùng con số với `/workstation/alerts`.
     *
     * Máy trạm chỉ đẩy dòng `synced_at IS NULL` nên hàng đợi bình thường rất
     * ngắn; hơn 50 dòng chờ trong một lượt là quán vừa mất mạng dài hoặc vừa
     * nâng cấp rồi đẩy tồn kho cũ — chia lô là đúng, nhận không giới hạn chỉ
     * biến một sự cố thành một request khổng lồ.
     */
    private const BATCH_MAX = 50;

    /**
     * Mười một trường tiền (`paid_locally` + năm cặp local/cloud), tất cả BẮT
     * BUỘC, số nguyên, ĐƠN VỊ NHỎ NHẤT, CHO PHÉP ÂM.
     *
     * Danh sách lấy từ NGƯỜI GHI, không gõ lại ở đây: gõ hai lần là cách một
     * trường lặng lẽ mất rule rồi bị `validate()` strip đi, trong khi mọi test
     * service-level vẫn xanh (#2622).
     *
     * @var list<string>
     */
    private const MONEY_FIELDS = OrderMoneyEvidenceRecorder::MONEY_FIELDS;

    #[OA\Post(
        path: '/api/v1/workstation/money-overwrites',
        summary: 'Máy trạm đẩy bằng chứng lệch tiền lên Cloud (#2885)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['overwrites'],
                properties: [
                    new OA\Property(
                        property: 'overwrites',
                        type: 'array',
                        maxItems: self::BATCH_MAX,
                        items: new OA\Items(
                            required: [
                                'local_id', 'order_id', 'occurred_at',
                                'paid_locally',
                                'total_amount_local', 'total_amount_cloud',
                                'subtotal_local', 'subtotal_cloud',
                                'tax_amount_local', 'tax_amount_cloud',
                                'service_charge_local', 'service_charge_cloud',
                                'discount_amount_local', 'discount_amount_cloud',
                            ],
                            properties: [
                                new OA\Property(property: 'local_id', type: 'integer', minimum: 1, description: 'Id autoincrement cục bộ — khoá idempotency cùng với device.'),
                                new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', description: 'RFC3339 UTC, bắt buộc kết thúc bằng Z.'),
                                new OA\Property(property: 'paid_locally', type: 'integer'),
                                new OA\Property(property: 'total_amount_local', type: 'integer'),
                                new OA\Property(property: 'total_amount_cloud', type: 'integer'),
                                new OA\Property(property: 'subtotal_local', type: 'integer'),
                                new OA\Property(property: 'subtotal_cloud', type: 'integer'),
                                new OA\Property(property: 'tax_amount_local', type: 'integer'),
                                new OA\Property(property: 'tax_amount_cloud', type: 'integer'),
                                new OA\Property(property: 'service_charge_local', type: 'integer'),
                                new OA\Property(property: 'service_charge_cloud', type: 'integer'),
                                new OA\Property(property: 'discount_amount_local', type: 'integer'),
                                new OA\Property(property: 'discount_amount_cloud', type: 'integer'),
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
                description: 'Đã nhận (kể cả khi toàn bộ là dòng trùng)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'accepted', type: 'integer'),
                    new OA\Property(property: 'duplicates', type: 'integer'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Thiếu/sai device token'),
            new OA\Response(response: 422, description: 'Payload sai hình dạng · thiết bị chưa gắn chi nhánh · tổ chức chưa nhân bản'),
        ],
    )]
    public function store(Request $request, OrderMoneyEvidenceRecorder $recorder): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        if ($branchId === null) {
            // Cùng lý lẽ với `/alerts`: chưa gắn chi nhánh là trạng thái ghép
            // cặp, không phải lỗi máy chủ. Và quy bằng chứng về đâu thì không
            // có câu trả lời.
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
        // thấy. Org local phải suy qua mirror console, cùng đường mà
        // `RoleResolver::brandRole()` và các policy đang dùng.
        $organizationId = Organization::query()
            ->where('console_organization_id', $branch->console_organization_id)
            ->value('id');

        if ($organizationId === null) {
            return response()->json(['message' => 'Tổ chức của chi nhánh chưa được nhân bản về Tempo.'], 422);
        }

        $accepted = 0;
        $duplicates = 0;

        foreach ($data['overwrites'] as $row) {
            // Ba trường quy-về-đâu lấy từ TOKEN, không lấy từ payload — một
            // thiết bị không ghi được bằng chứng sang chi nhánh khác.
            //
            // Gọi theo TỪNG DÒNG chứ không gộp cả lô: `record()` trả `false`
            // cho dòng trùng, và một dòng trùng KHÔNG được làm rơi những dòng
            // mới đi cùng lô.
            $written = $recorder->record(
                (string) $device->id,
                (string) $branchId,
                (string) $organizationId,
                $row,
            );

            $written ? $accepted++ : $duplicates++;
        }

        // 202 chứ không 200, cùng lý lẽ với `/alerts`: Cloud đã NHẬN, không
        // hứa gì thêm.
        return response()->json(['accepted' => $accepted, 'duplicates' => $duplicates], 202);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        $rules = [
            // `min:1` chứ không chỉ `array`: một lô rỗng không mang thông tin
            // nào, và chấp nhận nó là mời máy trạm gõ cửa mỗi tick vô ích.
            'overwrites' => ['required', 'array', 'min:1', 'max:'.self::BATCH_MAX],

            // `min:1` — id autoincrement của SQLite bắt đầu từ 1. Một `local_id`
            // ≤ 0 nghĩa là đầu kia gửi giá trị mặc định của biến chưa gán, và
            // nhận nó sẽ tạo một khoá idempotency giả mà mọi dòng lỗi cùng
            // đâm vào.
            'overwrites.*.local_id' => ['required', $this->strictlyInteger(), 'integer', 'min:1'],
            'overwrites.*.order_id' => ['required', 'uuid'],

            // RFC3339 **UTC**: bắt buộc hậu tố `Z`. Chấp nhận thêm `+09:00`
            // nghe có vẻ rộng lượng, nhưng nó mở đúng cái cửa mà #1091 đóng —
            // một thiết bị gửi giờ tường (wall clock) mà quên offset thì Cloud
            // lưu sai 9 tiếng, và bằng chứng kiểm toán sai giờ còn tệ hơn
            // không có bằng chứng. Nhận cả bản có phần giây lẻ vì thư viện
            // thời gian ở đầu kia có thể phát ra nó.
            'overwrites.*.occurred_at' => [
                'required',
                'string',
                'date_format:Y-m-d\TH:i:s\Z,Y-m-d\TH:i:s.v\Z,Y-m-d\TH:i:s.u\Z',
            ],
        ];

        foreach (self::MONEY_FIELDS as $field) {
            // KHÔNG có `min` — số ÂM và số 0 đều hợp lệ. Giảm giá vượt sinh ra
            // số âm thật, và ép `min:0` sẽ từ chối đúng những dòng bất thường
            // nhất, tức chính những dòng cần bằng chứng nhất.
            $rules['overwrites.*.'.$field] = ['required', $this->strictlyInteger(), 'integer'];
        }

        return $rules;
    }

    /**
     * Bắt buộc kiểu JSON **integer** thật, không phải "thứ ép được về integer".
     *
     * Luật `integer` của Laravel chạy qua `filter_var(FILTER_VALIDATE_INT)`, nên
     * nó nhận cả `"1190"` lẫn `true` — và `true` đi tiếp vào `(int)` thành **1**.
     * Trên một bảng bằng chứng tiền, đó là dữ liệu bịa mà không ai thấy: nó
     * không ném, không log, chỉ ra một con số sai trong sổ đối soát.
     *
     * Đầu kia là Go (`encoding/json` phát số nguyên đúng kiểu), nên chặt ở đây
     * không mất gì của đường thật, mà chặn được đúng lớp lỗi khó thấy nhất.
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
