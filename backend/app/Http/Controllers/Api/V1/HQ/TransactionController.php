<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\OrderPayment;
use App\Services\Payment\Observation\TransactionLookupService;
use App\Support\BusinessClock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * T3 của #2876 (#2880) — tra cứu giao dịch toàn kênh, CHỈ ĐỌC.
 *
 * Trước endpoint này, muốn biết một giao dịch PayPay hôm qua ra sao thì phải
 * vào DB: `routes/api/hq/` không có file nào liệt kê giao dịch, màn hình đơn
 * hàng không hiện dòng thanh toán, và các TS type đã sinh sẵn
 * (`OrderPayment.ts`, `PaymentAttempt.ts`) không trang nào dùng.
 *
 * Đây là vế thiếu của **電子帳簿保存法 検索要件** — xem docblock của
 * {@see TransactionLookupService}.
 *
 * ## Khác gì màn hình settlement đã có
 *
 * `hq/{brand}/settlements` trả lời "cổng đã trả tiền về chưa, phí bao nhiêu" —
 * quan hệ quán ↔ CỔNG. Endpoint này trả lời "giao dịch X là gì" — quan hệ quán
 * ↔ KHÁCH. Hai câu hỏi khác nhau trên hai sổ khác nhau
 * (`docs/guide/gateway-settlement.md`), nên gộp màn hình sẽ làm cả hai khó đọc.
 */
final class TransactionController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(private readonly TransactionLookupService $lookup) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/transactions',
        summary: 'Tra cứu giao dịch toàn kênh — 電子帳簿保存法 検索要件 (#2880)',
        tags: ['HQ Payments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: '取引年月日 — ngày business time của chi nhánh', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'amount_min', in: 'query', required: false, description: '取引金額 — khoảng, không phải giá trị chính xác', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'amount_max', in: 'query', required: false, schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'provider', in: 'query', required: false, description: '取引先 — cổng thanh toán', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reference', in: 'query', required: false, description: 'MỘT ô cho mọi loại mã: reference_no · idempotency_key (kể cả mã Glory trần) · payment_code · provider_object_id · provider_request_key', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách giao dịch + phân trang'),
            new OA\Response(response: 403, description: 'Không có quyền đọc giao dịch của brand'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        // Quyền đi theo chính `OrderPayment`, KHÔNG dựng permission mới: ai
        // được xem một khoản thu thì được tra nó. Một trục quyền thứ hai cho
        // cùng một tài sản là chỗ để hai trục lệch nhau về sau — cùng lý lẽ
        // với `SettlementController` gắn quyền vào `PaymentGatewayConnection`.
        $this->authorize('viewAny', OrderPayment::class);

        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'amount_min' => ['nullable', 'numeric'],
            'amount_max' => ['nullable', 'numeric'],
            'branch_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', 'max:50'],
            'tender_key' => ['nullable', 'string', 'max:100'],
            'till_session_id' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        [$fromUtc, $toUtc] = $this->businessWindow($data);

        $page = $this->lookup->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'branch_id' => $data['branch_id'] ?? null,
            'status' => $data['status'] ?? null,
            'provider' => $data['provider'] ?? null,
            'tender_key' => $data['tender_key'] ?? null,
            'till_session_id' => $data['till_session_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'amount_min' => $data['amount_min'] ?? null,
            'amount_max' => $data['amount_max'] ?? null,
            'from_utc' => $fromUtc,
            'to_utc' => $toUtc,
            'per_page' => $data['per_page'] ?? 25,
        ]);

        return response()->json([
            'data' => collect($page->items())->map(fn (OrderPayment $r) => $this->lookup->payload($r))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Quy đổi ngày NGHIỆP VỤ sang cận UTC.
     *
     * Quán VN (UTC+7) và JP (UTC+9) chạy chung một backend UTC, nên "ngày
     * 15/08" không phải một khoảng toàn cục (#1091). `utcRangeForBusinessDates`
     * là chỗ duy nhất được phép làm phép quy đổi này — tự `Carbon::parse` ở đây
     * là đúng thứ `BusinessTimeArchitectureTest` cấm.
     *
     * Không có `branch_id` thì timezone rơi về mặc định của
     * `timezoneForBranch(null)`. Đó là đánh đổi có ý thức: một brand nhiều múi
     * giờ tra theo ngày mà không chọn chi nhánh sẽ nhận một khoảng XẤP XỈ. Cận
     * trên là **exclusive** (`addDay`), nên không có ca nào rơi ra ngoài vì
     * làm tròn giây.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string}
     */
    private function businessWindow(array $data): array
    {
        [$from, $until] = BusinessClock::utcRangeForBusinessDates(
            $data['branch_id'] ?? null,
            $data['date_from'] ?? null,
            $data['date_to'] ?? null,
        );

        return [$from?->toDateTimeString(), $until?->toDateTimeString()];
    }
}
