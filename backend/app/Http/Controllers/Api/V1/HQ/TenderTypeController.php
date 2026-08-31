<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\TillTenderType;
use App\Services\Till\TillTenderTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * #1881 — từ vựng tender ở cấp tổ chức (HQ).
 *
 * ## Ba điều KHÔNG sửa được, và vì sao
 *
 * `tender_key` là **từ vựng tiền**: `order_payments.tender_key` chụp nó lên
 * từng chứng từ, và `reconcile()` so khớp theo nó. Nên phần lớn thao tác trông
 * như "quản trị bình thường" ở đây thật ra là thao tác trên dữ liệu bất biến.
 *
 * | Không cho | Vì sao |
 * |---|---|
 * | Đổi `tender_key` | Payment cũ sẽ trỏ vào một từ không còn nghĩa. Đổi *nhãn* thì có `till_tender_type_translations` |
 * | Đổi `parent_tender_key` khi đã có payment | `till_settlement_tender_details` lưu `tender_key` chứ KHÔNG lưu nhóm cha — nhóm được áp lúc ĐỌC. Sửa nó là viết lại cách gom của mọi 精算 cũ, mà đó là chứng từ pháp lý |
 * | Xoá khi đã có payment | Làm mồ côi sổ cái. Nghĩa vụ lưu chứng từ ở JP là 7/10 năm |
 *
 * Cả ba đều trả **409** kèm số payment đang tham chiếu, không phải 422: đây
 * không phải dữ liệu gửi lên sai định dạng, mà là một thao tác hợp lệ về hình
 * thức nhưng mâu thuẫn với trạng thái hiện có. Con số đi kèm để người vận hành
 * biết *tại sao* và *bao nhiêu*, thay vì chỉ biết "không được".
 *
 * ## Ranh giới với tầng shop
 *
 * HQ đặt **từ vựng**; chi nhánh chỉ chọn **bật/tắt** cái HQ đã định nghĩa
 * (`ShopTillTenderActivationController`). Hàng cấp tổ chức là `branch_id IS
 * NULL` — cùng ranh giới đã chốt ở #1370 cho settlement, và `routes/api/shops/
 * tender-types.php` vốn đã trả 403 cho mọi thao tác ghi lên hàng org-wide.
 */
final class TenderTypeController extends Controller
{
    use HasOrganizationContext;

    public function __construct(private readonly TillTenderTypeService $tenderTypes) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/tender-types',
        summary: 'Từ vựng tender cấp tổ chức',
        tags: ['HQ'],
        responses: [new OA\Response(response: 200, description: 'Danh sách tender')],
    )]
    public function index(Request $request): JsonResponse
    {
        $rows = $this->tenderTypes->listForOrganization(
            $this->getOrganizationId(),
            $request->boolean('include_inactive'),
        );

        $usage = $this->tenderTypes->usageCounts(
            $this->getOrganizationId(),
            $rows->pluck('tender_key')->all(),
        );

        return response()->json([
            'data' => $rows->map(fn (TillTenderType $t): array => $this->payload($t, $usage))->all(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/tender-types',
        summary: 'Thêm một tender vào từ vựng',
        tags: ['HQ'],
        responses: [new OA\Response(response: 201, description: 'Đã tạo')],
    )]
    public function store(Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId();

        $data = $request->validate([
            // Khoá là thứ MÁY đọc và là thứ nằm lại trên chứng từ mười năm sau.
            // Ràng ký tự ở đây để không có ai đặt khoá bằng tiếng Nhật rồi phát
            // hiện nó vỡ khi xuất CSV hay so khớp với báo cáo của gateway.
            'tender_key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('till_tender_types', 'tender_key')
                    ->where('organization_id', $organizationId)
                    ->whereNull('branch_id')
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            // BẮT BUỘC: cột NOT NULL, và nó quyết định ngữ nghĩa gom 精算.
            // Cho nullable rồi tự điền mặc định là chọn hộ người vận hành một
            // thứ ảnh hưởng tới báo cáo tiền.
            'category' => ['required', 'string', 'max:64'],
            'parent_tender_key' => ['nullable', 'string', 'max:64'],
            'payment_method_code' => ['nullable', 'string', 'max:64'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_expected_anchor' => ['nullable', 'boolean'],
            'requires_terminal_total' => ['nullable', 'boolean'],
        ]);

        $row = new TillTenderType($data);
        $row->organization_id = $organizationId;
        // NULL = từ vựng của cả tổ chức. Hàng có branch_id là ghi đè của chi
        // nhánh và KHÔNG được tạo từ đây.
        $row->branch_id = null;
        $row->is_active = true;
        $row->save();

        return response()->json(['data' => $this->payload($row, [])], 201);
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/tender-types/{id}',
        summary: 'Sửa nhãn / nhóm / thứ tự (KHÔNG sửa được khoá)',
        tags: ['HQ'],
        responses: [new OA\Response(response: 409, description: 'Thao tác mâu thuẫn với payment đã có')],
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $row = $this->tenderTypes->findForOrganizationOrFail($this->getOrganizationId(), $id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:64'],
            'parent_tender_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_expected_anchor' => ['sometimes', 'boolean'],
            'requires_terminal_total' => ['sometimes', 'boolean'],
        ]);

        // `tender_key` cố ý KHÔNG có trong danh sách trên. Nhưng gửi lên mà bị
        // bỏ qua trong im lặng còn tệ hơn bị từ chối: người dùng thấy 200, tin
        // là đã đổi, và phát hiện ra vài tuần sau qua một báo cáo.
        if ($request->has('tender_key') && $request->string('tender_key')->toString() !== (string) $row->tender_key) {
            return $this->conflict(
                'TENDER_KEY_IMMUTABLE',
                'tender_key không đổi được — payment đã chụp khoá này lên chứng từ. Đổi nhãn hiển thị thay vì đổi khoá.',
                $this->tenderTypes->usageCount($this->getOrganizationId(), (string) $row->tender_key),
            );
        }

        if (array_key_exists('parent_tender_key', $data)
            && $data['parent_tender_key'] !== $row->parent_tender_key) {
            $used = $this->tenderTypes->usageCount($this->getOrganizationId(), (string) $row->tender_key);

            if ($used > 0) {
                return $this->conflict(
                    'TENDER_GROUP_IMMUTABLE_ONCE_USED',
                    'Không đổi được nhóm khi tender đã có payment: báo cáo 精算 gom theo nhóm lúc ĐỌC, '
                    .'nên đổi bây giờ sẽ viết lại cách gom của mọi ca cũ. Muốn gom khác thì tạo tender_key mới.',
                    $used,
                );
            }
        }

        $row->fill($data)->save();

        return response()->json(['data' => $this->payload($row, [])]);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/tender-types/{id}',
        summary: 'Xoá — chỉ khi CHƯA có payment nào',
        tags: ['HQ'],
        responses: [new OA\Response(response: 409, description: 'Đã có payment — chỉ được tắt')],
    )]
    public function destroy(string $id): JsonResponse
    {
        $row = $this->tenderTypes->findForOrganizationOrFail($this->getOrganizationId(), $id);

        $used = $this->tenderTypes->usageCount($this->getOrganizationId(), (string) $row->tender_key);

        if ($used > 0) {
            return $this->conflict(
                'TENDER_IN_USE',
                'Không xoá được tender đã có payment — sổ cái sẽ mồ côi. Hãy tắt (is_active = false): '
                .'nó biến khỏi lựa chọn mới nhưng chứng từ và báo cáo cũ vẫn tra được.',
                $used,
            );
        }

        // Gõ nhầm lúc mới dựng là chuyện thường, và một tender chưa ai dùng thì
        // xoá đi không mất gì. Vẫn là xoá MỀM — bảng có `deleted_at`.
        $row->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @param array<string, int> $usage */
    private function payload(TillTenderType $t, array $usage): array
    {
        $used = $usage[$t->tender_key] ?? null;

        return [
            'id' => $t->id,
            'tender_key' => $t->tender_key,
            'name' => $t->name,
            'category' => $t->category,
            'parent_tender_key' => $t->parent_tender_key,
            'payment_method_code' => $t->payment_method_code,
            'currency_code' => $t->currency_code,
            'sort_order' => (int) $t->sort_order,
            'is_active' => (bool) $t->is_active,
            'is_expected_anchor' => (bool) $t->is_expected_anchor,
            'requires_terminal_total' => (bool) $t->requires_terminal_total,
            // Ba cờ dưới đây để UI nói SỰ THẬT trước khi người dùng gõ, thay vì
            // cho gõ rồi trả 409. Một ô nhập bị từ chối sau khi bấm Lưu là cách
            // tệ nhất để truyền đạt một ràng buộc.
            'payment_count' => $used,
            'key_editable' => false,
            'group_editable' => $used === null ? null : $used === 0,
            'deletable' => $used === null ? null : $used === 0,
        ];
    }

    private function conflict(string $code, string $message, int $used): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error_code' => $code,
            'payment_count' => $used,
        ], 409);
    }
}
