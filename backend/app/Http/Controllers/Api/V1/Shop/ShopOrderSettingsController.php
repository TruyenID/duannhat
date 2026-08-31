<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Shop\EffectiveOrderPolicyService;
use App\Services\Shop\ShopOrderSettingsService;
use App\Services\Shop\VoidableStatusResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Shop-scoped order settings — read + partial update.
 *
 * Endpoints:
 *   GET   /api/v1/shops/{shopSlug}/settings/order
 *   PATCH /api/v1/shops/{shopSlug}/settings/order
 *
 * Known fields (see schemas/Backend/Shop/ShopOrderSetting.yaml):
 *   - default_order_item_status (BR-SOS02/03) — sets the item status for
 *     new items when staff doesn't override. Valid: pending, preparing,
 *     ready, served. Null = pending (system default).
 *   - enable_quick_order (BR-SOS04) — when true, pos-web replaces the
 *     create-order dialog with a one-tap "Tạo nhanh" button that posts an
 *     empty body (floating order: no table, no customer, no guest count).
 *   - service_charge_rate (BR-SOS05) — branch-level service rate used by the
 *     checkout calculator. Consumption tax is now brand TaxTypes (plan-043),
 *     not a branch tax_rate (dropped in T6.2).
 *   - currency_code (BR-SOS06) — display currency for pos-web.
 *   - split_bill_rounding_mode (BR-SOS07) — dine-in split-bill rounding
 *     mode (auto/integer/two_decimals/none).
 *
 * PATCH is strictly partial: only keys actually sent are persisted, so
 * omitting `enable_quick_order` preserves its current value instead of
 * resetting to false. Covered by "leaves enable_quick_order unchanged
 * when PATCH omits the key" in ShopOrderSettingsTest.
 *
 * Open-shift/-chain 409 guard codes (#1130 — FROZEN, match EXACTLY; the
 * three names are inconsistent by history and renaming is a breaking client
 * sweep, so the full set is the contract, not a suffix pattern):
 *   CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT
 *   TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT
 *   TAX_ROUNDING_LOCKED_OPEN_SHIFT
 */
