<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\TableSession;
use App\Services\Customer\CustomerTableSessionService;
use App\Services\Shop\BrandSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerTableController extends Controller
{
    public function __construct(
        private readonly CustomerTableSessionService $tableSessions,
        private readonly BrandSettingsService $brandSettings,
    ) {}

    public function show(string $qrToken): JsonResponse
    {
        $table = Table::where('qr_token', $qrToken)
            ->where('is_active', true)
            ->with(['zone:id,name', 'branch', 'branch.brand'])
            ->first();

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $branch = $table->branch;
        $brand = $branch?->brand;
        // BR-SOS05: kéo currency/tax/service từ ShopOrderSetting để FE customer-web
        // (dine-in flow) đồng bộ ngay khi load /tables/{qrToken} mà không cần
        // re-fetch /branches. Trước đây thiếu → FE fallback JPY → UI không đổi
        // sau khi shop chỉnh setting.
        $setting = $branch ? ShopOrderSetting::where('branch_id', $branch->id)->first() : null;

        return response()->json([
            'data' => [
                'table' => [
                    'id' => $table->id,
                    'number' => $table->code ?? $table->name,
                    'seats' => $table->seat_count,
                    'status' => $table->status,
                    'qr_token' => $table->qr_token,
                ],
                'zone' => $table->zone ? [
                    'id' => $table->zone->id,
                    'name' => $table->zone->name,
                ] : null,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'slug' => $branch->slug,
                    'code' => $branch->code,
                    'address' => $branch->address,
                    'phone' => $branch->phone,
                    'img_branches' => $branch->img_branches,
                    // #936 — per-breakpoint banners (see CustomerBranchController).
                    ...$branch->bannerUrls(),
                    'logo' => $branch->logo,
                    'business_hours' => $branch->business_hours,
                    'weekly_hours' => $branch->weekly_hours,
                    // #1447 — `weekly_hours` is a WALL CLOCK at the branch, so it is
                    // unreadable without the zone it was written in. Omitting this
                    // made the dine-in menu fall back to the CUSTOMER's device clock
                    // (customer-web `opening-hours.ts` → `atBranch`), so a phone in
                    // Vietnam judged a Tokyo shop two hours early and showed "Cửa
                    // hàng đã đóng cửa" while it was open. Keep in step with
                    // CustomerBranchController, which has carried it since #1160.
                    'timezone' => $branch->timezone,
                    'seat_capacity' => $branch->seat_capacity !== null ? (int) $branch->seat_capacity : null,
                    // Cast (float) — Eloquent decimal cast mặc định trả string,
                    // FE gọi .toFixed() trên string → TypeError. Endpoint /branches
                    // cũng cast tương tự nên giữ behavior nhất quán giữa 2 endpoint.
                    'review_avg_rating' => $branch->review_avg_rating !== null ? (float) $branch->review_avg_rating : null,
                    'review_total_count' => $branch->review_total_count !== null ? (int) $branch->review_total_count : 0,
                    // plan-043 T6.2 — legacy branch tax_rate dropped; consumption
                    // tax is per-line via brand tax types.
                    //
                    // #1778 — `prices_include_tax` PHẢI có ở đây, không chỉ ở
                    // `/customer/branches`. Màn dine-in ghi đè `currentBranch`
                    // bằng payload của endpoint NÀY, nên một cờ vắng mặt không
                    // im lặng giữ giá trị cũ — nó thành `false`, và menu dán
                    // nhãn "Chưa gồm thuế" lên đúng những giá ĐÃ gồm thuế.
                    //
                    // Sai theo hướng đắt hơn: khách đọc ￥1,300 rồi tự cộng thêm
                    // 8–10%. Và nó lật theo từng lần load — hai endpoint đua
                    // nhau, cái nào về sau thì thắng — nên nhìn qua tưởng lỗi
                    // hiển thị ngẫu nhiên chứ không phải thiếu một trường.
                    //
                    // Giữ NGUYÊN cách đọc của `/customer/branches` (cùng
                    // `$setting`, cùng default `false`); lệch nhau ở đây là tạo
                    // ra đúng loại "hai màn nói ngược" mà issue mô tả.
                    'prices_include_tax' => (bool) ($setting?->prices_include_tax ?? false),
                    'service_charge_rate' => (float) ($setting?->service_charge_rate ?? 0),
                    // Default VND để khớp pipeline định giá + charge currency (#815).
                    'currency_code' => (string) ($setting?->currency_code ?? 'VND'),
                    'brand' => $brand ? [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                        'logo_url' => $brand->logo_url,
                        // #2047 — giải từ id File lúc đọc (rơi về cột URL cũ khi
                        // brand chưa chuyển). Tên trường giữ nguyên nên
                        // customer-web không phải đổi gì.
                        'customer_header_logo_url' => $this->brandSettings->logoUrl($brand, 'customer_header_logo_file_id', 'customer_header_logo_url'),
                        'customer_order_logo_url' => $this->brandSettings->logoUrl($brand, 'customer_order_logo_file_id', 'customer_order_logo_url'),
                        'customer_order_subtitle' => $brand->customer_order_subtitle,
                    ] : null,
                ] : null,
            ],
        ]);
    }

    public function callStaff(string $qrToken): JsonResponse
    {
        $table = Table::where('qr_token', $qrToken)
            ->where('is_active', true)
            ->with('branch.brand')
            ->first();

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $table->update(['call_requested_at' => now()]);

        return response()->json([
            'data' => [
                'called_at' => $table->call_requested_at->toISOString(),
                'table_number' => $table->code ?? $table->name,
                'branch_id' => $table->branch_id,
                'brand_id' => $table->branch?->brand?->id,
                'message' => 'Staff has been called.',
            ],
        ]);
    }

    /**
     * Customer confirms seating at a free table (QR scan → "Đồng ý").
     * Transitions status from `free` → `occupied`.
     *
     * Idempotent: repeated calls on an already-occupied table return 200.
     * Any other source status (cleaning / reserved / out_of_service / paid)
     * returns 409 so the frontend can show the appropriate blocker.
     *
     * Concurrent scans are serialised via `lockForUpdate` — only the first
     * caller wins; the second sees `occupied` and takes the idempotent path.
     *
     * No `table_status_changes` row is written: BR-TSC03 requires the
     * `changed_by_id` column to reference a real SSO user, and the customer
     * flow is anonymous.
     */
    public function occupy(string $qrToken): JsonResponse
    {
        return response()->json(['data' => $this->tableSessions->occupy($qrToken)]);
    }

    /**
     * plan-034 — Multi-device join endpoint that replaces `/occupy`.
     *
     * Behaviour per `tables.status`:
     *
     *   - `free`                → flip to `occupied`, INSERT TableSession,
     *                             return {status: "joined", session, order: null}
     *   - `occupied`            → lookup TableSession.status=open for this
     *                             table, return {status: "joined", session,
     *                             order: <existing open CustomerOrder or null>}
     *   - `paid`                → return {status: "paid_recent", paid_order, can_start_new_session: true}.
     *                             FE shows a "Đặt thêm món" button that re-calls
     *                             this endpoint with `?force_new=true`.
     *   - `paid` + force_new=1  → flip paid→occupied, open a new session.
     *   - `cleaning` / `reserved` / `out_of_service` → 423 Locked.
     *
     * Idempotent: a device that already holds the open session just gets
     * the same session back. Concurrent scans are serialised via
     * `lockForUpdate` on the `tables` row inside the transaction.
     *
     * Same anonymity guarantee as `occupy()`: no `table_status_changes`
     * row written because the customer flow is anonymous.
     */
    public function join(string $qrToken, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->tableSessions->join($qrToken, $request->boolean('force_new')),
        ]);
    }

    /**
     * Customer releases the table they're currently sitting at ("Đổi bàn").
     * occupied → free so another guest can scan and take it. Idempotent when
     * already free; 409 for any other source status.
     */
    public function release(string $qrToken): JsonResponse
    {
        return response()->json(['data' => $this->tableSessions->release($qrToken)]);
    }
}
