<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\GatewayPayout;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentSettlement;
use App\Models\SettlementReportBatch;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\SettlementAgingReportService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Đường đọc tầng settlement (#1370, plan-050 M5 T5.0).
 *
 * Backend M1/M2/M4-core đã sinh dữ liệu đối soát từ lâu (scheduler
 * `settlements:reconcile` chạy 06:30 hằng ngày) nhưng KHÔNG có route nào đọc
 * ra — muốn xem tiền về thì phải vào thẳng DB. Controller này là hợp đồng đọc
 * đó, và nó phải có trước T5.1 (màn hình admin-web), nếu không người làm UI sẽ
 * vừa dựng màn vừa tự nghĩ ra API — kiểu làm cho ra một API vừa đúng một cái
 * bảng rồi thôi.
 *
 * BỐN HÌNH DẠNG, BỐN ENDPOINT — cố ý không gộp thành một endpoint có `?mode=`:
 * dòng settlement, lô báo cáo đã nhập, payout của cổng, và bảng tuổi nợ là bốn
 * thực thể khác nhau về khoá chính lẫn nhịp thay đổi. Gộp thì mỗi bên đọc phải
 * đoán hình dạng trả về từ tham số truy vấn.
 *
 * HỢP ĐỒNG G1 — KHÔNG BAO GIỜ TRẢ ESTIMATE. `payment_attempts.estimated_fee_minor`
 * là con số L1 phỏng đoán lúc bán, KHÔNG phải phí thật do cổng báo về. Mọi số
 * tiền ở đây đến từ `payment_settlements` / `gateway_payouts` — tức từ báo cáo
 * của cổng. Một dashboard kế toán đọc phải estimate là một dashboard nói dối,
 * và cái sai đó không tự lộ ra vì con số vẫn "trông đúng".
 */
class SettlementController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    private const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly SettlementAgingReportService $aging,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/settlements',
        summary: 'Danh sách dòng settlement của brand (phí + tiền thực nhận do cổng báo về)',
        description: 'Lọc: connection_id, status, kind, provider, currency, settled_from/settled_to (theo provider_settled_at), unmatched=1 (dòng cổng báo mà không khớp được order_payment nào). KHÔNG bao giờ trả estimate (G1).',
        tags: ['HQ'],
        responses: [
            new OA\Response(response: 200, description: 'Trang dòng settlement'),
            new OA\Response(response: 403, description: 'Không có quyền đọc gateway connection của brand'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $connectionIds = $this->brandConnectionIds($request);

        $query = PaymentSettlement::query()
            ->whereIn('connection_id', $connectionIds)
            ->when($request->filled('connection_id'), fn ($q) => $q->where('connection_id', $request->string('connection_id')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            // #2864 — tiền của merchant KHÁC không đi vào bản đối soát mặc định.
            // Tài khoản Stripe dùng chung với trang đặt món WooCommerce của quán,
            // và ¥366.643 của họ đã nằm sẵn trong bảng này. CSV ở đây là thứ kế
            // toán mở ra và cộng; để nó lẫn vào là để một con số trông đúng dạng
            // nhưng gồm doanh thu không phải của Tempo.
            // Vẫn lấy ra được — chỉ cần hỏi đích danh `?status=foreign`, nên đây
            // là mặc định an toàn chứ không phải giấu dữ liệu.
            ->unless($request->filled('status'), fn ($q) => $q->whereNotIn('status', [
                SettlementStatus::Foreign->value,
                // #2981 — cùng lý lẽ: điều chỉnh phí của chính Stripe
                // không phải doanh thu đơn hàng, nên nó không thuộc bảng
                // đối soát mặc định. `?status=fee_adjustment` vẫn lấy ra.
                SettlementStatus::FeeAdjustment->value,
            ]))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')->toString()))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')->toString()))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency', $request->string('currency')->toString()))
            // `unmatched` = dòng cổng báo về mà không gắn được vào order_payment
            // nào. Đó là hình dạng "orphan" mà #1370 nêu; nó không phải một bảng
            // riêng, chỉ là một lát của cùng bảng này.
            ->when($request->boolean('unmatched'), fn ($q) => $q->whereNull('order_payment_id'))
            ->when($request->filled('settled_from'), fn ($q) => $q->where('provider_settled_at', '>=', $request->string('settled_from')->toString()))
            ->when($request->filled('settled_to'), fn ($q) => $q->where('provider_settled_at', '<=', $request->string('settled_to')->toString()))
            ->orderByDesc('provider_settled_at')
            ->orderByDesc('created_at');

        $page = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => array_map(
                fn (PaymentSettlement $row): array => $this->settlementPayload($row),
                $page->items(),
            ),
            'meta' => $this->pageMeta($page),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/settlements/export',
        summary: 'Xuất CSV đối soát cho kế toán (plan-050 T5.2)',
        tags: ['HQ'],
        responses: [new OA\Response(response: 200, description: 'CSV, text/csv')],
    )]
    /**
     * plan-050 T5.2 — xuất CSV cho kế toán.
     *
     * ## Ba quyết định, đều có lý do đã trả giá ở nơi khác
     *
     * **KHÔNG phân trang.** Mọi endpoint khác ở đây phân trang; cái này thì
     * không, và đó là khác biệt cố ý. Một file kế toán bị cắt ở dòng thứ 50 vẫn
     * mở được, vẫn cộng ra một con số, và con số đó SAI mà không có dấu hiệu gì.
     * Thà trả file lớn còn hơn trả file thiếu trông như đủ.
     *
     * **Stream, không dựng trong bộ nhớ.** Một kỳ kế toán có thể vài chục nghìn
     * dòng; `implode` cả mảng rồi trả về là cách chắc chắn nhất để hết bộ nhớ
     * đúng vào cuối tháng, lúc người ta cần nó nhất.
     *
     * **Tiền ở ĐƠN VỊ NHỎ NHẤT, số nguyên, kèm cột `currency`.** Không chia cho
     * 100, không format. Chia ra là đưa số thập phân vào một file mà Excel sẽ
     * diễn giải lại theo locale của máy — và JPY thì không có phần lẻ, còn VND
     * lại khác. Người nhận cần con số nguyên bản để tự đối chiếu.
     *
     * Thêm BOM UTF-8: kế toán Nhật mở CSV bằng Excel, và Excel không có BOM sẽ
     * đọc UTF-8 thành mojibake. Một dòng ba byte đổi file từ "không dùng được"
     * sang "mở phát ăn ngay".
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $connectionIds = $this->brandConnectionIds($request);

        $query = PaymentSettlement::query()
            ->whereIn('connection_id', $connectionIds)
            ->when($request->filled('connection_id'), fn ($q) => $q->where('connection_id', $request->string('connection_id')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            // #2864 — tiền của merchant KHÁC không đi vào bản đối soát mặc định.
            // Tài khoản Stripe dùng chung với trang đặt món WooCommerce của quán,
            // và ¥366.643 của họ đã nằm sẵn trong bảng này. CSV ở đây là thứ kế
            // toán mở ra và cộng; để nó lẫn vào là để một con số trông đúng dạng
            // nhưng gồm doanh thu không phải của Tempo.
            // Vẫn lấy ra được — chỉ cần hỏi đích danh `?status=foreign`, nên đây
            // là mặc định an toàn chứ không phải giấu dữ liệu.
            ->unless($request->filled('status'), fn ($q) => $q->whereNotIn('status', [
                SettlementStatus::Foreign->value,
                // #2981 — cùng lý lẽ: điều chỉnh phí của chính Stripe
                // không phải doanh thu đơn hàng, nên nó không thuộc bảng
                // đối soát mặc định. `?status=fee_adjustment` vẫn lấy ra.
                SettlementStatus::FeeAdjustment->value,
            ]))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')->toString()))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')->toString()))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency', $request->string('currency')->toString()))
            ->when($request->boolean('unmatched'), fn ($q) => $q->whereNull('order_payment_id'))
            ->when($request->filled('settled_from'), fn ($q) => $q->where('provider_settled_at', '>=', $request->string('settled_from')->toString()))
            ->when($request->filled('settled_to'), fn ($q) => $q->where('provider_settled_at', '<=', $request->string('settled_to')->toString()))
            ->orderBy('provider_settled_at')
            ->orderBy('id');

        return $this->streamCsv('settlements', [
            'settlement_id', 'connection_id', 'provider', 'kind', 'status',
            'external_ref', 'currency',
            'gross_minor', 'fee_minor', 'fee_tax_minor', 'net_minor',
            'provider_settled_at', 'payout_id', 'order_payment_id', 'matched',
        ], $query, static fn ($row): array => [
            $row->id,
            $row->connection_id,
            $row->provider instanceof \BackedEnum ? $row->provider->value : $row->provider,
            $row->kind instanceof \BackedEnum ? $row->kind->value : $row->kind,
            $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            $row->external_ref,
            $row->currency,
            $row->gross_minor,
            $row->fee_minor,
            $row->fee_tax_minor,
            $row->net_minor,
            $row->provider_settled_at?->toIso8601String(),
            $row->payout_id,
            $row->order_payment_id,
            // Cột tường minh thay vì bắt người đọc suy từ ô rỗng — một ô trống
            // trong Excel có quá nhiều cách hiểu.
            $row->order_payment_id === null ? 'no' : 'yes',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/settlements/batches',
        summary: 'Các lô báo cáo đối soát đã nhập, kèm số dòng khớp / không khớp',
        tags: ['HQ'],
        responses: [new OA\Response(response: 200, description: 'Trang lô báo cáo')],
    )]
    public function batches(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $page = SettlementReportBatch::query()
            ->whereIn('connection_id', $this->brandConnectionIds($request))
            ->when($request->filled('connection_id'), fn ($q) => $q->where('connection_id', $request->string('connection_id')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('imported_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => array_map(fn (SettlementReportBatch $batch): array => [
                'id' => $batch->id,
                'connection_id' => $batch->connection_id,
                'provider' => $batch->provider,
                'cycle_label' => $batch->cycle_label,
                'row_count' => (int) $batch->row_count,
                'matched_count' => (int) $batch->matched_count,
                'orphan_count' => (int) $batch->orphan_count,
                'status' => $batch->status,
                'imported_at' => $batch->imported_at?->toIso8601String(),
            ], $page->items()),
            'meta' => $this->pageMeta($page),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/settlements/payouts',
        summary: 'Payout của cổng về tài khoản ngân hàng (tiền thực sự rời khỏi cổng)',
        tags: ['HQ'],
        responses: [new OA\Response(response: 200, description: 'Trang payout')],
    )]
    public function payouts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $page = GatewayPayout::query()
            ->whereIn('connection_id', $this->brandConnectionIds($request))
            ->when($request->filled('connection_id'), fn ($q) => $q->where('connection_id', $request->string('connection_id')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('expected_arrival_date')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => array_map(fn (GatewayPayout $payout): array => [
                'id' => $payout->id,
                'connection_id' => $payout->connection_id,
                'provider' => $payout->provider,
                'external_payout_id' => $payout->external_payout_id,
                'gross_minor' => (int) $payout->gross_minor,
                'fee_minor' => (int) $payout->fee_minor,
                'net_minor' => (int) $payout->net_minor,
                'currency' => $payout->currency,
                'status' => $payout->status,
                'expected_arrival_date' => $payout->expected_arrival_date?->toDateString(),
                'paid_at' => $payout->paid_at?->toIso8601String(),
                'reconciled_at' => $payout->reconciled_at?->toIso8601String(),
                'bank_ref' => $payout->bank_ref,
            ], $page->items()),
            'meta' => $this->pageMeta($page),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/settlements/aging',
        summary: 'Tuổi nợ của tiền chờ payout, theo connection + currency',
        description: 'Σ payment_settlements.net_minor ở trạng thái pending_payout, chia theo bucket ngày. Không đọc cột estimate (G1).',
        tags: ['HQ'],
        responses: [new OA\Response(response: 200, description: 'Bảng tuổi nợ')],
    )]
    public function aging(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $connectionIds = $this->brandConnectionIds($request);

        if ($request->filled('connection_id')) {
            $requested = $request->string('connection_id')->toString();
            // Lọc theo danh sách của brand TRƯỚC khi hỏi service: service không
            // biết brand, và `pendingPayoutAging(null)` quét MỌI connection của
            // MỌI tenant. Một connection_id không thuộc brand này phải ra rỗng,
            // không được ra dữ liệu của người khác.
            $connectionIds = in_array($requested, $connectionIds, true) ? [$requested] : [];
        }

        $rows = [];
        foreach ($connectionIds as $connectionId) {
            foreach ($this->aging->pendingPayoutAging($connectionId) as $row) {
                $rows[] = $row;
            }
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Connection của brand đang mở — nguồn scoping DUY NHẤT của controller này.
     *
     * Mọi endpoint lọc `whereIn` trên danh sách này trước khi áp bộ lọc của
     * người dùng, nên một `connection_id` gõ tay thuộc brand khác trả về rỗng
     * chứ không rò dữ liệu. `brand_id` lấy từ attribute mà middleware brand đặt,
     * cùng đường mà PaymentGatewayConnectionController đang dùng.
     *
     * @return list<string>
     */
    #[OA\Get(path: '/api/v1/hq/{brandSlug}/settlements/batches/export', summary: 'Xuất CSV lô báo cáo', tags: ['HQ'], responses: [new OA\Response(response: 200, description: 'CSV')])]
    public function batchesExport(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $query = SettlementReportBatch::query()
            ->whereIn('connection_id', $this->brandConnectionIds($request))
            ->when($request->filled('connection_id'), fn ($q) => $q->where('connection_id', $request->string('connection_id')->toString()))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderBy('imported_at')
            ->orderBy('id');

        return $this->streamCsv('settlement-batches', [
            'batch_id', 'connection_id', 'provider', 'cycle_label',
            'row_count', 'matched_count', 'orphan_count', 'status', 'imported_at',
        ], $query, static fn ($b): array => [
            $b->id,
            $b->connection_id,
            $b->provider instanceof \BackedEnum ? $b->provider->value : $b->provider,
            $b->cycle_label,
            (int) $b->row_count,
            (int) $b->matched_count,
            (int) $b->orphan_count,
            $b->status instanceof \BackedEnum ? $b->status->value : $b->status,
            $b->imported_at?->toIso8601String(),
        ]);
    }

    #[OA\Get(path: '/api/v1/hq/{brandSlug}/settlements/payouts/export', summary: 'Xuất CSV payout', tags: ['HQ'], responses: [new OA\Response(response: 200, description: 'CSV')])]
    public function payoutsExport(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $query = GatewayPayout::query()
            ->whereIn('connection_id', $this->brandConnectionIds($request))
            ->when($request->filled('connection_id'), fn ($q) => $q->where('connection_id', $request->string('connection_id')->toString()))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency', $request->string('currency')->toString()))
            ->orderBy('paid_at')
            ->orderBy('id');

        return $this->streamCsv('settlement-payouts', [
            'payout_id', 'connection_id', 'provider', 'external_payout_id',
            'currency', 'gross_minor', 'fee_minor', 'net_minor',
            'status', 'expected_arrival_date', 'paid_at', 'reconciled_at', 'bank_ref',
        ], $query, static fn ($p): array => [
            $p->id,
            $p->connection_id,
            $p->provider instanceof \BackedEnum ? $p->provider->value : $p->provider,
            $p->external_payout_id,
            $p->currency,
            (int) $p->gross_minor,
            (int) $p->fee_minor,
            (int) $p->net_minor,
            $p->status instanceof \BackedEnum ? $p->status->value : $p->status,
            $p->expected_arrival_date?->toDateString(),
            $p->paid_at?->toIso8601String(),
            $p->reconciled_at?->toIso8601String(),
            $p->bank_ref,
        ]);
    }

    /**
     * Streamer CSV dùng chung cho MỌI export của màn Settlements.
     *
     * Bốn tab xuất bốn tài nguyên khác nhau, nhưng ba tính chất phải giống hệt
     * nhau ở cả bốn — và đó là lý do gom vào một chỗ thay vì chép bốn lần:
     * **BOM UTF-8**, **không phân trang**, **chunkById**. Chép ra là mở đường
     * cho một tab quên BOM hoặc quên bỏ phân trang, mà cả hai đều hỏng im lặng:
     * mojibake thì người dùng đổ cho Excel, còn thiếu dòng thì không ai thấy.
     *
     * @param  list<string>  $headers
     * @param  callable(mixed): list<mixed>  $toRow
     */
    private function streamCsv(string $name, array $headers, $query, callable $toRow): StreamedResponse
    {
        $filename = "{$name}-".now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headers, $query, $toRow): void {
            $out = fopen('php://output', 'wb');

            // BOM: kế toán Nhật mở CSV bằng Excel, và Excel không có BOM đọc
            // UTF-8 thành mojibake. Ba byte đổi file từ "không dùng được" sang
            // "mở phát ăn ngay".
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);

            // chunkById, KHÔNG paginate: một file kế toán bị cắt vẫn mở được,
            // vẫn cộng ra một con số, và con số đó sai mà không có dấu hiệu gì.
            $query->chunkById(500, function ($rows) use ($out, $toRow): void {
                foreach ($rows as $row) {
                    fputcsv($out, $toRow($row));
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function brandConnectionIds(Request $request): array
    {
        return PaymentGatewayConnection::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('brand_id', (string) $request->attributes->get('brand_id'))
            ->pluck('id')
            ->all();
    }

    /**
     * Dòng settlement — danh sách trường là ALLOWLIST, không phải `toArray()`.
     *
     * `toArray()` sẽ trả mọi cột model hiện có VÀ mọi cột thêm sau này, nên một
     * cột estimate hay một cột nội bộ thêm về sau sẽ tự động rò ra API mà không
     * ai sửa file này. Với dữ liệu tiền thì mặc định phải là "không trả gì trừ
     * khi khai".
     *
     * @return array<string, mixed>
     */
    private function settlementPayload(PaymentSettlement $row): array
    {
        return [
            'id' => $row->id,
            'connection_id' => $row->connection_id,
            'provider' => $row->provider,
            'kind' => $row->kind,
            'order_payment_id' => $row->order_payment_id,
            'gross_minor' => (int) $row->gross_minor,
            'fee_minor' => (int) $row->fee_minor,
            'fee_tax_minor' => (int) $row->fee_tax_minor,
            'net_minor' => (int) $row->net_minor,
            'currency' => $row->currency,
            'source' => $row->source,
            'external_ref' => $row->external_ref,
            'report_batch_id' => $row->report_batch_id,
            'payout_id' => $row->payout_id,
            'status' => $row->status,
            'provider_settled_at' => $row->provider_settled_at?->toIso8601String(),
        ];
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 50);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array<string, int>
     */
    private function pageMeta($page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ];
    }
}