class ShopOrderSettingsController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(private readonly ShopOrderSettingsService $settings) {}

    // =========================================================================
    //  Show
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/settings/order',
        summary: 'Get order settings for this shop',
        tags: ['Shop Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order settings', content: new OA\JsonContent(properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'default_order_item_status', type: 'string', nullable: true, enum: ['pending', 'preparing', 'ready', 'served'], description: 'null = pending (system default)'),
                        new OA\Property(property: 'enable_quick_order', type: 'boolean', description: 'When true, pos-web replaces the create-order dialog with a one-tap "Tạo nhanh" button that posts a floating order (no table/customer/guest).'),
                        // plan-043 BUG-6 — the legacy `tax_rate` property was removed:
                        // the shop_order_settings.tax_rate column was dropped in Phase 6
                        // (T6.2) and is no longer serialized. Superseded by
                        // default_tax_type_id + per-product tax-type assignment.
                        new OA\Property(property: 'service_charge_rate', type: 'string', description: 'Branch service charge rate (%) as string, precision 5, scale 2.'),
                        // plan-043 — consumption-tax settings (軽減税率 / インボイス). See docs/guide/tax-types.md.
                        new OA\Property(property: 'default_tax_type_id', type: 'string', format: 'uuid', nullable: true, description: 'plan-043 — branch-level default TaxType (tier-3 of the resolve chain). null = fall through to the brand default (TaxType.is_default).'),
                        new OA\Property(property: 'prices_include_tax', type: 'boolean', description: 'plan-043 — 総額表示: when true, menu prices already include tax and the engine extracts 内税. Snapshotted per order; the PATCH flip is blocked (409) while a shift is open.'),
                        new OA\Property(property: 'service_charge_tax_rate', type: 'string', description: 'plan-043 — tax rate (%) applied to the service charge, independent of item rates.'),
                        new OA\Property(property: 'close_report_tax_breakdown', type: 'boolean', description: 'plan-043 — gate the per-rate 課税売上/消費税 block on the thermal close report (the Z-report PDF always includes it).'),
                        new OA\Property(property: 'currency_code', type: 'string', description: 'Display currency for pos-web (BR-SOS06).'),
                        new OA\Property(
                            property: 'split_bill_rounding_mode',
                            type: 'string',
                            enum: ['auto', 'integer', 'two_decimals', 'none'],
                            description: 'Dine-in split-bill rounding mode (BR-SOS07).',
                        ),
                        new OA\Property(
                            property: 'available_currencies',
                            type: 'array',
                            items: new OA\Items(type: 'object'),
                            description: 'Selectable display currencies for this shop.',
                        ),
                        new OA\Property(
                            property: 'available_statuses',
                            type: 'array',
                            items: new OA\Items(type: 'object', properties: [
                                new OA\Property(property: 'value', type: 'string'),
                                new OA\Property(property: 'label', type: 'string'),
                                new OA\Property(property: 'description', type: 'string'),
                            ]),
                            description: 'Valid options for default_order_item_status'
                        ),
                    ]
                ),
            ])),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        $setting = ShopOrderSetting::where('branch_id', $shop->id)->first();

        // #491 — resolve the effective "table status after payment" so every
        // app (POS / customer / admin) reads the actual behaviour: shop
        // override ?? HQ brand default ?? 'free'. The raw shop value (null =
        // inherit) is also returned so the Settings page can render the
        // "Theo HQ / Tự chọn" tri-state.
        $brandId = Brand::where('console_brand_id', $shop->console_brand_id)->value('id');
        $brandTableStatusDefault = $brandId
            ? BrandOrderPolicy::where('brand_id', $brandId)->value('default_table_status_after_payment')
            : null;
        $effectiveTableStatusAfterPayment =
            $setting?->table_status_after_payment ?? $brandTableStatusDefault ?? 'free';

        return response()->json([
            'data' => [
                'default_order_item_status' => $setting?->default_order_item_status,
                'enable_quick_order' => (bool) ($setting?->enable_quick_order ?? false),
                // BR-SOS05 — branch-level rates surfaced to pos-web for the
                // checkout draft live-preview. Cast to string to mirror
                // Laravel's Decimal serialisation (precision 5, scale 2).
                'service_charge_rate' => (string) ($setting?->service_charge_rate ?? '0.00'),
                // BR-SOS06 — display currency for pos-web. Default 'VND' for
                // branches that never picked one.
                'currency_code' => (string) ($setting?->currency_code ?? 'VND'),
                // BR-SOS07 — dine-in split-bill rounding mode.
                'split_bill_rounding_mode' => (string) ($setting?->split_bill_rounding_mode ?? 'auto'),
                // BR-SOS08 — plan-035 payment policy + email-required.
                // null preserved as null so the FE can render "Theo HQ" tri-state.
                'prep_before_payment' => $setting?->prep_before_payment,
                'customer_email_required' => (bool) ($setting?->customer_email_required ?? false),
                // Whether the workstation prints the shift-open cash-count slip
                // on shift open. Default true when the branch has no row yet.
                'print_shift_open_report' => (bool) ($setting?->print_shift_open_report ?? true),
                // #1306 — table-paid slip. Default true matches the fallback the
                // workstation has always used, so exposing the switch changes no
                // existing branch's printing behaviour.
                'print_table_paid' => (bool) ($setting?->print_table_paid ?? true),
                // 精算 close-report optional-section toggles (default true).
                'close_report_payment_methods' => (bool) ($setting?->close_report_payment_methods ?? true),
                'close_report_service_charge' => (bool) ($setting?->close_report_service_charge ?? true),
                'close_report_denominations' => (bool) ($setting?->close_report_denominations ?? true),
                'close_report_drawer_check' => (bool) ($setting?->close_report_drawer_check ?? true),
                // plan-043 — per-rate 課税売上/消費税 block on the thermal close report.
                'close_report_tax_breakdown' => (bool) ($setting?->close_report_tax_breakdown ?? true),
                // plan-043 BUG-3 — the three consumption-tax fields the admin-web
                // Settings page hydrates its Tax section from (page.tsx:329-331).
                // They were writable via PATCH but ABSENT from this GET payload, so
                // after a reload the switch/inputs reset to off/0 despite the saved
                // DB values. Serialize them here to close the round-trip.
                'default_tax_type_id' => $setting?->default_tax_type_id,
                'prices_include_tax' => (bool) ($setting?->prices_include_tax ?? false),
                // string to mirror the other Decimal(5,2) fields' serialisation.
                'service_charge_tax_rate' => (string) ($setting?->service_charge_tax_rate ?? '0.00'),
                // plan-045 — tax rounding rule (round/ceil/floor; legacy values
                // normalized). decimals coerced to 0 so the UI always shows a
                // number (the "auto"/currency-step option was dropped in rev-B).
                'tax_rounding_mode' => ShopOrderSettingsService::normalizeTaxRoundingMode($setting?->tax_rounding_mode),
                'tax_rounding_decimals' => $setting?->tax_rounding_decimals ?? 0,
                // Item-edit policy — false (default) = pending-only (BR-OI05);
                // true = edit/remove/void an item in ANY status. Read by pos-web
                // (relax the edit UI) + synced to the workstation engine.
                'allow_item_edit_any_status' => (bool) ($setting?->allow_item_edit_any_status ?? false),
                // plan-051 — per-status void matrix. Raw column (null = legacy
                // fallback) + the RESOLVED effective list clients drive
                // canVoid from, + the stock-deduction timing.
                'item_voidable_statuses' => $setting?->item_voidable_statuses,
                'effective_item_voidable_statuses' => VoidableStatusResolver::resolve($setting),
                'stock_deduction_timing' => $setting?->stock_deduction_timing ?? 'on_close',
                // #1152 — display policy for the resolved 登録番号 (default ON).
                'show_seller_registration_on_receipt' => (bool) ($setting?->show_seller_registration_on_receipt ?? true),
                // #876 — optional per-shop toggle: Handy may settle an order
                // directly at the table. OFF (default) = order-taking only.
                'handy_allow_direct_payment' => (bool) ($setting?->handy_allow_direct_payment ?? false),
                // #2806 — pay-at-counter channel + its kiosk QR. Both default
                // true, INCLUDING for a branch that has no row yet, so exposing
                // the switches changes nothing for anybody until a shop touches
                // them. Same defaults are echoed by the customer-facing
                // payment-context endpoint; they have to agree.
                'counter_pay_enabled' => (bool) ($setting?->counter_pay_enabled ?? true),
                'counter_pay_show_qr' => (bool) ($setting?->counter_pay_show_qr ?? false),
                // Ngôn ngữ phiếu in — null preserved so admin sees "Theo mặc
                // định chi nhánh" instead of a language nobody picked.
                'print_label_locale' => $setting?->print_label_locale,
                // plan-037 — null preserved so admin sees "Theo HQ" (brand default).
                'confirmation_timeout_minutes' => $setting?->confirmation_timeout_minutes,
                // #1160 — raw shop value (null = inherit HQ) + the resolved
                // number the customer ETA actually uses, so admin can show
                // "Theo HQ (5 phút/món)" without a second request.
                'prep_minutes_per_item' => $setting?->prep_minutes_per_item,
                'effective_prep_minutes_per_item' => app(EffectiveOrderPolicyService::class)
                    ->resolve($shop)['prep_minutes_per_item'],
                // #491 — raw shop value (null = inherit HQ) + resolved effective
                // value the apps act on.
                'table_status_after_payment' => $setting?->table_status_after_payment,
                'effective_table_status_after_payment' => $effectiveTableStatusAfterPayment,
                'available_currencies' => $this->availableCurrencies(),
                'available_statuses' => $this->availableStatuses(),
            ],
        ]);
    }

    // =========================================================================
    //  Update
    // =========================================================================

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/settings/order',
        summary: 'Update order settings for this shop',
        tags: ['Shop Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'default_order_item_status', type: 'string', nullable: true, enum: ['pending', 'preparing', 'ready', 'served'], description: 'Set to null to reset to system default (pending)'),
                new OA\Property(property: 'enable_quick_order', type: 'boolean', description: 'Toggle pos-web quick-order UX (BR-SOS04). Omit the key to leave unchanged.'),
                new OA\Property(property: 'service_charge_rate', type: 'number', format: 'float', description: 'Branch service charge rate (%) \/ 0-100.'),
                // plan-043 — consumption-tax settings. See docs/guide/tax-types.md.
                new OA\Property(property: 'default_tax_type_id', type: 'string', format: 'uuid', nullable: true, description: 'plan-043 — branch default TaxType. Must belong to the shop\'s brand and be active (422 otherwise); null clears it (fall through to the brand default).'),
                new OA\Property(property: 'prices_include_tax', type: 'boolean', description: 'plan-043 — 総額表示 flag. Flipping it while any till at this branch has an open shift returns 409 TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT.'),
                new OA\Property(property: 'service_charge_tax_rate', type: 'number', format: 'float', description: 'plan-043 — tax rate (%) \/ 0-100 applied to the service charge.'),
                new OA\Property(property: 'close_report_tax_breakdown', type: 'boolean', description: 'plan-043 — toggle the per-rate block on the thermal close report.'),
                new OA\Property(property: 'currency_code', type: 'string', description: 'Display currency code (BR-SOS06).'),
                new OA\Property(property: 'split_bill_rounding_mode', type: 'string', enum: ['auto', 'integer', 'two_decimals', 'none'], description: 'Dine-in split-bill rounding mode (BR-SOS07).'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Settings updated', content: new OA\JsonContent(properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'default_order_item_status', type: 'string', nullable: true),
                        new OA\Property(property: 'enable_quick_order', type: 'boolean'),
                        new OA\Property(property: 'available_statuses', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                ),
            ])),
            new OA\Response(response: 409, description: 'Tax-included mode change blocked while a cashier shift is open (TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT)'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $validStatuses = implode(',', [
            OrderItemStatusEnum::Pending->value,
            OrderItemStatusEnum::Preparing->value,
            OrderItemStatusEnum::Ready->value,
            OrderItemStatusEnum::Served->value,
        ]);

        $validCurrencies = implode(',', array_column($this->availableCurrencies(), 'code'));

        $request->validate([
            'default_order_item_status' => ['nullable', 'string', "in:{$validStatuses}"],
            'enable_quick_order' => ['sometimes', 'boolean'],
            // BR-SOS05 — admin-only knobs for the checkout calculator.
            'service_charge_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            // BR-SOS06 — pos-web display currency. Constrained to the
            // hand-picked list in availableCurrencies() to avoid arbitrary
            // ISO codes that pos-web's Intl.NumberFormat wouldn't format
            // sensibly (e.g. cryptocurrency tickers).
            'currency_code' => ['sometimes', 'string', "in:{$validCurrencies}"],
            // BR-SOS07 — dine-in split-bill rounding mode.
            'split_bill_rounding_mode' => ['sometimes', 'string', Rule::in(['auto', 'integer', 'two_decimals', 'none'])],
            // BR-SOS08 — plan-035. nullable bool so admin can pick "Theo HQ"
            // (null) / "Bật" / "Tắt" tri-state from the FE.
            'prep_before_payment' => ['sometimes', 'nullable', 'boolean'],
            // #491 — nullable so admin can pick "Theo HQ" (null) / "Trống" /
            // "Đang dọn". Only free|cleaning accepted.
            'table_status_after_payment' => ['sometimes', 'nullable', Rule::in(['free', 'cleaning'])],
            'customer_email_required' => ['sometimes', 'boolean'],
            // Print the shift-open cash-count slip on shift open (default true).
            'print_shift_open_report' => ['sometimes', 'boolean'],
            // #1306 — print the table-paid slip when a table's bill is settled.
            'print_table_paid' => ['sometimes', 'boolean'],
            'close_report_payment_methods' => ['sometimes', 'boolean'],
            'close_report_service_charge' => ['sometimes', 'boolean'],
            'close_report_denominations' => ['sometimes', 'boolean'],
            'close_report_drawer_check' => ['sometimes', 'boolean'],
            // plan-037 — confirmation timeout override. null = inherit
            // brand. 1–30 minutes (cap matches what the FE countdown
            // can reasonably display without losing UX).
            'confirmation_timeout_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:30'],
            // #1160 — prep minutes per item; the customer ETA is this x total
            // quantity. null = inherit the brand default. 0 is allowed (a shop
            // that hands over pre-made goods instantly); the 120 cap keeps a
            // fat-fingered value from promising a next-day pickup on a big cart.
            'prep_minutes_per_item' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            // plan-043 — consumption-tax settings. default_tax_type_id's
            // same-brand + active check runs below (brand comes from the
            // resolved shop, not the request). prices_include_tax has the
            // open-shift 409 guard below.
            'default_tax_type_id' => ['sometimes', 'nullable', 'uuid'],
            'prices_include_tax' => ['sometimes', 'boolean'],
            'service_charge_tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'close_report_tax_breakdown' => ['sometimes', 'boolean'],
            // plan-045 BR-SOS12 — consumption-tax rounding rule (端数処理),
            // independent of split_bill_rounding_mode. Snapshotted per order.
            // rev-B: three directions round/ceil/floor; decimals required 0–3
            // (the "auto"/currency-step option was dropped — no more null).
            'tax_rounding_mode' => ['sometimes', 'string', Rule::in(['round', 'ceil', 'floor'])],
            'tax_rounding_decimals' => ['sometimes', 'integer', 'min:0', 'max:3'],
            // Item-edit policy — when true, order items can be edited/removed/
            // voided regardless of status (OFF = pending-only, BR-OI05).
            'allow_item_edit_any_status' => ['sometimes', 'boolean'],
            // plan-051 — per-status void matrix (null clears back to the
            // legacy-flag fallback) + stock-deduction timing.
            'item_voidable_statuses' => ['sometimes', 'nullable', 'array'],
            'item_voidable_statuses.*' => [Rule::in(VoidableStatusResolver::activeStatuses())],
            'stock_deduction_timing' => ['sometimes', Rule::in(['on_close', 'on_preparing', 'on_add'])],
            'show_seller_registration_on_receipt' => ['sometimes', 'boolean'],
            // #876 — Handy direct payment (default OFF).
            'handy_allow_direct_payment' => ['sometimes', 'boolean'],
            // #2806 — this rule is the GATE, not the delivery: the service
            // reads `$request` directly, so an unruled key would still be
            // written, just never rejected. Measured by removing these two
            // lines — the round-trip tests stay green and only the 422 test
            // fails. Without them `boolean()` would coerce any junk a caller
            // sends ("no", 0, []) into a real switch position.
            'counter_pay_enabled' => ['sometimes', 'boolean'],
            'counter_pay_show_qr' => ['sometimes', 'boolean'],
            // Ngôn ngữ của MỌI phiếu in tại quán. Whitelist đúng tập locale
            // toàn hệ thống (omnify.yaml `locale.locales`) — cột là string(8)
            // nên nếu không chặn ở đây, một giá trị rác sẽ sync xuống
            // workstation và phiếu im lặng rơi về nhãn mặc định. null =
            // "chưa cấu hình", workstation tự fallback (branches.locale →
            // pos_print_locale → ja).
            'print_label_locale' => ['sometimes', 'nullable', Rule::in(['ja', 'en', 'vi'])],
        ]);

        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        // #1687 — the guard+write transaction moved VERBATIM into
        // ShopOrderSettingsService: four mid-shift guards (plan-031 currency,
        // plan-043 tax-included, plan-045 tax-rounding, #1129 service-charge
        // advisory) reading under lockForUpdate in the same transaction as the
        // write. The controller keeps validation and the response envelope.
        // #1129 — the advisory flag is computed inside that transaction and
        // rides back on the result object for the response `meta`.
        $result = $this->settings->update($request, $shop, $this->getOrganizationId());
        $setting = $result->setting;
        $serviceChargeTaxRateWarning = $result->serviceChargeTaxRateWarning;

        return response()->json([
            'data' => [
                'default_order_item_status' => $setting->default_order_item_status,
                'enable_quick_order' => (bool) $setting->enable_quick_order,
                'service_charge_rate' => (string) $setting->service_charge_rate,
                'currency_code' => (string) $setting->currency_code,
                'split_bill_rounding_mode' => (string) $setting->split_bill_rounding_mode,
                'prep_before_payment' => $setting->prep_before_payment,
                'customer_email_required' => (bool) $setting->customer_email_required,
                'print_shift_open_report' => (bool) $setting->print_shift_open_report,
                // #1306 — the PATCH response is serialised HERE, separately from show().
                // Missing it echoes null for a value that was saved correctly, which
                // reads to the FE as "not configured".
                'print_table_paid' => (bool) $setting->print_table_paid,
                'close_report_payment_methods' => (bool) $setting->close_report_payment_methods,
                'close_report_service_charge' => (bool) $setting->close_report_service_charge,
                'close_report_denominations' => (bool) $setting->close_report_denominations,
                'close_report_drawer_check' => (bool) $setting->close_report_drawer_check,
                'confirmation_timeout_minutes' => $setting->confirmation_timeout_minutes,
                // #1160 — echo both the raw override and what it resolves to;
                // the saved() hook already busted the policy cache above, so
                // this read reflects the value just written.
                'prep_minutes_per_item' => $setting->prep_minutes_per_item,
                'effective_prep_minutes_per_item' => app(EffectiveOrderPolicyService::class)
                    ->resolve($shop)['prep_minutes_per_item'],
                // plan-043 — consumption-tax settings.
                'default_tax_type_id' => $setting->default_tax_type_id,
                'prices_include_tax' => (bool) $setting->prices_include_tax,
                'service_charge_tax_rate' => (string) $setting->service_charge_tax_rate,
                'close_report_tax_breakdown' => (bool) $setting->close_report_tax_breakdown,
                // plan-045 — tax rounding rule (round/ceil/floor; legacy normalized).
                'tax_rounding_mode' => ShopOrderSettingsService::normalizeTaxRoundingMode($setting->tax_rounding_mode),
                'tax_rounding_decimals' => $setting->tax_rounding_decimals ?? 0,
                'allow_item_edit_any_status' => (bool) $setting->allow_item_edit_any_status,
                'item_voidable_statuses' => $setting->item_voidable_statuses,
                'effective_item_voidable_statuses' => VoidableStatusResolver::resolve($setting),
                'stock_deduction_timing' => $setting->stock_deduction_timing ?? 'on_close',
                'handy_allow_direct_payment' => (bool) $setting->handy_allow_direct_payment,
                // #2806 — echoed back so the admin form re-reads what it just
                // saved rather than trusting its own optimistic state.
                //
                // `?? true` is NOT decoration. When this PATCH is what CREATES
                // the row, the model instance in hand was never re-read from the
                // database, so a column the request did not mention holds `null`
                // here rather than its DDL default — and `(bool) null` is
                // `false`. The neighbours above get away without it only because
                // their defaults happen to be false. A shop toggling the QR off
                // would otherwise be told, in the very same response, that its
                // counter channel had been switched off too.
                'counter_pay_enabled' => (bool) ($setting->counter_pay_enabled ?? true),
                'counter_pay_show_qr' => (bool) ($setting->counter_pay_show_qr ?? false),
                'print_label_locale' => $setting->print_label_locale,
                'available_currencies' => $this->availableCurrencies(),
                'available_statuses' => $this->availableStatuses(),
            ],
            'meta' => [
                // #1129 — advisory, not a block: the write already happened.
                // True means a cashier shift is open at this branch AND the
                // service-charge tax rate just moved, so that shift now spans
                // two rates.
                'service_charge_tax_rate_changed_during_open_shift' => $serviceChargeTaxRateWarning,
            ],
        ]);
    }

    /**
     * POS-scoped update of ONLY the 精算 close-report section toggles.
     *
     * Unlike update() (admin, SSO — touches currency / tax / policy with the
     * open-shift + currency-change guards), this method writes nothing but the
     * four close_report_* booleans. A cashier terminal (device-token auth via
     * ResolvePosShop) can flip which sections print without any risk to
     * currency / tax / order-policy settings. Branch comes from the resolved
     * POS shop context, never from the request body.
     */
    public function updateCloseReportToggles(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        $request->validate([
            'close_report_payment_methods' => ['sometimes', 'boolean'],
            'close_report_service_charge' => ['sometimes', 'boolean'],
            'close_report_denominations' => ['sometimes', 'boolean'],
            'close_report_drawer_check' => ['sometimes', 'boolean'],
            'close_report_tax_breakdown' => ['sometimes', 'boolean'],
        ]);

        // AUDIT FIX 1.5 (2026-07-14): the tax-breakdown block on the thermal
        // close report is an audit control — an anonymous device token could
        // previously switch it off with no accountable actor. Layout toggles
        // (payment methods / denominations / …) stay device-accessible, but
        // the tax toggle now requires an authenticated USER (the middleware
        // stamps `ssoUser` only on the human path — the device path resolves
        // user() to the Device model) so every change has an owner. The
        // Z-report PDF is unaffected either way — it always prints the
        // breakdown.
        if ($request->has('close_report_tax_breakdown') && ! $request->attributes->has('ssoUser')) {
            abort(response()->json([
                'message' => 'Only a signed-in user can change the tax-breakdown print toggle.',
                'code' => 'TAX_BREAKDOWN_TOGGLE_FORBIDDEN',
            ], 403));
        }

        $payload = [];
        foreach ([
            'close_report_payment_methods',
            'close_report_service_charge',
            'close_report_denominations',
            'close_report_drawer_check',
            'close_report_tax_breakdown',
        ] as $key) {
            if ($request->has($key)) {
                $payload[$key] = $request->boolean($key);
            }
        }

        $setting = ShopOrderSetting::updateOrCreate(
            ['branch_id' => $shop->id],
            $payload,
        );

        return response()->json([
            'data' => [
                'close_report_payment_methods' => (bool) $setting->close_report_payment_methods,
                'close_report_service_charge' => (bool) $setting->close_report_service_charge,
                'close_report_denominations' => (bool) $setting->close_report_denominations,
                'close_report_drawer_check' => (bool) $setting->close_report_drawer_check,
                'close_report_tax_breakdown' => (bool) $setting->close_report_tax_breakdown,
            ],
        ]);
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Hand-picked ISO 4217 currencies supported by pos-web's
     * Intl.NumberFormat rendering. Add new entries here when a new
     * region comes online — the admin-web settings dropdown reads
     * from this list, so adding a code automatically makes it pickable.
     *
     * #1687 — the `normalizeTaxRoundingMode` / `branchHasOpenShift` /
     * `branchHasOpenChain` helpers that used to sit here moved with the
     * guard+write transaction into `ShopOrderSettingsService`. The
     * plan-043/031 note about what "genuinely occupying a till" means went
     * with `branchHasOpenShift`, which is the method it describes.
     *
     * @return array<int, array{code: string, label: string, symbol: string}>
     */
    private function availableCurrencies(): array
    {
        return [
            ['code' => 'VND', 'label' => 'Vietnamese Đồng (VND)', 'symbol' => '₫'],
            ['code' => 'JPY', 'label' => 'Japanese Yen (JPY)', 'symbol' => '¥'],
            ['code' => 'USD', 'label' => 'US Dollar (USD)', 'symbol' => '$'],
            ['code' => 'EUR', 'label' => 'Euro (EUR)', 'symbol' => '€'],
            ['code' => 'KRW', 'label' => 'Korean Won (KRW)', 'symbol' => '₩'],
            ['code' => 'CNY', 'label' => 'Chinese Yuan (CNY)', 'symbol' => '¥'],
            ['code' => 'THB', 'label' => 'Thai Baht (THB)', 'symbol' => '฿'],
            ['code' => 'IDR', 'label' => 'Indonesian Rupiah (IDR)', 'symbol' => 'Rp'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    private function availableStatuses(): array
    {
        return [
            [
                'value' => 'pending',
                'label' => OrderItemStatusEnum::Pending->label(),
                'description' => 'Full flow: pending → preparing → ready → served',
            ],
            [
                'value' => 'preparing',
                'label' => OrderItemStatusEnum::Preparing->label(),
                'description' => 'Skip pending — items go straight to kitchen',
            ],
            [
                'value' => 'ready',
                'label' => OrderItemStatusEnum::Ready->label(),
                'description' => 'Skip pending + preparing — counter service',
            ],
            [
                'value' => 'served',
                'label' => OrderItemStatusEnum::Served->label(),
                'description' => 'Skip all — self-service / grab and go',
            ],
        ];
    }
}
