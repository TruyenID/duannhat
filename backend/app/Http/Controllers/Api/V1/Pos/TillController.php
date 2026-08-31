<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\DenominationResource;
use App\Http\Resources\Pos\TillSessionResource;
use App\Http\Resources\Pos\TillTenderTypeResource;
use App\Models\Denomination;
use App\Models\PeripheralDevice;
use App\Models\TillSession;
use App\Models\TillTenderCategory;
use App\Services\Pos\TillSessionService;
use App\Services\Till\TenderTypeResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plan 030 — Read endpoints for the cashier-shift surface.
 *
 *   GET /pos/till/current        — Till + open session (or null)
 *   GET /pos/till/denominations  — denomination master for a currency
 *   GET /pos/till/tender-types   — branch-scoped reconciliation tender list
 */
class TillController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TillSessionService $sessions,
    ) {}

    public function current(Request $request): JsonResponse
    {
        $this->authorize('viewCurrent', TillSession::class);

        $branchId = $request->attributes->get('shop_id');
        $current = $this->sessions->currentForBranch($branchId);

        return response()->json([
            'data' => [
                'till' => [
                    'id' => $current['till']->id,
                    'till_code' => $current['till']->till_code,
                    'default_currency_code' => $current['till']->default_currency_code,
                    'variance_tolerance_amount' => (float) $current['till']->variance_tolerance_amount,
                    'current_session_id' => $current['till']->current_session_id,
                ],
                'open_session' => $current['open_session']
                    ? (new TillSessionResource($current['open_session']->loadMissing([
                        'openingCounts.denomination',
                        'closingCounts',
                        'cashEvents',
                    ])))->toArray($request)
                    : null,
            ],
        ]);
    }

    /**
     * Plan-044 R2 — gap-payment preview for the shift-open reconciliation panel.
     * Lists the branch's unattributed payments taken during the previous shift's
     * close-gap so the cashier can confirm which to attribute to the new shift
     * (see TillSessionService::gapPreview). No open session required — this is
     * read before opening ca-2.
     *
     * `?till_code=` (tuỳ chọn, #2724) — két mà màn hình sắp mở ca. Client nào
     * mở ca bằng két khác MAIN thì phải truyền nó ở CẢ đây lẫn `POST …/open`:
     * đường ghi (claim) đo cửa sổ theo két của lượt mở, nên hai đầu lệch két là
     * tiền được tích trên màn hình mà không vào ca nào. Bỏ trống ⇒ MAIN ở cả
     * hai đầu.
     */
    public function gapPreview(Request $request): JsonResponse
    {
        $this->authorize('viewCurrent', TillSession::class);

        $branchId = $request->attributes->get('shop_id');

        return response()->json([
            'data' => $this->sessions->gapPreview($branchId, $this->tillCodeFrom($request)),
        ]);
    }

    /**
     * #2696 — đơn còn treo tiền, đọc ở CÙNG màn mở ca với gapPreview.
     *
     * Cùng màn hình nhưng KHỐI RIÊNG: gapPreview đo tiền đã vào mà chưa gắn ca,
     * cái này đo đơn có thể chưa thu tiền. Hai động từ khác nhau — gắn ca vs
     * truy rồi đóng/huỷ đơn.
     *
     * Không cần ca đang mở: nó được đọc TRƯỚC khi mở ca kế.
     *
     * `?till_code=` (tuỳ chọn, #2724) — cùng ý nghĩa với `gapPreview`.
     */
    public function unresolvedOrders(Request $request): JsonResponse
    {
        $this->authorize('viewCurrent', TillSession::class);

        $branchId = $request->attributes->get('shop_id');

        return response()->json([
            'data' => $this->sessions->unresolvedOrdersPreview($branchId, $this->tillCodeFrom($request)),
        ]);
    }

    /**
     * `till_code` từ query string, đã chuẩn hoá (#2724).
     *
     * Chuỗi rỗng ⇒ null: một client gửi `?till_code=` phải rơi về đường tự giải,
     * chứ không đi tìm một két mang mã rỗng (và `resolveTillForBranch` sẽ TẠO
     * đúng cái két rác đó).
     */
    private function tillCodeFrom(Request $request): ?string
    {
        $code = $request->query('till_code');

        if (! is_string($code)) {
            return null;
        }

        $code = trim($code);

        return $code === '' ? null : $code;
    }

    public function denominations(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TillSession::class);

        $currency = $request->query('currency');
        if (! $currency) {
            $branchId = $request->attributes->get('shop_id');
            $till = $this->sessions->resolveTillForBranch($branchId);
            $currency = $till->default_currency_code;
        }

        $rows = Denomination::query()
            ->where('is_active', true)
            ->where('currency_code', strtoupper((string) $currency))
            ->whereNull('organization_id')
            ->orWhere(function ($q) use ($currency, $request) {
                $q->where('currency_code', strtoupper((string) $currency))
                    ->where('organization_id', $request->attributes->get('organization_id'))
                    ->where('is_active', true);
            })
            ->orderByRaw("CASE kind WHEN 'note' THEN 0 WHEN 'coin' THEN 1 ELSE 2 END")
            ->orderByDesc('value')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => DenominationResource::collection($rows)->resolve($request),
        ]);
    }

    public function tenderTypes(Request $request, TenderTypeResolver $resolver): JsonResponse
    {
        $this->authorize('viewAny', TillSession::class);

        // #1156 — the close screen renders the branch's EFFECTIVE tender list:
        // org vocabulary with per-branch overrides applied (an override with
        // is_active=false hides the tender; an active override replaces its
        // org row instead of duplicating it). Also org-scopes the query, which
        // the previous raw filter did not.
        $rows = $resolver->effectiveForBranch(
            (string) $request->attributes->get('organization_id'),
            (string) $request->attributes->get('shop_id'),
        );

        return response()->json([
            'data' => TillTenderTypeResource::collection($rows)->resolve($request),
        ]);
    }

    /**
     * Pos-web close-page reads this to know which categories to render +
     * what label/order. Returns org-wide system rows overlaid with this
     * shop's custom rows; the close-page iterates in `sort_order` and
     * groups tender_types by `category` key.
     */
    public function tenderCategories(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TillSession::class);

        $orgId = $request->attributes->get('organization_id');
        $branchId = $request->attributes->get('shop_id');

        $rows = TillTenderCategory::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get(['id', 'key', 'name', 'sort_order', 'is_system']);

        return response()->json(['data' => $rows]);
    }

    /**
     * #1156 — the branch's registered payment terminals with their
     * `metadata.accepts`, for the POS brand sub-choice and the per-terminal
     * 精算 sections. A thin projection reachable by a paired device (the full
     * peripheral CRUD lives behind Platform SSO, which a device token cannot
     * reach). Metadata is whitelisted to accepts/model — a LAN client has no
     * business seeing another device's host/port, and `secret` must never
     * leave the server.
     */
    public function paymentTerminals(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TillSession::class);

        $rows = PeripheralDevice::query()
            ->where('branch_id', (string) $request->attributes->get('shop_id'))
            ->where('type', 'payment_terminal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'metadata'])
            ->map(fn (PeripheralDevice $device): array => [
                'id' => $device->id,
                'name' => $device->name,
                'is_active' => (bool) $device->is_active,
                'metadata' => [
                    'accepts' => $device->metadata['accepts'] ?? null,
                    'model' => $device->metadata['model'] ?? null,
                ],
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }
}
