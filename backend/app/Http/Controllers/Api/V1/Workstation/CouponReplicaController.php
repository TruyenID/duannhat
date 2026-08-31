<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Omnify\Enums\CouponStatusEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/workstation/coupons
 *
 * Replica feed for the workstation's PullCoupons. Returns the active
 * coupon list scoped to the device's organization so LAN-mode
 * apply-coupon can validate (window, cap, per-customer limit, branch
 * whitelist) without a Cloud round-trip.
 *
 * Wire schema (Plan: workstation full coupon parity) uses backend column
 * names verbatim — `min_order_subtotal`, `max_discount_cap`,
 * `usage_limit_per_customer` — so the workstation table can mirror with
 * no rename map. Legacy field names (`min_order_amount`, `max_discount`,
 * `per_customer_limit`) ALSO ship for one rollout window so older
 * workstation builds still get a usable replica during deploy windows.
 *
 * Cloud retains usage_limit + per-customer authority — the workstation
 * pre-validates against this replica's `times_used` snapshot, then sync
 * UP applies the redemption and Cloud may roll back with
 * COUPON_USAGE_EXCEEDED if the cap has been hit by another terminal in
 * the meantime.
 */
class CouponReplicaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $orgId = $device?->organization_id;
        if (! $orgId) {
            return response()->json(['data' => []]);
        }

        // Eager-load branches[] for the branch whitelist. Workstation
        // enforces CouponService::validateBranch locally so LAN-offline
        // staff can't apply a coupon scoped to a sibling branch.
        // Stored coupon status is draft|paused (CouponStatusEnum); the
        // active|scheduled|expired|exhausted transitions are derived at
        // read-time from valid_from/until + usage_limit_total/times_used.
        // We forward both stored statuses to the workstation — its
        // CouponEngine.computeStatus runs the same derivation locally
        // and rejects scheduled/expired/exhausted/paused at apply time.
        $rows = Coupon::query()
            ->with(['branches:id', 'translations:id,coupon_id,locale,name,description'])
            ->where('organization_id', $orgId)
            ->orderBy('code')
            ->get([
                'id', 'code', 'name', 'description',
                'discount_type', 'discount_value',
                'min_order_subtotal',
                'max_discount_cap',
                'valid_from', 'valid_until',
                'usage_limit_total', 'times_used',
                'usage_limit_per_customer',
                'status',
                'brand_id', 'organization_id',
            ]);

        return response()->json([
            'data' => $rows->map(function (Coupon $c) {
                $maxCap = $c->max_discount_cap;
                $perCustomer = $c->usage_limit_per_customer;
                $minSubtotal = (int) round((float) $c->min_order_subtotal);
                $maxCapInt = $maxCap !== null ? (int) round((float) $maxCap) : null;
                $perCustInt = $perCustomer !== null ? (int) $perCustomer : null;

                return [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'description' => $c->description,
                    'discount_type' => $c->discount_type instanceof \BackedEnum ? $c->discount_type->value : $c->discount_type,
                    // #2118 — GIỮ NGUYÊN nghĩa cũ: số nguyên đã làm tròn, cho máy
                    // trạm chưa cập nhật. Đổi nghĩa trường này là lệch 100 LẦN
                    // trong cửa sổ giữa hai lần deploy (Cloud trước, quán sau):
                    // một coupon 12,5% sẽ tới máy trạm cũ như 1250%.
                    'discount_value' => (int) round((float) $c->discount_value),
                    // #2118 — giá trị CHÍNH XÁC, nhân 100 để tránh số thực trên
                    // dây và trong SQLite của máy trạm.
                    //
                    //	percent 12,5  →  1250   (đọc như basis point)
                    //	fixed   5,25  →   525
                    //
                    // Nhân 100 chứ không phải "basis point cho riêng percent":
                    // `(int) round()` cũ cắt cụt CẢ HAI loại. Cột là
                    // `decimal(12,2)`, nên coupon cố định $5,25 của một quán USD
                    // đang tới máy trạm thành $5 — cùng một lỗi truyền tải, chỉ
                    // chưa ai đo. Một trường phục vụ cả hai thì không còn chỗ cho
                    // loại thứ hai lọt.
                    //
                    // Máy trạm mới ưu tiên trường này; máy trạm cũ không biết nó
                    // nên chạy y hệt hôm nay ⇒ KHÔNG ràng buộc thứ tự deploy.
                    // Cùng khuôn với cặp `min_order_subtotal`/`min_order_amount`
                    // ngay bên dưới.
                    'discount_value_x100' => (int) round((float) $c->discount_value * 100),
                    // Backend-native field names (workstation reads these
                    // post-migration 027).
                    'min_order_subtotal' => $minSubtotal,
                    'max_discount_cap' => $maxCapInt,
                    'usage_limit_per_customer' => $perCustInt,
                    // Legacy names — kept for one rollout window so a
                    // workstation that hasn't applied migration 027 yet
                    // still ingests the row. Drop after all sites are
                    // on >= 027.
                    'min_order_amount' => $minSubtotal,
                    'max_discount' => $maxCapInt,
                    'per_customer_limit' => $perCustInt,
                    'valid_from' => optional($c->valid_from)?->toIso8601String() ?? '',
                    'valid_until' => optional($c->valid_until)?->toIso8601String() ?? '',
                    'usage_limit_total' => $c->usage_limit_total,
                    'times_used' => (int) $c->times_used,
                    'status' => $c->status instanceof \BackedEnum ? $c->status->value : $c->status,
                    'brand_id' => $c->brand_id,
                    'organization_id' => $c->organization_id,
                    // Branch whitelist — empty array means "all branches"
                    // (CouponService::validateBranch convention).
                    'branches' => $c->branches->pluck('id')->all(),
                    // Phase C: i18n rows. Empty when no translations
                    // have been authored; device falls back to the
                    // canonical name/description.
                    'translations' => $c->translations->map(fn ($t) => [
                        'locale' => $t->locale,
                        'name' => $t->name,
                        'description' => $t->description,
                    ])->all(),
                    // Workstation-side stacking flags. Backend doesn't
                    // store these on coupons (single-coupon-per-order is
                    // enforced via the unique constraint on
                    // coupon_redemptions.customer_order_id). Safe
                    // defaults so workstation engine keeps its existing
                    // semantics.
                    'stacking_mode' => 'exclusive',
                    'exclusive_with_promotions' => false,
                    'applies_to' => 'order',
                ];
            })->all(),
        ]);
    }
}
