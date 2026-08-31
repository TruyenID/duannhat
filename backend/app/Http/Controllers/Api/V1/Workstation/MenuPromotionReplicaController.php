<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\MenuPromotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/workstation/menu-promotions
 *
 * Replica feed for the workstation's PullPromotions. The real
 * `menu_promotions` table stores: discount_percent, applies_to,
 * daily_time_from/to, weekdays (json), valid_from/until,
 * stacking_mode, is_active. There is NO `priority` column, NO
 * `discount_type/value`, NO `starts_at/ends_at`, NO `products`
 * relation and NO separate `schedules` table — schedule lives
 * inline on the row.
 *
 * We map onto the workstation's PullPromotions struct so the wire
 * shape stays stable:
 *   discount_type      → "percent" (the only type the table models;
 *                        matches the workstation CHECK enum
 *                        ('percent','amount','fixed_unit_price') —
 *                        previously emitted as "percentage" which
 *                        aborted the local PullPromotions tx)
 *   discount_value     → discount_percent (rounded int — workstation
 *                        applies percent off as an integer)
 *   starts_at/ends_at  → valid_from/valid_until
 *   exclusive_with_coupons → derived from stacking_mode
 *   priority           → 100 (constant; ordering is by valid_from desc)
 *   product_sku_ids    → [] (per-SKU membership is not modelled at
 *                        this level — promotion's applies_to enum
 *                        targets order/category/menu instead)
 *   schedules[]        → one row built from the inline weekdays
 *                        + daily_time_from/to so the workstation can
 *                        re-run the schedule match against branch-
 *                        local clock.
 */
class MenuPromotionReplicaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;
        $orgId = $device?->organization_id;
        if (! $branchId || ! $orgId) {
            return response()->json(['data' => []]);
        }

        // Eager-load products + categories.products so we can flatten
        // categories into a single product_ids[] for the workstation
        // engine (decision Q-B2 — workstation has no pos_product_categories
        // pivot; backend does the expansion to keep the wire dense).
        $promos = MenuPromotion::query()
            ->with([
                'products:id',
                'categories:id',
                'categories.products:id',
                'translations:id,menu_promotion_id,locale,name,description',
            ])
            ->where('organization_id', $orgId)
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->where('is_active', true)
            ->orderBy('valid_from', 'desc')
            ->get();

        $rows = $promos->map(function (MenuPromotion $p) {
            $weekdays = is_array($p->weekdays) ? $p->weekdays : [0, 1, 2, 3, 4, 5, 6];
            $from = $p->daily_time_from ?? '';
            $to = $p->daily_time_to ?? '';

            $schedules = [];
            foreach ($weekdays as $dow) {
                $schedules[] = [
                    'id' => $p->id.'-'.$dow,
                    'day_of_week' => (int) $dow,
                    'daily_time_from' => $from,
                    'daily_time_to' => $to,
                ];
            }

            $stackingMode = $p->stacking_mode instanceof \BackedEnum
                ? $p->stacking_mode->value
                : ($p->stacking_mode ?? 'stackable_with_coupons');

            $appliesTo = $p->applies_to instanceof \BackedEnum
                ? $p->applies_to->value
                : ($p->applies_to ?? 'products');

            // Flatten products + categories.products into one
            // deduplicated product_ids[]. Workstation engine matches
            // by membership in this list (or `applies_to=all_items`
            // bypasses the check entirely).
            $productIds = collect();
            if (in_array($appliesTo, ['products', 'mixed'], true)) {
                $productIds = $productIds->merge($p->products->pluck('id'));
            }
            if (in_array($appliesTo, ['categories', 'mixed'], true)) {
                foreach ($p->categories as $cat) {
                    $productIds = $productIds->merge($cat->products->pluck('id'));
                }
            }
            $productIds = $productIds->filter()->unique()->values()->all();

            return [
                'id' => $p->id,
                'name' => $p->name ?? '',
                'description' => $p->description,
                'discount_type' => 'percent',
                'discount_value' => (int) round((float) $p->discount_percent),
                'starts_at' => optional($p->valid_from)?->toIso8601String() ?? '',
                'ends_at' => optional($p->valid_until)?->toIso8601String() ?? '',
                'is_active' => (bool) $p->is_active,
                'exclusive_with_coupons' => $stackingMode !== 'stackable_with_coupons',
                'stacking_mode' => $stackingMode,
                'applies_to' => $appliesTo,
                'priority' => 100,
                'branch_id' => $p->branch_id,
                'brand_id' => $p->brand_id,
                'organization_id' => $p->organization_id,
                'created_at' => optional($p->created_at)?->toIso8601String() ?? '',
                // Phase B canonical field — workstation engine matches
                // by membership in this list.
                'product_ids' => $productIds,
                // Legacy: kept empty for pre-028 workstations during
                // the rollout window; they would have ignored Phase B
                // promotions anyway since the field they read no longer
                // exists. Drop after all sites are >= 028.
                'product_sku_ids' => [],
                'schedules' => $schedules,
                // Phase C: i18n rows.
                'translations' => $p->translations->map(fn ($t) => [
                    'locale' => $t->locale,
                    'name' => $t->name,
                    'description' => $t->description,
                ])->all(),
            ];
        });

        return response()->json(['data' => $rows->all()]);
    }
}
