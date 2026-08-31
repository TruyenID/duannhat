<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Compliance\ComplianceProfileResolver;
use App\Services\Order\Contracts\BranchOpenShiftStatus;
use App\Services\Till\EloquentBranchOpenShiftStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * #1687 (child of #1666) — the guard+write transaction behind
 * `PATCH /api/v1/shops/{shopSlug}/settings/order`, lifted VERBATIM out of
 * `ShopOrderSettingsController::update()`.
 *
 * This is code MOTION, not a cleanup. The body contains four mid-shift guards
 * that read under `lockForUpdate` — plan-031 (currency_code), plan-043
 * (prices_include_tax), plan-045 (tax_rounding_mode / _decimals) and #1129
 * (service_charge_tax_rate advisory) — and they protect cashier-shift
 * reconciliation. Read order, lock order and the 409 `code` strings are the
 * contract; keep them as they are.
 *
 * Open-shift/-chain 409 guard codes (#1130 — FROZEN, match EXACTLY; the
 * three names are inconsistent by history and renaming is a breaking client
 * sweep, so the full set is the contract, not a suffix pattern):
 *   CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT
 *   TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT
 *   TAX_ROUNDING_LOCKED_OPEN_SHIFT
 *
 * The caller keeps validation and the response envelope: this service does not
 * know what the HTTP payload looks like, only which keys were sent.
 */
class ShopOrderSettingsService
{
    /**
     * The two mid-shift predicates arrive through a published port instead of
     * `Till` / `TillSessionService` directly: this class writes
     * `shop_order_settings` so it belongs to Ordering, and Ordering must not
     * reach into Payments. The queries themselves moved verbatim into
     * {@see EloquentBranchOpenShiftStatus}.
     */
    public function __construct(
        private readonly BranchOpenShiftStatus $openShifts,
        private readonly ComplianceProfileResolver $complianceProfiles,
    ) {}

    /**
     * #2108 (từ #2102) — create-only defaults for a branch's first
     * `shop_order_settings` row, derived from the organization's operating
     * country: JP ⇒ `prices_include_tax = true` (総額表示義務 — displayed
     * prices must be tax-INCLUDED, so a new JP shop must not silently add tax
     * on top and over-collect).
     *
     * Country rides {@see ComplianceProfileResolver} — the single sanctioned
     * reader of `organizations.operating_country` (#1445) — and inherits its
     * fail-safe-to-JP posture (#1153): an org that has not mirrored a country
     * yet defaults to the JP profile, hence tax-included. That posture is a
     * deliberate match with every other compliance parameter, not an accident.
     *
     * Deliberately NOT a schema/column-default change (omnify regen is out of
     * scope for #2108) and applied ONLY when the row is first created — an
     * explicit admin value always wins, and existing rows are never touched.
     *
     * @return array<string, bool>
     */
    public function creationDefaults(Branch $shop): array
    {
        return [
            'prices_include_tax' => $this->complianceProfiles
                ->forOrganization($shop->console_organization_id)
                ->country() === 'JP',
        ];
    }

