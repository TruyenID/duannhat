<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ShopOrderSetting;
use App\Models\Zone;
use App\Services\Customer\CustomerMenuService;
use App\Services\Customer\PayPayAvailabilityService;
use App\Services\Payment\Orchestration\Internal\CustomerWebStripeConnectionResolver;
use App\Services\Shop\BrandSettingsService;
use App\Services\Shop\EffectiveOrderPolicyService;
use Illuminate\Http\JsonResponse;

class CustomerBranchController extends Controller
{
    /**
     * Return all active branches with their brand info.
     * Public endpoint — no auth required.
     */
    public function index(EffectiveOrderPolicyService $policyService, BrandSettingsService $brandSettings): JsonResponse
    {
        try {
            $branches = Branch::where('is_active', true)
                ->with(['brand', 'translations'])
                ->orderBy('name')
                ->get();

            // Pre-load ShopOrderSetting cho tất cả branch trong 1 query để tránh N+1.
            // Branch model project-level không re-declare relation shopOrderSettings
            // (chỉ có ở BranchBaseModel mà Branch không kế thừa), nên truy vấn trực
            // tiếp qua ShopOrderSetting bằng whereIn rồi keyBy('branch_id').
            $settings = ShopOrderSetting::whereIn('branch_id', $branches->pluck('id'))
                // plan-043 T5.5 — eager-load the branch's default tax type so
                // the public payload can carry its 2 rates (店内/持ち帰り) for
                // the 総額表示 preview without an N+1 per branch.
                ->with('defaultTaxType')
                ->get()
                ->keyBy('branch_id');

            $data = $branches->map(function (Branch $branch) use ($settings, $policyService, $brandSettings) {
                $setting = $settings->get($branch->id);
                // plan-035 — bundle effective policy + locale so customer-web
                // brand context has phone country + email-required flag at
                // first paint, no extra round-trip.
                $policy = $policyService->resolve($branch);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'slug' => $branch->slug,
                    'code' => $branch->code,
                    'address' => $branch->address,
                    'phone' => $branch->phone,
                    'img_branches' => $branch->img_branches,
                    // #936 — per-breakpoint banners; customer-web picks one
                    // with <picture><source media> and falls back down the
                    // chain (mobile → tablet → desktop → img_branches).
                    ...$branch->bannerUrls(),
                    'logo' => $branch->logo,
                    'seat_capacity' => $branch->seat_capacity,
                    'business_hours' => $branch->business_hours,
                    'weekly_hours' => $branch->weekly_hours,
                    // #1160 — weekly_hours is a WALL CLOCK at the shop, so the
                    // client needs the shop's zone to judge a pickup slot the
                    // same way the server does (#1091). Without it a Hanoi
                    // customer would test a Tokyo shop's 22:00 against UTC+7.
                    'timezone' => $branch->timezone,
                    'service_charge_rate' => (float) ($setting?->service_charge_rate ?? 0),
                    // plan-043 T5.5 — per-rate consumption-tax fields for
                    // customer-web's 総額表示 (tax-included) previews. Additive:
                    // the legacy flat `tax_rate` above is KEPT until Phase 6 so
                    // already-deployed clients keep working. `default_tax_type`
                    // carries the branch default's 2 rates so the client can
                    // pick dine_in vs takeaway locally.
                    'prices_include_tax' => (bool) ($setting?->prices_include_tax ?? false),
                    'service_charge_tax_rate' => (float) ($setting?->service_charge_tax_rate ?? 0),
                    'default_tax_type' => $setting?->defaultTaxType ? [
                        'id' => $setting->defaultTaxType->id,
                        'code' => $setting->defaultTaxType->code,
                        'rate' => (float) $setting->defaultTaxType->rate,
                    ] : null,
                    // Currency hiển thị do shop chọn (default JPY khi chưa cấu hình).
                    // FE dùng Intl.NumberFormat(locale, { style: 'currency', currency }).
                    'currency_code' => (string) ($setting?->currency_code ?? 'JPY'),
                    'split_bill_rounding_mode' => (string) ($setting?->split_bill_rounding_mode ?? 'auto'),
                    'locale' => $branch->locale,
                    'effective_order_policy' => $policy,
                    'review_avg_rating' => $branch->review_avg_rating ? (float) $branch->review_avg_rating : null,
                    'review_total_count' => (int) ($branch->review_total_count ?? 0),
                    'brand' => $branch->brand ? [
                        'id' => $branch->brand->id,
                        'name' => $branch->brand->name,
                        'slug' => $branch->brand->slug,
                        'logo_url' => $branch->brand->logo_url,
                        // #2047 — xem CustomerTableController: URL giải từ id File
                        // lúc đọc, tên trường giữ nguyên cho customer-web.
                        'customer_header_logo_url' => $brandSettings->logoUrl($branch->brand, 'customer_header_logo_file_id', 'customer_header_logo_url'),
                        'customer_order_logo_url' => $brandSettings->logoUrl($branch->brand, 'customer_order_logo_file_id', 'customer_order_logo_url'),
                        'customer_order_subtitle' => $branch->brand->customer_order_subtitle,
                    ] : null,
                ];
            });

            return response()->json(['data' => $data]);
        } catch (\Throwable $e) {
            \Log::error('[CustomerBranchController::index] Failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to load branches',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return all active zones with their active tables for a branch.
     * Public endpoint — used by the dine-in table picker.
     */
    public function zones(string $branchSlug): JsonResponse
    {
        $branch = Branch::where('slug', $branchSlug)
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $zones = Zone::where('branch_id', $branch->id)
            ->where('is_active', true)
            ->with(['tables' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->orderBy('display_order')
            // #3170 — zones.display_order defaults to 0, so it ties just as menu
            // rows do; tie-break on the unique id.
            ->orderBy('zones.id')
            ->get();

        $data = $zones->map(fn (Zone $zone) => [
            'id' => $zone->id,
            'name' => $zone->name,
            'tables' => $zone->tables->map(fn ($table) => [
                'id' => $table->id,
                'number' => $table->code ?? $table->name,
                'seats' => $table->seat_count,
                'status' => $table->status->value,
                'qr_token' => $table->qr_token,
            ]),
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Return cart timeout configuration for a branch.
     * Resolves effective timeout via 4-tier cascade: shop menu → shop default → HQ menu → HQ brand.
     * Returns null for timeout_minutes if no tier has a value (= no cart expiration enforcement).
     *
     * Uses CustomerMenuService to find the ACTIVE menu (priority + schedule aware), then
     * extracts just the timeout + end_time fields needed by customer-web for cart deadline logic.
     *
     * Public endpoint — used by customer-web when adding first item to cart.
     */
    public function getCartConfig(string $branchSlug, CustomerMenuService $menuService, EffectiveOrderPolicyService $policyService): JsonResponse
    {
        $branch = Branch::where('slug', $branchSlug)
            ->where('is_active', true)
            ->with('brand')
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        // Use the same menu resolution logic as /menu endpoint (priority + schedule aware).
        // getMenuForBranch() returns null when no menu matches current day/time.
        $menuData = $menuService->getMenuForBranch((string) $branch->id);

        $effectiveTimeout = $menuData['cart_timeout_minutes'] ?? null;
        $currentEndTime = $menuData['schedule_end_time'] ?? null;

        // plan-035 — effective payment policy + phone-validation country.
        // Customer-web takeaway checkout reads this on mount: gates email
        // required-ness, shows "Phải pay trước" banner, drives
        // libphonenumber country code.
        $policy = $policyService->resolve($branch);

        return response()->json([
            'data' => [
                'effective_timeout_minutes' => $effectiveTimeout,
                'current_menu_end_time' => $currentEndTime,
                'effective_order_policy' => $policy,
            ],
        ]);
    }

    /**
     * Plan-048 T2.5 — the payment-policy identity customer-web is about to pay
     * under: the published revision + the effective customer_web Stripe option
     * id. The client fetches this on checkout/pay mount and echoes the pair
     * back on the *-payment-intent call so the server can log drift (policy
     * republished between mount and pay). Both fields are null when the branch
     * has no policy-backed Stripe option yet (legacy global-connection era) —
     * the client then simply sends no hint.
     *
     * Ids only, never provider/connection detail: this is a public endpoint.
     */
    public function paymentContext(
        string $branchSlug,
        CustomerWebStripeConnectionResolver $resolver,
        PayPayAvailabilityService $paypay,
    ): JsonResponse {
        $branch = Branch::where('slug', $branchSlug)
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $refs = $resolver->resolveForBranch($branch);
        $paypayAvailability = $paypay->forBranch($branch);
        // #2806 — may legitimately be null: a branch that never opened the
        // settings screen has no row, and the defaults below cover it.
        $setting = ShopOrderSetting::where('branch_id', $branch->id)->first();

        return response()->json([
            'data' => [
                'policy_revision' => $refs['policyRevision'] ?? null,
                'gateway_option_id' => $refs['optionId'] ?? null,
                // #1125 option B — when true the pay page shows Stripe's
                // dynamic method tabs (Konbini, 銀行振込…) and treats
                // processing/voucher confirms as awaiting, not failure.
                'async_payment_methods_enabled' => (bool) config('payments.async_payment_methods.enabled', false),
                // plan-054 — a boolean, deliberately: the PayPay radio renders
                // either way, and this only decides whether choosing it runs the
                // QR flow or keeps today's settle-by-hand label. A booleanish
                // answer also honours the "ids only" rule above — the effective
                // option array carries connection ids and the full policy trace.
                'paypay_enabled' => $paypayAvailability['enabled'],
                // #2806 — the shop's own answer about the pay-at-counter
                // channel, replacing a rule customer-web used to DERIVE from
                // gateway state (#2545: "offer counter only when nothing online
                // works"). That rule flipped three times because nobody could
                // set it; now the branch sets it.
                //
                // The `?? true` defaults are the whole reason this is safe to
                // ship: a branch with no shop_order_settings row keeps behaving
                // exactly as it does today. They must stay in step with the
                // admin-facing defaults in ShopOrderSettingsController.
                'counter_pay_enabled' => (bool) ($setting?->counter_pay_enabled ?? true),
                // Only hides the QR. The payload behind it (the guest's chosen
                // split amount and per-item units) is unaffected — that is the
                // sole path carrying those numbers to the kiosk.
                //
                // #3206 — fallback `false`, khớp default mới của cột. HAI mặc
                // định phải cùng một câu trả lời: đổi mỗi DDL thì chi nhánh
                // CHƯA CÓ HÀNG `shop_order_settings` vẫn nhận `true` từ đây, và
                // yêu cầu "bỏ QR" lại không đạt đúng ở nhóm chưa cấu hình —
                // nhóm đông nhất khi mở chi nhánh mới.
                'counter_pay_show_qr' => (bool) ($setting?->counter_pay_show_qr ?? false),
            ],
        ]);
    }
}