    /**
     * Apply a partial update to a branch's order settings, running the
     * mid-shift guards inside the same transaction as the write.
     *
     * `$request` is passed through rather than flattened into an array on
     * purpose: the body distinguishes `has()` from `exists()` and leans on
     * `boolean()` casting per key, and re-deriving that from an array is
     * exactly the kind of "while I'm here" edit this extraction must not make.
     */
    public function update(Request $request, Branch $shop, string $organizationId): ShopOrderSettingsUpdateResult
    {
        // AUDIT FIX 3.6 (2026-07-14): the open-shift guards used to run as bare
        // reads before the write — a shift opening between the check and the
        // updateOrCreate could land under the new currency / tax mode. The
        // guard reads + the write now share one transaction, and the current-
        // value reads take lockForUpdate on the settings row — the SAME row
        // TillSessionService::open() now locks — so a concurrent flip and
        // shift-open serialize instead of interleaving.
        // #1129 — the advisory flag is set inside the transaction (where the
        // pre-write value is read under the same lock as the guards) and
        // travels back out on the result object for the response envelope.
        return DB::transaction(function () use ($request, $shop, $organizationId): ShopOrderSettingsUpdateResult {

            // #1690 — bốn guard dưới đây hỏi CÙNG một câu. Trước đó mỗi cái tự
            // gọi cặp `branchHasOpenShift() || branchHasOpenChain()`, tức tối đa
            // 4 lượt, và `branchHasOpenChain` không rẻ. Cả bốn nằm TRONG
            // transaction đang giữ `lockForUpdate` trên `shop_order_settings` —
            // đúng hàng `TillSessionService::open()` tranh chấp — nên mỗi truy
            // vấn thừa là thời gian một thu ngân bị chặn mở ca.
            //
            // Memo hoá an toàn ở đây CHÍNH VÌ cái khoá đó: không ca nào mở hay
            // đóng được trong lúc transaction này chạy, nên bốn câu trả lời
            // giống hệt nhau theo cấu trúc, không phải theo may rủi.
            $midShift = null;
            $branchIsMidShift = function () use (&$midShift, $shop): bool {
                return $midShift ??= $this->openShifts->branchHasOpenShift($shop->id)
                    || $this->openShifts->branchHasOpenChain($shop->id);
            };

            // Currency mid-shift guard. Flipping currency_code while any till at
            // this branch has an open cashier session would orphan the session's
            // snapshot (opening_float_amount, denomination counts) — they were
            // recorded in the old currency and the reconciliation at close()
            // would reformat them with the new one, producing meaningless
            // variance. Block early with a structured 409 so admin-web can
            // surface a clear message ("Close all open shifts first").
            //
            // Defense in depth: pos-web close-page also pins currency to the
            // session snapshot (TillSession.default_currency_code) so it stays
            // correct even if a flip somehow slips through this gate.
            if ($request->has('currency_code')) {
                $newCurrency = (string) $request->input('currency_code');
                $currentCurrency = (string) (ShopOrderSetting::where('branch_id', $shop->id)
                    ->lockForUpdate()
                    ->value('currency_code') ?? '');

                if (strtoupper($newCurrency) !== strtoupper($currentCurrency)) {
                    if ($branchIsMidShift()) {
                        abort(response()->json([
                            'message' => 'Currency cannot be changed while a cashier shift is open. Close all open shifts at this branch first.',
                            'code' => 'CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT',
                        ], 409));
                    }
                }
            }

            // plan-043 — tax-included mode mid-shift guard (BR-SOS10). Flipping
            // prices_include_tax while a shift is open would make the shift's
            // orders span two tax modes, so the shift report can't reconcile.
            // Same 409 pattern as the currency guard (plan-031). Rate / default-
            // type changes are NOT blocked — per-line snapshots protect the data
            // (Q6); admin-web only shows an advisory for those.
            if ($request->has('prices_include_tax')) {
                $newMode = $request->boolean('prices_include_tax');
                $currentMode = (bool) (ShopOrderSetting::where('branch_id', $shop->id)
                    ->lockForUpdate()
                    ->value('prices_include_tax') ?? false);

                if ($newMode !== $currentMode) {
                    if ($branchIsMidShift()) {
                        abort(response()->json([
                            'message' => 'Tax-included pricing cannot be changed while a cashier shift is open. Close all open shifts at this branch first.',
                            'code' => 'TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT',
                        ], 409));
                    }
                }
            }

            // plan-045 BR-SOS12 — tax-rounding mid-shift guard. Each order snapshots
            // the rounding rule at create, so flipping it mid-shift splits the shift
            // across two rounding rules and its Z-report can't reconcile. Same 409
            // pattern as the tax-mode guard above; only fires on an actual change.
            $roundingChange = false;
            if ($request->has('tax_rounding_mode')) {
                // Normalize both sides so a legacy snapshot (half_up/round_up/
                // round_down) vs the new round/ceil/floor doesn't read as a change
                // when the direction is actually the same.
                $current = self::normalizeTaxRoundingMode(
                    ShopOrderSetting::where('branch_id', $shop->id)
                        ->lockForUpdate()->value('tax_rounding_mode')
                );
                $roundingChange = $roundingChange
                    || self::normalizeTaxRoundingMode($request->input('tax_rounding_mode')) !== $current;
            }
            if ($request->exists('tax_rounding_decimals')) {
                // null (legacy "auto") ≡ 0 for the primary integer currencies, so
                // treat the one-time null→0 migration as a non-change.
                $current = ShopOrderSetting::where('branch_id', $shop->id)
                    ->lockForUpdate()->value('tax_rounding_decimals');
                $new = (int) ($request->input('tax_rounding_decimals') ?? 0);
                $roundingChange = $roundingChange || $new !== (int) ($current ?? 0);
            }
            // #1129 — service_charge_tax_rate feeds the SAME per-rate group
            // rounding as an item rate, so changing it mid-shift splits the
            // shift across two rates. It is NOT blocked, for the same reason a
            // tax rate is not (plan-043 Q6: per-line snapshots protect orders
            // already created) — but the operator is now told, instead of
            // finding out from a Z-report that will not reconcile.
            $serviceChargeTaxRateChanged = false;
            if ($request->has('service_charge_tax_rate')) {
                $current = (float) (ShopOrderSetting::where('branch_id', $shop->id)
                    ->lockForUpdate()->value('service_charge_tax_rate') ?? 0);
                $serviceChargeTaxRateChanged = (float) $request->input('service_charge_tax_rate') !== $current;
            }
            $serviceChargeTaxRateWarning = $serviceChargeTaxRateChanged
                && ($branchIsMidShift());

            if ($roundingChange && ($branchIsMidShift())) {
                abort(response()->json([
                    'message' => 'Tax rounding cannot be changed while a cashier shift is open. Close all open shifts at this branch first.',
                    'code' => 'TAX_ROUNDING_LOCKED_OPEN_SHIFT',
                ], 409));
            }

            // plan-043 — default_tax_type_id must belong to the shop's brand and
            // be active (only active types are assignable; existing references
            // stay valid). null clears the branch default (fall through to brand).
            if ($request->exists('default_tax_type_id') && $request->input('default_tax_type_id') !== null) {
                $typeId = (string) $request->input('default_tax_type_id');
                $ownsActiveType = TaxType::where('id', $typeId)
                    ->where('brand_id', $shop->brand?->id)
                    ->where('is_active', true)
                    ->exists();

                if (! $ownsActiveType) {
                    throw ValidationException::withMessages([
                        'default_tax_type_id' => 'The selected tax type must belong to this shop\'s brand and be active.',
                    ]);
                }
            }

            // Only persist fields that the caller actually sent so PATCH stays a true
            // partial update — omitting `enable_quick_order` must not reset it.
            $payload = [
                'organization_id' => $organizationId,
            ];
            if ($request->has('default_order_item_status')) {
                $payload['default_order_item_status'] = $request->input('default_order_item_status');
            }
            if ($request->has('enable_quick_order')) {
                $payload['enable_quick_order'] = $request->boolean('enable_quick_order');
            }
            if ($request->has('service_charge_rate')) {
                $payload['service_charge_rate'] = $request->input('service_charge_rate');
            }
            if ($request->has('currency_code')) {
                $payload['currency_code'] = $request->input('currency_code');
            }
            if ($request->has('split_bill_rounding_mode')) {
                $payload['split_bill_rounding_mode'] = $request->input('split_bill_rounding_mode');
            }
            if ($request->exists('prep_before_payment')) {
                // null stays null so the row falls back to BrandOrderPolicy.
                $payload['prep_before_payment'] = $request->input('prep_before_payment') === null
                    ? null
                    : $request->boolean('prep_before_payment');
            }
            if ($request->exists('table_status_after_payment')) {
                // #491 — null stays null so the shop row inherits the HQ brand
                // default (BrandOrderPolicy.default_table_status_after_payment).
                $payload['table_status_after_payment'] = $request->input('table_status_after_payment') ?: null;
            }
            if ($request->has('customer_email_required')) {
                $payload['customer_email_required'] = $request->boolean('customer_email_required');
            }
            if ($request->has('print_shift_open_report')) {
                $payload['print_shift_open_report'] = $request->boolean('print_shift_open_report');
            }
            if ($request->has('print_table_paid')) {
                $payload['print_table_paid'] = $request->boolean('print_table_paid');
            }
            foreach ([
                'close_report_payment_methods',
                'close_report_service_charge',
                'close_report_denominations',
                'close_report_drawer_check',
            ] as $closeReportToggle) {
                if ($request->has($closeReportToggle)) {
                    $payload[$closeReportToggle] = $request->boolean($closeReportToggle);
                }
            }
            if ($request->exists('confirmation_timeout_minutes')) {
                // null stays null = inherit from brand.
                $val = $request->input('confirmation_timeout_minutes');
                $payload['confirmation_timeout_minutes'] = $val === null ? null : (int) $val;
            }
            if ($request->exists('prep_minutes_per_item')) {
                // #1160 — null stays null = inherit the brand default.
                $val = $request->input('prep_minutes_per_item');
                $payload['prep_minutes_per_item'] = $val === null ? null : (int) $val;
            }
            // plan-043 — consumption-tax settings (partial-update friendly).
            if ($request->exists('default_tax_type_id')) {
                $payload['default_tax_type_id'] = $request->input('default_tax_type_id') ?: null;
            }
            if ($request->has('prices_include_tax')) {
                $payload['prices_include_tax'] = $request->boolean('prices_include_tax');
            }
            if ($request->has('service_charge_tax_rate')) {
                $payload['service_charge_tax_rate'] = $request->input('service_charge_tax_rate');
            }
            if ($request->has('close_report_tax_breakdown')) {
                $payload['close_report_tax_breakdown'] = $request->boolean('close_report_tax_breakdown');
            }
            // plan-045 BR-SOS12 — tax rounding rule.
            if ($request->has('tax_rounding_mode')) {
                $payload['tax_rounding_mode'] = $request->input('tax_rounding_mode');
            }
            if ($request->exists('tax_rounding_decimals')) {
                // Validation requires an integer 0–3 (no more null "auto").
                $payload['tax_rounding_decimals'] = (int) ($request->input('tax_rounding_decimals') ?? 0);
            }
            // plan-051 — void matrix (null = clear back to legacy fallback)
            // + stock-deduction timing.
            if ($request->exists('item_voidable_statuses')) {
                $statuses = $request->input('item_voidable_statuses');
                $payload['item_voidable_statuses'] = $statuses === null
                    ? null
                    : array_values(array_unique(array_map('strval', (array) $statuses)));
            }
            if ($request->has('stock_deduction_timing')) {
                $payload['stock_deduction_timing'] = $request->input('stock_deduction_timing');
            }
            // Item-edit policy — pending-only (false) vs any-status (true).
            if ($request->has('allow_item_edit_any_status')) {
                $payload['allow_item_edit_any_status'] = $request->boolean('allow_item_edit_any_status');
            }

            if ($request->has('show_seller_registration_on_receipt')) {
                $payload['show_seller_registration_on_receipt'] = $request->boolean('show_seller_registration_on_receipt');
            }

            if ($request->has('handy_allow_direct_payment')) {
                $payload['handy_allow_direct_payment'] = $request->boolean('handy_allow_direct_payment');
            }

            // #2806 — pay-at-counter channel + its kiosk QR. This is the THIRD
            // allowlist a new column has to be named in (validation rules,
            // $fillable on the editable model, and here), and this one is the
            // quietest: without it the controller still echoes the request
            // value back, so the response looks like a successful save while
            // the row never changes.
            foreach (['counter_pay_enabled', 'counter_pay_show_qr'] as $counterPayToggle) {
                if ($request->has($counterPayToggle)) {
                    $payload[$counterPayToggle] = $request->boolean($counterPayToggle);
                }
            }
            // Ngôn ngữ phiếu in. `exists` (không phải `has`) để null gửi lên
            // vẫn ghi được — đó là cách quản lý chọn lại "Theo mặc định chi
            // nhánh" sau khi đã đặt một ngôn ngữ cụ thể.
            if ($request->exists('print_label_locale')) {
                $payload['print_label_locale'] = $request->input('print_label_locale') ?: null;
            }

            // #2108 — split the old `updateOrCreate` so CREATE-only country
            // defaults exist: on the first row for a branch, start from
            // {@see creationDefaults()} (JP ⇒ prices_include_tax = true) and
            // let anything the caller explicitly sent override it; on an
            // existing row, apply only the sent keys (defaults never re-apply).
            $setting = ShopOrderSetting::query()
                ->where('branch_id', $shop->id)
                ->first();

            if ($setting === null) {
                $setting = ShopOrderSetting::create(array_merge(
                    ['branch_id' => $shop->id],
                    $this->creationDefaults($shop),
                    $payload,
                ));
            } else {
                $setting->update($payload);
            }

            return new ShopOrderSettingsUpdateResult(
                $setting,
                $serviceChargeTaxRateWarning,
            );

        }); // end AUDIT FIX 3.6 guard+write transaction
    }

    /**
     * plan-045 rev-B — collapse a stored tax-rounding mode to one of the three
     * canonical directions. Accepts the new names (round/ceil/floor) AND the
     * legacy aliases (half_up/round_up/round_down) so a shop configured before
     * the rename reads back a value the settings UI can render; blank/unknown
     * defaults to round.
     *
     * Static + public because the controller serialises the same value on the
     * GET and on the PATCH response envelope. One definition, three call sites
     * — a second copy would drift the day someone adds a fourth alias.
     */
    public static function normalizeTaxRoundingMode(?string $mode): string
    {
        return match ($mode) {
            'ceil', 'round_up' => 'ceil',
            'floor', 'round_down' => 'floor',
            default => 'round',
        };
    }
}
